<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Receiving a delivery moves stock, so it is Inventory Staff work only.
requireCapability('po.manage');

$userId = $_SESSION['user_id'];
$branchId = inventoryAssignedBranchId($pdo, (int)$userId);
if (!$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch before receiving deliveries.');
}
$poId = (int)($_GET['po_id'] ?? $_POST['po_id'] ?? 0);

$stmt = $pdo->prepare("SELECT po.*, s.supplier_name, b.branch_name FROM purchase_orders po JOIN suppliers s ON po.supplier_id=s.supplier_id JOIN branches b ON b.branch_id=po.branch_id WHERE po.po_id=? AND po.branch_id=?");
$stmt->execute([$poId, $branchId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('danger', 'Purchase order not found.');
    redirect('modules/procurement/index.php');
}
if (!in_array($po['status'], ['Approved', 'Ordered', 'Partially Received'])) {
    setFlash('danger', 'This purchase order is not open for receiving (must be Approved, Ordered, or Partially Received).');
    redirect('modules/procurement/po_view.php?id=' . $poId);
}

$pageTitle = 'Receive Delivery - ' . $po['po_number'];

// ---------- Handle Receive ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive') {
    csrfVerify();
    $deliveryDateInput = $_POST['delivery_date'] ?? '';
    $deliveryDate = is_string($deliveryDateInput) ? $deliveryDateInput : '';
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : "\0";
    $qtyInput     = $_POST['qty'] ?? [];       // keyed by po_item_id
    $expiryInput  = $_POST['expiry'] ?? [];    // keyed by po_item_id
    try {
        if (!is_array($qtyInput) || !is_array($expiryInput)) throw new InvalidArgumentException('Invalid delivery item quantities.');
        $delivery = recordPurchaseDelivery($pdo, $poId, (int)$branchId, (int)$userId, (string)$deliveryDate, $remarks, $qtyInput, $expiryInput);
        logActivity($pdo, $userId, 'Procurement', "Received delivery {$delivery['delivery_number']} for PO {$delivery['po_number']} (payable ₱" . number_format($delivery['payable_amount'], 2) . ' pending Finance payment)');
        setFlash('success', "Delivery {$delivery['delivery_number']} recorded with ₱" . number_format($delivery['payable_amount'], 2) . ' payable. Stock will be released after Finance marks it paid.');
    } catch (Throwable $e) {
        error_log('receive delivery failed: ' . $e->getMessage());
        setFlash('danger', $e instanceof InvalidArgumentException || $e instanceof RuntimeException ? $e->getMessage() : 'Failed to record delivery. No changes were saved.');
        redirect('modules/procurement/receive.php?po_id=' . $poId);
    }

    redirect('modules/procurement/po_view.php?id=' . $poId);
}

// ---------- Data for form ----------
$itemStmt = $pdo->prepare("SELECT poi.*, i.item_name, i.item_code, i.is_perishable, un.unit_symbol
                            FROM purchase_order_items poi
                            JOIN items i ON poi.item_id = i.item_id
                            JOIN units un ON i.unit_id = un.unit_id
                            WHERE poi.po_id = ?
                            ORDER BY poi.po_item_id ASC");
$itemStmt->execute([$poId]);
$poItems = $itemStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<a href="<?= BASE_URL ?>procurement/po-view?id=<?= $poId ?>" class="text-decoration-none text-muted small d-inline-block mb-3">
    <i class="bi bi-arrow-left"></i> Back to <?= clean($po['po_number']) ?>
</a>


<div class="card-panel p-3">
    <div class="mb-3">
        <span class="text-muted small">Supplier:</span> <strong><?= clean($po['supplier_name']) ?></strong>
        <span class="text-muted small ms-3">Branch:</span> <strong><?= clean($po['branch_name']) ?></strong>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="receive">
        <input type="hidden" name="po_id" value="<?= $poId ?>">

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Delivery Date</label>
                <input type="date" name="delivery_date" class="form-control" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-8 mb-3">
                <label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-control" maxlength="255" placeholder="e.g. Delivered via courier, 1 box damaged, etc.">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Item</th>
                        <th class="text-end">Ordered</th>
                        <th class="text-end">Already Received</th>
                        <th class="text-end">Remaining</th>
                        <th style="width:160px;">Qty to Receive</th>
                        <th style="width:160px;">Expiry Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($poItems as $it):
                        $remaining = $it['quantity_ordered'] - $it['quantity_received'];
                    ?>
                        <tr>
                            <td><?= clean($it['item_name']) ?> <span class="text-muted small">(<?= clean($it['item_code']) ?>)</span></td>
                            <td class="text-end"><?= number_format($it['quantity_ordered'], 2) ?> <?= clean($it['unit_symbol']) ?></td>
                            <td class="text-end"><?= number_format($it['quantity_received'], 2) ?> <?= clean($it['unit_symbol']) ?></td>
                            <td class="text-end"><?= number_format($remaining, 2) ?> <?= clean($it['unit_symbol']) ?></td>
                            <td>
                                <?php if ($remaining > 0): ?>
                                    <input type="number" step="0.01" min="0.01" max="<?= min($remaining, 99999999.99) ?>"
                                        name="qty[<?= (int)$it['po_item_id'] ?>]" class="form-control form-control-sm">
                                <?php else: ?>
                                    <span class="badge bg-success">Fully Received</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($remaining > 0 && $it['is_perishable']): ?>
                                    <input type="date" name="expiry[<?= (int)$it['po_item_id'] ?>]" class="form-control form-control-sm">
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg me-1"></i>Confirm Receipt</button>
    </form>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
