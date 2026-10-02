<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Delivery History';
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to view delivery history.');
}

$search = trim($_GET['search'] ?? '');
$filterFrom = $_GET['date_from'] ?? '';
$filterTo   = $_GET['date_to'] ?? '';

$sql = "SELECT d.*, po.po_number, s.supplier_name, b.branch_name, pp.status AS payable_status, pp.amount AS payable_amount,
    CONCAT(u.first_name, ' ', u.last_name) AS received_by_name,
        (SELECT COALESCE(SUM(quantity_delivered),0) FROM delivery_items WHERE delivery_id = d.delivery_id) AS total_qty,
    pp.finance_transaction_id
        FROM deliveries d
        JOIN purchase_orders po ON d.po_id = po.po_id
        JOIN suppliers s ON po.supplier_id = s.supplier_id
    JOIN branches b ON b.branch_id=po.branch_id
    LEFT JOIN purchase_payables pp ON pp.delivery_id=d.delivery_id
        JOIN users u ON d.received_by = u.user_id
        WHERE 1=1";
$params = [];
if ($branchId) { $sql .= " AND po.branch_id=?"; $params[] = $branchId; }

if ($search !== '') {
    $sql .= " AND (d.delivery_number LIKE ? OR po.po_number LIKE ? OR s.supplier_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterFrom !== '') {
    $sql .= " AND d.delivery_date >= ?";
    $params[] = $filterFrom;
}
if ($filterTo !== '') {
    $sql .= " AND d.delivery_date <= ?";
    $params[] = $filterTo;
}

$sql .= " ORDER BY d.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<a href="<?= BASE_URL ?>procurement" class="text-decoration-none text-muted small d-inline-block mb-3">
    <i class="bi bi-arrow-left"></i> Back to Purchase Orders
</a>

<div class="card-panel p-3 mb-3">
    <form method="GET" class="row g-2">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control" placeholder="Search GRN #, PO #, or supplier..." value="<?= clean($search) ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="date_from" class="form-control" value="<?= clean($filterFrom) ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="date_to" class="form-control" value="<?= clean($filterTo) ?>">
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
        <div class="col-md-2 d-grid">
            <a href="<?= BASE_URL ?>procurement/deliveries" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>GRN Number</th>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Branch</th>
                    <th>Delivery Date</th>
                    <th class="text-end">Total Qty</th>
                    <th>Payable</th>
                    <th>Received By</th>
                    <th class="text-end">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deliveries)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No deliveries recorded yet.</td></tr>
                <?php else: foreach ($deliveries as $d): ?>
                    <tr>
                        <td class="fw-medium"><?= clean($d['delivery_number']) ?></td>
                        <td><?= clean($d['po_number']) ?></td>
                        <td><?= clean($d['supplier_name']) ?></td>
                        <td><?= clean($d['branch_name']) ?></td>
                        <td><?= formatDate($d['delivery_date']) ?></td>
                        <td class="text-end"><?= number_format($d['total_qty'], 2) ?></td>
                        <td><?= $d['payable_status'] ? '<span class="badge ' . ($d['payable_status'] === 'PAID' ? 'bg-success' : 'bg-warning text-dark') . '">' . clean($d['payable_status']) . '</span><div>' . peso($d['payable_amount']) . '</div>' : '-' ?></td>
                        <td class="text-muted small"><?= clean($d['received_by_name']) ?></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>procurement/delivery-view?id=<?= $d['delivery_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small mt-2 mb-0">Showing latest 200 records. Use filters to narrow down further.</p>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
