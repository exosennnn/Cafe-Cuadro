<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Stock Movement';
$userId = $_SESSION['user_id'];

// Inventory Staff record movements; the Owner gets view / monitor only
// (the ledger below stays fully visible to both).
$canManage = userCanDo('stock.manage');
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$userId) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to record stock movements.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

// ---------- Handle Stock In ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_in') {
    requireCapability('stock.manage');
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $qtyInput = $_POST['quantity'] ?? '';
    $qty = is_string($qtyInput) && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $qtyInput) ? (float)$qtyInput : 0.0;
    $expiryInput = $_POST['expiry_date'] ?? '';
    $expiry = is_string($expiryInput) && $expiryInput !== '' ? $expiryInput : null;
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : "\0";
    // Amount actually paid for this stock. When filled in, it is posted to
    // Finance as an expense (same as goods received against a PO). Blank / 0
    // means it was not a purchase (donation, transfer...) - nothing is posted.
    $amountInput = $_POST['amount_paid'] ?? '';
    $amountPaid = $amountInput === '' ? 0.0 : (is_string($amountInput) && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amountInput) ? (float)$amountInput : -1.0);
    $expiryValid = $expiryInput === '' || (is_string($expiryInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryInput) && checkdate((int)substr($expiryInput, 5, 2), (int)substr($expiryInput, 8, 2), (int)substr($expiryInput, 0, 4)));
    $itemStmt = $pdo->prepare("SELECT item_name, is_perishable FROM items WHERE item_id=? AND status='Active'");
    if ($itemId !== false && $itemId > 0) $itemStmt->execute([$itemId]);
    $stockItem = $itemStmt->fetch();

    if ($itemId === false || $itemId < 1 || !$stockItem || !is_finite($qty) || $qty <= 0 || $qty > 99999999.99) {
        setFlash('danger', 'Please select an item and enter a valid quantity.');
    } elseif ($amountPaid < 0) {
        setFlash('danger', 'Amount paid cannot be negative.');
    } elseif (strlen($remarks) > 255) {
        setFlash('danger', 'Remarks must be 255 characters or fewer.');
    } elseif (!$expiryValid) {
        setFlash('danger', 'Enter a valid expiry date.');
    } elseif ((int)$stockItem['is_perishable'] === 1 && $expiry === null) {
        setFlash('danger', 'An expiry date is required for a perishable item.');
    } else {
        $pdo->beginTransaction();
        try {
            adjustInventoryItemStock($pdo, (int)($branchId ?? 1), (int)$itemId, $qty);
            $pdo->prepare("INSERT INTO stock_movements (item_id, branch_id, movement_type, reason, quantity, expiry_date, reference_type, remarks, user_id) VALUES (?, ?, 'IN', 'Manual Stock In', ?, ?, 'Manual', ?, ?)")
                ->execute([$itemId, (int)($branchId ?? 1), $qty, $expiry, $remarks, $userId]);
            $movementId = (int)$pdo->lastInsertId();

            // Post the purchase to Finance inside the SAME transaction: stock
            // can never go up with the money missing, or the reverse.
            if ($amountPaid > 0) {
                $itemName = (string)$stockItem['item_name'];
                $desc = "Stock in purchase: " . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . " x {$itemName}"
                      . ($remarks !== '' ? " ({$remarks})" : '');
                $pdo->prepare("INSERT INTO finance_transactions (transaction_type, fin_category_id, amount, transaction_date, description, reference_type, reference_id, created_by) VALUES ('Expense', ?, ?, CURDATE(), ?, 'StockIn', ?, ?)")
                    ->execute([purchasesFinanceCategoryId($pdo), $amountPaid, (function_exists('mb_substr') ? mb_substr($desc, 0, 255) : substr($desc, 0, 255)), $movementId, $userId]);
            }

            $pdo->commit();
            logActivity($pdo, $userId, 'Inventory', "Stock In: +$qty for item #$itemId" . ($amountPaid > 0 ? ' (' . peso($amountPaid) . ' posted to Finance)' : ''));
            setFlash('success', $amountPaid > 0
                ? 'Stock in recorded. ' . peso($amountPaid) . ' was posted to Finance as a purchase expense.'
                : 'Stock in recorded. No amount was entered, so nothing was posted to Finance.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('stock_in failed: ' . $e->getMessage());
            setFlash('danger', 'Failed to record stock in. Nothing was saved.');
        }
    }
    redirect('modules/inventory/stock_movement.php');
}

