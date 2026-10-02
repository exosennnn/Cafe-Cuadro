<?php
require 'database.php';
require 'app.php';
require_admin();

$message      = "";
$edit_product = null;

function handle_image_upload($current_image_url = '') {
    if (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] === UPLOAD_ERR_NO_FILE) {
        return $current_image_url;
    }
    $file = $_FILES['product_image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Image upload failed. Please try again.'];
    }
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_types)) {
        return ['error' => 'Only JPG, PNG, GIF, or WEBP images are allowed.'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['error' => 'Image must be under 2MB.'];
    }
    $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename   = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $upload_dir = 'images/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        return ['error' => 'Could not save image. Check folder permissions.'];
    }
    return $upload_dir . $filename;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id            = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $category_id   = filter_input(INPUT_POST, 'category_id', FILTER_VALIDATE_INT);
        $sku           = trim($_POST['sku'] ?? '');
        $name          = trim($_POST['name'] ?? '');
        $price         = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
        $stock         = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);
        $is_active     = isset($_POST['is_active']) ? 1 : 0;
        $current_image = trim($_POST['current_image_url'] ?? '');

        if ($name === '' || $price === false || $price < 0 || $stock === false || $stock < 0) {
            $message = "<div class='alert error'>Please enter a valid product name, price, and stock.</div>";
        } else {
            $image_url = handle_image_upload($current_image);
            if (is_array($image_url) && isset($image_url['error'])) {
                $message = "<div class='alert error'>" . h($image_url['error']) . "</div>";
            } else {
                try {
                    if ($id) {
                        $stmt = $conn->prepare("UPDATE products SET category_id=?, sku=?, name=?, price=?, stock=?, is_active=?, image_url=? WHERE id=?");
                        $stmt->execute([$category_id ?: null, $sku ?: null, $name, $price, $stock, $is_active, $image_url, $id]);
                        sync_low_stock_notification_by_product($id, $stock);
                        header("Location: " . BASE_URL . "pos/products?saved=updated#product-list"); exit;
                    } else {
                        $stmt = $conn->prepare("INSERT INTO products (category_id, sku, name, price, stock, is_active, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$category_id ?: null, $sku ?: null, $name, $price, $stock, $is_active, $image_url]);
                        $new_id = (int) $conn->lastInsertId();
                        sync_low_stock_notification_by_product($new_id, $stock);
                        header("Location: " . BASE_URL . "pos/products?saved=added#product-list"); exit;
                    }
                } catch (PDOException $e) {
                    $message = "<div class='alert error'>Database save failure. Could not save product. Please try again.</div>";
                }
            }
        }
    }

    if ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            try {
                $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
                $stmt->execute([$id]);
                header("Location: " . BASE_URL . "pos/products?saved=removed#product-list"); exit;
            } catch (PDOException $e) {
                $message = "<div class='alert error'>Database save failure. Could not remove product.</div>";
            }
        }
    }

    // Restocking a product per branch. This is separate from the "Stock"
    // field on the Add/Edit Product form above, which only ever touches
    // products.stock - a column the cashier (sales_transaction.php) does
    // NOT read. The cashier checks/deducts branch_inventory.stock instead,
    // and until now nothing in the UI could write to that table, so a
    // product could never be restocked once it hit 0 at a branch.
    if ($action === 'update_branch_stock') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $branch_id  = filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT);
        $stock      = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);

        if (!$product_id || !$branch_id || $stock === false || $stock < 0) {
            $message = "<div class='alert error'>Please enter a valid stock quantity.</div>";
        } else {
            try {
                $check = $conn->prepare("SELECT branch_inventory_id, stock FROM branch_inventory WHERE product_id = ? AND branch_id = ?");
                $check->execute([$product_id, $branch_id]);
                $existingRow = $check->fetch(PDO::FETCH_ASSOC);
                $oldStock = $existingRow ? (int) $existingRow['stock'] : 0;

                if ($existingRow) {
                    $upd = $conn->prepare("UPDATE branch_inventory SET stock = ? WHERE branch_inventory_id = ?");
                    $upd->execute([$stock, $existingRow['branch_inventory_id']]);
                } else {
                    $ins = $conn->prepare("INSERT INTO branch_inventory (branch_id, product_id, stock) VALUES (?, ?, ?)");
                    $ins->execute([$branch_id, $product_id, $stock]);
                }

                // Keep the existing low-stock alert system in sync, the same
                // way sales_transaction.php does after a sale deducts stock.
                sync_low_stock_notification_by_product($product_id, $stock);

                // Only notify Inventory Staff if this update is what just
                // dropped the product to 0 (old stock was > 0). Prevents
                // re-notifying every time the row is saved while already at 0.
                if ($oldStock > 0 && $stock <= 0) {
                    notify_inventory_out_of_stock($product_id, $branch_id);
                }

                header("Location: " . BASE_URL . "pos/products?saved=stock#product-list"); exit;
            } catch (PDOException $e) {
                $message = "<div class='alert error'>Could not update branch stock. Please try again.</div>";
            }
        }
    }
}

