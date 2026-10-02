<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
// Employee Manager oversees ALL employees company-wide, regardless of
// department/position (Barista, Cook, Cashier, etc. all report to them).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrfVerify();
  $action = $_POST['action'] ?? '';

  if ($action === 'update') {
    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
    $target = null;
    if ($employeeId !== false && $employeeId > 0) {
      $targetStmt = $pdo->prepare('SELECT e.user_id, e.shift, u.role_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=?');
      $targetStmt->execute([$employeeId]);
      $target = $targetStmt->fetch();
    }

    if (!$target || (int)$target['user_id'] === (int)$userId || in_array((int)$target['role_id'], [ROLE_HR_MANAGER, ROLE_OWNER], true)) {
      setFlash('error', 'You are not allowed to edit this employee record.');
      redirect('modules/employee_manager/employees.php');
    }

    $departmentInput = $_POST['department_id'] ?? '';
    $branchInput = $_POST['branch_id'] ?? '';
    $positionInput = $_POST['position'] ?? '';
    $shiftInput = $_POST['shift'] ?? '';
    $departmentId = null;
    $branchId = null;
    $position = is_string($positionInput) ? trim($positionInput) : '';
    $shift = is_string($shiftInput) ? $shiftInput : '';
    $errors = [];

    if ($departmentInput !== '') {
      if (!is_string($departmentInput) || !ctype_digit($departmentInput) || (int)$departmentInput < 1) {
        $errors[] = 'Please select a valid department.';
      } else {
        $departmentId = (int)$departmentInput;
        $check = $pdo->prepare('SELECT department_id FROM departments WHERE department_id=?');
        $check->execute([$departmentId]);
        if (!$check->fetchColumn()) { $errors[] = 'The selected department does not exist.'; }
      }
    }
    if ($branchInput !== '') {
      if (!is_string($branchInput) || !ctype_digit($branchInput) || (int)$branchInput < 1) {
        $errors[] = 'Please select a valid branch.';
      } else {
        $branchId = (int)$branchInput;
        $check = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id=?');
        $check->execute([$branchId]);
        if (!$check->fetchColumn()) { $errors[] = 'The selected branch does not exist.'; }
      }
    }
    if (strlen($position) > 100 || preg_match('/[\x00-\x1F\x7F]/', $position)) {
      $errors[] = 'Position must be 100 characters or fewer and cannot contain control characters.';
    }
    if (!isset(SHIFT_SCHEDULES[$shift])) {
      $errors[] = 'Please select a valid shift.';
    }

    if ($errors) {
      foreach ($errors as $error) { setFlash('error', $error); }
      redirect('modules/employee_manager/employees.php');
    }

    $pdo->prepare('UPDATE employees SET department_id=?, branch_id=?, position=?, shift=? WHERE employee_id=?')
      ->execute([$departmentId, $branchId, $position !== '' ? $position : null, $shift, $employeeId]);
    setFlash('success', 'Operational employee details updated.');
    logAudit($pdo, $userId, 'UPDATE_EMPLOYEE_OPERATIONS', 'Employee Records', "employee_id=$employeeId");

    if ($target['shift'] !== $shift) {
      createNotification($pdo, (int)$target['user_id'], 'Work Schedule Updated',
        'Your shift has been set to ' . shiftLabel($shift) . '. Check My Attendance for your Time In/Out window.',
        'SHIFT', (int)$employeeId);
    }
  }
  redirect('modules/employee_manager/employees.php');
}

$search = trim($_GET['search'] ?? '');
$where = '1=1'; $params = [];
if ($search !== '') { $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR e.employee_code LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; $params[]="%$search%"; }

$total = $pdo->prepare("SELECT COUNT(*) AS c FROM employees e JOIN users u ON e.user_id=u.user_id WHERE $where");
$total->execute($params);
$total = $total->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 10);

