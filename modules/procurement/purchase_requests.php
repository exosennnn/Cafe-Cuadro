<?php
/**
 * PURCHASE REQUESTS
 * INTEGRATION: Inventory -> Procurement.
 *
 * - Any item at/under its reorder level shows up as a low-stock alert
 *   that Inventory Staff can turn into a Purchase Request with one
 *   click (source = AUTO_LOW_STOCK).
 * - Inventory Staff can also raise a manual PR for anything.
 * - Owner reviews: approves or rejects pending requests. The Owner does
 *   NOT raise requests - approval and request must stay in different
 *   hands for the control to mean anything.
 * - Once Approved, Inventory Staff converts the request into a real
 *   Purchase Order (picks a supplier + unit price) - this creates the
 *   purchase_orders/purchase_order_items rows and links pr.po_id back to
 *   it, completing the Procurement handoff. The Owner monitors it.
 */
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Purchase Requests';
$canApprove = userCanDo('pr.approve');  // Owner - review / approve
$canRequest = userCanDo('pr.create');   // Inventory Staff - create / submit
$canConvert = userCanDo('po.manage');   // Inventory Staff - create the PO
$branchId = $canRequest ? inventoryAssignedBranchId($pdo) : null;
if ($canRequest && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch before creating purchase requests.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

// ---------- Auto-flag: raise a PR from a low-stock item ----------
if (isset($_POST['action']) && $_POST['action'] === 'raise_from_low_stock') {
    requireCapability('pr.create');
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $stmt = $pdo->prepare("SELECT i.item_name, i.reorder_level, COALESCE(bii.current_stock,0) AS current_stock
        FROM items i LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?
        WHERE i.item_id=? AND i.status='Active' AND COALESCE(bii.current_stock,0)<=i.reorder_level");
    if ($itemId === false || $itemId < 1) {
        setFlash('danger', 'Please select a valid low-stock item.');
        redirect('modules/procurement/purchase_requests.php');
    }
    $stmt->execute([$branchId, $itemId]);
    $item = $stmt->fetch();

    if ($item) {
        $existing = $pdo->prepare("SELECT pr_id FROM purchase_requests WHERE item_id = ? AND branch_id=? AND status IN ('Pending','Approved')");
        $existing->execute([$itemId, $branchId]);
        if ($existing->fetch()) {
            setFlash('danger', 'There is already an open purchase request for ' . $item['item_name'] . '.');
        } else {
            $suggestedQty = max($item['reorder_level'] * 2 - $item['current_stock'], $item['reorder_level']);
            $prNumber = generateDocNumber($pdo, 'PR');
            $pdo->prepare("INSERT INTO purchase_requests (pr_number, item_id, branch_id, requested_qty, reason, source, status, requested_by) VALUES (?, ?, ?, ?, ?, 'AUTO_LOW_STOCK', 'Pending', ?)")
                ->execute([$prNumber, $itemId, $branchId, $suggestedQty, 'Auto-generated: stock (' . $item['current_stock'] . ') at/under reorder level (' . $item['reorder_level'] . ')', $_SESSION['user_id']]);
            $newPrId = (int) $pdo->lastInsertId();
            logActivity($pdo, $_SESSION['user_id'], 'Procurement', "Raised low-stock PR $prNumber for {$item['item_name']}");
            notifyRole($pdo, ROLE_OWNER, 'Purchase Request Needs Approval', "PR {$prNumber} ({$item['item_name']}, qty {$suggestedQty}) is waiting for your approval.", 'purchase_request', $newPrId);
            setFlash('success', "Purchase request $prNumber created for {$item['item_name']}.");
        }
    }
    redirect('modules/procurement/purchase_requests.php');
}

// ---------- Manual PR ----------
if (isset($_POST['action']) && $_POST['action'] === 'manual_request') {
    requireCapability('pr.create');
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $qtyInput = $_POST['requested_qty'] ?? '';
    $qty = is_string($qtyInput) && is_numeric($qtyInput) ? (float)$qtyInput : 0.0;
    $reasonInput = $_POST['reason'] ?? '';
    $reason = is_string($reasonInput) ? trim($reasonInput) : "\0";

    $itemCheck = $pdo->prepare("SELECT item_id FROM items WHERE item_id=? AND status='Active'");
    if ($itemId !== false) { $itemCheck->execute([$itemId]); }
    if ($itemId === false || $itemId < 1 || !$itemCheck->fetch() || !is_finite($qty) || $qty <= 0 || $qty > 99999999.99 || strlen($reason) > 255) {
        setFlash('danger', 'Please choose an item and a valid quantity.');
    } else {
        $prNumber = generateDocNumber($pdo, 'PR');
        $pdo->prepare("INSERT INTO purchase_requests (pr_number, item_id, branch_id, requested_qty, reason, source, status, requested_by) VALUES (?, ?, ?, ?, ?, 'MANUAL', 'Pending', ?)")
            ->execute([$prNumber, $itemId, $branchId, $qty, $reason ?: null, $_SESSION['user_id']]);
        $newPrId = (int) $pdo->lastInsertId();
        logActivity($pdo, $_SESSION['user_id'], 'Procurement', "Raised manual PR $prNumber");

        $itemNameStmt = $pdo->prepare("SELECT item_name FROM items WHERE item_id = ?");
        $itemNameStmt->execute([$itemId]);
        $itemName = $itemNameStmt->fetchColumn() ?: 'an item';
        notifyRole($pdo, ROLE_OWNER, 'Purchase Request Needs Approval', "PR {$prNumber} ({$itemName}, qty {$qty}) is waiting for your approval.", 'purchase_request', $newPrId);

        setFlash('success', "Purchase request $prNumber submitted.");
    }
    redirect('modules/procurement/purchase_requests.php');
}

// ---------- Approve / Reject ----------
if (in_array($_POST['action'] ?? '', ['approve_request', 'reject_request'], true)) {
    requireCapability('pr.approve');
    $requestId = filter_var($_POST['pr_id'] ?? null, FILTER_VALIDATE_INT);
    if ($requestId === false || $requestId < 1) {
        setFlash('danger', 'Invalid purchase request.');
    } else {
        $newStatus = $_POST['action'] === 'approve_request' ? 'Approved' : 'Rejected';
        $update = $pdo->prepare("UPDATE purchase_requests SET status=?, approved_by=? WHERE pr_id=? AND status='Pending'");
        $update->execute([$newStatus, $_SESSION['user_id'], $requestId]);
        if ($update->rowCount() === 1) {
            setFlash('success', 'Purchase request ' . strtolower($newStatus) . '.');
        } else {
            setFlash('danger', 'This request is no longer pending.');
        }
    }
    redirect('modules/procurement/purchase_requests.php');
}

// ---------- Convert an Approved PR into a real Purchase Order ----------
if (isset($_POST['action']) && $_POST['action'] === 'convert_to_po') {
    requireCapability('po.manage');
    $prId = filter_var($_POST['pr_id'] ?? null, FILTER_VALIDATE_INT);
    $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
    $priceInput = $_POST['unit_price'] ?? '';
    $unitPrice = is_string($priceInput) && is_numeric($priceInput) ? (float)$priceInput : 0.0;

    if ($prId === false || $prId < 1 || $supplierId === false || $supplierId < 1 || !is_finite($unitPrice) || $unitPrice <= 0 || $unitPrice > 99999999.99) {
        setFlash('danger', 'Please choose a supplier and a valid unit price.');
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM purchase_requests WHERE pr_id=? AND status='Approved' AND branch_id=? FOR UPDATE");
            $stmt->execute([$prId, $branchId]);
            $pr = $stmt->fetch();
            if (!$pr) {
                throw new RuntimeException('This request is not approved or does not belong to your branch.');
            }
            $supplierCheck = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id=? AND status='Active'");
            $supplierCheck->execute([$supplierId]);
            if (!$supplierCheck->fetch()) {
                throw new RuntimeException('Please choose an active supplier.');
            }
            $subtotal = round($pr['requested_qty'] * $unitPrice, 2);
            $poNumber = generateDocNumber($pdo, 'PO');

            $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, branch_id, order_date, status, total_amount, remarks, created_by) VALUES (?, ?, ?, CURDATE(), 'Pending', ?, ?, ?)")
                ->execute([$poNumber, $supplierId, $pr['branch_id'], $subtotal, 'Converted from purchase request ' . $pr['pr_number'], $_SESSION['user_id']]);
            $poId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)")
                ->execute([$poId, $pr['item_id'], $pr['requested_qty'], $unitPrice, $subtotal]);

            $requestUpdate = $pdo->prepare("UPDATE purchase_requests SET status='Converted', po_id=? WHERE pr_id=? AND status='Approved'");
            $requestUpdate
                ->execute([$poId, $prId]);
            if ($requestUpdate->rowCount() !== 1) {
                throw new RuntimeException('The request changed before conversion could be completed.');
            }

            $pdo->commit();
            logActivity($pdo, $_SESSION['user_id'], 'Procurement', "Converted PR {$pr['pr_number']} to PO $poNumber");
            setFlash('success', "Converted to Purchase Order $poNumber.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('PR conversion failed: ' . $e->getMessage());
            setFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Could not create the purchase order. Please try again.');
        }
    }
    redirect('modules/procurement/purchase_requests.php');
}

