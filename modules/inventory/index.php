<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Inventory Items';

// Inventory Staff manage items; the Owner gets view / monitor only.
$canManage = userCanDo('inventory.manage');
$branchId = currentRoleId() === ROLE_INVENTORY_STAFF ? inventoryAssignedBranchId($pdo, (int)$_SESSION['user_id']) : null;
if (currentRoleId() === ROLE_INVENTORY_STAFF && !$branchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to manage stock.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pos_mapping') {
    requireCapability('inventory.manage');
    $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $productIdInput = $_POST['product_id'] ?? '';
    $ratioInput = $_POST['pos_units_per_item'] ?? '1';

    if ($itemId === false || $itemId < 1 || !is_string($productIdInput) || !is_string($ratioInput)) {
        setFlash('danger', 'Invalid Inventory-to-POS mapping.');
    } elseif ($productIdInput === '') {
        $pdo->prepare('DELETE FROM item_pos_mappings WHERE item_id=?')->execute([$itemId]);
        setFlash('success', 'Direct POS mapping removed. Recipe-based production remains available.');
    } else {
        $productId = filter_var($productIdInput, FILTER_VALIDATE_INT);
        $conversion = preg_match('/^\d{1,6}(?:\.\d{1,4})?$/', $ratioInput) ? (float)$ratioInput : 0.0;
        $inventoryPerSale = $conversion > 0 ? 1 / $conversion : 0.0;
        if ($productId === false || $productId < 1 || $conversion <= 0 || $conversion > 999999.9999 || $inventoryPerSale <= 0) {
            file_put_contents('C:\xampp\htdocs\unified\modules\inventory\debug.log', "Fail 1: product_id=$productId, ratio=$ratioInput, conv=$conversion\n", FILE_APPEND);
            setFlash('danger', 'Choose a POS product and enter a valid units-per-Inventory-unit conversion.');
        } else {
            $itemCheck = $pdo->prepare("SELECT item_id FROM items WHERE item_id=? AND status='Active'
                AND NOT EXISTS (SELECT 1 FROM product_ingredients WHERE item_id=items.item_id)");
            $itemCheck->execute([$itemId]);
            $productCheck = $pdo->prepare("SELECT id FROM products WHERE id=? AND is_active=1 AND NOT EXISTS (SELECT 1 FROM product_ingredients WHERE product_id=products.id)");
            $productCheck->execute([$productId]);
            $r1 = $itemCheck->fetch();
            $r2 = $productCheck->fetch();
            if (!$r1 || !$r2) {
                file_put_contents('C:\xampp\htdocs\unified\modules\inventory\debug.log', "Fail 2: item=$itemId (r1=" . json_encode($r1) . "), product=$productId (r2=" . json_encode($r2) . ")\n", FILE_APPEND);
                setFlash('danger', 'The item is inactive, already used by a recipe, or the POS product is missing, inactive, or recipe-backed.');
            } else {
                $dupCheck = $pdo->prepare("SELECT item_id FROM item_pos_mappings WHERE product_id=? AND item_id!=?");
                $dupCheck->execute([$productId, $itemId]);
                if ($dupCheck->fetch()) {
                    setFlash('danger', 'That POS product is already directly linked to another Inventory item.');
                } else {
                    try {
                        $mapping = $pdo->prepare("INSERT INTO item_pos_mappings (item_id, product_id, pos_units_per_item, created_by) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), pos_units_per_item=VALUES(pos_units_per_item), created_by=VALUES(created_by)");
                        $mapping->execute([$itemId, $productId, $conversion, $_SESSION['user_id']]);
                        file_put_contents('C:\xampp\htdocs\unified\modules\inventory\debug.log', "Success: item=$itemId, product=$productId\n", FILE_APPEND);
                        setFlash('success', 'Inventory item linked to the POS product.');
                    } catch (PDOException $exception) {
                        file_put_contents('C:\xampp\htdocs\unified\modules\inventory\debug.log', "Fail 3: " . $exception->getMessage() . "\n", FILE_APPEND);
                        setFlash('danger', 'That POS product is already linked to another Inventory item.');
                    }
                }
            }
        }
    }
    redirect('modules/inventory/index.php');
}

// ---------- Handle Add / Edit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    requireCapability('inventory.manage');
    $itemIdInput  = $_POST['item_id'] ?? '';
    $itemId       = $itemIdInput === '' ? null : filter_var($itemIdInput, FILTER_VALIDATE_INT);
    $name         = trim($_POST['item_name'] ?? '');
    $categoryId   = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    $unitId       = filter_var($_POST['unit_id'] ?? null, FILTER_VALIDATE_INT);
    $costInput    = $_POST['cost_price'] ?? '0';
    $reorderInput = $_POST['reorder_level'] ?? '0';
    $costPrice    = is_string($costInput) && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $costInput) ? (float)$costInput : -1;
    $reorderLevel = is_string($reorderInput) && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $reorderInput) ? (float)$reorderInput : -1;
    $isPerishable = isset($_POST['is_perishable']) ? 1 : 0;
    $status       = $_POST['status'] ?? 'Active';

    $categoryCheck = $pdo->prepare('SELECT category_id FROM categories WHERE category_id=?');
    $unitCheck = $pdo->prepare('SELECT unit_id FROM units WHERE unit_id=?');
    if ($categoryId !== false && $categoryId > 0) $categoryCheck->execute([$categoryId]);
    if ($unitId !== false && $unitId > 0) $unitCheck->execute([$unitId]);
    $validItemId = $itemId === null || ($itemId !== false && $itemId > 0);
    if (!$validItemId || $name === '' || strlen($name) > 150 || preg_match('/[\x00-\x1F\x7F]/', $name) || $categoryId === false || $categoryId < 1 || !$categoryCheck->fetch() || $unitId === false || $unitId < 1 || !$unitCheck->fetch() || $costPrice < 0 || $reorderLevel < 0 || !in_array($status, ['Active', 'Inactive'], true)) {
        setFlash('danger', 'Enter a valid item name, category, unit, cost, and reorder level.');
    } else {
        if ($itemId !== null) {
            $stmt = $pdo->prepare("UPDATE items SET item_name=?, category_id=?, unit_id=?, cost_price=?, reorder_level=?, is_perishable=?, status=? WHERE item_id=?");
            $stmt->execute([$name, $categoryId, $unitId, $costPrice, $reorderLevel, $isPerishable, $status, $itemId]);
            setFlash('success', 'Item updated successfully.');
            logActivity($pdo, $_SESSION['user_id'], 'Inventory', 'Updated item: ' . $name);
        } else {
            // Insert first (starting stock is always 0 - use Stock In to add quantity)
            $stmt = $pdo->prepare("INSERT INTO items (item_name, category_id, unit_id, cost_price, reorder_level, is_perishable, status, current_stock) VALUES (?, ?, ?, ?, ?, ?, ?, 0)");
            $stmt->execute([$name, $categoryId, $unitId, $costPrice, $reorderLevel, $isPerishable, $status]);
            $newId = $pdo->lastInsertId();

            // Auto-generate item code tied to its own ID: ITM-0001
            $code = sprintf('ITM-%04d', $newId);
            $pdo->prepare("UPDATE items SET item_code = ? WHERE item_id = ?")->execute([$code, $newId]);

            setFlash('success', "Item added successfully (Code: $code). Use Stock In to add initial quantity.");
            logActivity($pdo, $_SESSION['user_id'], 'Inventory', 'Added item: ' . $name);
        }
    }
    redirect('modules/inventory/index.php');
}

