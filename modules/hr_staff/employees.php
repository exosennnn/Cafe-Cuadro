<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contracts.php';
require_once __DIR__ . '/../../includes/employment_terms.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
        if ($empId === false || $empId < 1) {
          setFlash('error', 'Invalid employee record.');
          redirect('modules/hr_staff/employees.php');
        }

        // Server-side guard: block editing own record, or an HR Manager / Employee Manager record
        $targetCheck = $pdo->prepare("SELECT u.user_id, u.role_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=?");
        $targetCheck->execute([$empId]);
        $target = $targetCheck->fetch();
        if (!$target || (int)$target['user_id'] === (int)$userId || in_array((int)$target['role_id'], [ROLE_HR_MANAGER, ROLE_EMPLOYEE_MANAGER, ROLE_OWNER], true)) {
          setFlash('error', 'You are not allowed to edit this record.');
          redirect('modules/hr_staff/employees.php');
        }

        $deptInput = $_POST['department_id'] ?? '';
        $branchInput = $_POST['branch_id'] ?? '';
        $reportsInput = $_POST['reports_to'] ?? '';
        $positionInput = $_POST['position'] ?? '';
        $salaryInput = $_POST['basic_salary'] ?? '';
        $deptValue = is_string($deptInput) ? trim($deptInput) : '';
        $branchValue = is_string($branchInput) ? trim($branchInput) : '';
        $reportsValue = is_string($reportsInput) ? trim($reportsInput) : '';
        $position = is_string($positionInput) ? trim($positionInput) : '';
        $type = $_POST['employment_type'] ?? '';
        $status = $_POST['employment_status'] ?? '';
        $errors = [];

        $deptId = null;
        if ($deptValue !== '') {
          if (!ctype_digit($deptValue) || (int)$deptValue < 1) {
            $errors[] = 'Please select a valid department.';
          } else {
            $deptId = (int)$deptValue;
            $check = $pdo->prepare('SELECT department_id FROM departments WHERE department_id=?');
            $check->execute([$deptId]);
            if (!$check->fetchColumn()) { $errors[] = 'The selected department does not exist.'; }
          }
        }

        $branchId = null;
        if ($branchValue !== '') {
          if (!ctype_digit($branchValue) || (int)$branchValue < 1) {
            $errors[] = 'Please select a valid branch.';
          } else {
            $branchId = (int)$branchValue;
            $check = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id=?');
            $check->execute([$branchId]);
            if (!$check->fetchColumn()) { $errors[] = 'The selected branch does not exist.'; }
          }
        }

        $reportsTo = null;
        if ($reportsValue !== '') {
          if (!ctype_digit($reportsValue) || (int)$reportsValue < 1) {
            $errors[] = 'Please select a valid manager.';
          } else {
            $reportsTo = (int)$reportsValue;
            $check = $pdo->prepare("SELECT e.employee_id FROM employees e JOIN users u ON e.user_id=u.user_id
              WHERE e.employee_id=? AND e.employment_status='ACTIVE' AND u.status='ACTIVE' AND u.role_id=?");
            $check->execute([$reportsTo, ROLE_EMPLOYEE_MANAGER]);
            if (!$check->fetchColumn()) { $errors[] = 'Reports To must be an active Employee Manager.'; }
          }
        }

        if ($position === '' || strlen($position) > 255 || preg_match('/[\x00-\x1F\x7F]/', $position)) {
          $errors[] = 'Position is required, must be 255 characters or fewer, and cannot contain control characters.';
        }
        if (!in_array($type, ['FULL_TIME', 'PART_TIME', 'CONTRACTUAL', 'PROBATIONARY'], true)) {
          $errors[] = 'Please select a valid employment type.';
        }
        if (!in_array($status, ['ACTIVE', 'ON_LEAVE', 'RESIGNED', 'TERMINATED'], true)) {
          $errors[] = 'Please select a valid employment status.';
        }
        if (!is_string($salaryInput) || !preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $salaryInput)) {
          $errors[] = 'Basic salary must be zero or greater with at most two decimals.';
        }

        // End dates that go with the chosen employment type
        $termDates = ['probation_end_date' => null, 'contract_end_date' => null, 'employment_end_date' => null];
        if (employmentTermsReady($pdo) && in_array($type, EMPLOYMENT_TYPES, true)) {
          $hiredRow = $pdo->prepare('SELECT date_hired FROM employees WHERE employee_id=?');
          $hiredRow->execute([$empId]);
          [$termErrors, $termDates] = employmentTermsFromPost($_POST, $type, $hiredRow->fetchColumn() ?: null);
          $errors = array_merge($errors, $termErrors);
        }

        if ($errors) {
          foreach ($errors as $error) { setFlash('error', $error); }
          redirect('modules/hr_staff/employees.php');
        }
        $salary = (float)$salaryInput;

        $update = $pdo->prepare("UPDATE employees SET department_id=?, branch_id=?, position=?, employment_type=?, employment_status=?, basic_salary=?, reports_to=? WHERE employee_id=?");
        $update->execute([$deptId, $branchId, $position, $type, $status, $salary, $reportsTo, $empId]);
        $rowsChanged = $update->rowCount();
        if (employmentTermsReady($pdo)) {
          $termBefore = $pdo->prepare('SELECT probation_end_date, contract_end_date, employment_end_date FROM employees WHERE employee_id=?');
          $termBefore->execute([$empId]);
          $termBefore = $termBefore->fetch() ?: [];
          employmentTermsSave($pdo, (int)$empId, $type, $status, $termDates);
          foreach ($termDates as $f => $v) {
            if ((string)($termBefore[$f] ?? '') !== (string)($v ?? '')) { $rowsChanged++; }
          }
        }
        if ($rowsChanged < 1) {
          setFlash('error', 'Employee record was not changed.');
          redirect('modules/hr_staff/employees.php');
        }
        setFlash('success', 'Employee record updated.');
        logAudit($pdo, $userId, 'UPDATE_EMPLOYEE', 'Employee Records', "employee_id=$empId");
    }
    redirect('modules/hr_staff/employees.php');
}

