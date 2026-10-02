<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Creating / editing a purchase order is Inventory Staff work - the whole
// page is gated, not just its save handler.
requireCapability('po.manage');

$userId = $_SESSION['user_id'];
$branchId = inventoryAssignedBranchId($pdo, (int)$userId);
if (!$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch before managing purchase orders.');
}
$poId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$isEdit = false;
$po = null;
$existingItems = [];

if ($poId) {
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE po_id = ? AND branch_id=?");
    $stmt->execute([$poId, $branchId]);
    $po = $stmt->fetch();

    if (!$po) {
        setFlash('danger', 'Purchase order not found.');
        redirect('modules/procurement/index.php');
    }
    if ($po['status'] !== 'Pending') {
        setFlash('danger', 'Only Pending purchase orders can be edited.');
        redirect('modules/procurement/po_view.php?id=' . $poId);
    }
    $isEdit = true;

    $itemStmt = $pdo->prepare("SELECT poi.*, i.item_name FROM purchase_order_items poi JOIN items i ON poi.item_id = i.item_id WHERE poi.po_id = ?");
    $itemStmt->execute([$poId]);
    $existingItems = $itemStmt->fetchAll();
}

$pageTitle = $isEdit ? 'Edit Purchase Order' : 'Create Purchase Order';
$branchNameStmt = $pdo->prepare('SELECT branch_name FROM branches WHERE branch_id=? AND status=\'ACTIVE\'');
$branchNameStmt->execute([$branchId]);
$branchName = $branchNameStmt->fetchColumn();