$edit_id = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($edit_id) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_product = $stmt->fetch(PDO::FETCH_ASSOC);
}

$search        = trim($_GET['search'] ?? '');
$filter_cat    = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$filter_status = $_GET['status'] ?? '';
$search_found  = true; // default

// Shared query string used to preserve the active search/category/status
// filters when linking back into this same page (e.g. from the Edit
// button). Previously only `search` was carried over, so editing a
// product from a filtered list silently dropped the category/status
// filter on return.
$filter_query_parts = [];
if ($search !== '') {
    $filter_query_parts[] = 'search=' . urlencode($search);
}
if ($filter_cat) {
    $filter_query_parts[] = 'category=' . urlencode($filter_cat);
}
if ($filter_status !== '') {
    $filter_query_parts[] = 'status=' . urlencode($filter_status);
}
$filter_query_string = $filter_query_parts ? '&' . implode('&', $filter_query_parts) : '';

try {
    $categories = $conn->query("SELECT id, name FROM menu_categories ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

    $sql    = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN menu_categories c ON c.id = p.category_id WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $sql      .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
        $params[]  = "%{$search}%";
        $params[]  = "%{$search}%";
    }
    if ($filter_cat) {
        $sql      .= " AND p.category_id = ?";
        $params[]  = $filter_cat;
    }
    if ($filter_status === 'active') {
        $sql .= " AND p.is_active = 1";
    } elseif ($filter_status === 'inactive') {
        $sql .= " AND p.is_active = 0";
    }

    $sql .= " ORDER BY p.is_active DESC, p.name";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $search_found = !($search !== '' && empty($products));

    // Per-branch stock (branch_inventory) for every product currently
    // shown, keyed by product_id then branch_id. This is what the cashier
    // actually checks/deducts - separate from the products.stock column
    // above. Products with no branch_inventory row yet (e.g. added before
    // a branch existed) are treated as 0 stock for that branch.
    $branches = $conn->query("SELECT branch_id, branch_name FROM branches WHERE status = 'ACTIVE' ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);

    $branchStockMap = [];
    if ($products) {
        $productIds = array_column($products, 'id');
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $biStmt = $conn->prepare("SELECT product_id, branch_id, stock, reorder_level FROM branch_inventory WHERE product_id IN ($placeholders)");
        $biStmt->execute($productIds);
        foreach ($biStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $branchStockMap[$row['product_id']][$row['branch_id']] = $row;
        }
    }

} catch (PDOException $e) {
    $categories     = [];
    $products       = [];
    $branches       = [];
    $branchStockMap = [];
    $message        = "<div class='alert error'>Database retrieval failure. Could not load products. Please refresh or try again.</div>";
}

$pageTitle = 'Product Management';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">
<div class="mb-4">
    <h1 class="h4 fw-bold mb-1">Product Management</h1>
</div>
<?php
$saved = $_GET['saved'] ?? '';
if ($saved === 'updated') $message = "<div class='alert success'>✅ Product updated successfully.</div>";
elseif ($saved === 'added')   $message = "<div class='alert success'>✅ Product added successfully.</div>";
elseif ($saved === 'removed') $message = "<div class='alert success'>✅ Product removed from active menu.</div>";
elseif ($saved === 'stock')   $message = "<div class='alert success'>✅ Branch stock updated successfully.</div>";
?>
<?= $message ?>

<section class="panel">
    <h2><?= $edit_product ? 'Edit Product' : 'Add Product' ?></h2>
    <form method="POST" enctype="multipart/form-data" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= h($edit_product['id'] ?? '') ?>">
        <input type="hidden" name="current_image_url" value="<?= h($edit_product['image_url'] ?? '') ?>">

        <div>
            <label for="sku">SKU</label>
            <input id="sku" name="sku" value="<?= h($edit_product['sku'] ?? '') ?>" placeholder="BRG001">
        </div>
        <div>
            <label for="name">Product Name</label>
            <input id="name" name="name" value="<?= h($edit_product['name'] ?? '') ?>" required>
        </div>
        <div>
            <label for="category_id">Category</label>
            <select id="category_id" name="category_id">
                <option value="">No category</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['id']) ?>" <?= (($edit_product['category_id'] ?? '') == $cat['id']) ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="price">Price (₱)</label>
            <input id="price" name="price" type="number" step="0.01" min="0" value="<?= h($edit_product['price'] ?? '') ?>" required>
        </div>
        <div>
            <label for="stock">Stock</label>
            <?php
                // New products now start at 0 stock instead of a hardcoded
                // 999 placeholder, so a freshly added item isn't sellable
                // (and doesn't trip a false "in stock" state) until someone
                // deliberately sets a real quantity.
                $stock_value = array_key_exists('stock', (array) $edit_product) ? $edit_product['stock'] : 0;
            ?>
            <input id="stock" name="stock" type="number" min="0" value="<?= h($stock_value) ?>" required>
        </div>

        <div style="grid-column: 1 / -1;">
            <label for="product_image">Product Image (JPG/PNG/WEBP, max 2MB)</label>
            <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                <?php if (!empty($edit_product['image_url'])): ?>
                    <img src="<?= h($edit_product['image_url']) ?>" alt="Current image"
                         style="width:80px; height:80px; object-fit:cover; border-radius:14px; border:1.5px solid #e6d8c5; box-shadow:0 2px 8px rgba(42,24,16,0.06);">
                    <span class="muted" style="font-size:13px;">Current image (upload a new one to replace)</span>
                <?php endif; ?>
                <input id="product_image" name="product_image" type="file" accept="image/*"
                       style="flex:1; min-width:200px;" onchange="previewImage(this)">
                <img id="image_preview" src="" alt="" style="display:none; width:80px; height:80px; object-fit:cover; border-radius:14px; border:1.5px solid #e6d8c5; box-shadow:0 2px 8px rgba(42,24,16,0.06);">
            </div>
        </div>

        <div>
            <label><input type="checkbox" name="is_active" style="width:auto;" <?= (($edit_product['is_active'] ?? 1) ? 'checked' : '') ?>> Active</label>
            <button type="submit" style="margin-top:8px;"><?= $edit_product ? 'Save Changes' : 'Add Product' ?></button>
            <?php if ($edit_product): ?>
                <a class="button secondary" href="<?= BASE_URL ?>pos/products<?= $filter_query_string ? '?' . ltrim($filter_query_string, '&') : '' ?>" style="margin-top:8px;">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<script>