$stmt = $pdo->prepare("SELECT e.employee_id, e.employee_code, e.user_id AS account_user_id, e.department_id, e.branch_id,
        e.position, e.shift, e.employment_status, e.date_hired,
        u.role_id, u.first_name, u.last_name, u.email, u.phone, u.profile_photo,
        d.department_name, b.branch_name
    FROM employees e JOIN users u ON e.user_id=u.user_id LEFT JOIN departments d ON e.department_id=d.department_id
    LEFT JOIN branches b ON e.branch_id=b.branch_id
    WHERE $where ORDER BY u.first_name LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$employees = $stmt->fetchAll();
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$branches = $pdo->query("SELECT branch_id, branch_name FROM branches ORDER BY branch_name")->fetchAll();

$pageTitle = 'Department Employees';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-people-fill"></i></div>
  <div>
    <h4>Employees</h4>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search employee..." value="<?= e($search) ?>"></div>
  <div class="col-md-2"><button class="btn btn-outline-primary w-100"><i class="bi bi-search"></i></button></div>
</form>

<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="employeesTable">
      <thead><tr><th>Photo</th><th>Code</th><th>Name</th><th>Contact</th><th>Branch</th><th>Department</th><th>Position</th><th>Shift</th><th>Status</th><th>Date Hired</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $e): ?>
        <tr>
          <td><?= renderAvatar($e['profile_photo'], $e['first_name'], $e['last_name'], 36) ?></td>
          <td><?= e($e['employee_code']) ?></td>
          <td><?= e($e['first_name'].' '.$e['last_name']) ?></td>
          <td><?= e($e['email']) ?><br><small class="text-muted"><?= e($e['phone']) ?></small></td>
          <td><?= $e['branch_name'] ? '<span class="badge bg-light text-dark border">'.e($e['branch_name']).'</span>' : '<span class="text-muted">-</span>' ?></td>
          <td><?= e($e['department_name'] ?? '-') ?></td>
          <td><?= e($e['position']) ?></td>
          <td><small><?= e(shiftLabel($e['shift'] ?? null)) ?></small></td>
          <td><span class="badge <?= $e['employment_status']=='ACTIVE'?'bg-success':'bg-secondary' ?>"><?= e($e['employment_status']) ?></span></td>
          <td><?= fdate($e['date_hired']) ?></td>
          <td>
            <?php if ((int)$e['account_user_id'] !== (int)$userId && !in_array((int)$e['role_id'], [ROLE_HR_MANAGER, ROLE_OWNER], true)): ?>
              <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#employeeOperationsModal" data-employee="<?= e(json_encode($e)) ?>"><i class="bi bi-pencil"></i></button>
            <?php else: ?>
              <span class="small text-muted">View only</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($employees)): ?><tr><td colspan="11" class="text-center text-muted">No employees found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>

<div class="modal fade" id="employeeOperationsModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="employee_id" id="operations_employee_id">
      <div class="modal-header"><h5 class="modal-title">Edit Operational Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label" for="operations_department_id">Department</label>
          <select name="department_id" id="operations_department_id" class="form-select"><option value="">-- None --</option>
            <?php foreach ($departments as $department): ?><option value="<?= $department['department_id'] ?>"><?= e($department['department_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label" for="operations_branch_id">Branch</label>
          <select name="branch_id" id="operations_branch_id" class="form-select"><option value="">-- None --</option>
            <?php foreach ($branches as $branch): ?><option value="<?= $branch['branch_id'] ?>"><?= e($branch['branch_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label" for="operations_position">Position</label><input name="position" id="operations_position" class="form-control" maxlength="100"></div>
        <div class="mb-3"><label class="form-label" for="operations_shift">Shift</label>
          <select name="shift" id="operations_shift" class="form-select" required>
            <?php foreach (SHIFT_SCHEDULES as $shiftKey => $schedule): ?><option value="<?= e($shiftKey) ?>"><?= e($schedule['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary">Save Operational Details</button></div>
    </form>
  </div></div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#employeesTable').DataTable({ order: [], paging: false, info: false, columnDefs: [{ orderable: false, targets: 0 }] });
});

document.getElementById('employeeOperationsModal').addEventListener('show.bs.modal', function (event) {
  var employee = JSON.parse(event.relatedTarget.dataset.employee);
  document.getElementById('operations_employee_id').value = employee.employee_id;
  document.getElementById('operations_department_id').value = employee.department_id || '';
  document.getElementById('operations_branch_id').value = employee.branch_id || '';
  document.getElementById('operations_position').value = employee.position || '';
  document.getElementById('operations_shift').value = employee.shift || 'MORNING';
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
