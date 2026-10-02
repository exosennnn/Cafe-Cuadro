<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Purchase Report';
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to view purchase reports.');
}

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$supplierFilter = $_GET['supplier'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT po.*, s.supplier_name, b.branch_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id=s.supplier_id
    JOIN branches b ON b.branch_id=po.branch_id
        WHERE po.order_date BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if ($branchId) {
    $sql .= ' AND po.branch_id=?';
    $params[] = $branchId;
}
if ($supplierFilter !== '') {
    $sql .= ' AND po.supplier_id=?';
    $params[] = $supplierFilter;
}
if ($statusFilter !== '') {
    $sql .= ' AND po.status=?';
    $params[] = $statusFilter;
}
$sql .= ' ORDER BY po.order_date DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$totalOrderedValue = array_sum(array_column($orders, 'total_amount'));
$cancelledValue = array_sum(array_map(fn($o) => $o['status'] === 'Cancelled' ? $o['total_amount'] : 0, $orders));
$poIds = array_column($orders, 'po_id');
$actualReceived = 0.0;
if ($poIds) {
    $in = implode(',', array_fill(0, count($poIds), '?'));
    $recvStmt = $pdo->prepare("SELECT COALESCE(SUM(pp.amount),0) FROM purchase_payables pp
        JOIN deliveries d ON d.delivery_id=pp.delivery_id
        WHERE pp.status='PAID' AND d.po_id IN ($in)");
    $recvStmt->execute($poIds);
    $actualReceived = (float)$recvStmt->fetchColumn();
}

$supplierBreakdown = [];
foreach ($orders as $o) {
    $key = $o['supplier_name'];
    if (!isset($supplierBreakdown[$key])) $supplierBreakdown[$key] = ['count' => 0, 'total' => 0];
    $supplierBreakdown[$key]['count']++;
    $supplierBreakdown[$key]['total'] += $o['total_amount'];
}
arsort($supplierBreakdown);
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY supplier_name ASC")->fetchAll();

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

<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <a href="<?= BASE_URL ?>reports" class="btn btn-outline-secondary btn-sm">Stock Report</a>
    <a href="<?= BASE_URL ?>reports/purchases" class="btn btn-brand btn-sm">Purchase Report</a>
</div>

<div class="card-panel p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-2">
            <input type="date" name="date_from" class="form-control" value="<?= clean($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="date_to" class="form-control" value="<?= clean($dateTo) ?>">
        </div>
        <div class="col-md-3">
            <select name="supplier" class="form-select">
                <option value="">All Suppliers</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['supplier_id'] ?>" <?= $supplierFilter == $s['supplier_id'] ? 'selected' : '' ?>>
                        <?= clean($s['supplier_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <?php foreach (['Pending','Approved','Ordered','Partially Received','Received','Cancelled'] as $st): ?>
                    <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search"></i></button>
        </div>
        <div class="col-md-1 d-grid">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer"></i>
            </button>
        </div>
    </form>
</div>

<div class="card-panel p-3 mb-3">
    <h5 class="mb-1">Purchase Report</h5>
    <p class="text-muted small mb-0">Order date <?= formatDate($dateFrom) ?> to <?= formatDate($dateTo) ?> · Generated on <?= date('M d, Y g:i A') ?></p>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-orange"><i class="bi bi-truck"></i></div>
            <div class="stat-value"><?= count($orders) ?></div>
            <div class="stat-label">Purchase Orders</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon mb-2"><i class="bi bi-receipt"></i></div>
            <div class="stat-value"><?= peso($totalOrderedValue) ?></div>
            <div class="stat-label">Total Ordered Value</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-green"><i class="bi bi-box-arrow-in-down"></i></div>
            <div class="stat-value"><?= peso($actualReceived) ?></div>
            <div class="stat-label">Paid Delivery Value</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-red"><i class="bi bi-x-circle"></i></div>
            <div class="stat-value"><?= peso($cancelledValue) ?></div>
            <div class="stat-label">Cancelled Value</div>
        </div>
    </div>
</div>

<div class="card-panel p-3 mb-3">
    <h6 class="mb-3"><i class="bi bi-list-ul me-1"></i> Purchase Orders</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                     <th>Branch</th>
                    <th>Order Date</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Total Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                     <tr><td colspan="6" class="text-center text-muted py-4">No purchase orders in this range.</td></tr>
                <?php else: foreach ($orders as $po): ?>
                    <tr>
                        <td class="fw-medium"><?= clean($po['po_number']) ?></td>
                        <td><?= clean($po['supplier_name']) ?></td>
                         <td><?= clean($po['branch_name']) ?></td>
                        <td><?= formatDate($po['order_date']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $statusBadge[$po['status']] ?? 'bg-secondary' ?>"><?= clean($po['status']) ?></span>
                        </td>
                        <td class="text-end"><?= peso($po['total_amount']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($orders)): ?>
            <tfoot>
                <tr class="table-light">
                     <td colspan="5" class="text-end fw-medium">Total</td>
                    <td class="text-end fw-bold"><?= peso($totalOrderedValue) ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<div class="card-panel p-3">
    <h6 class="mb-3"><i class="bi bi-people me-1"></i> Spend by Supplier</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Supplier</th>
                    <th class="text-center">Purchase Orders</th>
                    <th class="text-end">Total Ordered Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($supplierBreakdown)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No data for this range.</td></tr>
                <?php else: foreach ($supplierBreakdown as $name => $data): ?>
                    <tr>
                        <td><?= clean($name) ?></td>
                        <td class="text-center"><?= $data['count'] ?></td>
                        <td class="text-end"><?= peso($data['total']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
