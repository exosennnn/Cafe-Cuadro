<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Manage Categories';

// Categories are part of Ingredients & Stock: Inventory Staff manage,
// Owner views only.
$canManage = userCanDo('inventory.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

// Handle Add / Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    requireCapability('inventory.manage');
    $categoryInput = $_POST['category_id'] ?? '';
    $categoryId = $categoryInput === '' ? null : filter_var($categoryInput, FILTER_VALIDATE_INT);
    $name = trim($_POST['category_name'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    $validId = $categoryId === null || ($categoryId !== false && $categoryId > 0);
    if (!$validId || $name === '' || strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) || strlen($desc) > 255) {
        setFlash('danger', 'Enter a category name (100 characters maximum) and description (255 characters maximum).');
    } else {
        if ($categoryId !== null) {
            $stmt = $pdo->prepare("UPDATE categories SET category_name = ?, description = ? WHERE category_id = ?");
            $stmt->execute([$name, $desc, $categoryId]);
            setFlash('success', 'Category updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
            $stmt->execute([$name, $desc]);
            setFlash('success', 'Category added successfully.');
        }
        logActivity($pdo, $_SESSION['user_id'], 'Inventory', 'Saved category: ' . $name);
    }
    redirect('modules/inventory/categories.php');
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_category') {
    requireCapability('inventory.manage');
    $id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        setFlash('danger', 'Invalid category.');
        redirect('modules/inventory/categories.php');
    }
    // Prevent delete if items are using this category
    $check = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: this category is used by existing items.');
    } else {
        $pdo->prepare("DELETE FROM categories WHERE category_id = ?")->execute([$id]);
        setFlash('success', 'Category deleted.');
    }
    redirect('modules/inventory/categories.php');
}

$categories = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.category_id) AS item_count
    FROM categories c ORDER BY c.category_name ASC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <a href="<?= BASE_URL ?>inventory" class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left"></i> Back to Items
        </a>
    </div>
    <?php if ($canManage): ?>
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Category
    </button>
    <?php endif; ?>
</div>

<?php if (!$canManage): ?><?= readOnlyNotice('item categories') ?><?php endif; ?>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Category Name</th>
                    <th>Description</th>
                    <th class="text-center">Items Using</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No categories yet.</td></tr>
                <?php else: foreach ($categories as $c): ?>
                    <tr>
                        <td><?= clean($c['category_name']) ?></td>
                        <td class="text-muted"><?= clean($c['description']) ?: '-' ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $c['item_count'] ?></span></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                            <button class="btn btn-sm btn-outline-primary"
                                onclick='openEditModal(<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this category?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="category_id" value="<?= (int)$c['category_id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete category"><i class="bi bi-trash"></i></button>
                            </form>
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

<?php if ($canManage): ?>
<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalLabel">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="category_id" id="category_id">
                <div class="mb-3">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="category_name" id="category_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="2"></textarea>
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
    document.getElementById('categoryModalLabel').innerText = 'Add Category';
    document.getElementById('category_id').value = '';
    document.getElementById('category_name').value = '';
    document.getElementById('description').value = '';
}
function openEditModal(data) {
    document.getElementById('categoryModalLabel').innerText = 'Edit Category';
    document.getElementById('category_id').value = data.category_id;
    document.getElementById('category_name').value = data.category_name;
    document.getElementById('description').value = data.description ?? '';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
