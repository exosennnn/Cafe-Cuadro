<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER, ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
$actorRole = (int)currentRoleId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    if ($action === 'record') {
      $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
      $dateInput = $_POST['attendance_date'] ?? '';
      $timeInInput = $_POST['time_in'] ?? '';
      $timeOutInput = $_POST['time_out'] ?? '';
      $status = $_POST['status'] ?? '';
      $remarksInput = $_POST['remarks'] ?? '';
      $date = is_string($dateInput) ? trim($dateInput) : '';
      $timeInValue = is_string($timeInInput) ? trim($timeInInput) : '';
      $timeOutValue = is_string($timeOutInput) ? trim($timeOutInput) : '';
      $remarks = is_string($remarksInput) ? trim($remarksInput) : '';
      $errors = [];

      if ($empId === false || $empId < 1) {
        $errors[] = 'Please select a valid employee.';
      }
      $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
      $dateErrors = DateTime::getLastErrors();
      if (!$dateObject || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $dateObject->format('Y-m-d') !== $date) {
        $errors[] = 'Attendance date must be a valid YYYY-MM-DD date.';
      } elseif ($date < APP_LAUNCH_DATE) {
        $errors[] = 'Attendance date must be ' . APP_LAUNCH_DATE . ' or later.';
      }
      foreach (['time_in' => $timeInValue, 'time_out' => $timeOutValue] as $field => $value) {
        if ($value !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
          $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid HH:MM time.';
        }
      }
      if ($timeInValue !== '' && $timeOutValue !== '' && $timeOutValue < $timeInValue) {
        $errors[] = 'Time Out cannot be earlier than Time In.';
      }
      if (!in_array($status, ['PRESENT', 'LATE', 'ABSENT', 'HALF_DAY', 'ON_LEAVE'], true)) {
        $errors[] = 'Please select a valid attendance status.';
      }
      if (strlen($remarks) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $remarks)) {
        $errors[] = 'Attendance remarks must be 2000 characters or fewer and cannot contain control characters.';
      }

      if ($errors) {
        foreach ($errors as $error) { setFlash('error', $error); }
      } else {
        try {
          $pdo->beginTransaction();
          $employeeCheck = $pdo->prepare("SELECT e.employee_id, e.user_id, u.role_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=? AND e.employment_status='ACTIVE' FOR UPDATE");
          $employeeCheck->execute([$empId]);
          $attendanceTarget = $employeeCheck->fetch();
          if (!$attendanceTarget) {
            throw new RuntimeException('That employee is not active or does not exist.');
          }
          $protectedAttendanceTarget = (int)$attendanceTarget['role_id'] === ROLE_OWNER
            || ($actorRole === ROLE_HR_STAFF && (int)$attendanceTarget['role_id'] === ROLE_HR_MANAGER)
            || ($actorRole === ROLE_EMPLOYEE_MANAGER && (
              (int)$attendanceTarget['user_id'] === (int)$userId
              || (int)$attendanceTarget['role_id'] === ROLE_HR_MANAGER
            ));
          if ($protectedAttendanceTarget) {
            throw new RuntimeException('You are not allowed to edit this attendance record.');
          }
          $timeIn = $timeInValue !== '' ? $timeInValue . ':00' : null;
          $timeOut = $timeOutValue !== '' ? $timeOutValue . ':00' : null;
          $existing = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id=? AND attendance_date=? FOR UPDATE");
          $existing->execute([$empId, $date]);
          if ($existing->fetch()) {
            $saved = $pdo->prepare("UPDATE attendance SET time_in=?, time_out=?, status=?, remarks=? WHERE employee_id=? AND attendance_date=?");
            $saved->execute([$timeIn, $timeOut, $status, $remarks, $empId, $date]);
          } else {
            $saved = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in, time_out, status, remarks) VALUES (?,?,?,?,?,?)");
            $saved->execute([$empId, $date, $timeIn, $timeOut, $status, $remarks]);
            if ($saved->rowCount() !== 1) {
              throw new RuntimeException('Attendance record was not inserted.');
            }
          }
          $pdo->commit();
          setFlash('success', 'Attendance record saved.');
          logAudit($pdo, $userId, 'RECORD_ATTENDANCE', 'Attendance', "employee_id=$empId date=$date");
        } catch (Throwable $exception) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          error_log('hr_staff attendance record error: ' . $exception->getMessage());
          setFlash('error', 'Attendance record could not be saved. No changes were made.');
        }
      }
    } elseif ($action === 'review_half_day') {
      if (!in_array($actorRole, [ROLE_HR_STAFF, ROLE_HR_MANAGER], true)) {
        setFlash('error', 'Only HR Staff or HR Manager may review half day requests.');
        redirect('modules/hr_staff/attendance.php');
      }
      $hdId = filter_var($_POST['half_day_id'] ?? null, FILTER_VALIDATE_INT);
      $decision = $_POST['decision'] ?? '';
      $remarksInput = $_POST['review_remarks'] ?? '';
      $remarks = is_string($remarksInput) ? trim($remarksInput) : '';
      if ($hdId === false || $hdId < 1) {
        setFlash('error', 'Invalid half day request.');
        redirect('modules/hr_staff/attendance.php');
      }
      if (!in_array($decision, ['approve', 'reject'], true)) {
        setFlash('error', 'Please select a valid decision.');
        redirect('modules/hr_staff/attendance.php');
      }
      if (strlen($remarks) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $remarks)) {
        setFlash('error', 'Review remarks must be 2000 characters or fewer and cannot contain control characters.');
        redirect('modules/hr_staff/attendance.php');
      }
        $check = $pdo->prepare("SELECT hd.*, e.user_id FROM half_day_requests hd JOIN employees e ON hd.employee_id=e.employee_id WHERE hd.half_day_id=? AND hd.status='PENDING'");
        $check->execute([$hdId]);
        $hd = $check->fetch();
        if ($hd) {
            $newStatus = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
          $review = $pdo->prepare("UPDATE half_day_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), review_remarks=? WHERE half_day_id=? AND status='PENDING'");
          $review->execute([$newStatus, $userId, $remarks, $hdId]);
          if ($review->rowCount() === 1) {
            setFlash('success', 'Half day request ' . strtolower($newStatus) . '.');
            logAudit($pdo, $userId, 'REVIEW_HALF_DAY', 'Attendance', "half_day_id=$hdId status=$newStatus");
            createNotification($pdo, (int)$hd['user_id'],
              $newStatus === 'APPROVED' ? 'Half Day Request Approved' : 'Half Day Request Rejected',
              'Your half day request for ' . fdate($hd['request_date']) . ' was ' . strtolower($newStatus) . '.' . ($remarks ? ' Remarks: ' . $remarks : ''),
              'HALF_DAY', $hdId);
          } else {
            setFlash('error', 'This half day request was already reviewed.');
          }
        } else {
            setFlash('error', 'This half day request was already reviewed.');
        }
    }
    redirect('modules/hr_staff/attendance.php');
}

