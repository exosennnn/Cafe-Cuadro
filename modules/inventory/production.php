<?php
/**
 * RESTOCK POS PRODUCTS (Production)
 * INTEGRATION: Inventory -> POS.
 *
 * Closes the last leg of the loop: HRMS hires/assigns a Cashier -> POS
 * sells -> a product hits 0 (pos/app.php notify_inventory_out_of_stock)
 * -> Inventory Staff raises a Purchase Request -> Owner approves ->
 * Supplier delivers -> Inventory Staff records actual quantities -> Finance
 * pays the delivery -> branch ingredient stock is released -> **this page**
 * can produce recipe-backed products for POS.
 *
 * The step here is intentionally a manual confirmation (Inventory Staff
 * picks a product + branch + batch quantity and clicks Produce), but
 * everything after that click is automatic and atomic in one DB
 * transaction: ingredients are deducted from `items` per the product's
 * recipe (`product_ingredients`), a `stock_movements` row is logged for
 * each ingredient consumed, and the produced quantity is credited to
 * `branch_inventory.stock` so it is immediately sellable in
 * pos/sales_transaction.php.
 */
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';
require_once __DIR__ . '/../../pos/app.php'; // for sync_low_stock_notification_by_product()

$pageTitle = 'Restock POS Products';
$userId = $_SESSION['user_id'];

// Inventory Staff confirm a production batch; the Owner monitors.
$canManage = userCanDo('production.manage');

$branches = $pdo->query("SELECT branch_id, branch_name FROM branches WHERE status='ACTIVE' ORDER BY branch_name")->fetchAll();
$branchIds = array_map('intval', array_column($branches, 'branch_id'));
$assignedBranchId = $canManage ? inventoryAssignedBranchId($pdo, (int)$userId) : null;
if ($canManage && !$assignedBranchId) {
    http_response_code(403);
    die('Inventory Staff must be assigned to an active branch to restock POS products.');
}

$branchId = $canManage ? (int)$assignedBranchId : (int)($_GET['branch_id'] ?? currentBranchId() ?? 0);
if (!in_array($branchId, $branchIds, true)) {
    $branchId = $branchIds[0] ?? 0;
}

