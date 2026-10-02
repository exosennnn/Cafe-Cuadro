<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Stock Report';
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to view stock reports.');
}

$categoryFilter = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';
$stockExpression = $branchId ? 'COALESCE(bii.current_stock,0)' : 'COALESCE(i.current_stock,0)';
$branchStockJoin = $branchId ? 'LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?' : '';

$sql = "SELECT i.*, $stockExpression AS report_stock, c.category_name, u.unit_symbol
        FROM items i
        JOIN categories c ON i.category_id = c.category_id
        JOIN units u ON i.unit_id = u.unit_id
    $branchStockJoin
        WHERE i.status = 'Active'";
$params = $branchId ? [$branchId] : [];

if ($categoryFilter !== '') {
    $sql .= " AND i.category_id = ?";
    $params[] = $categoryFilter;
}
if ($stockFilter === 'low') {
    $sql .= " AND $stockExpression <= i.reorder_level";
}

$sql .= " ORDER BY c.category_name ASC, i.item_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$totalValue = 0;
foreach ($items as $it) {
    $totalValue += $it['report_stock'] * $it['cost_price'];
}
$lowCount = 0;
foreach ($items as $it) {
    if ($it['report_stock'] <= $it['reorder_level']) $lowCount++;
}

$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 mb-3 no-print">
    <a href="<?= BASE_URL ?>reports" class="btn btn-brand btn-sm">Stock Report</a>
    <a href="<?= BASE_URL ?>reports/purchases" class="btn btn-outline-secondary btn-sm">Purchase Report</a>
</div>

<div class="card-panel p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <select name="category" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['category_id'] ?>" <?= $categoryFilter == $c['category_id'] ? 'selected' : '' ?>>
                        <?= clean($c['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <select name="stock" class="form-select">
                <option value="">All Stock Levels</option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
        <div class="col-md-2 d-grid">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </form>
</div>

<div class="card-panel p-3 mb-3">
    <h5 class="mb-1">Inventory Stock Report</h5>
    <p class="text-muted small mb-0">Generated on <?= date('M d, Y g:i A') ?><?= $stockFilter === 'low' ? ' — Low Stock Only' : '' ?></p>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2"><i class="bi bi-box-seam"></i></div>
            <div class="stat-value"><?= count($items) ?></div>
            <div class="stat-label">Items Listed</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-red"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-value"><?= $lowCount ?></div>
            <div class="stat-label">Low Stock Items</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-green"><i class="bi bi-cash-stack"></i></div>
            <div class="stat-value"><?= peso($totalValue) ?></div>
            <div class="stat-label">Total Stock Value</div>
        </div>
    </div>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th class="text-end">Cost Price</th>
                    <th class="text-end"><?= $branchId ? 'Branch Stock' : 'Current Stock' ?></th>
                    <th class="text-end">Reorder Level</th>
                    <th class="text-end">Stock Value</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No items found.</td></tr>
                <?php else: foreach ($items as $it):
                    $isLow = $it['report_stock'] <= $it['reorder_level'];
                    $value = $it['report_stock'] * $it['cost_price'];
                ?>
                    <tr>
                        <td class="text-muted small"><?= clean($it['item_code']) ?></td>
                        <td class="fw-medium"><?= clean($it['item_name']) ?></td>
                        <td><?= clean($it['category_name']) ?></td>
                        <td><?= clean($it['unit_symbol']) ?></td>
                        <td class="text-end"><?= peso($it['cost_price']) ?></td>
                        <td class="text-end"><?= number_format($it['report_stock'], 2) ?></td>
                        <td class="text-end text-muted"><?= number_format($it['reorder_level'], 2) ?></td>
                        <td class="text-end fw-medium"><?= peso($value) ?></td>
                        <td class="text-center">
                            <?php if ($isLow): ?>
                                <span class="badge badge-low-stock">Low</span>
                            <?php else: ?>
                                <span class="badge bg-success">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($items)): ?>
            <tfoot>
                <tr class="table-light">
                    <td colspan="7" class="text-end fw-medium">Total Stock Value</td>
                    <td class="text-end fw-bold"><?= peso($totalValue) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
