<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/employment_terms.php';
requireRole([ROLE_HR_MANAGER, ROLE_OWNER]);

$userId = $_SESSION['user_id'];
$isOwner = (int)currentRoleId() === ROLE_OWNER;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
    $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
    $target = null;
    if ($empId !== false && $empId > 0) {
      $targetStmt = $pdo->prepare("SELECT e.*, u.role_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=?");
      $targetStmt->execute([$empId]);
      $target = $targetStmt->fetch();
    }

    $deptId = $_POST['department_id'] ?? null;
    $branchId = $_POST['branch_id'] ?? null;
    $reportsTo = $_POST['reports_to'] ?? null;
    $position = trim($_POST['position'] ?? '');
    $type = $_POST['employment_type'] ?? '';
    $status = $_POST['employment_status'] ?? '';
    $salaryRaw = trim($_POST['basic_salary'] ?? '');
    $salary = is_numeric($salaryRaw) ? (float)$salaryRaw : null;
    $dateHired = trim($_POST['date_hired'] ?? '');
    $deptId = $deptId === '' ? null : filter_var($deptId, FILTER_VALIDATE_INT);
    $branchId = $branchId === '' ? null : filter_var($branchId, FILTER_VALIDATE_INT);
    $reportsTo = $reportsTo === '' ? null : filter_var($reportsTo, FILTER_VALIDATE_INT);
        $shift = $_POST['shift'] ?? DEFAULT_SHIFT;
    $dateHired = $dateHired === '' ? null : $dateHired;

        $errors = [];
    if (!$target || (!$isOwner && (
      (int)$target['user_id'] === (int)$userId
      || in_array((int)$target['role_id'], [ROLE_HR_MANAGER, ROLE_OWNER], true)
    ))) {
      $errors[] = 'You are not allowed to edit this employee record.';
    }
    if ($deptId !== null && ($deptId === false || $deptId < 1)) {
      $errors[] = 'Please select a valid department.';
    } elseif ($deptId !== null) {
      $check = $pdo->prepare('SELECT department_id FROM departments WHERE department_id=?');
      $check->execute([$deptId]);
      if (!$check->fetch()) { $errors[] = 'Please select a valid department.'; }
    }
    if ($branchId !== null && ($branchId === false || $branchId < 1)) {
      $errors[] = 'Please select a valid branch.';
    } elseif ($branchId !== null) {
      $check = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id=?');
      $check->execute([$branchId]);
      if (!$check->fetch()) { $errors[] = 'Please select a valid branch.'; }
    }
    if ($reportsTo !== null && ($reportsTo === false || $reportsTo < 1 || (int)$reportsTo === (int)$empId)) {
      $errors[] = 'Please select a valid manager.';
    } elseif ($reportsTo !== null) {
      $check = $pdo->prepare("SELECT e.employee_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=? AND u.role_id=?");
      $check->execute([$reportsTo, ROLE_EMPLOYEE_MANAGER]);
      if (!$check->fetch()) { $errors[] = 'Please select a valid manager.'; }
    }
    if ($position !== '' && strlen($position) > 100) {
      $errors[] = 'Position must be 100 characters or fewer.';
    }
    if (!in_array($type, EMPLOYMENT_TYPES, true)) {
      $errors[] = 'Please select a valid employment type.';
    }
    $allowedStatuses = ['ACTIVE', 'ON_LEAVE', 'RESIGNED', 'TERMINATED'];
    if (!in_array($status, $allowedStatuses, true)) {
      $errors[] = 'Please select a valid employment status.';
    }
    if ($salary === null || !is_finite($salary) || $salary < 0 || $salary > 9999999999.99 || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $salaryRaw)) {
            $errors[] = 'Basic salary must be zero or greater.';
        }
    if ($dateHired !== null) {
      $date = DateTime::createFromFormat('!Y-m-d', $dateHired);
      if (!$date || $date->format('Y-m-d') !== $dateHired) {
        $errors[] = 'Please enter a valid hire date.';
      } elseif ($dateHired < APP_LAUNCH_DATE || $dateHired > date('Y-m-d')) {
        $errors[] = 'Date hired must be between ' . fdate(APP_LAUNCH_DATE) . ' and today.';
      }
        }
    if (!isset(SHIFT_SCHEDULES[$shift])) {
      $errors[] = 'Please select a valid shift.';
    }
    // End dates that go with the chosen employment type (Probationary / Contractual / Part-Time)
    $termDates = ['probation_end_date' => null, 'contract_end_date' => null, 'employment_end_date' => null];
    if (employmentTermsReady($pdo) && in_array($type, EMPLOYMENT_TYPES, true)) {
      [$termErrors, $termDates] = employmentTermsFromPost($_POST, $type, $dateHired ?? ($target['date_hired'] ?? null));
      $errors = array_merge($errors, $termErrors);
    }

    if (empty($errors) && $target) {
      $before = $target;
      $changes = [];
      foreach (['department_id' => $deptId, 'branch_id' => $branchId, 'position' => ($position ?: null), 'employment_type' => $type, 'employment_status' => $status, 'basic_salary' => $salary, 'reports_to' => $reportsTo, 'date_hired' => $dateHired, 'shift' => $shift] as $field => $value) {
        if ((string)($before[$field] ?? '') !== (string)($value ?? '')) { $changes[] = $field; }
      }
      if (employmentTermsReady($pdo)) {
        foreach ($termDates as $field => $value) {
          if ((string)($before[$field] ?? '') !== (string)($value ?? '')) { $changes[] = $field; }
        }
      }
            $pdo->prepare("UPDATE employees SET department_id=?, branch_id=?, position=?, employment_type=?, employment_status=?, basic_salary=?, reports_to=?, date_hired=?, shift=? WHERE employee_id=?")
        ->execute([$deptId, $branchId, ($position ?: null), $type, $status, $salary, $reportsTo, $dateHired, $shift, $empId]);
            employmentTermsSave($pdo, (int)$empId, $type, $status, $termDates);
            setFlash('success', 'Employee record updated.');
      logAudit($pdo, $userId, 'UPDATE_EMPLOYEE', 'Employees', "employee_id=$empId changed=" . implode(',', $changes));

            if ($before && $before['shift'] !== $shift) {
                createNotification(
                    $pdo,
                    (int)$before['user_id'],
                    'Work Schedule Updated',
                    'Your shift has been set to ' . shiftLabel($shift) . '. Check My Attendance for your Time In/Out window.',
                    'SHIFT',
                    $empId
                );
            }
        } else {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        }
    } elseif (in_array($action, ['regularize', 'renew_contract', 'end_employment'], true)) {
      // HR Manager review decisions for probationary / contractual employees
      $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
      if ($empId === false || $empId < 1) {
        setFlash('error', 'Invalid employee record.');
      } else {
        [$ok, $msg] = applyEmploymentTermAction($pdo, (int)$userId, $action, (int)$empId, $_POST['new_end_date'] ?? null);
        setFlash($ok ? 'success' : 'error', $msg);
      }
    } elseif ($action === 'deactivate') {
      $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
      $stmt = $pdo->prepare("SELECT e.user_id, u.role_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=?");
        $stmt->execute([$empId]);
        $row = $stmt->fetch();
      if (!$row || (int)$row['user_id'] === (int)$userId || (!$isOwner && in_array((int)$row['role_id'], [ROLE_HR_MANAGER, ROLE_OWNER], true))) {
        setFlash('error', 'You are not allowed to deactivate this employee record.');
      } else {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE employees SET employment_status='TERMINATED' WHERE employee_id=?")->execute([$empId]);
        $pdo->prepare("UPDATE users SET status='SUSPENDED' WHERE user_id=?")->execute([$row['user_id']]);
        $pdo->commit();
        setFlash('success', 'Employee record deactivated.');
        logAudit($pdo, $userId, 'DEACTIVATE_EMPLOYEE', 'Employees', "employee_id=$empId employment_status=TERMINATED user_status=SUSPENDED");
        }
    }
    redirect('modules/hr_manager/employees.php');
}