// ---------- Handle Confirm Production ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'produce') {
    requireCapability('production.manage');
    csrfVerify();

    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
    $qtyInput = $_POST['qty'] ?? '';
    $qty = is_string($qtyInput) && preg_match('/^\d{1,8}$/', $qtyInput) ? (int)$qtyInput : 0;
    $postedBranchId = (int)($_POST['branch_id'] ?? 0);

    $productStmt = $pdo->prepare("SELECT id, name FROM products WHERE id = ? AND is_active = 1");
    if ($productId !== false && $productId > 0) $productStmt->execute([$productId]);
    $product = $productStmt->fetch();

    if ($postedBranchId !== $branchId || !in_array($postedBranchId, $branchIds, true)) {
        setFlash('danger', 'The selected branch is not your assigned branch.');
    } elseif (!$product) {
        setFlash('danger', 'Product not found or inactive.');
    } elseif ($qty <= 0) {
        setFlash('danger', 'Please enter a batch quantity greater than zero.');
    } else {
        try {
            $pdo->beginTransaction();

            // Lock the recipe's ingredient rows for the duration of this
            // check-then-deduct so two staff producing the same product at
            // the same time can't both pass the "enough stock?" check.
            $recipeStmt = $pdo->prepare("SELECT pi.item_id, pi.qty_per_unit, i.item_name, COALESCE(bii.current_stock,0) AS current_stock, u.unit_symbol
                                          FROM product_ingredients pi
                                          JOIN items i ON i.item_id = pi.item_id
                                          JOIN units u ON u.unit_id = i.unit_id
                                          LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?
                                          WHERE pi.product_id = ?
                                          FOR UPDATE");
            $recipeStmt->execute([$postedBranchId, $productId]);
            $recipe = $recipeStmt->fetchAll();

            if (empty($recipe)) {
                throw new Exception("\"{$product['name']}\" has no ingredient recipe set up yet, so it can't be auto-restocked here. Set its recipe first, or use POS \xE2\x86\x92 Product Management to set its stock directly.");
            }

            $shortages = [];
            foreach ($recipe as $r) {
                $needed = (float)$r['qty_per_unit'] * $qty;
                if ($needed > (float)$r['current_stock']) {
                    $shortages[] = $r['item_name'] . ' (need ' . number_format($needed, 2) . ' ' . $r['unit_symbol'] . ', have ' . number_format($r['current_stock'], 2) . ' ' . $r['unit_symbol'] . ')';
                }
            }
            if ($shortages) {
                throw new Exception("Not enough ingredients for {$qty}x {$product['name']}: " . implode('; ', $shortages) . '.');
            }

            foreach ($recipe as $r) {
                $needed = (float)$r['qty_per_unit'] * $qty;
                adjustInventoryItemStock($pdo, $postedBranchId, (int)$r['item_id'], -$needed);
                $pdo->prepare("INSERT INTO stock_movements (item_id, branch_id, movement_type, reason, quantity, reference_type, remarks, user_id) VALUES (?, ?, 'OUT', 'Usage', ?, 'Manual', ?, ?)")
                    ->execute([$r['item_id'], $postedBranchId, $needed, "Consumed to restock {$qty}x {$product['name']} for POS", $userId]);
            }

            $biStmt = $pdo->prepare("SELECT branch_inventory_id, stock FROM branch_inventory WHERE branch_id = ? AND product_id = ? FOR UPDATE");
            $biStmt->execute([$postedBranchId, $productId]);
            $bi = $biStmt->fetch();
            if ($bi) {
                $pdo->prepare("UPDATE branch_inventory SET stock = stock + ? WHERE branch_inventory_id = ?")
                    ->execute([$qty, $bi['branch_inventory_id']]);
                $newStock = (int)$bi['stock'] + $qty;
            } else {
                $pdo->prepare("INSERT INTO branch_inventory (branch_id, product_id, stock) VALUES (?, ?, ?)")
                    ->execute([$postedBranchId, $productId, $qty]);
                $newStock = $qty;
            }

            $pdo->commit();

            // Reuse the existing POS low-stock/out-of-stock bell: if this
            // batch brings the product back above the threshold, this
            // resolves the earlier "Product Out of Stock" alert instead of
            // leaving it open forever.
            sync_low_stock_notification_by_product($productId, $newStock);

            logActivity($pdo, $userId, 'Inventory', "Restocked POS: +{$qty}x {$product['name']} for branch #{$postedBranchId} (ingredients deducted)");
            setFlash('success', "Produced {$qty}x {$product['name']} - now {$newStock} in stock at POS. Ingredients deducted from Inventory.");
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setFlash('danger', $e->getMessage());
        }
    }
    redirect('modules/inventory/production.php?branch_id=' . $postedBranchId);
}

