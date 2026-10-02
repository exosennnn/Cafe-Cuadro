<?php
/**
 * PRODUCT RECIPES
 * INTEGRATION: Inventory <-> POS.
 *
 * Defines how much of each raw ingredient (`items`) one unit of a POS
 * `product` consumes (`product_ingredients.qty_per_unit`). This is what
 * powers both:
 *   - the automatic ingredient deduction on every POS sale
 *     (applyPosSaleToInventoryAndFinance() in includes/functions.php), and
 *   - the "Max Makeable Now" batch limit on Restock POS Products
 *     (modules/inventory/production.php).
 * A product with no recipe here can't be auto-restocked and its sales
 * won't touch Inventory - it just shows "No recipe set" everywhere.
 */
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Product Recipes';
$userId = $_SESSION['user_id'];

// Inventory Staff define recipes; the Owner monitors.
$canManage = userCanDo('recipes.manage');

$focusProductId = (int)($_GET['product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

// ---------- Handle Add Ingredient ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_ingredient') {
    requireCapability('recipes.manage');
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
    
    $itemIds = $_POST['item_id'] ?? [];
    $qtys    = $_POST['qty_per_unit'] ?? [];
    
    if (!is_array($itemIds)) $itemIds = [$itemIds];
    if (!is_array($qtys)) $qtys = [$qtys];

    $productOk = $pdo->prepare("SELECT id FROM products WHERE id=? AND is_active=1 AND NOT EXISTS (SELECT 1 FROM item_pos_mappings WHERE product_id=products.id)");
    if ($productId !== false && $productId > 0) $productOk->execute([$productId]);

    if ($productId === false || $productId < 1 || !$productOk->fetch()) {
        setFlash('danger', 'Product not found, inactive, or directly linked to purchased Inventory stock.');
        redirect('modules/inventory/recipes.php');
        exit;
    }

    $successCount = 0;
    
    for ($i = 0; $i < count($itemIds); $i++) {
        $itemId = filter_var($itemIds[$i], FILTER_VALIDATE_INT);
        $qtyVal = is_numeric($qtys[$i]) ? (float)$qtys[$i] : null;

        $itemOk = $pdo->prepare("SELECT item_id FROM items WHERE item_id = ? AND status = 'Active'");
        if ($itemId !== false && $itemId > 0) $itemOk->execute([$itemId]);

        if ($itemId === false || $itemId < 1 || !$itemOk->fetch()) {
            continue;
        }
        if ($qtyVal === null || !is_finite($qtyVal) || $qtyVal <= 0 || $qtyVal > 9999999.999) {
            continue;
        }

        $existing = $pdo->prepare("SELECT product_ingredient_id FROM product_ingredients WHERE product_id = ? AND item_id = ?");
        $existing->execute([$productId, $itemId]);
        if ($row = $existing->fetch()) {
            $pdo->prepare("UPDATE product_ingredients SET qty_per_unit = ? WHERE product_ingredient_id = ?")
                ->execute([$qtyVal, $row['product_ingredient_id']]);
        } else {
            $pdo->prepare("INSERT INTO product_ingredients (product_id, item_id, qty_per_unit) VALUES (?, ?, ?)")
                ->execute([$productId, $itemId, $qtyVal]);
        }
        $successCount++;
        logActivity($pdo, $userId, 'Inventory', "Set recipe line for product #$productId: item #$itemId = $qtyVal per unit");
    }

    if ($successCount > 0) {
        setFlash('success', "$successCount ingredient(s) processed for recipe.");
    } else {
        setFlash('warning', "No valid ingredients were added. Check if items are active and quantities are > 0.");
    }
    redirect('modules/inventory/recipes.php?product_id=' . $productId);
    exit;
}

// ---------- Handle Remove Ingredient ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_ingredient') {
    requireCapability('recipes.manage');
    $lineId = filter_var($_POST['line_id'] ?? null, FILTER_VALIDATE_INT);
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
    if ($lineId === false || $lineId < 1 || $productId === false || $productId < 1) {
        setFlash('danger', 'Invalid recipe line.');
    } else {
        $delete = $pdo->prepare('DELETE FROM product_ingredients WHERE product_ingredient_id=? AND product_id=?');
        $delete->execute([$lineId, $productId]);
        if ($delete->rowCount() === 1) {
            logActivity($pdo, $userId, 'Inventory', "Removed recipe line #$lineId");
            setFlash('success', 'Ingredient removed from recipe.');
        } else {
            setFlash('danger', 'Recipe line not found.');
        }
    }
    redirect('modules/inventory/recipes.php?product_id=' . $productId);
}

// ---------- Data for the page ----------
$products = $pdo->query("SELECT id, name, sku FROM products WHERE is_active = 1 ORDER BY name")->fetchAll();
$items    = $pdo->query("SELECT item_id, item_name, unit_id FROM items WHERE status='Active' ORDER BY item_name")->fetchAll();
$units    = $pdo->query("SELECT unit_id, unit_symbol FROM units")->fetchAll();
$unitSymbolById = array_column($units, 'unit_symbol', 'unit_id');

$recipeRows = $pdo->query("SELECT pi.*, i.item_name, i.unit_id FROM product_ingredients pi JOIN items i ON i.item_id = pi.item_id")->fetchAll();
$recipeByProduct = [];
foreach ($recipeRows as $r) {
    $recipeByProduct[$r['product_id']][] = $r;
}

// Live stock per Inventory item (summed across branches) so this page always
// reflects the latest Inventory transactions (Stock In/Out, POS sales, GRN...).
$stockByItem = [];
foreach ($pdo->query("SELECT item_id, SUM(current_stock) AS qty FROM branch_item_inventory GROUP BY item_id")->fetchAll() as $sr) {
    $stockByItem[(int)$sr['item_id']] = (float)$sr['qty'];
}

