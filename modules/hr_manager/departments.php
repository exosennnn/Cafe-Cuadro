<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER, ROLE_OWNER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create' || $action === 'update') {
      $name = trim($_POST['department_name'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
      $managerInput = $_POST['manager_id'] ?? '';
      $managerId = $managerInput === '' ? null : filter_var($managerInput, FILTER_VALIDATE_INT);
      $errors = [];

      if ($action === 'update' && ($departmentId === false || $departmentId < 1)) {
        $errors[] = 'Please select a valid department.';
      }
      if ($name === '' || strlen($name) > 100) {
        $errors[] = 'Department name is required and must be 100 characters or fewer.';
      }
      if (strlen($desc) > 255) {
        $errors[] = 'Description must be 255 characters or fewer.';
      }
      if ($managerId !== null && ($managerId === false || $managerId < 1)) {
        $errors[] = 'Please select a valid department manager.';
      } elseif ($managerId !== null) {
        $managerCheck = $pdo->prepare("SELECT e.employee_id FROM employees e JOIN users u ON e.user_id=u.user_id
          WHERE e.employee_id=? AND u.role_id=? AND u.status='ACTIVE' AND e.employment_status='ACTIVE'");
        $managerCheck->execute([$managerId, ROLE_EMPLOYEE_MANAGER]);
        if (!$managerCheck->fetch()) {
          $errors[] = 'The selected department manager is not an active Employee Manager.';
        }
      }

      $existing = null;
      if ($action === 'update' && empty($errors)) {
        $existingStmt = $pdo->prepare('SELECT * FROM departments WHERE department_id=?');
        $existingStmt->execute([$departmentId]);
        $existing = $existingStmt->fetch();
        if (!$existing) {
          $errors[] = 'Department not found.';
        }
      }
      if (empty($errors)) {
        $duplicate = $pdo->prepare('SELECT department_id FROM departments WHERE department_name=? AND department_id<>?');
        $duplicate->execute([$name, $action === 'update' ? $departmentId : 0]);
        if ($duplicate->fetch()) {
          $errors[] = 'A department with that name already exists.';
        }
      }

        // There's only one Employee Manager for the whole company (they
        // oversee every department - Barista, Cashier, Crew, etc.), so
        // auto-assign them here instead of asking HR to pick per department.
        // Only falls back to the manual dropdown selection if that's not
        // possible (zero or more than one active Employee Manager on file).
        $soleManagerId = getSoleEmployeeManagerId($pdo);
      $managerId = $soleManagerId ?? $managerId;
      if (empty($errors) && $action === 'create') {
            $pdo->prepare("INSERT INTO departments (department_name, description, manager_id) VALUES (?,?,?)")->execute([$name, $desc, $managerId]);
            setFlash('success', 'Department created.');
        logAudit($pdo, $userId, 'CREATE_DEPARTMENT', 'Departments', "name=$name manager_id=" . ($managerId ?? 'NULL'));
      } elseif (empty($errors)) {
        $pdo->prepare("UPDATE departments SET department_name=?, description=?, manager_id=? WHERE department_id=?")->execute([$name, $desc, $managerId, $departmentId]);
            setFlash('success', 'Department updated.');
        $changed = [];
        if ((string)$existing['department_name'] !== $name) { $changed[] = 'department_name'; }
        if ((string)($existing['description'] ?? '') !== $desc) { $changed[] = 'description'; }
        if ((string)($existing['manager_id'] ?? '') !== (string)($managerId ?? '')) { $changed[] = 'manager_id'; }
        logAudit($pdo, $userId, 'UPDATE_DEPARTMENT', 'Departments', "department_id=$departmentId changed=" . implode(',', $changed));
      } else {
        foreach ($errors as $error) {
          setFlash('error', $error);
        }
        }
    } elseif ($action === 'delete') {
      $id = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
      $department = null;
      if ($id !== false && $id > 0) {
        $departmentStmt = $pdo->prepare('SELECT department_id, department_name FROM departments WHERE department_id=?');
        $departmentStmt->execute([$id]);
        $department = $departmentStmt->fetch();
      }
      if (!$department) {
        setFlash('error', 'Department not found.');
      } else {
        $related = [];
        foreach (['employees' => 'department_id', 'job_vacancies' => 'department_id', 'announcements' => 'target_department'] as $table => $column) {
          $relatedStmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column=?");
          $relatedStmt->execute([$id]);
          if ((int)$relatedStmt->fetchColumn() > 0) { $related[] = $table; }
        }
        if ($related) {
          setFlash('error', 'This department cannot be deleted because it has related records (' . implode(', ', $related) . '). Reassign or archive those records first.');
          logAudit($pdo, $userId, 'BLOCK_DELETE_DEPARTMENT', 'Departments', "department_id=$id related=" . implode(',', $related));
        } else {
          $pdo->prepare("DELETE FROM departments WHERE department_id=?")->execute([$id]);
          setFlash('success', 'Department deleted.');
          logAudit($pdo, $userId, 'DELETE_DEPARTMENT', 'Departments', "department_id=$id");
        }
      }
    }
    redirect('modules/hr_manager/departments.php');
}

// Keep every department's manager in sync with the sole Employee Manager
// (if there is exactly one) - covers departments created before this
// person was hired, or before this auto-assignment existed.
syncDepartmentsToSoleEmployeeManager($pdo);
$soleManagerId = getSoleEmployeeManagerId($pdo);

$departments = $pdo->query("SELECT d.*, u.first_name, u.last_name,
    (SELECT COUNT(*) FROM employees WHERE department_id=d.department_id) AS emp_count
    FROM departments d LEFT JOIN employees e ON d.manager_id=e.employee_id LEFT JOIN users u ON e.user_id=u.user_id
    ORDER BY d.department_name")->fetchAll();

$managers = $pdo->query("SELECT e.employee_id, u.first_name, u.last_name FROM employees e JOIN users u ON e.user_id=u.user_id
    JOIN roles r ON u.role_id=r.role_id WHERE r.role_name='EMPLOYEE_MANAGER'")->fetchAll();

$pageTitle = 'Manage Departments';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4>Manage Departments</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="resetForm()"><i class="bi bi-plus-circle"></i> Add Department</button>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="departmentsTable">
      <thead><tr><th>Department</th><th>Description</th><th>Manager</th><th>Employees</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td><?= e($d['department_name']) ?></td>
          <td><?= e($d['description']) ?></td>
          <td><?= $d['first_name'] ? e($d['first_name'].' '.$d['last_name']) : '-' ?></td>
          <td><span class="badge bg-info"><?= (int)$d['emp_count'] ?></span></td>
          <td>
            <div class="d-flex gap-1">
              <button class="btn btn-sm btn-outline-primary" title="Edit" onclick='editDept(<?= json_encode($d) ?>)'><i class="bi bi-pencil"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this department only if it has no related records?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="department_id" value="<?= $d['department_id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#departmentsTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>

<div class="modal fade" id="deptModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" id="formAction" value="create">
    <input type="hidden" name="department_id" id="department_id">
    <div class="modal-header"><h5 class="modal-title" id="modalTitle">Add Department</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Department Name</label><input type="text" name="department_name" id="department_name" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Description</label><textarea name="description" id="description" class="form-control"></textarea></div>
      <div class="mb-3">
        <label class="form-label">Department Manager</label>
        <?php if ($soleManagerId !== null): ?>
          <?php $soleManagerName = ''; foreach ($managers as $m) { if ((int)$m['employee_id'] === $soleManagerId) { $soleManagerName = $m['first_name'].' '.$m['last_name']; break; } } ?>
          <input type="text" class="form-control" value="<?= e($soleManagerName) ?>" disabled>
          <div class="form-text">Auto-assigned - <?= e($soleManagerName) ?> is the only Employee Manager and oversees every department.</div>
        <?php else: ?>
          <select name="manager_id" id="manager_id" class="form-select">
            <option value="">-- None --</option>
            <?php foreach ($managers as $m): ?><option value="<?= $m['employee_id'] ?>"><?= e($m['first_name'].' '.$m['last_name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
  </form>
</div></div></div>

<script>
function resetForm() {
  document.getElementById('modalTitle').innerText = 'Add Department';
  document.getElementById('formAction').value = 'create';
  document.querySelector('#deptModal form').reset();
}
function editDept(d) {
  document.getElementById('modalTitle').innerText = 'Edit Department';
  document.getElementById('formAction').value = 'update';
  document.getElementById('department_id').value = d.department_id;
  document.getElementById('department_name').value = d.department_name;
  document.getElementById('description').value = d.description || '';
  var managerSelect = document.getElementById('manager_id');
  if (managerSelect) { managerSelect.value = d.manager_id || ''; }
  new bootstrap.Modal(document.getElementById('deptModal')).show();
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>