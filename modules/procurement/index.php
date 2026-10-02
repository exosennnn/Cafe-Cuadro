<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Purchase Orders';

// Inventory Staff create/edit POs after approval; the Owner monitors.
$canManage = userCanDo('po.manage');
$canApprove = userCanDo('po.approve');
$branchId = $canManage ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if ($canManage && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to manage purchase orders.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

// ---------- Handle Cancel / Delete PO ----------
if (in_array($_POST['action'] ?? '', ['cancel_po', 'delete_po'], true)) {
    requireCapability('po.manage');
    $id = filter_var($_POST['po_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        setFlash('danger', 'Invalid purchase order.');
        redirect('modules/procurement/index.php');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT po_number, status FROM purchase_orders WHERE po_id=? AND branch_id=? FOR UPDATE');
        $stmt->execute([$id, $branchId]);
        $po = $stmt->fetch();
        if (!$po) throw new RuntimeException('Purchase order not found in your branch.');

        if ($_POST['action'] === 'cancel_po') {
            $received = $pdo->prepare('SELECT COALESCE(SUM(quantity_received),0) FROM purchase_order_items WHERE po_id=?');
            $received->execute([$id]);
            if (!in_array($po['status'], ['Pending', 'Approved', 'Ordered'], true) || (float)$received->fetchColumn() > 0) {
                throw new RuntimeException('Only an open PO with no received quantities can be cancelled.');
            }
            $pdo->prepare("UPDATE purchase_orders SET status='Cancelled' WHERE po_id=? AND branch_id=?")->execute([$id, $branchId]);
            $message = 'Cancelled PO ' . $po['po_number'];
            setFlash('success', 'Purchase order cancelled.');
        } else {
            $received = $pdo->prepare('SELECT COALESCE(SUM(quantity_received),0) FROM purchase_order_items WHERE po_id=?');
            $received->execute([$id]);
            $linkedRequest = $pdo->prepare('SELECT COUNT(*) FROM purchase_requests WHERE po_id=?');
            $linkedRequest->execute([$id]);
            if ($po['status'] !== 'Pending' || (float)$received->fetchColumn() > 0 || (int)$linkedRequest->fetchColumn() > 0) {
                throw new RuntimeException('Only an unreceived, unlinked Pending PO can be deleted.');
            }
            $pdo->prepare('DELETE FROM purchase_order_items WHERE po_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_orders WHERE po_id=? AND branch_id=?')->execute([$id, $branchId]);
            $message = 'Deleted PO ' . $po['po_number'];
            setFlash('success', 'Purchase order deleted.');
        }
        $pdo->commit();
        logActivity($pdo, $_SESSION['user_id'], 'Procurement', $message);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        setFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Purchase order action failed.');
    }
    redirect('modules/procurement/index.php');
}

// ---------- Filters ----------
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$supplierFilter = $_GET['supplier'] ?? '';

$sql = "SELECT po.*, s.supplier_name, b.branch_name, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.supplier_id
        JOIN users u ON po.created_by = u.user_id
    JOIN branches b ON b.branch_id=po.branch_id
        WHERE 1=1";
$params = [];
if ($branchId) { $sql .= ' AND po.branch_id=?'; $params[] = $branchId; }

if ($search !== '') {
    $sql .= " AND po.po_number LIKE ?";
    $params[] = "%$search%";
}
if ($statusFilter !== '') {
    $sql .= " AND po.status = ?";
    $params[] = $statusFilter;
}
if ($supplierFilter !== '') {
    $sql .= " AND po.supplier_id = ?";
    $params[] = $supplierFilter;
}

$sql .= " ORDER BY po.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$suppliers = $pdo->query("SELECT * FROM suppliers WHERE status='Active' ORDER BY supplier_name ASC")->fetchAll();

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

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>procurement/suppliers" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-people me-1"></i> Suppliers
        </a>
        <a href="<?= BASE_URL ?>procurement/deliveries" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clipboard-check me-1"></i> Delivery History
        </a>
    </div>
    <?php if ($canManage): ?>
    <a href="<?= BASE_URL ?>procurement/po-form" class="btn btn-brand">
        <i class="bi bi-plus-lg me-1"></i> Create Purchase Order
    </a>
    <?php endif; ?>
</div>

<?php if (!$canManage && !$canApprove): ?><?= readOnlyNotice('purchase orders') ?><?php endif; ?>

<!-- Filters -->
<div class="card-panel p-3 mb-3">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-3">
            <input type="text" name="search" class="form-control" placeholder="Search PO number..." value="<?= clean($search) ?>">
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
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
        <div class="col-md-1 d-grid">
            <a href="<?= BASE_URL ?>procurement" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>PO Number</th>
                    <th>Supplier</th>
                    <th>Branch</th>
                    <th>Order Date</th>
                    <th>Expected Date</th>
                    <th class="text-end">Total Amount</th>
                    <th class="text-center">Status</th>
                    <th>Created By</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No purchase orders found.</td></tr>
                <?php else: foreach ($orders as $po): ?>
                    <tr>
                        <td class="fw-medium"><?= clean($po['po_number']) ?></td>
                        <td><?= clean($po['supplier_name']) ?></td>
                        <td><?= clean($po['branch_name']) ?></td>
                        <td><?= formatDate($po['order_date']) ?></td>
                        <td><?= $po['expected_date'] ? formatDate($po['expected_date']) : '-' ?></td>
                        <td class="text-end"><?= peso($po['total_amount']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $statusBadge[$po['status']] ?? 'bg-secondary' ?>"><?= clean($po['status']) ?></span>
                        </td>
                        <td class="text-muted small"><?= clean($po['created_by_name']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= BASE_URL ?>procurement/po-view?id=<?= $po['po_id'] ?>" class="btn btn-sm btn-outline-secondary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($canManage && $po['status'] === 'Pending'): ?>
                                <a href="<?= BASE_URL ?>procurement/po-form?id=<?= $po['po_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this purchase order?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_po"><input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canManage && in_array($po['status'], ['Pending', 'Approved', 'Ordered'], true)): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this purchase order?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="cancel_po"><input type="hidden" name="po_id" value="<?= (int)$po['po_id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Cancel PO"><i class="bi bi-x-circle"></i></button>
                                </form>
                            <?php endif; ?>
                            <?php if ($canManage && in_array($po['status'], ['Approved', 'Ordered', 'Partially Received'])): ?>
                                <a href="<?= BASE_URL ?>procurement/receive?po_id=<?= $po['po_id'] ?>" class="btn btn-sm btn-success" title="Receive Delivery">
                                    <i class="bi bi-box-arrow-in-down"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
