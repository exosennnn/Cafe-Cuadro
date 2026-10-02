<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

/**
 * INVENTORY DASHBOARD
 * Landing page for the Inventory Staff role. Stock and procurement figures
 * only - money figures (income / expense / net) live on the Finance
 * dashboard (modules/finance/dashboard.php) and are no longer shown here.
 * (This file was modules/dashboard/index.php, a shared page that mixed
 * Inventory, Procurement and Finance together; that path now redirects here.)
 */
$pageTitle = 'Inventory Dashboard';
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to view inventory.');
}

// ---------- Stat: Total Inventory Items (active) ----------
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM items WHERE status = 'Active'")->fetchColumn();

// ---------- Stat: Low Stock Items ----------
$stockExpression = $branchId ? 'COALESCE(bii.current_stock,0)' : 'COALESCE(i.current_stock,0)';
$branchStockJoin = $branchId ? 'LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?' : '';
$lowStockStmt = $pdo->prepare("
    SELECT i.item_id, i.item_name, $stockExpression AS current_stock, i.reorder_level, u.unit_symbol
    FROM items i
    JOIN units u ON i.unit_id = u.unit_id
    $branchStockJoin
    WHERE i.status = 'Active' AND $stockExpression <= i.reorder_level
    ORDER BY ($stockExpression - i.reorder_level) ASC
    LIMIT 8
");
$lowStockStmt->execute($branchId ? [$branchId] : []);
$lowStockItems = $lowStockStmt->fetchAll();
$lowCountSql = "SELECT COUNT(*) FROM items i $branchStockJoin WHERE i.status='Active' AND $stockExpression<=i.reorder_level";
$lowCountStmt = $pdo->prepare($lowCountSql);
$lowCountStmt->execute($branchId ? [$branchId] : []);
$lowStockCount = (int)$lowCountStmt->fetchColumn();

// ---------- Stat: Pending / Open Purchase Orders ----------
$pendingPoSql = "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Pending', 'Approved', 'Ordered', 'Partially Received')" . ($branchId ? ' AND branch_id=?' : '');
$pendingPoStmt = $pdo->prepare($pendingPoSql);
$pendingPoStmt->execute($branchId ? [$branchId] : []);
$pendingPO = (int)$pendingPoStmt->fetchColumn();

// ---------- Stat: Purchase Requests awaiting approval ----------
$pendingPrSql = "SELECT COUNT(*) FROM purchase_requests WHERE status='Pending'" . ($branchId ? ' AND branch_id=?' : '');
$pendingPrStmt = $pdo->prepare($pendingPrSql);
$pendingPrStmt->execute($branchId ? [$branchId] : []);
$pendingPR = (int)$pendingPrStmt->fetchColumn();

// ---------- Recent Activity ----------
$recentActivity = $pdo->query("
    SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    WHERE al.module IN ('Inventory', 'Procurement')
    ORDER BY al.created_at DESC
    LIMIT 10
")->fetchAll();

// ---------- Recent Purchase Orders ----------
$recentPoSql = "SELECT po.po_id, po.po_number, po.status, po.total_amount, s.supplier_name, b.branch_name
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.supplier_id
    JOIN branches b ON b.branch_id=po.branch_id
    " . ($branchId ? 'WHERE po.branch_id=?' : '') . "
    ORDER BY po.created_at DESC
    LIMIT 5";
$recentPoStmt = $pdo->prepare($recentPoSql);
$recentPoStmt->execute($branchId ? [$branchId] : []);
$recentPOs = $recentPoStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';

$moduleIcon = [
    'Inventory'    => 'bi-box-seam',
    'Procurement'  => 'bi-truck',
];
$statusBadge = [
    'Pending'            => 'bg-secondary',
    'Approved'           => 'bg-info text-dark',
    'Ordered'            => 'bg-primary',
    'Partially Received'  => 'bg-warning text-dark',
    'Received'           => 'bg-success',
    'Cancelled'          => 'bg-danger',
];
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>inventory" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2"><i class="bi bi-box-seam"></i></div>
                <div class="stat-value"><?= number_format($totalItems) ?></div>
                <div class="stat-label">Total Inventory Items</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>inventory?stock=low" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 bg-grad-red"><i class="bi bi-exclamation-triangle"></i></div>
                <div class="stat-value"><?= number_format($lowStockCount) ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>procurement" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 bg-grad-orange"><i class="bi bi-truck"></i></div>
                <div class="stat-value"><?= number_format($pendingPO) ?></div>
                <div class="stat-label">Open Purchase Orders</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>procurement/purchase-requests" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 bg-grad-green"><i class="bi bi-clipboard-check"></i></div>
                <div class="stat-value"><?= number_format($pendingPR) ?></div>
                <div class="stat-label">Purchase Requests Pending</div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3">
    <!-- Low Stock Alerts -->
    <div class="col-md-6">
        <div class="card-panel p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-exclamation-triangle text-danger me-1"></i> Low Stock Alerts</h6>
                <a href="<?= BASE_URL ?>inventory?stock=low" class="small text-decoration-none">View all</a>
            </div>
            <?php if (empty($lowStockItems)): ?>
                <p class="text-muted small mb-0">All items are above their reorder level. Nice.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Item</th>
                                <th class="text-end">Stock</th>
                                <th class="text-end">Reorder Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lowStockItems as $it): ?>
                                <tr>
                                    <td><?= clean($it['item_name']) ?></td>
                                    <td class="text-end">
                                        <?= number_format($it['current_stock'], 2) ?> <?= clean($it['unit_symbol']) ?>
                                        <span class="badge badge-low-stock ms-1">Low</span>
                                    </td>
                                    <td class="text-end text-muted"><?= number_format($it['reorder_level'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="col-md-6">
        <div class="card-panel p-3 h-100">
            <h6 class="mb-3"><i class="bi bi-clock-history me-1"></i> Recent Activity</h6>
            <?php if (empty($recentActivity)): ?>
                <p class="text-muted small mb-0">No activity recorded yet.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($recentActivity as $log): ?>
                        <li class="d-flex align-items-start gap-2 mb-3">
                            <i class="bi <?= $moduleIcon[$log['module']] ?? 'bi-info-circle' ?> text-secondary mt-1"></i>
                            <div>
                                <div class="small"><?= clean($log['action']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem;">
                                    <?= clean($log['full_name']) ?> · <?= formatDate($log['created_at'], 'M d, Y g:i A') ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card-panel p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-truck me-1"></i> Recent Purchase Orders</h6>
                <a href="<?= BASE_URL ?>procurement" class="small text-decoration-none">View all</a>
            </div>
            <?php if (empty($recentPOs)): ?>
                <p class="text-muted small mb-0">No purchase orders yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Branch</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPOs as $po): ?>
                                <tr>
                                    <td class="fw-medium"><?= clean($po['po_number']) ?></td>
                                    <td><?= clean($po['supplier_name']) ?></td>
                                    <td><?= clean($po['branch_name']) ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $statusBadge[$po['status']] ?? 'bg-secondary' ?>"><?= clean($po['status']) ?></span>
                                    </td>
                                    <td class="text-end"><?= peso($po['total_amount']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= BASE_URL ?>procurement/po-view?id=<?= $po['po_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
