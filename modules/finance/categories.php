<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Manage Finance Categories';

// ---------- Handle Add / Edit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCapability('finance.manage');
    $catId = $_POST['fin_category_id'] ?? '';
    $name  = trim($_POST['category_name'] ?? '');
    $type  = $_POST['type'] ?? 'Expense';

    if ($name === '' || !in_array($type, ['Income', 'Expense'])) {
        setFlash('danger', 'Category name and a valid type are required.');
    } else {
        if ($catId) {
            $isSystemCheck = $pdo->prepare("SELECT is_system, type FROM finance_categories WHERE fin_category_id = ?");
            $isSystemCheck->execute([$catId]);
            $existing = $isSystemCheck->fetch();
            if ($existing && $existing['is_system']) {
                // System categories (Sales, Purchases) keep their type locked; only the name can change
                $pdo->prepare("UPDATE finance_categories SET category_name = ? WHERE fin_category_id = ?")
                    ->execute([$name, $catId]);
            } else {
                $pdo->prepare("UPDATE finance_categories SET category_name = ?, type = ? WHERE fin_category_id = ?")
                    ->execute([$name, $type, $catId]);
            }
            setFlash('success', 'Category updated successfully.');
        } else {
            $pdo->prepare("INSERT INTO finance_categories (category_name, type, is_system) VALUES (?, ?, 0)")
                ->execute([$name, $type]);
            setFlash('success', 'Category added successfully.');
        }
        logActivity($pdo, $_SESSION['user_id'], 'Finance', 'Saved finance category: ' . $name);
    }
    redirect('modules/finance/categories.php');
}

// ---------- Handle Delete ----------
if (isset($_GET['delete'])) {
    requireCapability('finance.manage');
    $id = (int)$_GET['delete'];
    $check = $pdo->prepare("SELECT is_system FROM finance_categories WHERE fin_category_id = ?");
    $check->execute([$id]);
    $cat = $check->fetch();

    $useCheck = $pdo->prepare("SELECT COUNT(*) FROM finance_transactions WHERE fin_category_id = ?");
    $useCheck->execute([$id]);

    if (!$cat) {
        setFlash('danger', 'Category not found.');
    } elseif ($cat['is_system']) {
        setFlash('danger', 'This is a system category and cannot be deleted.');
    } elseif ($useCheck->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: this category already has transactions recorded.');
    } else {
        $pdo->prepare("DELETE FROM finance_categories WHERE fin_category_id = ?")->execute([$id]);
        setFlash('success', 'Category deleted.');
    }
    redirect('modules/finance/categories.php');
}

$categories = $pdo->query("
    SELECT fc.*, (SELECT COUNT(*) FROM finance_transactions ft WHERE ft.fin_category_id = fc.fin_category_id) AS txn_count
    FROM finance_categories fc ORDER BY fc.type ASC, fc.category_name ASC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
$financeTab = 'categories';
include __DIR__ . '/../../includes/finance_nav.php';
?>

<div class="d-flex justify-content-end align-items-center mb-3">
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#catModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Category
    </button>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Category Name</th>
                    <th class="text-center">Type</th>
                    <th class="text-center">System</th>
                    <th class="text-center">Transactions</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No finance categories yet.</td></tr>
                <?php else: foreach ($categories as $c): ?>
                    <tr>
                        <td class="fw-medium"><?= clean($c['category_name']) ?></td>
                        <td class="text-center">
                            <span class="badge <?= $c['type'] === 'Income' ? 'bg-success' : 'bg-danger' ?>"><?= $c['type'] ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($c['is_system']): ?>
                                <i class="bi bi-lock-fill text-muted" title="System category"></i>
                            <?php else: ?>
                                <i class="bi bi-dash text-muted"></i>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $c['txn_count'] ?></span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($c) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if (!$c['is_system']): ?>
                                <a href="?delete=<?= $c['fin_category_id'] ?>" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete this category?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <div class="modal-header">
                <h5 class="modal-title" id="catModalLabel">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="fin_category_id" id="fin_category_id">
                <div class="mb-3">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="category_name" id="category_name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select name="type" id="type" class="form-select">
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                    </select>
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
    document.getElementById('catModalLabel').innerText = 'Add Category';
    document.getElementById('fin_category_id').value = '';
    document.getElementById('category_name').value = '';
    document.getElementById('type').value = 'Expense';
    document.getElementById('type').disabled = false;
    document.getElementById('systemNote').style.display = 'none';
}
function openEditModal(data) {
    document.getElementById('catModalLabel').innerText = 'Edit Category';
    document.getElementById('fin_category_id').value = data.fin_category_id;
    document.getElementById('category_name').value = data.category_name;
    document.getElementById('type').value = data.type;
    document.getElementById('type').disabled = data.is_system == 1;
    document.getElementById('systemNote').style.display = data.is_system == 1 ? 'block' : 'none';
    new bootstrap.Modal(document.getElementById('catModal')).show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
