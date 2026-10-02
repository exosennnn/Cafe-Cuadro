<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$deliveryId = (int)($_GET['id'] ?? 0);
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to view delivery details.');
}

$stmt = $pdo->prepare("SELECT d.*, po.po_number, po.po_id, s.supplier_name, CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
                        , b.branch_name
                        FROM deliveries d
                        JOIN purchase_orders po ON d.po_id = po.po_id
                        JOIN suppliers s ON po.supplier_id = s.supplier_id
                        JOIN branches b ON b.branch_id=po.branch_id
                        JOIN users u ON d.received_by = u.user_id
                        WHERE d.delivery_id = ?" . ($branchId ? ' AND po.branch_id=?' : ''));
$stmt->execute($branchId ? [$deliveryId, $branchId] : [$deliveryId]);
$delivery = $stmt->fetch();

if (!$delivery) {
    setFlash('danger', 'Delivery not found.');
    redirect('modules/procurement/deliveries.php');
}

$pageTitle = 'Delivery ' . $delivery['delivery_number'];

$itemStmt = $pdo->prepare("SELECT di.*, i.item_name, i.item_code, un.unit_symbol, poi.unit_price
                            FROM delivery_items di
                            JOIN items i ON di.item_id = i.item_id
                            JOIN units un ON i.unit_id = un.unit_id
                            JOIN purchase_order_items poi ON di.po_item_id = poi.po_item_id
                            WHERE di.delivery_id = ?");
$itemStmt->execute([$deliveryId]);
$deliveryItems = $itemStmt->fetchAll();

$payableStmt = $pdo->prepare("SELECT * FROM purchase_payables WHERE delivery_id=?");
$payableStmt->execute([$deliveryId]);
$payable = $payableStmt->fetch();

include __DIR__ . '/../../includes/header.php';
?>

<a href="<?= BASE_URL ?>procurement/po-view?id=<?= $delivery['po_id'] ?>" class="text-decoration-none text-muted small d-inline-block mb-3">
    <i class="bi bi-arrow-left"></i> Back to <?= clean($delivery['po_number']) ?>
</a>

<div class="card-panel p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <h5 class="mb-1"><?= clean($delivery['delivery_number']) ?></h5>
            <div class="text-muted small">
                For <a href="<?= BASE_URL ?>procurement/po-view?id=<?= $delivery['po_id'] ?>"><?= clean($delivery['po_number']) ?></a>
                · <?= clean($delivery['supplier_name']) ?>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-3">
            <div class="text-muted small">Delivery Date</div>
            <div class="fw-medium"><?= formatDate($delivery['delivery_date']) ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-muted small">Received By</div>
            <div class="fw-medium"><?= clean($delivery['received_by_name']) ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-muted small">Recorded On</div>
            <div class="fw-medium"><?= formatDate($delivery['created_at'], 'M d, Y g:i A') ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-muted small">Branch</div>
            <div class="fw-medium"><?= clean($delivery['branch_name']) ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-muted small">Supplier Payable</div>
            <div class="fw-bold"><?= $payable ? peso($payable['amount']) . ' · ' . clean($payable['status']) : 'Not created' ?></div>
        </div>
    </div>
    <?php if (!empty($delivery['remarks'])): ?>
        <div class="mt-2 text-muted small"><i class="bi bi-chat-left-text me-1"></i><?= clean($delivery['remarks']) ?></div>
    <?php endif; ?>
</div>

<div class="card-panel p-3">
    <h6 class="mb-3"><i class="bi bi-box-seam me-1"></i> Items Received</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th class="text-end">Quantity Delivered</th>
                    <th>Expiry Date</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Line Cost</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deliveryItems as $di): ?>
                    <tr>
                        <td><?= clean($di['item_name']) ?> <span class="text-muted small">(<?= clean($di['item_code']) ?>)</span></td>
                        <td class="text-end"><?= number_format($di['quantity_delivered'], 2) ?> <?= clean($di['unit_symbol']) ?></td>
                        <td><?= $di['expiry_date'] ? formatDate($di['expiry_date']) : '-' ?></td>
                        <td class="text-end"><?= peso($di['unit_price']) ?></td>
                        <td class="text-end"><?= peso($di['quantity_delivered'] * $di['unit_price']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