// Products that are linked DIRECTLY to a purchased Inventory item
// (Inventory > Ingredients & Stock > Link to POS). They have no recipe lines
// on purpose - the sale deducts the linked item - so they must not show up
// as "No recipe set".
$directByProduct = [];
foreach ($pdo->query("SELECT m.product_id, m.item_id, m.pos_units_per_item, i.item_name, i.unit_id
                      FROM item_pos_mappings m JOIN items i ON i.item_id = m.item_id")->fetchAll() as $dm) {
    $directByProduct[(int)$dm['product_id']] = $dm;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-journal-richtext me-1"></i> Product Recipes</h4>
    </div>
</div>

<?php if (!$canManage): ?><?= readOnlyNotice('product recipes') ?><?php endif; ?>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>Recipe (per unit)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No active products found.</td></tr>
                <?php else: foreach ($products as $p):
                    $recipe = $recipeByProduct[$p['id']] ?? [];
                    $direct = $directByProduct[(int)$p['id']] ?? null;
                ?>
                    <tr class="<?= $focusProductId === (int)$p['id'] ? 'table-warning' : '' ?>">
                        <td class="fw-medium"><?= clean($p['name']) ?><div class="text-muted small"><?= clean($p['sku']) ?></div></td>
                        <td>
                            <?php if ($direct && empty($recipe)): ?>
                                <span class="badge bg-info-subtle text-dark border me-1 mb-1">
                                    <i class="bi bi-link-45deg"></i> Linked to Inventory: <?= clean($direct['item_name']) ?>
                                    (1 <?= clean($unitSymbolById[$direct['unit_id']] ?? '') ?> = <?= rtrim(rtrim(number_format((float)$direct['pos_units_per_item'], 4), '0'), '.') ?> pcs)
                                    &middot; In stock: <?= number_format($stockByItem[(int)$direct['item_id']] ?? 0, 2) ?> <?= clean($unitSymbolById[$direct['unit_id']] ?? '') ?>
                                </span>
                            <?php elseif (empty($recipe)): ?>
                                <span class="badge bg-light text-muted border">No recipe set</span>
                            <?php else: foreach ($recipe as $r): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= clean($r['item_name']) ?> &times; <?= number_format($r['qty_per_unit'], 3) ?> <?= clean($unitSymbolById[$r['unit_id']] ?? '') ?>
                                    <span class="text-muted">(stock: <?= number_format($stockByItem[(int)$r['item_id']] ?? 0, 2) ?>)</span>
                                    <?php if ($canManage): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this ingredient from the recipe?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="remove_ingredient"><input type="hidden" name="line_id" value="<?= (int)$r['product_ingredient_id'] ?>"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-link text-danger text-decoration-none p-0" title="Remove ingredient"><i class="bi bi-x-circle"></i></button></form>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($canManage && $direct): ?>
                                <span class="text-muted small">Managed in Inventory</span>
                            <?php elseif ($canManage): ?>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#recipeModal<?= $p['id'] ?>">
                                <i class="bi bi-plus-lg"></i> Add Ingredient
                            </button>
                            <div class="modal fade" id="recipeModal<?= $p['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                            <input type="hidden" name="action" value="add_ingredient">
                                            <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                            <div class="modal-header">
                                                <h6 class="modal-title">Add Ingredient - <?= clean($p['name']) ?></h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body" id="ingredients-container-<?= $p['id'] ?>">
                                                <div class="ingredient-row mb-3 border p-2 rounded">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <label class="form-label small mb-0 fw-bold">Ingredient</label>
                                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 d-none remove-row-btn" onclick="this.closest('.ingredient-row').remove()"><i class="bi bi-x-circle"></i> Remove</button>
                                                    </div>
                                                    <select name="item_id[]" class="form-select form-select-sm mb-2" required>
                                                        <option value="">Select ingredient...</option>
                                                        <?php foreach ($items as $it): ?>
                                                            <option value="<?= $it['item_id'] ?>"><?= clean($it['item_name']) ?> (<?= clean($unitSymbolById[$it['unit_id']] ?? '') ?>)</option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <label class="form-label small">Quantity per unit sold</label>
                                                    <input type="number" step="0.0001" min="0.0001" name="qty_per_unit[]" class="form-control form-control-sm" placeholder="e.g. 0.018" required>
                                                </div>
                                            </div>
                                            <div class="modal-body pt-0">
                                                <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-2" onclick="addIngredientRow(<?= $p['id'] ?>)">
                                                    <i class="bi bi-plus-lg"></i> Add Another Ingredient
                                                </button>
                                                <div class="form-text">Already on the recipe? Adding it again just updates the quantity.</div>
                                            </div>
                                            <div class="modal-footer"><button class="btn btn-sm btn-primary">Save Ingredients</button></div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                                <span class="text-muted small">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($focusProductId): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var row = document.querySelector('tr.table-warning');
    if (row) row.scrollIntoView({ behavior: 'smooth', block: 'center' });
});
</script>
<?php endif; ?>

<script>
function addIngredientRow(productId) {
    const container = document.getElementById('ingredients-container-' + productId);
    const firstRow = container.querySelector('.ingredient-row');
    const newRow = firstRow.cloneNode(true);
    
    // clear values
    newRow.querySelector('select').value = '';
    newRow.querySelector('input').value = '';
    
    // show the remove button
    const removeBtn = newRow.querySelector('.remove-row-btn');
    if (removeBtn) {
        removeBtn.classList.remove('d-none');
    }
    
    container.appendChild(newRow);
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>