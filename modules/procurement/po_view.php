<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$userId = $_SESSION['user_id'];
$poId = (int)($_GET['id'] ?? 0);

// Inventory Staff drive the PO through its statuses; the Owner monitors.
$canManage = userCanDo('po.manage');
$canApprove = userCanDo('po.approve');
$branchId = $canManage ? inventoryAssignedBranchId($pdo, (int)$userId) : null;
if ($canManage && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to manage purchase orders.');
}

$stmt = $pdo->prepare("SELECT po.*, s.supplier_name, s.contact_person, s.phone, s.email, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
                        , b.branch_name
                        FROM purchase_orders po
                        JOIN suppliers s ON po.supplier_id = s.supplier_id
                        JOIN users u ON po.created_by = u.user_id
                        JOIN branches b ON b.branch_id=po.branch_id
                        WHERE po.po_id = ?" . ($branchId ? ' AND po.branch_id=?' : ''));
$stmt->execute($branchId ? [$poId, $branchId] : [$poId]);
$po = $stmt->fetch();

if (!$po) {
    setFlash('danger', 'Purchase order not found.');
    redirect('modules/procurement/index.php');
}

// ---------- Handle status transitions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'po_status') {
    csrfVerify();
    $action = $_POST['transition'] ?? '';
    if ($action === 'approve') requireCapability('po.approve');
    else requireCapability('po.manage');

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT status FROM purchase_orders WHERE po_id=?" . ($branchId ? ' AND branch_id=?' : '') . " FOR UPDATE");
        $lock->execute($branchId ? [$poId, $branchId] : [$poId]);
        $currentStatus = $lock->fetchColumn();
        $transitions = [
            'approve' => ['Pending', 'Approved'],
            'order' => ['Approved', 'Ordered'],
            'cancel' => [['Pending', 'Approved', 'Ordered'], 'Cancelled'],
        ];
        if (!isset($transitions[$action])) throw new RuntimeException('Invalid purchase order action.');
        [$allowedStatuses, $newStatus] = $transitions[$action];
        if (!is_array($allowedStatuses)) $allowedStatuses = [$allowedStatuses];
        if (!in_array($currentStatus, $allowedStatuses, true)) throw new RuntimeException('That action is not allowed for the current purchase order status.');
        if ($action === 'cancel') {
            $receivedCheck = $pdo->prepare('SELECT COALESCE(SUM(quantity_received),0) FROM purchase_order_items WHERE po_id=?');
            $receivedCheck->execute([$poId]);
            if ((float)$receivedCheck->fetchColumn() > 0) throw new RuntimeException('A PO with received quantities cannot be cancelled.');
        }
        $pdo->prepare('UPDATE purchase_orders SET status=? WHERE po_id=?')->execute([$newStatus, $poId]);
        $pdo->commit();
        logActivity($pdo, $userId, 'Procurement', ucfirst($action) . ' PO: ' . $po['po_number']);
        setFlash('success', 'Purchase order ' . strtolower($newStatus) . '.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        setFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Purchase order status could not be updated.');
    }
    redirect('modules/procurement/po_view.php?id=' . $poId);
}

$pageTitle = 'PO ' . $po['po_number'];