$search = trim($_GET['search'] ?? '');
$deptFilter = $_GET['department'] ?? '';
$branchFilter = $_GET['branch'] ?? '';
if (!is_string($deptFilter) || ($deptFilter !== '' && (!ctype_digit($deptFilter) || (int)$deptFilter < 1))) { $deptFilter = ''; }
if (!is_string($branchFilter) || ($branchFilter !== '' && (!ctype_digit($branchFilter) || (int)$branchFilter < 1))) { $branchFilter = ''; }
$where = '1=1'; $params = [];
if ($search !== '') { $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR e.employee_code LIKE ?)"; $params[]="%$search%";$params[]="%$search%";$params[]="%$search%"; }
if ($deptFilter !== '') { $where .= " AND e.department_id=?"; $params[] = $deptFilter; }
if ($branchFilter !== '') { $where .= " AND e.branch_id=?"; $params[] = $branchFilter; }
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

// Contract numbers for this page of employees, fetched in one query rather
// than one per row.
$contractNos = employeeContractNumbers($pdo, array_column($employees, 'employee_id'));

$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches ORDER BY branch_name")->fetchAll();
$managers = $pdo->query("SELECT e.employee_id, u.first_name, u.last_name FROM employees e JOIN users u ON e.user_id=u.user_id JOIN roles r ON u.role_id=r.role_id WHERE r.role_name='EMPLOYEE_MANAGER'")->fetchAll();

$pageTitle = 'Employee Records';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-people-fill"></i></div>
  <div>
    <h4>Employee Records</h4>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search name or employee code..." value="<?= e($search) ?>"></div>
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
  <div class="col-md-1"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="employeesTable">
      <thead>
        <tr>
          <th>Photo</th>
          <th>Code</th>
          <th>Contract No.</th>
          <th>Name</th>
          <th>Branch</th>
          <th>Department</th>
          <th>Position</th>
          <th>Type</th>
          <th>Shift</th>
          <th>Status</th>
          <th>Salary</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($employees as $emp): ?>
        <tr>
          <td><?= renderAvatar($emp['profile_photo'], $emp['first_name'], $emp['last_name'], 36) ?></td>
          <td><?= e($emp['employee_code']) ?></td>
          <td class="text-nowrap small">
            <?php $empContractNo = $contractNos[(int)$emp['employee_id']] ?? null; ?>
            <?= $empContractNo ? '<strong>' . e($empContractNo) . '</strong>' : '<span class="text-muted">-</span>' ?>
          </td>
          <td><?= e($emp['first_name'].' '.$emp['last_name']) ?><br><small class="text-muted"><?= e($emp['email']) ?></small></td>
          <td><?= $emp['branch_name'] ? '<span class="badge bg-light text-dark border">'.e($emp['branch_name']).'</span>' : '<span class="text-muted">-</span>' ?></td>
          <td><?= e($emp['department_name'] ?? '-') ?></td>
          <td><?= e($emp['position']) ?></td>
          <td class="small"><?= renderEmploymentTermBadge($emp) ?></td>
          <td><small><?= e(shiftLabel($emp['shift'] ?? null)) ?></small></td>
          <td><span class="badge <?= $emp['employment_status']=='ACTIVE'?'bg-success':'bg-secondary' ?>"><?= e($emp['employment_status']) ?></span></td>
          <td><?= fmoney($emp['basic_salary']) ?></td>
          <td><?php if ((int)$emp['user_id'] !== (int)$userId && !in_array((int)$emp['role_id'], [ROLE_HR_MANAGER, ROLE_EMPLOYEE_MANAGER, ROLE_OWNER], true)): ?><button class="btn btn-sm btn-outline-primary" onclick='editEmp(<?= json_encode($emp) ?>)'><i class="bi bi-pencil"></i></button><?php else: ?><span class="small text-muted">View only</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $('#employeesTable').DataTable({ order: [], paging: false, info: false, columnDefs: [{ orderable: false, targets: [0, -1] }] });
});
</script>

<div class="modal fade" id="empModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="employee_id" id="employee_id">
      <div class="modal-header"><h5 class="modal-title">Edit Employee Record</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
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
          <div class="col-md-6 mb-3"><label class="form-label">Reports To (Manager)</label>
            <select name="reports_to" id="reports_to" class="form-select">
              <option value="">-- None --</option>
              <?php foreach ($managers as $m): ?><option value="<?= $m['employee_id'] ?>"><?= e($m['first_name'].' '.$m['last_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> Shift schedule (Morning/Afternoon/Graveyard) is managed by the HR Manager and can change from time to time — see Employee Records under HR Manager.</p>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
    </form>
  </div></div>
</div>

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
  document.getElementById('reports_to').value = e.reports_to || '';
  loadEndDates(e);
  new bootstrap.Modal(document.getElementById('empModal')).show();
}
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>