// ---------- Data for the page ----------
$products = $pdo->query("SELECT p.id, p.name, p.sku, m.item_id AS direct_inventory_item_id, i.item_name AS direct_inventory_item_name
    FROM products p
    LEFT JOIN item_pos_mappings m ON m.product_id=p.id
    LEFT JOIN items i ON i.item_id=m.item_id
    WHERE p.is_active=1 ORDER BY p.name")->fetchAll();

$recipeStmt = $pdo->prepare("SELECT pi.product_id, pi.item_id, pi.qty_per_unit, i.item_name, COALESCE(bii.current_stock,0) AS current_stock, u.unit_symbol
                            FROM product_ingredients pi
                            JOIN items i ON i.item_id = pi.item_id
                            JOIN units u ON u.unit_id = i.unit_id
                            LEFT JOIN branch_item_inventory bii ON bii.item_id=i.item_id AND bii.branch_id=?");
$recipeStmt->execute([$branchId]);
$recipeRows = $recipeStmt->fetchAll();
$recipeByProduct = [];
foreach ($recipeRows as $r) {
    $recipeByProduct[$r['product_id']][] = $r;
}

$currentStockByProduct = [];
if ($branchId) {
    $biRows = $pdo->prepare("SELECT product_id, stock FROM branch_inventory WHERE branch_id = ?");
    $biRows->execute([$branchId]);
    foreach ($biRows->fetchAll() as $row) {
        $currentStockByProduct[$row['product_id']] = (int)$row['stock'];
    }
}
$branchNames = array_column($branches, 'branch_name', 'branch_id');

function maxMakeable(array $recipe) {
    if (empty($recipe)) return null; // no recipe defined - can't auto-produce
    $max = null;
    foreach ($recipe as $r) {
        if ((float)$r['qty_per_unit'] <= 0) continue;
        $possible = (int)floor((float)$r['current_stock'] / (float)$r['qty_per_unit']);
        if ($max === null || $possible < $max) $max = $possible;
    }
    return $max ?? 0;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h4 class="mb-0"><i class="bi bi-arrow-repeat me-1"></i> Restock POS Products</h4>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="form-label small mb-0 text-muted">Branch</label>
        <?php if (!$canManage && count($branches) > 1): ?>
        <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($branches as $b): ?>
                <option value="<?= $b['branch_id'] ?>" <?= $branchId == $b['branch_id'] ? 'selected' : '' ?>><?= clean($b['branch_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
            <span class="badge bg-light text-dark border"><i class="bi bi-geo-alt me-1"></i><?= clean($branchNames[$branchId] ?? '-') ?></span>
            <input type="hidden" name="branch_id" value="<?= $branchId ?>">
        <?php endif; ?>
    </form>
</div>

<?php if (!$canManage): ?><?= readOnlyNotice('restocking POS products') ?><?php endif; ?>
<?php if (empty($branches)): ?>
    <div class="alert alert-warning">No active branches found - add one under Manage Branches first.</div>
<?php endif; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.11/css/dataTables.bootstrap5.min.css">

<div class="card-panel p-3">
    <div class="table-responsive">
        <table id="restockTable" class="table table-hover align-middle mb-0 w-100">
            <thead class="table-light">
                <tr>
                    <th>Product</th>
                    <th>Recipe (per unit)</th>
                    <th class="text-end">Current POS Stock</th>
                    <th class="text-end">Max Makeable Now</th>
                    <?php if ($canManage): ?><th class="text-end">Confirm</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No active products found.</td></tr>
                <?php else: foreach ($products as $p):
                    $recipe = $recipeByProduct[$p['id']] ?? [];
                    $directlyStocked = $p['direct_inventory_item_id'] !== null;
                    $max = $directlyStocked ? null : maxMakeable($recipe);
                    $current = $currentStockByProduct[$p['id']] ?? 0;
                ?>
                    <tr>
                        <td class="fw-medium"><?= clean($p['name']) ?><div class="text-muted small"><?= clean($p['sku']) ?></div></td>
                        <td>
                            <?php if (empty($recipe)): ?>
                                <span class="badge bg-light text-muted border">No recipe set</span>
                            <?php else: foreach ($recipe as $r): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= clean($r['item_name']) ?> &times; <?= number_format($r['qty_per_unit'], 3) ?> <?= clean($r['unit_symbol']) ?></span>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="text-end"><?= number_format($current) ?></td>
                        <td class="text-end">
                            <?php if ($directlyStocked): ?>
                                <span class="badge bg-info text-dark">Direct purchase</span>
                            <?php elseif ($max === null): ?>
                                <span class="text-muted">-</span>
                            <?php else: ?>
                                <span class="badge <?= $max > 0 ? 'bg-success' : 'bg-danger' ?>"><?= $max ?></span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canManage): ?>
                        <td class="text-end">
                            <?php if ($directlyStocked): ?>
                                <span class="text-muted small">Added after Finance marks the linked delivery PAID.</span>
                            <?php elseif ($max === null): ?>
                                <a href="<?= BASE_URL ?>inventory/recipes?product_id=<?= $p['id'] ?>" class="text-decoration-none small">Add recipe first</a>
                            <?php elseif ($max > 0): ?>
                            <form method="POST" class="d-flex gap-1 justify-content-end">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="produce">
                                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="branch_id" value="<?= $branchId ?>">
                                <input type="number" name="qty" class="form-control form-control-sm" style="width:80px;" min="1" max="<?= $max ?>" value="<?= $max ?>" required>
                                <button class="btn btn-sm btn-brand"><i class="bi bi-check-lg"></i> Produce</button>
                            </form>
                            <?php else: ?>
                                <span class="text-muted small">Out of ingredients</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net@1.13.11/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/datatables.net-bs5@1.13.11/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function () {
    $('#restockTable').DataTable({
        columnDefs: [
            { targets: 1, orderable: false }, // Recipe (per unit)
            <?php if ($canManage): ?>{ targets: -1, orderable: false }<?php endif; // Confirm ?>
        ],
        order: [[0, 'asc']],
        language: { search: '', searchPlaceholder: 'Search products...' }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