$dateFilter = $_GET['date'] ?? date('Y-m-d');
$stmt = $pdo->prepare("SELECT a.*, e.employee_code, u.first_name, u.last_name, d.department_name
    FROM attendance a JOIN employees e ON a.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    LEFT JOIN departments d ON e.department_id=d.department_id
    WHERE a.attendance_date = ? ORDER BY u.first_name");
$stmt->execute([$dateFilter]);
$records = $stmt->fetchAll();

$employees = $pdo->query("SELECT e.employee_id, e.employee_code, u.first_name, u.last_name FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employment_status='ACTIVE' ORDER BY u.first_name")->fetchAll();

$allowedHalfDayStatuses = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', ''];
$halfDayStatusFilter = $_GET['hd_status'] ?? 'PENDING';
if (!is_string($halfDayStatusFilter) || !in_array($halfDayStatusFilter, $allowedHalfDayStatuses, true)) {
  $halfDayStatusFilter = 'PENDING';
}
$hdWhere = '1=1'; $hdParams = [];
if ($halfDayStatusFilter !== '') { $hdWhere .= " AND hd.status=?"; $hdParams[] = $halfDayStatusFilter; }
$halfDayRequests = $pdo->prepare("SELECT hd.*, e.employee_code, u.first_name, u.last_name
    FROM half_day_requests hd JOIN employees e ON hd.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE $hdWhere ORDER BY hd.filed_at DESC LIMIT 50");
$halfDayRequests->execute($hdParams);
$halfDayRequests = $halfDayRequests->fetchAll();

$pageTitle = 'Attendance Monitoring';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-calendar-check-fill"></i></div>
    <div>
      <h4>Attendance Monitoring</h4>
    </div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#attModal"><i class="bi bi-plus-circle"></i> Record Attendance</button>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3"><input type="date" name="date" class="form-control" min="<?= APP_LAUNCH_DATE ?>" value="<?= e($dateFilter) ?>" onchange="this.form.submit()"></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="attendanceTable">
      <thead><tr><th>Employee</th><th>Department</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Remarks</th></tr></thead>
      <tbody>
      <?php foreach ($records as $r): ?>
        <tr>
          <td><?= e($r['first_name'].' '.$r['last_name']) ?> <small class="text-muted">(<?= e($r['employee_code']) ?>)</small></td>
          <td><?= e($r['department_name'] ?? '-') ?></td>
          <td><?= $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '-' ?></td>
          <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '-' ?></td>
          <td><span class="badge badge-status-<?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td><?= e($r['remarks']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($records)): ?><tr><td colspan="6" class="text-center text-muted">No attendance records for this date.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="attModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="record">
      <div class="modal-header"><h5 class="modal-title">Record Attendance</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Employee</label>
          <select name="employee_id" class="form-select" required>
            <?php foreach ($employees as $e): ?><option value="<?= $e['employee_id'] ?>"><?= e($e['first_name'].' '.$e['last_name'].' ('.$e['employee_code'].')') ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Date</label><input type="date" name="attendance_date" class="form-control" value="<?= e($dateFilter) ?>" required></div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">Time In</label><input type="time" name="time_in" class="form-control"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Time Out</label><input type="time" name="time_out" class="form-control"></div>
        </div>
        <div class="mb-3"><label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['PRESENT','LATE','ABSENT','HALF_DAY','ON_LEAVE'] as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" maxlength="2000"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
    </form>
  </div></div>
</div>

<?php if (in_array($actorRole, [ROLE_HR_STAFF, ROLE_HR_MANAGER], true)): ?>
<div class="d-flex justify-content-between align-items-center mb-3 mt-4">
  <h4 class="mb-0">Half Day Requests</h4>
</div>
<form method="GET" class="row g-2 mb-3">
  <input type="hidden" name="date" value="<?= e($dateFilter) ?>">
  <div class="col-md-3">
    <select name="hd_status" class="form-select" onchange="this.form.submit()">
      <option value="PENDING" <?= $halfDayStatusFilter==='PENDING'?'selected':'' ?>>Awaiting Review</option>
      <option value="APPROVED" <?= $halfDayStatusFilter==='APPROVED'?'selected':'' ?>>Approved</option>
      <option value="REJECTED" <?= $halfDayStatusFilter==='REJECTED'?'selected':'' ?>>Rejected</option>
      <option value="CANCELLED" <?= $halfDayStatusFilter==='CANCELLED'?'selected':'' ?>>Cancelled</option>
      <option value="" <?= $halfDayStatusFilter===''?'selected':'' ?>>All</option>
    </select>
  </div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="halfDayTable">
      <thead><tr><th>Employee</th><th>Date</th><th>Reason</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($halfDayRequests as $hd): ?>
        <tr>
          <td><?= e($hd['first_name'].' '.$hd['last_name']) ?> <small class="text-muted">(<?= e($hd['employee_code']) ?>)</small></td>
          <td><?= fdate($hd['request_date']) ?></td>
          <td><?= e($hd['reason']) ?></td>
          <td><span class="badge bg-<?= halfDayStatusColor($hd['status']) ?>"><?= e($hd['status']) ?></span></td>
          <td><?= e($hd['review_remarks']) ?></td>
          <td>
            <?php if ($hd['status'] === 'PENDING'): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#hd<?= $hd['half_day_id'] ?>"><i class="bi bi-check2"></i> Review</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($halfDayRequests)): ?><tr><td colspan="6" class="text-center text-muted">No half day requests found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($halfDayRequests as $hd): ?>
  <div class="modal fade" id="hd<?= $hd['half_day_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="review_half_day">
        <input type="hidden" name="half_day_id" value="<?= $hd['half_day_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Review Half Day Request</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <p class="small text-muted mb-1"><?= e($hd['first_name'].' '.$hd['last_name']) ?> - <?= fdate($hd['request_date']) ?></p>
          <p class="small"><?= e($hd['reason']) ?></p>
          <div class="mb-3"><label class="form-label">Decision</label>
            <select name="decision" class="form-select"><option value="approve">Approve</option><option value="reject">Reject</option></select>
          </div>
          <div class="mb-3"><label class="form-label">Remarks</label><textarea name="review_remarks" class="form-control" maxlength="2000"></textarea></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Submit</button></div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#attendanceTable').DataTable({ order: [] });
  $('#halfDayTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>