// ---------- Handle Stock Out ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stock_out') {
    requireCapability('stock.manage');
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $qtyInput = $_POST['quantity'] ?? '';
    $qty = is_string($qtyInput) && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $qtyInput) ? (float)$qtyInput : 0.0;
    $reasonInput = $_POST['reason'] ?? 'Usage';
    $reason = is_string($reasonInput) ? $reasonInput : '';
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : "\0";

    if ($itemId === false || $itemId < 1 || !is_finite($qty) || $qty <= 0 || $qty > 99999999.99 || !in_array($reason, ['Usage', 'Wastage', 'Spoilage', 'Expired'], true) || strlen($remarks) > 255) {
        setFlash('danger', 'Please select an item and enter a valid quantity.');
    } else {
        $pdo->beginTransaction();
        try {
            $stockSql = "SELECT i.item_name, " . ($branchId ? 'COALESCE(bii.current_stock,0)' : 'COALESCE(i.current_stock,0)') . " AS current_stock
                FROM items i " . ($branchId ? 'LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=' . (int)$branchId : '') . "
                WHERE i.item_id=? AND i.status='Active' FOR UPDATE";
            $itemStmt = $pdo->prepare($stockSql);
            $itemStmt->execute([$itemId]);
            $itemRow = $itemStmt->fetch();
            if (!$itemRow || $qty > (float)$itemRow['current_stock']) {
                throw new RuntimeException('Stock out quantity exceeds current branch stock on hand.');
            }
            adjustInventoryItemStock($pdo, (int)($branchId ?? 1), (int)$itemId, -$qty);
            $pdo->prepare("INSERT INTO stock_movements (item_id, branch_id, movement_type, reason, quantity, reference_type, remarks, user_id) VALUES (?, ?, 'OUT', ?, ?, 'Manual', ?, ?)")
                ->execute([$itemId, (int)($branchId ?? 1), $reason, $qty, $remarks, $userId]);
            $pdo->commit();
            logActivity($pdo, $userId, 'Inventory', "Stock Out ($reason): -$qty for {$itemRow['item_name']}");
            setFlash('success', 'Stock out recorded successfully.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            setFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Failed to record stock out.');
        }
    }
    redirect('modules/inventory/stock_movement.php');
}

// ---------- Handle Adjustment ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjustment') {
    requireCapability('stock.manage');
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $newQtyInput = $_POST['new_quantity'] ?? '';
    $newQty = is_string($newQtyInput) && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $newQtyInput) ? (float)$newQtyInput : -1.0;
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : "\0";

    if ($itemId === false || $itemId < 1 || $newQty < 0 || $newQty > 99999999.99 || strlen($remarks) > 255) {
        setFlash('danger', 'Please select an item and enter a valid corrected quantity.');
    } else {
        $pdo->beginTransaction();
        try {
            $stockValue = $branchId ? 'COALESCE(bii.current_stock,0)' : 'COALESCE(i.current_stock,0)';
            $branchJoin = $branchId ? 'LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=' . (int)$branchId : '';
            $itemStmt = $pdo->prepare("SELECT i.item_name, $stockValue AS current_stock FROM items i $branchJoin WHERE i.item_id=? AND i.status='Active' FOR UPDATE");
            $itemStmt->execute([$itemId]);
            $itemRow = $itemStmt->fetch();
            if (!$itemRow) throw new RuntimeException('Item not found or inactive.');
            $diff = $newQty - (float)$itemRow['current_stock'];
            if (abs($diff) < 0.000001) {
                $pdo->rollBack();
                setFlash('info', 'No change — corrected quantity is the same as current branch stock.');
            } else {
                adjustInventoryItemStock($pdo, (int)($branchId ?? 1), (int)$itemId, $diff);
                $direction = $diff > 0 ? 'increased' : 'decreased';
                $fullRemarks = "Adjusted from {$itemRow['current_stock']} to $newQty ($direction). " . $remarks;
                $pdo->prepare("INSERT INTO stock_movements (item_id, branch_id, movement_type, reason, quantity, reference_type, remarks, user_id) VALUES (?, ?, 'ADJUSTMENT', 'Correction', ?, 'Manual', ?, ?)")
                    ->execute([$itemId, (int)($branchId ?? 1), abs($diff), $fullRemarks, $userId]);
                $pdo->commit();
                logActivity($pdo, $userId, 'Inventory', "Adjustment for {$itemRow['item_name']}: {$itemRow['current_stock']} -> $newQty");
                setFlash('success', 'Stock adjustment recorded successfully.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            setFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Failed to record adjustment.');
        }
    }
    redirect('modules/inventory/stock_movement.php');
}