// ---------- Handle Delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    requireCapability('inventory.manage');
    $id = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
    $check = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM stock_movements WHERE item_id=?) +
        (SELECT COUNT(*) FROM purchase_requests WHERE item_id=?) +
        (SELECT COUNT(*) FROM purchase_order_items WHERE item_id=?) +
        (SELECT COUNT(*) FROM product_ingredients WHERE item_id=?);");
    $check->execute([$id === false ? 0 : $id, $id === false ? 0 : $id, $id === false ? 0 : $id, $id === false ? 0 : $id]);
    if ($check->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: this item has stock, procurement, or recipe history. Set it to Inactive instead.');
    } elseif ($id === false || $id < 1) {
        setFlash('danger', 'Invalid Inventory item.');
    } else {
        $pdo->prepare("DELETE FROM items WHERE item_id = ?")->execute([$id]);
        setFlash('success', 'Item deleted.');
    }
    redirect('modules/inventory/index.php');
}

// ---------- Filters ----------
$search = trim($_GET['search'] ?? '');
$categoryFilter = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';

$stockExpression = $branchId ? 'COALESCE(bii.current_stock,0)' : 'COALESCE(i.current_stock,0)';
$branchStockJoin = $branchId ? 'LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?' : '';
$sql = "SELECT i.*, c.category_name, u.unit_symbol, $stockExpression AS display_stock,
           m.product_id AS pos_product_id, m.pos_units_per_item, p.name AS pos_product_name
        FROM items i
        JOIN categories c ON i.category_id = c.category_id
        JOIN units u ON i.unit_id = u.unit_id
    $branchStockJoin
    LEFT JOIN item_pos_mappings m ON m.item_id=i.item_id
    LEFT JOIN products p ON p.id=m.product_id
        WHERE 1=1";
