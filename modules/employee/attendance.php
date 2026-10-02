<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

$pdo->exec("SET time_zone = '+08:00'");
$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT employee_id, shift FROM employees WHERE user_id=?");
$emp->execute([$userId]);
$emp = $emp->fetch();
$employeeId = $emp['employee_id'] ?? 0;
$employeeShift = $emp['shift'] ?? DEFAULT_SHIFT;

// Handle Time In / Time Out / Half Day Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employeeId) {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('H:i:s');
    $nowDt = new DateTime($today . ' ' . $now);

    if ($action === 'clock_in') {
      try {
        $pdo->beginTransaction();
        $employeeLock = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id=? AND employment_status=\'ACTIVE\' FOR UPDATE');
        $employeeLock->execute([$employeeId]);
        if (!$employeeLock->fetch()) {
          throw new RuntimeException('Your employee account is not active.');
        }
        $existing = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id=? AND attendance_date=? FOR UPDATE");
        $existing->execute([$employeeId, $today]);
        if (!$existing->fetch()) {
          $earliest = earliestClockInTime($employeeShift, $today);
          if ($nowDt < $earliest) {
            $pdo->rollBack();
            setFlash('error', 'You can only Time In starting ' . $earliest->format('g:i A') . ' for your ' . shiftLabel($employeeShift) . ' shift.');
          } else {
            $clockResult = computeClockInStatus($now, $employeeShift);
            $deductionAmount = $clockResult['status'] === 'LATE' ? computeLateDeductionAmount($clockResult['minutes_late']) : 0;
            $remarks = $clockResult['status'] === 'LATE'
              ? 'Late by ' . $clockResult['minutes_late'] . ' min(s) - ' . fmoney($deductionAmount) . ' deduction'
              : null;
            $insert = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in, status, remarks) VALUES (?,?,?,?,?)");
            $insert->execute([$employeeId, $today, $now, $clockResult['status'], $remarks]);
            $attendanceId = (int)$pdo->lastInsertId();
            if ($clockResult['status'] === 'LATE') {
              logAttendanceDeduction($pdo, $attendanceId, $employeeId, $deductionAmount, 'Late arrival: ' . $clockResult['minutes_late'] . ' minute(s) past ' . shiftLabel($employeeShift) . ' shift start');
            }
            $pdo->commit();
            if ($clockResult['status'] === 'LATE') {
              setFlash('error', 'Timed in late at ' . date('g:i A', strtotime($now)) . ' (' . $clockResult['minutes_late'] . ' min late) - ' . fmoney($deductionAmount) . ' deduction recorded.');
            } else {
              setFlash('success', 'Timed in at ' . date('g:i A', strtotime($now)) . '.');
            }
                }
            }
        if ($pdo->inTransaction()) { $pdo->commit(); }
      } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('employee attendance clock-in error: ' . $exception->getMessage());
        setFlash('error', 'Time In could not be recorded. No changes were made.');
        }
    } elseif ($action === 'clock_out') {
      try {
        $pdo->beginTransaction();
        $existing = $pdo->prepare("SELECT attendance_id, status, remarks FROM attendance WHERE employee_id=? AND attendance_date=? AND time_in IS NOT NULL AND time_out IS NULL FOR UPDATE");
        $existing->execute([$employeeId, $today]);
        $row = $existing->fetch();
        if ($row) {
            $bounds = getShiftBounds($employeeShift, $today);
            $halfDay = getHalfDayRequest($pdo, $employeeId, $today);
            $approvedEarlyOut = $halfDay && $halfDay['status'] === 'APPROVED';

            if (!$approvedEarlyOut && $nowDt < $bounds['end']) {
                setFlash('error', 'You can only Time Out starting ' . $bounds['end']->format('g:i A') . ' (end of your ' . shiftLabel($employeeShift) . ' shift). Need to leave early? File a Half Day Request below.');
            } else {
                $newStatus = $row['status'];
                $newRemarks = $row['remarks'];
                if ($approvedEarlyOut && $nowDt < $bounds['end']) {
                    $newStatus = 'HALF_DAY';
                    $newRemarks = trim(($newRemarks ? $newRemarks . ' | ' : '') . 'Approved half day - left early at ' . date('g:i A', strtotime($now)));
                }
                $update = $pdo->prepare("UPDATE attendance SET time_out=?, status=?, remarks=? WHERE attendance_id=? AND time_out IS NULL");
                $update->execute([$now, $newStatus, $newRemarks, $row['attendance_id']]);
                if ($update->rowCount() === 1) {
                  $pdo->commit();
                  setFlash('success', 'Timed out at ' . date('g:i A', strtotime($now)) . ($newStatus === 'HALF_DAY' ? ' (Half Day).' : '.'));
                } else {
                  $pdo->rollBack();
                }
            }
              if ($pdo->inTransaction()) { $pdo->commit(); }
        }
      } catch (Throwable $exception) {
              if ($pdo->inTransaction()) { $pdo->rollBack(); }
              error_log('employee attendance clock-out error: ' . $exception->getMessage());
              setFlash('error', 'Time Out could not be recorded. No changes were made.');
        }
    } elseif ($action === 'request_half_day') {
        $reqDate = $_POST['request_date'] ?? '';
      $reasonInput = $_POST['half_day_reason'] ?? '';
      $reason = is_string($reasonInput) ? trim($reasonInput) : '';
      $reqDateObj = is_string($reqDate) ? DateTime::createFromFormat('!Y-m-d', $reqDate) : false;
      $reqDateErrors = DateTime::getLastErrors();
      $validReqDate = $reqDateObj && ($reqDateErrors === false || ($reqDateErrors['warning_count'] === 0 && $reqDateErrors['error_count'] === 0)) && $reqDateObj->format('Y-m-d') === $reqDate;
      if (!$validReqDate || $reqDate < $today) {
        setFlash('error', 'Half day request date must be a valid date today or later.');
        } elseif ($reason === '') {
            setFlash('error', 'Please provide a reason for the half day request.');
      } elseif (strlen($reason) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
        setFlash('error', 'Half day reason must be 2000 characters or fewer and cannot contain control characters.');
        } else {
            try {
                $pdo->prepare("INSERT INTO half_day_requests (employee_id, request_date, reason) VALUES (?,?,?)")
                    ->execute([$employeeId, $reqDate, $reason]);
                $newId = (int)$pdo->lastInsertId();
                setFlash('success', 'Half day request submitted for approval.');
                logAudit($pdo, $userId, 'FILE_HALF_DAY', 'Attendance', "employee_id=$employeeId date=$reqDate");
                $notifMsg = ($_SESSION['full_name'] ?? 'An employee') . ' requested a half day on ' . fdate($reqDate) . '.';
                notifyRole($pdo, ROLE_HR_STAFF, 'Half Day Request', $notifMsg, 'HALF_DAY', $newId);
                notifyRole($pdo, ROLE_HR_MANAGER, 'Half Day Request', $notifMsg, 'HALF_DAY', $newId);
            } catch (Exception $ex) {
                setFlash('error', 'You already have a half day request filed for that date.');
            }
        }
    } elseif ($action === 'cancel_half_day') {
      $hdId = filter_var($_POST['half_day_id'] ?? null, FILTER_VALIDATE_INT);
      if ($hdId === false || $hdId < 1) {
        setFlash('error', 'Invalid half day request.');
      } else {
        $cancel = $pdo->prepare("UPDATE half_day_requests SET status='CANCELLED' WHERE half_day_id=? AND employee_id=? AND status='PENDING'");
        $cancel->execute([$hdId, $employeeId]);
        setFlash($cancel->rowCount() === 1 ? 'success' : 'error', $cancel->rowCount() === 1 ? 'Half day request cancelled.' : 'That half day request is no longer pending.');
      }
    }
    redirect('modules/employee/attendance.php');
}