// ---------- Data for the page ----------
$lowStockItems = [];
if ($canRequest) {
    $lowStockStmt = $pdo->prepare("SELECT i.item_id, i.item_name, COALESCE(bii.current_stock,0) AS current_stock, i.reorder_level, u.unit_name
        FROM items i
        LEFT JOIN units u ON u.unit_id=i.unit_id
        LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?
        WHERE i.status='Active' AND COALESCE(bii.current_stock,0)<=i.reorder_level
          AND NOT EXISTS (SELECT 1 FROM purchase_requests pr WHERE pr.item_id=i.item_id AND pr.branch_id=? AND pr.status IN ('Pending','Approved'))
        ORDER BY COALESCE(bii.current_stock,0) ASC");
    $lowStockStmt->execute([$branchId, $branchId]);
    $lowStockItems = $lowStockStmt->fetchAll();
}

$requestSql = "SELECT pr.*, i.item_name, u.unit_name, b.branch_name, req.first_name AS req_fname, req.last_name AS req_lname,
           app.first_name AS app_fname, app.last_name AS app_lname, po.po_number
    FROM purchase_requests pr
    JOIN items i ON i.item_id = pr.item_id
    LEFT JOIN units u ON u.unit_id = i.unit_id
    JOIN branches b ON b.branch_id=pr.branch_id
    JOIN users req ON req.user_id = pr.requested_by
    LEFT JOIN users app ON app.user_id = pr.approved_by
    LEFT JOIN purchase_orders po ON po.po_id = pr.po_id
    WHERE 1=1";
$requestParams = [];
if ($canRequest) {
    $requestSql .= " AND pr.branch_id=?";
    $requestParams[] = $branchId;
}
$requestSql .= " ORDER BY FIELD(pr.status,'Pending','Approved','Converted','Rejected'), pr.created_at DESC";
$requestStmt = $pdo->prepare($requestSql);
$requestStmt->execute($requestParams);
$requests = $requestStmt->fetchAll();

$allItems = $pdo->query("SELECT item_id, item_name FROM items WHERE status='Active' ORDER BY item_name")->fetchAll();
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers WHERE status='Active' ORDER BY supplier_name")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0"><i class="bi bi-file-earmark-text"></i> Purchase Requests</h4>
    </div>
</div>

<?php if ($lowStockItems): ?>
<div class="card mb-3 border-warning">
    <div class="card-header bg-warning-subtle"><i class="bi bi-exclamation-triangle"></i> Low Stock Alerts (<?= count($lowStockItems) ?>)</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Item</th><th>Current Stock</th><th>Reorder Level</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lowStockItems as $li): ?>
                <tr>
                    <td><?= htmlspecialchars($li['item_name']) ?></td>
                    <td class="text-danger fw-bold"><?= $li['current_stock'] ?> <?= htmlspecialchars($li['unit_name'] ?? '') ?></td>
                    <td><?= $li['reorder_level'] ?></td>
                    <td>
                        <?php if ($canRequest): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="raise_from_low_stock">
                            <input type="hidden" name="item_id" value="<?= $li['item_id'] ?>">
                            <button class="btn btn-sm btn-warning">Raise Purchase Request</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($canRequest): ?>