$params = $branchId ? [$branchId] : [];

if ($search !== '') {
    $sql .= " AND (i.item_name LIKE ? OR i.item_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($categoryFilter !== '') {
    $sql .= " AND i.category_id = ?";
    $params[] = $categoryFilter;
}
if ($stockFilter === 'low') {
    $sql .= " AND $stockExpression <= i.reorder_level";
}

$sql .= " ORDER BY i.item_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll();
$units = $pdo->query("SELECT * FROM units ORDER BY unit_name ASC")->fetchAll();
$posProducts = $canManage ? $pdo->query("SELECT id, name FROM products WHERE is_active=1 ORDER BY name")->fetchAll() : [];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>inventory/categories" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-tags me-1"></i> Categories
        </a>
        <a href="<?= BASE_URL ?>inventory/units" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-rulers me-1"></i> Units
        </a>
        <a href="<?= BASE_URL ?>inventory/stock-movement" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left-right me-1"></i> Stock Movement
        </a>
    </div>
    <?php if ($canManage): ?>
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#itemModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Item
    </button>
    <?php endif; ?>
</div>

<?php if (!$canManage): ?><?= readOnlyNotice('ingredients & stock') ?><?php endif; ?>

<!-- Filters -->
<div class="card-panel p-3 mb-3">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control" placeholder="Search item name or code..." value="<?= clean($search) ?>">
        </div>
        <div class="col-md-3">
            <select name="category" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['category_id'] ?>" <?= $categoryFilter == $c['category_id'] ? 'selected' : '' ?>>
                        <?= clean($c['category_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="stock" class="form-select">
                <option value="">All Stock Levels</option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock Only</option>
            </select>
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
    </form>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Code</th>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th class="text-end">Cost Price</th>
                    <th class="text-end"><?= $branchId ? 'Branch Stock' : 'Current Stock' ?></th>
                    <th class="text-end">Reorder Level</th>
                    <th class="text-center">Perishable</th>
                    <th class="text-center">Status</th>
                    <?php if ($canManage): ?><th>POS Product Link</th><?php endif; ?>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $canManage ? 11 : 10 ?>" class="text-center text-muted py-4">No items found.</td></tr>
                <?php else: foreach ($items as $item):
                    $isLow = $item['display_stock'] <= $item['reorder_level'];
                ?>
                    <tr>
                        <td><span class="text-muted small"><?= clean($item['item_code']) ?></span></td>
                        <td class="fw-medium"><?= clean($item['item_name']) ?></td>
                        <td><?= clean($item['category_name']) ?></td>
                        <td><?= clean($item['unit_symbol']) ?></td>
                        <td class="text-end"><?= peso($item['cost_price']) ?></td>
                        <td class="text-end">
                            <?= number_format($item['display_stock'], 2) ?>
                            <?php if ($isLow): ?>
                                <span class="badge badge-low-stock ms-1">Low</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-muted"><?= number_format($item['reorder_level'], 2) ?></td>
                        <td class="text-center">
                            <?php if ($item['is_perishable']): ?>
                                <i class="bi bi-check-circle-fill text-success"></i>
                            <?php else: ?>
                                <i class="bi bi-dash text-muted"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= $item['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= $item['status'] ?>
                            </span>
                        </td>
                        <?php if ($canManage): ?>
                        <td>
                            <form method="POST" class="d-flex gap-1 align-items-center">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="save_pos_mapping">
                                <input type="hidden" name="item_id" value="<?= (int)$item['item_id'] ?>">
                                <select name="product_id" class="form-select form-select-sm" aria-label="POS product for <?= clean($item['item_name']) ?>">
                                    <option value="">Recipe-based / not direct POS</option>
                                    <?php foreach ($posProducts as $product): ?>
                                        <option value="<?= (int)$product['id'] ?>" <?= (int)$item['pos_product_id'] === (int)$product['id'] ? 'selected' : '' ?>><?= clean($product['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="pos_units_per_item" class="form-control form-control-sm" style="width:110px" min="0.0001" max="999999.9999" step="0.0001" value="<?= $item['pos_units_per_item'] !== null ? clean($item['pos_units_per_item']) : '1.0000' ?>" title="POS units per Inventory unit" aria-label="POS units per Inventory unit">
                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Save POS mapping"><i class="bi bi-link-45deg"></i></button>
                            </form>
                            <small class="text-muted">POS units per Inventory unit</small>
                        </td>
                        <?php endif; ?>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openEditModal(<?= htmlspecialchars(json_encode($item), ENT_QUOTES, 'UTF-8') ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php endif; ?>
                            <a href="<?= BASE_URL ?>inventory/stock-movement?item_id=<?= $item['item_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Movement history">
                                <i class="bi bi-clock-history"></i>
                            </a>
                            <?php if ($canManage): ?>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this item?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= (int)$item['item_id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete item"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Item Modal -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="itemModalLabel">Add Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="item_id" id="item_id">

                <div class="mb-3">
                    <label class="form-label">Item Name</label>
                    <input type="text" name="item_name" id="item_name" class="form-control" required>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="category_id" class="form-select" required>
                            <option value="">Select category</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['category_id'] ?>"><?= clean($c['category_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Unit</label>
                        <select name="unit_id" id="unit_id" class="form-select" required>
                            <option value="">Select unit</option>
                            <?php foreach ($units as $u): ?>
                                <option value="<?= $u['unit_id'] ?>"><?= clean($u['unit_name']) ?> (<?= clean($u['unit_symbol']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Cost Price (per unit)</label>
                        <input type="number" step="0.01" min="0" max="99999999.99" name="cost_price" id="cost_price" class="form-control" value="0">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Reorder Level</label>
                        <input type="number" step="0.01" min="0" max="99999999.99" name="reorder_level" id="reorder_level" class="form-control" value="0">
                    </div>
                </div>

                <div class="mb-3" id="statusWrapper" style="display:none;">
                    <label class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>

                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_perishable" id="is_perishable" value="1">
                    <label class="form-check-label" for="is_perishable">
                        This item is perishable (requires expiry date tracking)
                    </label>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('itemModalLabel').innerText = 'Add Item';
    document.getElementById('item_id').value = '';
    document.getElementById('item_name').value = '';
    document.getElementById('category_id').value = '';
    document.getElementById('unit_id').value = '';
    document.getElementById('cost_price').value = 0;
    document.getElementById('reorder_level').value = 0;
    document.getElementById('is_perishable').checked = false;
    document.getElementById('statusWrapper').style.display = 'none';
    document.getElementById('newItemNote').style.display = 'block';
}
function openEditModal(data) {
    document.getElementById('itemModalLabel').innerText = 'Edit Item';
    document.getElementById('item_id').value = data.item_id;
    document.getElementById('item_name').value = data.item_name;
    document.getElementById('category_id').value = data.category_id;
    document.getElementById('unit_id').value = data.unit_id;
    document.getElementById('cost_price').value = data.cost_price;
    document.getElementById('reorder_level').value = data.reorder_level;
    document.getElementById('is_perishable').checked = data.is_perishable == 1;
    document.getElementById('status').value = data.status;
    document.getElementById('statusWrapper').style.display = 'block';
    document.getElementById('newItemNote').style.display = 'none';
    var modalEl = document.getElementById('itemModal');
    var modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    modal.show();
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