$search = trim($_GET['search'] ?? '');
$deptFilter = $_GET['department'] ?? '';
$branchFilter = $_GET['branch'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$where = '1=1'; $params = [];
if ($search !== '') { $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR e.employee_code LIKE ?)"; $params[]="%$search%";$params[]="%$search%";$params[]="%$search%"; }
if ($deptFilter !== '') { $where .= " AND e.department_id=?"; $params[] = $deptFilter; }
if ($branchFilter !== '') { $where .= " AND e.branch_id=?"; $params[] = $branchFilter; }
if ($statusFilter !== '') { $where .= " AND e.employment_status=?"; $params[] = $statusFilter; }
$total = $pdo->prepare("SELECT COUNT(*) AS c FROM employees e JOIN users u ON e.user_id=u.user_id WHERE $where");
$total->execute($params);
$total = $total->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 10);

$stmt = $pdo->prepare("SELECT e.*, u.role_id, u.first_name, u.last_name, u.email, u.profile_photo, d.department_name, b.branch_name
    FROM employees e JOIN users u ON e.user_id=u.user_id LEFT JOIN departments d ON e.department_id=d.department_id
    LEFT JOIN branches b ON e.branch_id=b.branch_id
    WHERE $where ORDER BY e.employee_id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$employees = $stmt->fetchAll();

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches ORDER BY branch_name")->fetchAll();
$managers = $pdo->query("SELECT e.employee_id, u.first_name, u.last_name FROM employees e JOIN users u ON e.user_id=u.user_id JOIN roles r ON u.role_id=r.role_id WHERE r.role_name='EMPLOYEE_MANAGER'")->fetchAll();

// Probationary / contract end-date alerts for the HR Manager (flag only - never auto-terminates)
$termReviews = getEmploymentTermReviews($pdo, $isOwner);
syncEmploymentTermNotifications($pdo, $termReviews);

$pageTitle = 'Manage Employees';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Manage Employees</h4>

<?= renderEmploymentTermReviewPanel($termReviews, BASE_URL . 'modules/hr_manager/employees.php') ?>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3"><input type="text" name="search" class="form-control" placeholder="Search name/code..." value="<?= e($search) ?>"></div>
  <div class="col-md-2">
    <select name="department" class="form-select">
      <option value="">All Departments</option>
      <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>" <?= $deptFilter==$d['department_id']?'selected':'' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select name="branch" class="form-select">
      <option value="">All Branches</option>
      <?php foreach ($branches as $b): ?><option value="<?= $b['branch_id'] ?>" <?= $branchFilter==$b['branch_id']?'selected':'' ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <select name="status" class="form-select">
      <option value="">All Statuses</option>
      <?php foreach (['ACTIVE','ON_LEAVE','RESIGNED','TERMINATED'] as $s): ?><option value="<?= $s ?>" <?= $statusFilter==$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i> Filter</button></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="employeesTable">
      <thead><tr><th>Photo</th><th>Code</th><th>Name</th><th>Branch</th><th>Department</th><th>Position</th><th>Employment Type</th><th>Shift</th><th>Status</th><th>Salary</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td><?= renderAvatar($emp['profile_photo'], $emp['first_name'], $emp['last_name'], 36) ?></td>
          <td><?= e($emp['employee_code']) ?></td>
          <td><?= e($emp['first_name'].' '.$emp['last_name']) ?><br><small class="text-muted"><?= e($emp['email']) ?></small></td>
          <td><?= $emp['branch_name'] ? '<span class="badge bg-light text-dark border">'.e($emp['branch_name']).'</span>' : '<span class="text-muted">-</span>' ?></td>
          <td><?= e($emp['department_name'] ?? '-') ?></td>
          <td><?= e($emp['position']) ?></td>
          <td class="small"><?= renderEmploymentTermBadge($emp) ?></td>
          <td><small><?= e(shiftLabel($emp['shift'] ?? null)) ?></small></td>
          <td><span class="badge <?= $emp['employment_status']=='ACTIVE'?'bg-success':'bg-secondary' ?>"><?= e($emp['employment_status']) ?></span></td>
          <td><?= fmoney($emp['basic_salary']) ?></td>
          <td>
            <?php $canManageEmployee = $isOwner || ((int)$emp['user_id'] !== (int)$userId && !in_array((int)$emp['role_id'], [ROLE_HR_MANAGER, ROLE_OWNER], true)); ?>
            <?php if ($canManageEmployee): ?>
              <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary" title="Edit" onclick='editEmp(<?= json_encode($emp) ?>)'><i class="bi bi-pencil"></i></button>
                <?php if ((int)$emp['user_id'] !== (int)$userId): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('This will deactivate the employee and suspend their user account. Continue?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="employee_id" value="<?= $emp['employee_id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Deactivate"><i class="bi bi-person-slash"></i></button>
                  </form>
                <?php endif; ?>
              </div>
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

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
<style>
/* ---- HR Manager DataTables styling — Purr'Coffee Café Theme ---- */
:root {
  --dt-ink: #2a1810; --dt-muted: #7a6558; --dt-border: #e6d8c5;
  --dt-accent: #5e6b46; --dt-success: #2e7d32; --dt-radius: 22px; --dt-radius-sm: 50px;
}
.card:has(table.dataTable) { border-radius: var(--dt-radius); border: 1px solid var(--dt-border); }
.dataTables_wrapper .dataTables_filter { text-align: right; margin-bottom: 12px; }
.dataTables_wrapper .dataTables_filter label { font-weight: 600; font-size: .85rem; color: var(--dt-ink); }
.dataTables_wrapper .dataTables_filter input { border-radius: 50px; padding: 6px 16px; margin-left: 8px; min-width: 220px; border: 1px solid var(--dt-border); }
.dataTables_wrapper .dataTables_filter input:focus { outline: none; border-color: var(--dt-accent) !important; box-shadow: 0 0 0 .2rem rgba(94,107,70,.18); }
table.dataTable thead th { background: #fbf6ee; color: var(--dt-ink); text-transform: uppercase; font-size: .72rem; letter-spacing: .04em; font-weight: 700; white-space: nowrap; padding-bottom: .75rem; border-bottom: 1px solid var(--dt-border); }
table.dataTable thead .sorting:after, table.dataTable thead .sorting_asc:after, table.dataTable thead .sorting_desc:after { color: var(--dt-muted); opacity: .55; }
table.dataTable tbody td { vertical-align: middle; padding-top: .85rem; padding-bottom: .85rem; border-bottom: 1px solid var(--dt-border); }
table.dataTable tbody td small.text-muted { color: var(--dt-muted) !important; }
.avatar, .avatar-circle { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: 700; font-size: .8rem; color: #fff; transition: transform 0.18s ease; }
.avatar:hover, .avatar-circle:hover { transform: scale(1.08); }
.avatar:nth-of-type(4n+1), .avatar-circle:nth-of-type(4n+1) { background: linear-gradient(135deg, #5e6b46, #4c5838); }
.avatar:nth-of-type(4n+2), .avatar-circle:nth-of-type(4n+2) { background: linear-gradient(135deg, #2a1810, #3b241a); }
.avatar:nth-of-type(4n+3), .avatar-circle:nth-of-type(4n+3) { background: linear-gradient(135deg, #8d5b4c, #b37d6f); }
.avatar:nth-of-type(4n+4), .avatar-circle:nth-of-type(4n+4) { background: linear-gradient(135deg, #2e7d32, #4caf50); }
table.dataTable .btn-group,
table.dataTable td .d-flex.gap-1 { flex-wrap: nowrap; }
table.dataTable .badge { font-weight: 700; border-radius: 999px; padding: .4em .75em; }
table.dataTable .badge.bg-light.text-dark.border { background: #fbf6ee !important; border: 1px solid var(--dt-border) !important; color: var(--dt-ink) !important; }
table.dataTable .badge.bg-success { background: #e8f5e9 !important; color: #1b5e20 !important; border: 1px solid #c8e6c9 !important; }
table.dataTable .badge.bg-secondary { background: #f3eee8 !important; color: #5a4b41 !important; border: 1px solid #e6d8c5 !important; }
table.dataTable .badge.bg-info { background: #eef1e4 !important; color: var(--dt-accent) !important; border: 1px solid #cfd8bd !important; }
table.dataTable .badge.bg-warning { background: #fff8e1 !important; color: #b78103 !important; border: 1px solid #ffe082 !important; }
table.dataTable .badge.bg-danger { background: #ffebee !important; color: #b71c1c !important; border: 1px solid #ffcdd2 !important; }
table.dataTable td .btn-outline-primary, table.dataTable td .btn-outline-secondary, table.dataTable td .btn-outline-danger { border-radius: 50px; width: 32px; height: 32px; padding: 0; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
table.dataTable td .btn-outline-primary { color: var(--dt-accent); border-color: var(--dt-border); }
table.dataTable td .btn-outline-primary:hover { background: var(--dt-accent); border-color: var(--dt-accent); color: #fff; }
.dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 50px !important; margin-left: 4px; }
.dataTables_wrapper .dataTables_paginate .paginate_button.current { background: var(--dt-accent) !important; border-color: var(--dt-accent) !important; color: #fff !important; }
</style>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#employeesTable').DataTable({ order: [], paging: false, info: false, columnDefs: [{ orderable: false, targets: [0, -1] }] });
});
</script>

<div class="modal fade" id="empModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="employee_id" id="employee_id">
    <div class="modal-header"><h5 class="modal-title">Edit Employee</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Branch</label>
          <select name="branch_id" id="branch_id" class="form-select">
            <option value="">-- None --</option>
            <?php foreach ($branches as $b): ?><option value="<?= $b['branch_id'] ?>"><?= e($b['branch_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3"><label class="form-label">Department</label>
          <select name="department_id" id="department_id" class="form-select">
            <option value="">-- None --</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mb-3"><label class="form-label">Position</label><input type="text" name="position" id="position" class="form-control"></div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Employment Type</label>
          <select name="employment_type" id="employment_type" class="form-select">
            <?php foreach (['FULL_TIME','PROBATIONARY','CONTRACTUAL','PART_TIME'] as $t): ?><option value="<?= $t ?>"><?= e(EMPLOYMENT_TYPE_LABELS[$t]) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3"><label class="form-label">Status</label>
          <select name="employment_status" id="employment_status" class="form-select">
            <?php foreach (['ACTIVE','ON_LEAVE','RESIGNED','TERMINATED'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <?= renderEmploymentEndDateFields() ?>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Basic Salary</label><input type="number" step="0.01" min="0" name="basic_salary" id="basic_salary" class="form-control"></div>
        <div class="col-md-6 mb-3"><label class="form-label">Date Hired</label><input type="date" min="<?= APP_LAUNCH_DATE ?>" name="date_hired" id="date_hired" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label">Reports To (Manager)</label>
        <select name="reports_to" id="reports_to" class="form-select">
          <option value="">-- None --</option>
          <?php foreach ($managers as $m): ?><option value="<?= $m['employee_id'] ?>"><?= e($m['first_name'].' '.$m['last_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Shift</label>
        <select name="shift" id="shift" class="form-select">
          <?php foreach (SHIFT_SCHEDULES as $key => $s): ?>
            <option value="<?= e($key) ?>"><?= e($s['label']) ?> (<?= date('g:i A', strtotime($s['start'])) ?> - <?= date('g:i A', strtotime($s['end'])) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Can be reassigned any time — it isn't fixed once set.</div>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
  </form>
</div></div></div>

<?= renderEmploymentEndDateScript() ?>
<script>
function editEmp(e) {
  document.getElementById('employee_id').value = e.employee_id;
  document.getElementById('branch_id').value = e.branch_id || '';
  document.getElementById('department_id').value = e.department_id || '';
  document.getElementById('position').value = e.position || '';
  document.getElementById('employment_type').value = e.employment_type;
  document.getElementById('employment_status').value = e.employment_status;
  document.getElementById('basic_salary').value = e.basic_salary;
  document.getElementById('date_hired').value = e.date_hired || '';
  document.getElementById('reports_to').value = e.reports_to || '';
  document.getElementById('shift').value = e.shift || 'MORNING';
  loadEndDates(e);
  new bootstrap.Modal(document.getElementById('empModal')).show();
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>