<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Manage Units';

// Units are part of Ingredients & Stock: Inventory Staff manage, Owner views.
$canManage = userCanDo('inventory.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    requireCapability('inventory.manage');
    $unitInput = $_POST['unit_id'] ?? '';
    $unitId = $unitInput === '' ? null : filter_var($unitInput, FILTER_VALIDATE_INT);
    $name = trim($_POST['unit_name'] ?? '');
    $symbol = trim($_POST['unit_symbol'] ?? '');

    $validId = $unitId === null || ($unitId !== false && $unitId > 0);
    if (!$validId || $name === '' || $symbol === '' || strlen($name) > 50 || strlen($symbol) > 10 || preg_match('/[\x00-\x1F\x7F]/', $name . $symbol)) {
        setFlash('danger', 'Unit name (50 characters maximum) and symbol (10 characters maximum) are required.');
    } else {
        if ($unitId !== null) {
            $stmt = $pdo->prepare("UPDATE units SET unit_name = ?, unit_symbol = ? WHERE unit_id = ?");
            $stmt->execute([$name, $symbol, $unitId]);
            setFlash('success', 'Unit updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO units (unit_name, unit_symbol) VALUES (?, ?)");
            $stmt->execute([$name, $symbol]);
            setFlash('success', 'Unit added successfully.');
        }
        logActivity($pdo, $_SESSION['user_id'], 'Inventory', 'Saved unit: ' . $name);
    }
    redirect('modules/inventory/units.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_unit') {
    requireCapability('inventory.manage');
    $id = filter_var($_POST['unit_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        setFlash('danger', 'Invalid unit.');
        redirect('modules/inventory/units.php');
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM items WHERE unit_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: this unit is used by existing items.');
    } else {
        $pdo->prepare("DELETE FROM units WHERE unit_id = ?")->execute([$id]);
        setFlash('success', 'Unit deleted.');
    }
    redirect('modules/inventory/units.php');
}

$units = $pdo->query("
    SELECT u.*, (SELECT COUNT(*) FROM items i WHERE i.unit_id = u.unit_id) AS item_count
    FROM units u ORDER BY u.unit_name ASC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <a href="<?= BASE_URL ?>inventory" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left"></i> Back to Items
    </a>
    <?php if ($canManage): ?>
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#unitModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Unit
    </button>
    <?php endif; ?>
</div>

<?php if (!$canManage): ?><?= readOnlyNotice('units of measure') ?><?php endif; ?>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Unit Name</th>
                    <th>Symbol</th>
                    <th class="text-center">Items Using</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($units)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No units yet.</td></tr>
                <?php else: foreach ($units as $u): ?>
                    <tr>
                        <td><?= clean($u['unit_name']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= clean($u['unit_symbol']) ?></span></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $u['item_count'] ?></span></td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                            <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this unit?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_unit"><input type="hidden" name="unit_id" value="<?= (int)$u['unit_id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete unit"><i class="bi bi-trash"></i></button>
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
<div class="modal fade" id="unitModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="unitModalLabel">Add Unit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="unit_id" id="unit_id">
                <div class="mb-3">
                    <label class="form-label">Unit Name</label>
                    <input type="text" name="unit_name" id="unit_name" class="form-control" required placeholder="e.g. Kilogram">
                </div>
                <div class="mb-3">
                    <label class="form-label">Symbol</label>
                    <input type="text" name="unit_symbol" id="unit_symbol" class="form-control" required placeholder="e.g. kg">
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
    document.getElementById('unitModalLabel').innerText = 'Add Unit';
    document.getElementById('unit_id').value = '';
    document.getElementById('unit_name').value = '';
    document.getElementById('unit_symbol').value = '';
}
function openEditModal(data) {
    document.getElementById('unitModalLabel').innerText = 'Edit Unit';
    document.getElementById('unit_id').value = data.unit_id;
    document.getElementById('unit_name').value = data.unit_name;
    document.getElementById('unit_symbol').value = data.unit_symbol;
    new bootstrap.Modal(document.getElementById('unitModal')).show();
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