// ---------- Handle Save ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    csrfVerify();
    if ($poId && (int)($_POST['po_id'] ?? 0) !== $poId) {
        setFlash('danger', 'Invalid purchase order submission.');
        redirect('modules/procurement/index.php');
    }
    $supplierId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
    $orderDate = $_POST['order_date'] ?? '';
    $expectedDate = $_POST['expected_date'] ?? '';
    $expectedDate = $expectedDate === '' ? null : $expectedDate;
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : "\0";
    $itemIds = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $errors = [];
    $dateIsValid = static function ($value): bool {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    };

    if ($supplierId === false || $supplierId < 1) $errors[] = 'Select a valid supplier.';
    if (!$dateIsValid($orderDate)) $errors[] = 'Enter a valid order date.';
    if ($expectedDate !== null && (!$dateIsValid($expectedDate) || $expectedDate < $orderDate)) $errors[] = 'Expected delivery must be a valid date on or after the order date.';
    if (strlen($remarks) > 255) $errors[] = 'Remarks must be 255 characters or fewer.';
    if (!is_array($itemIds) || !is_array($quantities) || !is_array($prices) || count($itemIds) !== count($quantities) || count($itemIds) !== count($prices) || count($itemIds) > 100) {
        $errors[] = 'Purchase order line items are invalid.';
        $itemIds = $quantities = $prices = [];
    }

    $lines = [];
    $seenItems = [];
    for ($i = 0; $i < count($itemIds); $i++) {
        $itIdInput = $itemIds[$i] ?? '';
        $qtyInput = $quantities[$i] ?? '';
        $priceInput = $prices[$i] ?? '';
        if ($itIdInput === '' && $qtyInput === '' && $priceInput === '') continue;
        $itId = filter_var($itIdInput, FILTER_VALIDATE_INT);
        if ($itId === false || $itId < 1 || !is_string($qtyInput) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $qtyInput) || !is_string($priceInput) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $priceInput)) {
            $errors[] = 'Each line needs a valid item, quantity, and unit cost.';
            continue;
        }
        $qty = (float)$qtyInput;
        $price = (float)$priceInput;
        if ($qty <= 0 || $price <= 0 || !is_finite($qty) || !is_finite($price)) {
            $errors[] = 'Quantity and unit cost must be greater than zero.';
            continue;
        }
        if (isset($seenItems[$itId])) {
            $errors[] = 'Each item can appear only once on a purchase order.';
            continue;
        }
        $seenItems[$itId] = true;
        $itemCheck = $pdo->prepare("SELECT item_id FROM items WHERE item_id=? AND status='Active'");
        $itemCheck->execute([$itId]);
        if (!$itemCheck->fetch()) {
            $errors[] = 'A selected item is inactive or no longer exists.';
            continue;
        }
        $subtotal = round($qty * $price, 2);
        if (!is_finite($subtotal) || $subtotal <= 0 || $subtotal > 99999999.99) {
            $errors[] = 'A line total exceeds the supported purchase order amount.';
            continue;
        }
        $lines[] = ['item_id' => $itId, 'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $subtotal];
    }
    if (empty($lines)) $errors[] = 'Add at least one line item.';
    $supplierCheck = $pdo->prepare("SELECT supplier_id FROM suppliers WHERE supplier_id=? AND status='Active'");
    if ($supplierId !== false && $supplierId > 0) {
        $supplierCheck->execute([$supplierId]);
        if (!$supplierCheck->fetch()) $errors[] = 'Select an active supplier.';
    }
    if ($errors) {
        setFlash('danger', implode(' ', array_unique($errors)));
        redirect('modules/procurement/po_form.php' . ($poId ? '?id=' . $poId : ''));
    }

    $totalAmount = array_sum(array_column($lines, 'subtotal'));
    if (!is_finite($totalAmount) || $totalAmount <= 0 || $totalAmount > 99999999.99) {
        setFlash('danger', 'Purchase order total is outside the supported amount range.');
        redirect('modules/procurement/po_form.php' . ($poId ? '?id=' . $poId : ''));
    }

    $pdo->beginTransaction();
    try {
        if ($isEdit) {
            $lock = $pdo->prepare("SELECT po_number, status, branch_id FROM purchase_orders WHERE po_id=? FOR UPDATE");
            $lock->execute([$poId]);
            $lockedPo = $lock->fetch();
            if (!$lockedPo || (int)$lockedPo['branch_id'] !== $branchId || $lockedPo['status'] !== 'Pending') {
                throw new RuntimeException('Only pending purchase orders for your assigned branch can be edited.');
            }
            $pdo->prepare("UPDATE purchase_orders SET supplier_id=?, order_date=?, expected_date=?, remarks=?, total_amount=? WHERE po_id=? AND branch_id=? AND status='Pending'")
                ->execute([$supplierId, $orderDate, $expectedDate, $remarks, $totalAmount, $poId, $branchId]);
            // Replace line items (safe: only allowed while status is Pending / nothing received yet)
            $pdo->prepare("DELETE FROM purchase_order_items WHERE po_id = ?")->execute([$poId]);
            $insStmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($lines as $l) {
                $insStmt->execute([$poId, $l['item_id'], $l['quantity'], $l['unit_price'], $l['subtotal']]);
            }
            logActivity($pdo, $userId, 'Procurement', 'Updated purchase order: ' . $lockedPo['po_number']);
            $pdo->commit();
            setFlash('success', 'Purchase order updated successfully.');
            redirect('modules/procurement/po_view.php?id=' . $poId);
        } else {
            $poNumber = generateDocNumber($pdo, 'PO');
            $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, branch_id, order_date, expected_date, status, total_amount, remarks, created_by) VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?, ?)")
                ->execute([$poNumber, $supplierId, $branchId, $orderDate, $expectedDate, $totalAmount, $remarks, $userId]);
            $newPoId = $pdo->lastInsertId();

            $insStmt = $pdo->prepare("INSERT INTO purchase_order_items (po_id, item_id, quantity_ordered, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
            foreach ($lines as $l) {
                $insStmt->execute([$newPoId, $l['item_id'], $l['quantity'], $l['unit_price'], $l['subtotal']]);
            }
            logActivity($pdo, $userId, 'Procurement', 'Created purchase order: ' . $poNumber);
            $pdo->commit();
            setFlash('success', "Purchase order $poNumber created successfully.");
            redirect('modules/procurement/po_view.php?id=' . $newPoId);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('purchase order save failed: ' . $e->getMessage());
        setFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Failed to save purchase order.');
        redirect('modules/procurement/po_form.php' . ($poId ? '?id=' . $poId : ''));
    }
}

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE status='Active' ORDER BY supplier_name ASC")->fetchAll();
$items = $pdo->query("SELECT item_id, item_name, item_code, unit_id, cost_price, (SELECT unit_symbol FROM units WHERE units.unit_id = items.unit_id) AS unit_symbol FROM items WHERE status='Active' ORDER BY item_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<a href="<?= BASE_URL ?>procurement" class="text-decoration-none text-muted small d-inline-block mb-3">
    <i class="bi bi-arrow-left"></i> Back to Purchase Orders
</a>

<div class="card-panel p-3">
    <form method="POST" id="poForm">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="po_id" value="<?= (int)($poId ?? 0) ?>">

        <div class="mb-3"><label class="form-label">Branch</label><input type="text" class="form-control" value="<?= clean($branchName ?: 'Unassigned') ?>" readonly></div>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Supplier</label>
                <select name="supplier_id" class="form-select" required>
                    <option value="">Select supplier</option>
                    <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['supplier_id'] ?>" <?= ($po && $po['supplier_id'] == $s['supplier_id']) ? 'selected' : '' ?>>
                            <?= clean($s['supplier_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Order Date</label>
                <input type="date" name="order_date" class="form-control" required
                    value="<?= $po ? clean($po['order_date']) : date('Y-m-d') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Expected Delivery Date</label>
                <input type="date" name="expected_date" class="form-control"
                    value="<?= $po && $po['expected_date'] ? clean($po['expected_date']) : '' ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Remarks</label>
            <input type="text" name="remarks" class="form-control" placeholder="Optional notes"
                value="<?= $po ? clean($po['remarks']) : '' ?>">
        </div>

        <hr>
        <h6 class="mb-3">Line Items</h6>

        <div class="table-responsive">
            <table class="table table-sm align-middle" id="itemsTable">
                <thead class="table-light">
                    <tr>
                        <th style="min-width:220px;">Item</th>
                        <th style="width:130px;">Quantity</th>
                        <th style="width:150px;">Unit Price</th>
                        <th style="width:150px;" class="text-end">Subtotal</th>
                        <th style="width:50px;"></th>
                    </tr>
                </thead>
                <tbody id="itemsBody"></tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end fw-medium">Total Amount</td>
                        <td class="text-end fw-bold" id="grandTotal">₱0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="addRow()">
            <i class="bi bi-plus-lg me-1"></i> Add Item
        </button>

        <div>
            <button type="submit" class="btn btn-brand"><i class="bi bi-check-lg me-1"></i>Save Purchase Order</button>
        </div>
    </form>
</div>

<script>
const ITEMS = <?= json_encode($items) ?>;
const EXISTING = <?= json_encode($existingItems) ?>;
let rowCount = 0;

function itemOptions(selectedId) {
    let html = '<option value="">Select item</option>';
    ITEMS.forEach(it => {
        const sel = (selectedId && selectedId == it.item_id) ? 'selected' : '';
        html += `<option value="${it.item_id}" data-cost="${it.cost_price}" data-unit="${it.unit_symbol}" ${sel}>${it.item_name} (${it.item_code})</option>`;
    });
    return html;
}

function addRow(existing) {
    const idx = rowCount++;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <select name="item_id[]" class="form-select form-select-sm item-select" required onchange="onItemChange(this)">
                ${itemOptions(existing ? existing.item_id : null)}
            </select>
        </td>
        <td><input type="number" step="0.01" min="0.01" max="99999999.99" name="quantity[]" class="form-control form-control-sm qty-input" value="${existing ? existing.quantity_ordered : ''}" required oninput="recalcRow(this)"></td>
        <td><input type="number" step="0.01" min="0.01" max="99999999.99" name="unit_price[]" class="form-control form-control-sm price-input" value="${existing ? existing.unit_price : ''}" required oninput="recalcRow(this)"></td>
        <td class="text-end subtotal-cell">₱0.00</td>
        <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="bi bi-x-lg"></i></button>
        </td>
    `;
    document.getElementById('itemsBody').appendChild(tr);
    if (existing) recalcRow(tr.querySelector('.qty-input'));
}

function onItemChange(select) {
    const row = select.closest('tr');
    const opt = select.options[select.selectedIndex];
    const priceInput = row.querySelector('.price-input');
    if (opt && opt.dataset.cost && (!priceInput.value || priceInput.value == '0')) {
        priceInput.value = opt.dataset.cost;
    }
    recalcRow(select);
}

function recalcRow(el) {
    const row = el.closest('tr');
    const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const subtotal = qty * price;
    row.querySelector('.subtotal-cell').innerText = '₱' + subtotal.toFixed(2);
    recalcGrandTotal();
}

function removeRow(btn) {
    btn.closest('tr').remove();
    recalcGrandTotal();
}

function recalcGrandTotal() {
    let total = 0;
    document.querySelectorAll('.qty-input').forEach(input => {
        const row = input.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const price = parseFloat(row.querySelector('.price-input').value) || 0;
        total += qty * price;
    });
    document.getElementById('grandTotal').innerText = '₱' + total.toFixed(2);
}

// Initialize rows
if (EXISTING.length > 0) {
    EXISTING.forEach(e => addRow(e));
} else {
    addRow();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