// ---------- Data for forms ----------
$stockExpression = $branchId ? 'COALESCE(bii.current_stock,0)' : 'COALESCE(i.current_stock,0)';
$branchStockJoin = $branchId ? 'LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?' : '';
$itemsStmt = $pdo->prepare("SELECT i.item_id, i.item_name, i.item_code, i.unit_id, $stockExpression AS current_stock, i.is_perishable, i.cost_price
    FROM items i $branchStockJoin WHERE i.status='Active' ORDER BY i.item_name ASC");
$itemsStmt->execute($branchId ? [$branchId] : []);
$items = $itemsStmt->fetchAll();

// ---------- Ledger filters ----------
$filterItem = $_GET['item_id'] ?? '';
$filterType = $_GET['type'] ?? '';
$filterFrom = $_GET['date_from'] ?? '';
$filterTo   = $_GET['date_to'] ?? '';

$ledgerSql = "SELECT sm.*, i.item_name, i.item_code, u.unit_symbol, b.branch_name, CONCAT(us.first_name, ' ', us.last_name) AS full_name
              FROM stock_movements sm
              JOIN items i ON sm.item_id = i.item_id
              JOIN units u ON i.unit_id = u.unit_id
              LEFT JOIN branches b ON b.branch_id=sm.branch_id
              JOIN users us ON sm.user_id = us.user_id
              WHERE 1=1";
$ledgerParams = [];
if ($branchId) {
    $ledgerSql .= ' AND sm.branch_id=?';
    $ledgerParams[] = $branchId;
}

if ($filterItem !== '') {
    $ledgerSql .= " AND sm.item_id = ?";
    $ledgerParams[] = $filterItem;
}
if ($filterType !== '') {
    $ledgerSql .= " AND sm.movement_type = ?";
    $ledgerParams[] = $filterType;
}
if ($filterFrom !== '') {
    $ledgerSql .= " AND DATE(sm.created_at) >= ?";
    $ledgerParams[] = $filterFrom;
}
if ($filterTo !== '') {
    $ledgerSql .= " AND DATE(sm.created_at) <= ?";
    $ledgerParams[] = $filterTo;
}

$ledgerSql .= " ORDER BY sm.created_at DESC LIMIT 200";
$ledgerStmt = $pdo->prepare($ledgerSql);
$ledgerStmt->execute($ledgerParams);
$movements = $ledgerStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<a href="<?= BASE_URL ?>inventory" class="text-decoration-none text-muted small d-inline-block mb-3">
    <i class="bi bi-arrow-left"></i> Back to Items
</a>

<?php if ($canManage): ?>
<!-- Action Tabs -->
<div class="card-panel p-3 mb-3">
    <ul class="nav nav-tabs" id="stockTabs" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stockIn">
                <i class="bi bi-box-arrow-in-down text-success me-1"></i> Stock In
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#stockOut">
                <i class="bi bi-box-arrow-up text-danger me-1"></i> Stock Out
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#adjustment">
                <i class="bi bi-sliders text-primary me-1"></i> Adjustment
            </button>
        </li>
    </ul>

    <div class="tab-content pt-3">
        <!-- STOCK IN -->
        <div class="tab-pane fade show active" id="stockIn">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="stock_in">
                <div class="col-md-4">
                    <label class="form-label">Item</label>
                    <select name="item_id" class="form-select item-select-in" required onchange="toggleExpiry(this); prefillAmount(this.form)">
                        <option value="">Select item</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= $it['item_id'] ?>" data-perishable="<?= $it['is_perishable'] ?>" data-cost="<?= (float)$it['cost_price'] ?>">
                                <?= clean($it['item_name']) ?> (<?= clean($it['item_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quantity</label>
                    <input type="number" step="0.01" min="0.01" max="99999999.99" name="quantity" class="form-control" required oninput="prefillAmount(this.form)">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Amount Paid (&#8369;)</label>
                    <input type="number" step="0.01" min="0" max="99999999.99" name="amount_paid" class="form-control" placeholder="0.00" oninput="this.dataset.touched='1'">
                    <div class="form-text">Posts to Finance as an expense. Leave blank if it wasn't bought.</div>
                </div>
                <div class="col-md-3 expiry-field" style="display:none;">
                    <label class="form-label">Expiry Date</label>
                    <input type="date" name="expiry_date" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control" maxlength="255" placeholder="e.g. Bought at the market, delivery top-up">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg me-1"></i>Record Stock In</button>
                </div>
            </form>
        </div>

        <!-- STOCK OUT -->
        <div class="tab-pane fade" id="stockOut">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="stock_out">
                <div class="col-md-4">
                    <label class="form-label">Item</label>
                    <select name="item_id" class="form-select" required>
                        <option value="">Select item</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= $it['item_id'] ?>">
                                <?= clean($it['item_name']) ?> (Branch stock: <?= number_format($it['current_stock'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Quantity</label>
                    <input type="number" step="0.01" min="0.01" max="99999999.99" name="quantity" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Reason</label>
                    <select name="reason" class="form-select">
                        <option value="Usage">Usage</option>
                        <option value="Wastage">Wastage</option>
                        <option value="Spoilage">Spoilage</option>
                        <option value="Expired">Expired</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control" maxlength="255" placeholder="Optional notes">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-check-lg me-1"></i>Record Stock Out</button>
                </div>
            </form>
        </div>

        <!-- ADJUSTMENT -->
        <div class="tab-pane fade" id="adjustment">
            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="adjustment">
                <div class="col-md-5">
                    <label class="form-label">Item</label>
                    <select name="item_id" class="form-select" required>
                        <option value="">Select item</option>
                        <?php foreach ($items as $it): ?>
                            <option value="<?= $it['item_id'] ?>">
                                <?= clean($it['item_name']) ?> (Branch: <?= number_format($it['current_stock'], 2) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Corrected Quantity</label>
                    <input type="number" step="0.01" min="0" max="99999999.99" name="new_quantity" class="form-control" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Remarks</label>
                    <input type="text" name="remarks" class="form-control" maxlength="255" placeholder="e.g. Physical count discrepancy">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Record Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<?= readOnlyNotice('stock movements') ?>
<?php endif; ?>

<!-- Ledger -->
<div class="card-panel p-3">
    <h6 class="mb-3"><i class="bi bi-journal-text me-1"></i> Stock Movement Ledger</h6>

    <form method="GET" class="row g-2 mb-3">
        <div class="col-md-3">
            <select name="item_id" class="form-select form-select-sm">
                <option value="">All Items</option>
                <?php foreach ($items as $it): ?>
                    <option value="<?= $it['item_id'] ?>" <?= $filterItem == $it['item_id'] ? 'selected' : '' ?>>
                        <?= clean($it['item_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="type" class="form-select form-select-sm">
                <option value="">All Types</option>
                <option value="IN" <?= $filterType === 'IN' ? 'selected' : '' ?>>Stock In</option>
                <option value="OUT" <?= $filterType === 'OUT' ? 'selected' : '' ?>>Stock Out</option>
                <option value="ADJUSTMENT" <?= $filterType === 'ADJUSTMENT' ? 'selected' : '' ?>>Adjustment</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($filterFrom) ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($filterTo) ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-brand btn-sm w-100">Filter</button>
        </div>
        <div class="col-md-1">
            <a href="<?= BASE_URL ?>inventory/stock-movement" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Branch</th>
                    <th>Type</th>
                    <th>Reason</th>
                    <th class="text-end">Quantity</th>
                    <th>Expiry</th>
                    <th>Remarks</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No stock movements found.</td></tr>
                <?php else: foreach ($movements as $m):
                    $typeBadge = ['IN' => 'bg-success', 'OUT' => 'bg-danger', 'ADJUSTMENT' => 'bg-primary'][$m['movement_type']];
                ?>
                    <tr>
                        <td class="text-nowrap"><?= formatDate($m['created_at'], 'M d, Y g:i A') ?></td>
                        <td><?= clean($m['item_name']) ?></td>
                        <td><?= clean($m['branch_name'] ?? '-') ?></td>
                        <td><span class="badge <?= $typeBadge ?>"><?= $m['movement_type'] ?></span></td>
                        <td><?= clean($m['reason']) ?></td>
                        <td class="text-end"><?= number_format($m['quantity'], 2) ?> <?= clean($m['unit_symbol']) ?></td>
                        <td><?= $m['expiry_date'] ? formatDate($m['expiry_date']) : '-' ?></td>
                        <td class="text-muted small"><?= clean($m['remarks']) ?: '-' ?></td>
                        <td class="text-muted small"><?= clean($m['full_name']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small mt-2 mb-0">Showing latest 200 records. Use filters to narrow down further.</p>
</div>

<script>
function toggleExpiry(select) {
    const opt = select.options[select.selectedIndex];
    const isPerishable = opt.getAttribute('data-perishable') === '1';
    const expiryField = select.closest('form').querySelector('.expiry-field');
    expiryField.style.display = isPerishable ? 'block' : 'none';
    expiryField.querySelector('input[name="expiry_date"]').required = isPerishable;
}

// Suggest Amount Paid = quantity x the item's cost price. It is only a
// suggestion: once the user types their own amount it is left alone.
function prefillAmount(form) {
    const amount = form.querySelector('[name="amount_paid"]');
    if (!amount || amount.dataset.touched === '1') return;
    const sel = form.querySelector('[name="item_id"]');
    const qty = parseFloat(form.querySelector('[name="quantity"]').value);
    const cost = sel && sel.selectedIndex > 0 ? parseFloat(sel.options[sel.selectedIndex].getAttribute('data-cost')) : NaN;
    amount.value = (qty > 0 && cost > 0) ? (qty * cost).toFixed(2) : '';
}

// Pre-select item and open Stock In tab if navigated from Items page with ?item_id=
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);
    const itemId = params.get('item_id');
    if (itemId) {
        document.querySelectorAll('select[name="item_id"]').forEach(function (sel) {
            sel.value = itemId;
        });
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