// Today's clock status
$todayDate = date('Y-m-d');
$nowTime = date('H:i:s');
$nowDt = new DateTime($todayDate . ' ' . $nowTime);
$todayRecord = null;
if ($employeeId) {
  $tr = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?");
  $tr->execute([$employeeId, $todayDate]);
    $todayRecord = $tr->fetch();
}

$shiftBoundsToday = getShiftBounds($employeeShift, $todayDate);
$earliestClockIn = earliestClockInTime($employeeShift, $todayDate);
$canClockInNow = $nowDt >= $earliestClockIn;

$todayHalfDay = $employeeId ? getHalfDayRequest($pdo, $employeeId, $todayDate) : null;
$approvedEarlyOutToday = $todayHalfDay && $todayHalfDay['status'] === 'APPROVED';
$canClockOutNow = $approvedEarlyOutToday || $nowDt >= $shiftBoundsToday['end'];

$month = $_GET['month'] ?? date('Y-m');
if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
  $month = date('Y-m');
}
if ($emp) {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND DATE_FORMAT(attendance_date,'%Y-%m')=? ORDER BY attendance_date DESC");
    $stmt->execute([$emp['employee_id'], $month]);
    $records = $stmt->fetchAll();
} else {
    $records = [];
}

$summary = ['PRESENT'=>0,'LATE'=>0,'ABSENT'=>0,'HALF_DAY'=>0,'ON_LEAVE'=>0];
foreach ($records as $r) { $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1; }

$myHalfDayRequests = [];
if ($employeeId) {
    $hdStmt = $pdo->prepare("SELECT * FROM half_day_requests WHERE employee_id=? ORDER BY filed_at DESC LIMIT 20");
    $hdStmt->execute([$employeeId]);
    $myHalfDayRequests = $hdStmt->fetchAll();
}

