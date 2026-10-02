<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Inventory Staff manage the supplier master list; the Owner views it.
// To let the Owner edit suppliers too, add ROLE_OWNER to
// CAPABILITY_ROLES['suppliers.manage'] in config/constants.php - nothing
// else in this file needs to change.
$canManage = userCanDo('suppliers.manage');
$pageTitle = $canManage ? 'Manage Suppliers' : 'Suppliers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
}

// ---------- Handle Add / Edit ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCapability('suppliers.manage');
    $supplierIdInput = $_POST['supplier_id'] ?? '';
    $supplierId = $supplierIdInput === '' ? null : filter_var($supplierIdInput, FILTER_VALIDATE_INT);
    $textInput = static fn($key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : "\0";
    $name          = $textInput('supplier_name');
    $contactPerson = $textInput('contact_person');
    $phone         = $textInput('phone');
    $email         = $textInput('email');
    $address       = $textInput('address');
    $status        = $_POST['status'] ?? 'Active';

    $phoneValid = $phone === '' || preg_match('/^\d{1,11}$/', $phone);
    $emailValid = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL);
    if (($supplierId !== null && ($supplierId === false || $supplierId < 1)) || $name === '' || strlen($name) > 150 || strlen($contactPerson) > 100 || strlen($address) > 255 || strlen($email) > 100 || !$phoneValid || !$emailValid || !in_array($status, ['Active', 'Inactive'], true)) {
        setFlash('danger', 'Enter a supplier name, valid email, and a phone number containing only digits (maximum 11 digits).');
    } else {
        if ($supplierId !== null) {
            $stmt = $pdo->prepare("UPDATE suppliers SET supplier_name=?, contact_person=?, phone=?, email=?, address=?, status=? WHERE supplier_id=?");
            $stmt->execute([$name, $contactPerson, $phone, $email, $address, $status, $supplierId]);
            setFlash('success', 'Supplier updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, status) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $contactPerson, $phone, $email, $address, $status]);
            setFlash('success', 'Supplier added successfully.');
        }
        logActivity($pdo, $_SESSION['user_id'], 'Procurement', 'Saved supplier: ' . $name);
    }
    redirect('modules/procurement/suppliers.php');
}

// ---------- Handle Delete ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_supplier') {
    requireCapability('suppliers.manage');
    $id = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        setFlash('danger', 'Invalid supplier.');
        redirect('modules/procurement/suppliers.php');
    }
    $check = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = ?");
    $check->execute([$id]);
    if ($check->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete: this supplier has purchase order history. Set status to Inactive instead.');
    } else {
        $pdo->prepare("DELETE FROM suppliers WHERE supplier_id = ?")->execute([$id]);
        setFlash('success', 'Supplier deleted.');
    }
    redirect('modules/procurement/suppliers.php');
}

$search = trim($_GET['search'] ?? '');
$sql = "SELECT s.*, (SELECT COUNT(*) FROM purchase_orders po WHERE po.supplier_id = s.supplier_id) AS po_count
        FROM suppliers s WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= " AND (s.supplier_name LIKE ? OR s.contact_person LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= " ORDER BY s.supplier_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <a href="<?= BASE_URL ?>procurement" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left"></i> Back to Purchase Orders
    </a>
    <?php if ($canManage): ?>
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Supplier
    </button>
    <?php endif; ?>
</div>

<?php if (!$canManage): ?><?= readOnlyNotice('suppliers') ?><?php endif; ?>

<div class="card-panel p-3 mb-3">
    <form method="GET" class="row g-2">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control" placeholder="Search supplier or contact person..." value="<?= clean($search) ?>">
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Search</button>
        </div>
    </form>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Supplier Name</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th class="text-center">Purchase Orders</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No suppliers found.</td></tr>
                <?php else: foreach ($suppliers as $s): ?>
                    <tr>
                        <td class="fw-medium"><?= clean($s['supplier_name']) ?></td>
                        <td><?= clean($s['contact_person']) ?: '-' ?></td>
                        <td><?= clean($s['phone']) ?: '-' ?></td>
                        <td><?= clean($s['email']) ?: '-' ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $s['po_count'] ?></span></td>
                        <td class="text-center">
                            <span class="badge <?= $s['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= $s['status'] ?></span>
                        </td>
                        <td class="text-end">
                            <?php if ($canManage): ?>
                            <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this supplier?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="supplier_id" value="<?= (int)$s['supplier_id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete supplier"><i class="bi bi-trash"></i></button>
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
<!-- Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierModalLabel">Add Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="supplier_id" id="supplier_id">

                <div class="mb-3">
                    <label class="form-label">Supplier Name</label>
                    <input type="text" name="supplier_name" id="supplier_name" class="form-control" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" name="contact_person" id="contact_person" class="form-control">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" id="phone" class="form-control" inputmode="numeric" pattern="[0-9]{0,11}" maxlength="11" title="Use digits only, up to 11 digits">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <textarea name="address" id="address" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-3" id="statusWrapper" style="display:none;">
                    <label class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
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
    document.getElementById('supplierModalLabel').innerText = 'Add Supplier';
    document.getElementById('supplier_id').value = '';
    document.getElementById('supplier_name').value = '';
    document.getElementById('contact_person').value = '';
    document.getElementById('phone').value = '';
    document.getElementById('email').value = '';
    document.getElementById('address').value = '';
    document.getElementById('statusWrapper').style.display = 'none';
}
function openEditModal(data) {
    document.getElementById('supplierModalLabel').innerText = 'Edit Supplier';
    document.getElementById('supplier_id').value = data.supplier_id;
    document.getElementById('supplier_name').value = data.supplier_name;
    document.getElementById('contact_person').value = data.contact_person ?? '';
    document.getElementById('phone').value = data.phone ?? '';
    document.getElementById('email').value = data.email ?? '';
    document.getElementById('address').value = data.address ?? '';
    document.getElementById('status').value = data.status;
    document.getElementById('statusWrapper').style.display = 'block';
    new bootstrap.Modal(document.getElementById('supplierModal')).show();
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