function previewImage(input) {
    const preview = document.getElementById('image_preview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<a name="product-list" id="product-list"></a>
<section class="panel" style="margin-top:18px;">
    <h2>Product List</h2>

    <form method="GET" class="form-grid" style="margin-bottom:16px;">
        <div>
            <label for="search">Search Product</label>
            <input id="search" name="search" value="<?= h($search) ?>" placeholder="Name or SKU...">
        </div>
        <div>
            <label for="category">Category</label>
            <select id="category" name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['id']) ?>" <?= $filter_cat == $cat['id'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="active"   <?= $filter_status === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div style="display:flex; gap:8px; align-items:flex-end;">
            <button type="submit">Search</button>
            <?php if ($search || $filter_cat || $filter_status): ?>
                <a class="button secondary" href="<?= BASE_URL ?>pos/products">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($search !== '' && !$search_found): ?>
        <div class="alert error" style="margin-bottom:14px;">
            No products found for "<strong><?= h($search) ?></strong>". Try a different name or SKU.
        </div>
    <?php elseif ($search !== '' && $search_found): ?>
        <div class="alert success" style="margin-bottom:14px;">
            <?= count($products) ?> product(s) found for "<strong><?= h($search) ?></strong>".
        </div>
    <?php endif; ?>

    <table class="prod-table">
        <thead>
            <tr>
                <th style="width:70px;">Image</th>
                <th>SKU</th>
                <th>Name</th>
                <th>Category</th>
                <th>Price</th>
                <th>Stock</th>
                <th>Branch Stock</th>
                <th>Status</th>
                <th style="min-width:130px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$products): ?>
            <tr><td colspan="9" style="text-align:center;" class="muted">No products found.</td></tr>
        <?php endif; ?>
        <?php foreach ($products as $product): ?>
            <tr style="<?= !$product['is_active'] ? 'opacity:0.5;' : '' ?>">
                <td style="width:70px;">
                    <?php if (!empty($product['image_url'])): ?>
                        <img src="<?= h($product['image_url']) ?>" alt="<?= h($product['name']) ?>"
                             style="width:52px; height:52px; object-fit:cover; border-radius:12px; border:1.5px solid #e6d8c5; display:block;">
                    <?php else: ?>
                        <div style="width:52px; height:52px; background:#eef1e4; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; border:1.5px solid #e6d8c5;">🍽️</div>
                    <?php endif; ?>
                </td>
                <td style="font-size:12.5px; color:#7a6558;"><?= h($product['sku']) ?></td>
                <td style="font-weight:600;"><?= h($product['name']) ?></td>
                <td style="font-size:13px;"><?= h($product['category_name'] ?? '') ?></td>
                <td style="font-weight:700; color:#5e6b46;">₱<?= money($product['price']) ?></td>
                <td style="text-align:center;"><?= h($product['stock']) ?></td>
                <td>
                    <?php if (!$branches): ?>
                        <span class="muted" style="font-size:12px;">No branches.</span>
                    <?php else: ?>
                        <div class="branch-stock-list">
                        <?php foreach ($branches as $branch):
                            $bi = $branchStockMap[$product['id']][$branch['branch_id']] ?? null;
                            $branchStock   = $bi ? (int) $bi['stock']         : 0;
                            $reorderLevel  = $bi ? (int) $bi['reorder_level'] : 10;
                            $isLow = $branchStock <= $reorderLevel;
                        ?>
                            <form method="POST" class="branch-stock-row">
                                <input type="hidden" name="csrf_token"  value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action"      value="update_branch_stock">
                                <input type="hidden" name="product_id" value="<?= h($product['id']) ?>">
                                <input type="hidden" name="branch_id"  value="<?= h($branch['branch_id']) ?>">
                                <span class="branch-label"><?= h($branch['branch_name']) ?></span>
                                <input type="number" name="stock" min="0" value="<?= h($branchStock) ?>"
                                       class="branch-stock-input" title="<?= h($branch['branch_name']) ?> stock">
                                <?php if ($branchStock <= 0): ?>
                                    <span class="stock-badge out">Out</span>
                                <?php elseif ($isLow): ?>
                                    <span class="stock-badge low">Low</span>
                                <?php else: ?>
                                    <span class="stock-badge-spacer"></span>
                                <?php endif; ?>
                                <button class="btn-stock-save" type="submit" title="Update <?= h($branch['branch_name']) ?> stock">Save</button>
                            </form>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= $product['is_active']
                    ? '<span class="badge" style="background:#dcfce7; color:#166534; font-size:11px; font-weight:700; border:1px solid #bbf7d0; padding:4px 10px; border-radius:50px;">Active</span>'
                    : '<span class="badge" style="background:#fee2e2; color:#991b1b; font-size:11px; font-weight:700; border:1px solid #fecaca; padding:4px 10px; border-radius:50px;">Inactive</span>' ?></td>
                <td>
                    <div class="actions">
                        <a class="button secondary" href="<?= BASE_URL ?>pos/products?edit=<?= h($product['id']) ?><?= $filter_query_string ?>" style="padding:6px 14px; font-size:12px;">Edit</a>
                        <form method="POST" onsubmit="return confirm('Remove this product from active menu?');">
                            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= h($product['id']) ?>">
                            <button class="danger" type="submit" style="padding:6px 14px; font-size:12px;">Remove</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<script>
if (window.location.hash === '#product-list') {
    document.addEventListener('DOMContentLoaded', function() {
        var el = document.getElementById('product-list');
        if (el) { setTimeout(function(){ el.scrollIntoView({behavior:'smooth', block:'start'}); }, 150); }
    });
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>