<div class="card mb-3">
    <div class="card-header">Raise a Manual Request</div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="manual_request">
            <div class="col-md-4">
                <label class="form-label small">Item</label>
                <select name="item_id" class="form-select form-select-sm" required>
                    <option value="">Select item...</option>
                    <?php foreach ($allItems as $it): ?>
                        <option value="<?= $it['item_id'] ?>"><?= htmlspecialchars($it['item_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Quantity</label>
                <input type="number" step="0.01" min="0.01" max="99999999.99" name="requested_qty" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-4">
                <label class="form-label small">Reason (optional)</label>
                <input type="text" name="reason" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100">Submit Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">All Requests</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>PR #</th><th>Item</th><th>Branch</th><th>Qty</th><th>Source</th><th>Requested By</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (!$requests): ?><tr><td colspan="8" class="text-center text-muted py-3">No purchase requests yet.</td></tr><?php endif; ?>
            <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['pr_number']) ?></td>
                    <td><?= htmlspecialchars($r['item_name']) ?></td>
                    <td><?= htmlspecialchars($r['branch_name']) ?></td>
                    <td><?= $r['requested_qty'] ?> <?= htmlspecialchars($r['unit_name'] ?? '') ?></td>
                    <td><span class="badge bg-secondary"><?= $r['source'] === 'AUTO_LOW_STOCK' ? 'Auto (Low Stock)' : 'Manual' ?></span></td>
                    <td><?= htmlspecialchars($r['req_fname'] . ' ' . $r['req_lname']) ?></td>
                    <td>
                        <?php $badge = ['Pending'=>'warning','Approved'=>'info','Converted'=>'success','Rejected'=>'danger'][$r['status']]; ?>
                        <span class="badge bg-<?= $badge ?>"><?= $r['status'] ?></span>
                        <?php if ($r['po_number']): ?><div class="small text-muted">PO: <?= htmlspecialchars($r['po_number']) ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($r['status'] === 'Pending' && $canApprove): ?>
                            <form method="POST" class="d-inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="pr_id" value="<?= (int)$r['pr_id'] ?>"><button class="btn btn-sm btn-success">Approve</button></form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Reject this request?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="pr_id" value="<?= (int)$r['pr_id'] ?>"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
                        <?php elseif ($r['status'] === 'Approved' && $canConvert): ?>
                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#convertModal<?= $r['pr_id'] ?>">Convert to PO</button>
                            <div class="modal fade" id="convertModal<?= $r['pr_id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="convert_to_po">
                                            <input type="hidden" name="pr_id" value="<?= $r['pr_id'] ?>">
                                            <div class="modal-header"><h6 class="modal-title">Convert <?= htmlspecialchars($r['pr_number']) ?> to Purchase Order</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                            <div class="modal-body">
                                                <label class="form-label small">Supplier</label>
                                                <select name="supplier_id" class="form-select form-select-sm mb-2" required>
                                                    <option value="">Select supplier...</option>
                                                    <?php foreach ($suppliers as $s): ?>
                                                        <option value="<?= $s['supplier_id'] ?>"><?= htmlspecialchars($s['supplier_name']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label class="form-label small">Unit Price</label>
                                                <input type="number" step="0.01" min="0.01" name="unit_price" class="form-control form-control-sm" required>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-primary btn-sm">Create Purchase Order</button></div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
