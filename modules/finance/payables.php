<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Supplier Payables';
$userId = (int)$_SESSION['user_id'];
$branchId = currentRoleId() === ROLE_FINANCE_STAFF ? inventoryAssignedBranchId($pdo, $userId) : null;
if (currentRoleId() === ROLE_FINANCE_STAFF && !$branchId) {
    http_response_code(403);
    die('Finance Staff must be assigned to an active branch before reviewing payables.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_paid') {
    requireCapability('finance.manage');
    csrfVerify();
    $payableId = filter_var($_POST['payable_id'] ?? null, FILTER_VALIDATE_INT);
    if ($payableId === false || $payableId < 1) {
        setFlash('danger', 'Select a valid supplier payable.');
        redirect('modules/finance/payables.php');
    }

    try {
        $paid = markSupplierPayablePaid($pdo, $payableId, $userId, $branchId);
        logActivity($pdo, $userId, 'Finance', "Paid payable {$paid['delivery_number']} for PO {$paid['po_number']} (" . peso($paid['amount']) . ')');
        setFlash('success', "Payable for {$paid['delivery_number']} marked PAID. Received inventory and mapped POS stock have been released.");
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('supplier payable payment failed: ' . $exception->getMessage());
        setFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Payment could not be recorded. No stock or Finance changes were saved.');
    }
    redirect('modules/finance/payables.php');
}

$sql = "SELECT pp.*, d.delivery_number, d.delivery_date, po.po_number, s.supplier_name, b.branch_name,
        CONCAT(ru.first_name,' ',ru.last_name) AS received_by_name,
        CONCAT(pu.first_name,' ',pu.last_name) AS paid_by_name
    FROM purchase_payables pp
    JOIN deliveries d ON d.delivery_id=pp.delivery_id
    JOIN purchase_orders po ON po.po_id=pp.po_id
    JOIN suppliers s ON s.supplier_id=pp.supplier_id
    JOIN branches b ON b.branch_id=pp.branch_id
    JOIN users ru ON ru.user_id=pp.received_by
    LEFT JOIN users pu ON pu.user_id=pp.paid_by
    WHERE 1=1";
$params = [];
if ($branchId) { $sql .= ' AND pp.branch_id=?'; $params[] = $branchId; }
$sql .= " ORDER BY FIELD(pp.status,'UNPAID','PAID'), pp.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$payables = $stmt->fetchAll();
$detailsByPayable = [];
if ($payables) {
    $payableIds = array_column($payables, 'payable_id');
    $placeholders = implode(',', array_fill(0, count($payableIds), '?'));
    $detailStmt = $pdo->prepare("SELECT pp.payable_id, i.item_name, un.unit_symbol, di.quantity_delivered, poi.unit_price, m.product_id, p.name AS product_name
        FROM purchase_payables pp
        JOIN delivery_items di ON di.delivery_id=pp.delivery_id
        JOIN items i ON i.item_id=di.item_id
        JOIN units un ON un.unit_id=i.unit_id
        JOIN purchase_order_items poi ON poi.po_item_id=di.po_item_id
        LEFT JOIN item_pos_mappings m ON m.item_id=di.item_id
        LEFT JOIN products p ON p.id=m.product_id
        WHERE pp.payable_id IN ($placeholders)
        ORDER BY pp.payable_id, di.delivery_item_id");
    $detailStmt->execute($payableIds);
    foreach ($detailStmt->fetchAll() as $detail) $detailsByPayable[$detail['payable_id']][] = $detail;
}

include __DIR__ . '/../../includes/header.php';
$financeTab = 'payables';
include __DIR__ . '/../../includes/finance_nav.php';
?>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Received</th><th>PO / GRN</th><th>Supplier</th><th>Branch</th><th>Delivered Items</th><th class="text-end">Payable</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$payables): ?><tr><td colspan="8" class="text-center text-muted py-4">No supplier payables are waiting.</td></tr><?php endif; ?>
            <?php foreach ($payables as $payable): ?>
                <tr>
                    <td><?= formatDate($payable['delivery_date']) ?></td>
                    <td><a href="<?= BASE_URL ?>procurement/po-view?id=<?= (int)$payable['po_id'] ?>"><?= clean($payable['po_number']) ?></a><div class="small text-muted"><?= clean($payable['delivery_number']) ?></div></td>
                    <td><?= clean($payable['supplier_name']) ?></td>
                    <td><?= clean($payable['branch_name']) ?></td>
                    <td class="small">
                        <?php foreach ($detailsByPayable[$payable['payable_id']] ?? [] as $detail): ?>
                            <div><?= clean($detail['item_name']) ?>: <?= number_format($detail['quantity_delivered'], 2) ?> <?= clean($detail['unit_symbol']) ?> × <?= peso($detail['unit_price']) ?>
                                <span class="text-muted">(<?= $detail['product_id'] !== null ? 'POS: ' . clean($detail['product_name']) : 'Recipe inventory' ?>)</span></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-end fw-semibold"><?= peso($payable['amount']) ?></td>
                    <td><span class="badge <?= $payable['status'] === 'PAID' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= clean($payable['status']) ?></span>
                        <?php if ($payable['status'] === 'PAID'): ?><div class="small text-muted"><?= clean($payable['paid_by_name'] ?? '') ?></div><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($payable['status'] === 'UNPAID'): ?>
                        <form method="POST" onsubmit="return confirm('Mark this supplier payable as PAID and release its received stock?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="payable_id" value="<?= (int)$payable['payable_id'] ?>">
                            <button class="btn btn-sm btn-success"><i class="bi bi-check-circle me-1"></i>Mark Paid</button>
                        </form>
                        <?php else: ?><a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>finance?type=Expense">View Expense</a><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>