$itemStmt = $pdo->prepare("SELECT poi.*, i.item_name, i.item_code, un.unit_symbol
                            FROM purchase_order_items poi
                            JOIN items i ON poi.item_id = i.item_id
                            JOIN units un ON i.unit_id = un.unit_id
                            WHERE poi.po_id = ?
                            ORDER BY poi.po_item_id ASC");
$itemStmt->execute([$poId]);
$poItems = $itemStmt->fetchAll();

$delStmt = $pdo->prepare("SELECT d.*, pp.status AS payable_status, pp.amount AS payable_amount, CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
                           FROM deliveries d
                           JOIN users u ON d.received_by = u.user_id
                           LEFT JOIN purchase_payables pp ON pp.delivery_id=d.delivery_id
                           WHERE d.po_id = ?
                           ORDER BY d.created_at DESC");
$delStmt->execute([$poId]);
$deliveries = $delStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';

$statusBadge = [
    'Pending'            => 'bg-secondary',
    'Approved'           => 'bg-info text-dark',
    'Ordered'            => 'bg-primary',
    'Partially Received'  => 'bg-warning text-dark',
    'Received'           => 'bg-success',
    'Cancelled'          => 'bg-danger',
];
?>

<a href="<?= BASE_URL ?>procurement" class="text-decoration-none text-muted small d-inline-block mb-3">
    <i class="bi bi-arrow-left"></i> Back to Purchase Orders
</a>

<div class="card-panel p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h5 class="mb-1"><?= clean($po['po_number']) ?>
                <span class="badge <?= $statusBadge[$po['status']] ?? 'bg-secondary' ?> ms-2"><?= clean($po['status']) ?></span>
            </h5>
            <div class="text-muted small">Created by <?= clean($po['created_by_name']) ?> on <?= formatDate($po['created_at'], 'M d, Y g:i A') ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($canManage && $po['status'] === 'Pending'): ?>
                <a href="<?= BASE_URL ?>procurement/po-form?id=<?= (int)$po['po_id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
            <?php endif; ?>
            <?php if ($canApprove && $po['status'] === 'Pending'): ?>
                <form method="POST" onsubmit="return confirm('Approve this purchase order?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="po_status"><input type="hidden" name="transition" value="approve"><button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Approve PO</button></form>
            <?php endif; ?>
            <?php if ($canManage && $po['status'] === 'Approved'): ?>
                <form method="POST" onsubmit="return confirm('Mark this purchase order as Ordered?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="po_status"><input type="hidden" name="transition" value="order"><button class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Mark as Ordered</button></form>
            <?php endif; ?>
            <?php if ($canManage && in_array($po['status'], ['Approved', 'Ordered', 'Partially Received'], true)): ?>
                <a href="<?= BASE_URL ?>procurement/receive?po_id=<?= $poId ?>" class="btn btn-brand btn-sm"><i class="bi bi-box-arrow-in-down me-1"></i>Receive Delivery</a>
            <?php endif; ?>
            <?php if ($canManage && in_array($po['status'], ['Pending', 'Approved', 'Ordered'], true)): ?>
                <form method="POST" onsubmit="return confirm('Cancel this purchase order?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="po_status"><input type="hidden" name="transition" value="cancel"><button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-circle me-1"></i>Cancel PO</button></form>
            <?php endif; ?>
            <?php if (!$canManage && !$canApprove): ?><span class="badge bg-light text-muted border"><i class="bi bi-eye me-1"></i>View / monitor only</span><?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3">
            <div class="text-muted small">Supplier</div>
            <div class="fw-medium"><?= clean($po['supplier_name']) ?></div>
            <div class="text-muted small"><?= clean($po['contact_person']) ?: '-' ?> <?= $po['phone'] ? '· ' . clean($po['phone']) : '' ?></div>
        </div>
        <div class="col-md-2">
            <div class="text-muted small">Branch</div>
            <div class="fw-medium"><?= clean($po['branch_name']) ?></div>
        </div>
        <div class="col-md-2">
            <div class="text-muted small">Order Date</div>
            <div class="fw-medium"><?= formatDate($po['order_date']) ?></div>
        </div>
        <div class="col-md-3">
            <div class="text-muted small">Expected Delivery</div>
            <div class="fw-medium"><?= $po['expected_date'] ? formatDate($po['expected_date']) : '-' ?></div>
        </div>
        <div class="col-md-2">
            <div class="text-muted small">Total Amount</div>
            <div class="fw-bold"><?= peso($po['total_amount']) ?></div>
        </div>
    </div>
    <?php if (!empty($po['remarks'])): ?>
        <div class="mt-2 text-muted small"><i class="bi bi-chat-left-text me-1"></i><?= clean($po['remarks']) ?></div>
    <?php endif; ?>
</div>

<div class="card-panel p-3 mb-3">
    <h6 class="mb-3"><i class="bi bi-list-ul me-1"></i> Line Items</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th class="text-end">Ordered</th>
                    <th class="text-end">Received</th>
                    <th class="text-end">Remaining</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Subtotal</th>
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
                        <td class="text-end <?= $remaining > 0 ? 'text-warning' : 'text-success' ?>">
                            <?= number_format($remaining, 2) ?> <?= clean($it['unit_symbol']) ?>
                        </td>
                        <td class="text-end"><?= peso($it['unit_price']) ?></td>
                        <td class="text-end"><?= peso($it['subtotal']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td colspan="5" class="text-end fw-medium">Total</td>
                    <td class="text-end fw-bold"><?= peso($po['total_amount']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card-panel p-3">
    <h6 class="mb-3"><i class="bi bi-truck me-1"></i> Deliveries Received</h6>
    <?php if (empty($deliveries)): ?>
        <p class="text-muted mb-0">No deliveries recorded yet for this purchase order.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>GRN Number</th>
                        <th>Delivery Date</th>
                        <th>Received By</th>
                        <th>Payable</th>
                        <th>Remarks</th>
                        <th class="text-end">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deliveries as $d): ?>
                        <tr>
                            <td class="fw-medium"><?= clean($d['delivery_number']) ?></td>
                            <td><?= formatDate($d['delivery_date']) ?></td>
                            <td><?= clean($d['received_by_name']) ?></td>
                            <td>
                                <?php if ($d['payable_status']): ?>
                                    <span class="badge <?= $d['payable_status'] === 'PAID' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= clean($d['payable_status']) ?></span>
                                    <div class="small"><?= peso($d['payable_amount']) ?></div>
                                <?php else: ?><span class="text-muted small">No payable</span><?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= clean($d['remarks']) ?: '-' ?></td>
                            <td class="text-end">
                                <a href="<?= BASE_URL ?>procurement/delivery-view?id=<?= $d['delivery_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