$pageTitle = 'My Attendance';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-clock-history"></i></div>
  <div>
    <h4>My Attendance</h4>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
      <h6 class="mb-1"><i class="bi bi-clock-history"></i> Today, <?= date('F j, Y') ?></h6>
      <div class="text-muted small mb-1">Your shift: <strong><?= e(shiftLabel($employeeShift)) ?></strong></div>
      <?php if ($todayRecord && $todayRecord['time_in']): ?>
        <span class="text-muted small">Timed in at <strong><?= date('g:i A', strtotime($todayRecord['time_in'])) ?></strong></span>
        <?php if ($todayRecord['time_out']): ?>
          <span class="text-muted small"> &middot; Timed out at <strong><?= date('g:i A', strtotime($todayRecord['time_out'])) ?></strong></span>
        <?php else: ?>
          <span class="text-muted small"> &middot; Time Out opens at <strong><?= $shiftBoundsToday['end']->format('g:i A') ?></strong><?= $approvedEarlyOutToday ? ' (or earlier - your Half Day request was approved)' : '' ?></span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-muted small">You haven't timed in yet today. Time In opens at <strong><?= $earliestClockIn->format('g:i A') ?></strong>.</span>
      <?php endif; ?>
    </div>
    <div class="text-end">
      <?php if (!$todayRecord || !$todayRecord['time_in']): ?>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="clock_in">
          <button class="btn btn-primary" <?= $canClockInNow ? '' : 'disabled' ?>><i class="bi bi-box-arrow-in-right"></i> Time In</button>
        </form>
        <?php if (!$canClockInNow): ?><div class="small text-muted mt-1">Opens <?= $earliestClockIn->format('g:i A') ?></div><?php endif; ?>
      <?php elseif (!$todayRecord['time_out']): ?>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="clock_out">
          <button class="btn btn-outline-primary" <?= $canClockOutNow ? '' : 'disabled' ?>><i class="bi bi-box-arrow-right"></i> Time Out</button>
        </form>
        <?php if (!$canClockOutNow): ?><div class="small text-muted mt-1">Opens <?= $shiftBoundsToday['end']->format('g:i A') ?> unless a Half Day is approved</div><?php endif; ?>
      <?php else: ?>
        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Completed for today</span>
      <?php endif; ?>
    </div>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3"><input type="month" name="month" class="form-control" value="<?= e($month) ?>" onchange="this.form.submit()"></div>
</form>

<div class="row g-3 mb-3">
  <?php foreach ($summary as $k => $v): ?>
    <div class="col"><div class="card text-center"><div class="card-body py-2"><small class="text-muted"><?= str_replace('_',' ',$k) ?></small><h5 class="mb-0"><?= $v ?></h5></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="attendanceTable">
      <thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Status</th><th>Remarks</th></tr></thead>
      <tbody>
      <?php foreach ($records as $r): ?>
        <tr>
          <td><?= fdate($r['attendance_date']) ?></td>
          <td><?= $r['time_in'] ? date('g:i A', strtotime($r['time_in'])) : '-' ?></td>
          <td><?= $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '-' ?></td>
          <td><span class="badge badge-status-<?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td><?= e($r['remarks']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($records)): ?><tr><td colspan="5" class="text-center text-muted">No attendance records for this month.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h6 class="mb-1">Half Day Requests</h6>
        <p class="text-muted small mb-0">Need to time out before your shift ends? File a request here first - you can only time out early once it's approved.</p>
      </div>
      <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#halfDayModal"><i class="bi bi-plus-circle"></i> Request Half Day</button>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="halfDayTable">
        <thead><tr><th>Date</th><th>Reason</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($myHalfDayRequests as $hd): ?>
          <tr>
            <td><?= fdate($hd['request_date']) ?></td>
            <td><?= e($hd['reason']) ?></td>
            <td><span class="badge bg-<?= halfDayStatusColor($hd['status']) ?>"><?= e($hd['status']) ?></span></td>
            <td><?= e($hd['review_remarks']) ?></td>
            <td>
              <?php if ($hd['status'] === 'PENDING'): ?>
                <form method="POST" onsubmit="return confirm('Cancel this half day request?')">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="action" value="cancel_half_day">
                  <input type="hidden" name="half_day_id" value="<?= $hd['half_day_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancel</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($myHalfDayRequests)): ?><tr><td colspan="5" class="text-center text-muted">No half day requests filed yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#attendanceTable').DataTable({ order: [[0, 'desc']] });
  $('#halfDayTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>

<div class="modal fade" id="halfDayModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="request_half_day">
      <div class="modal-header"><h5 class="modal-title">Request Half Day</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Date</label><input type="date" name="request_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required></div>
        <div class="mb-3"><label class="form-label">Reason</label><textarea name="half_day_reason" class="form-control" rows="3" maxlength="2000" required></textarea></div>
        <p class="text-muted small mb-0">This request needs HR approval before you'll be able to time out earlier than <?= $shiftBoundsToday['end']->format('g:i A') ?> on that date.</p>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Submit Request</button></div>
    </form>
  </div></div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>