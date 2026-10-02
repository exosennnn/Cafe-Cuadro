<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT employee_id, shift FROM employees WHERE user_id=?");
$emp->execute([$userId]);
$emp = $emp->fetch();
$employeeId = $emp['employee_id'] ?? 0;
$employeeShift = $emp['shift'] ?? DEFAULT_SHIFT;

// Handle Time In / Time Out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employeeId) {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $now = date('H:i:s');

    if ($action === 'clock_in') {
        $existing = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id=? AND attendance_date=?");
        $existing->execute([$employeeId, $today]);
        if (!$existing->fetch()) {
            $clockResult = computeClockInStatus($now, $employeeShift);
            $deductionAmount = $clockResult['status'] === 'LATE' ? computeLateDeductionAmount($clockResult['minutes_late']) : 0;
            $remarks = $clockResult['status'] === 'LATE'
                ? 'Late by ' . $clockResult['minutes_late'] . ' min(s) - ' . fmoney($deductionAmount) . ' deduction'
                : null;
            $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, time_in, status, remarks) VALUES (?,?,?,?,?)")
                ->execute([$employeeId, $today, $now, $clockResult['status'], $remarks]);
            if ($clockResult['status'] === 'LATE') {
                logAttendanceDeduction($pdo, (int)$pdo->lastInsertId(), $employeeId, $deductionAmount, 'Late arrival: ' . $clockResult['minutes_late'] . ' minute(s) past ' . shiftLabel($employeeShift) . ' shift start');
                setFlash('error', 'Timed in late at ' . date('g:i A', strtotime($now)) . ' (' . $clockResult['minutes_late'] . ' min late) - ' . fmoney($deductionAmount) . ' deduction recorded.');
            } else {
                setFlash('success', 'Timed in at ' . date('g:i A', strtotime($now)) . '.');
            }
        }
    } elseif ($action === 'clock_out') {
        $existing = $pdo->prepare("SELECT attendance_id FROM attendance WHERE employee_id=? AND attendance_date=? AND time_out IS NULL");
        $existing->execute([$employeeId, $today]);
        $row = $existing->fetch();
        if ($row) {
            $pdo->prepare("UPDATE attendance SET time_out=? WHERE attendance_id=?")->execute([$now, $row['attendance_id']]);
            setFlash('success', 'Timed out at ' . date('g:i A', strtotime($now)) . '.');
        }
    }
    redirect('modules/employee_manager/my_attendance.php');
}

// Today's clock status
$todayRecord = null;
if ($employeeId) {
    $tr = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=CURDATE()");
    $tr->execute([$employeeId]);
    $todayRecord = $tr->fetch();
}

$month = $_GET['month'] ?? date('Y-m');
if ($emp) {
    $stmt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id=? AND DATE_FORMAT(attendance_date,'%Y-%m')=? ORDER BY attendance_date DESC");
    $stmt->execute([$emp['employee_id'], $month]);
    $records = $stmt->fetchAll();
} else {
    $records = [];
}

$summary = ['PRESENT'=>0,'LATE'=>0,'ABSENT'=>0,'HALF_DAY'=>0,'ON_LEAVE'=>0];
foreach ($records as $r) { $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1; }

$pageTitle = 'My Attendance';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-clock-history"></i></div>
  <div>
    <h4>My Attendance</h4>
  </div>
</div>

<div class="card border-0 shadow-sm rounded-3 mb-3">
  <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
    <div>
      <h6 class="mb-1"><i class="bi bi-clock-history"></i> Today, <?= date('F j, Y') ?></h6>
      <?php if ($todayRecord && $todayRecord['time_in']): ?>
        <span class="text-muted small">Timed in at <strong><?= date('g:i A', strtotime($todayRecord['time_in'])) ?></strong></span>
        <?php if ($todayRecord['time_out']): ?>
          <span class="text-muted small"> &middot; Timed out at <strong><?= date('g:i A', strtotime($todayRecord['time_out'])) ?></strong></span>
        <?php else: ?>
          <span class="text-muted small"> &middot; Expected time out: <strong><?= date('g:i A', strtotime($todayRecord['time_in'] . ' +8 hours')) ?></strong> (8-hour shift)</span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-muted small">You haven't timed in yet today.</span>
      <?php endif; ?>
    </div>
    <div>
      <?php if (!$todayRecord || !$todayRecord['time_in']): ?>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="clock_in">
          <button class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> Time In</button>
        </form>
      <?php elseif (!$todayRecord['time_out']): ?>
        <form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="clock_out">
          <button class="btn btn-outline-primary"><i class="bi bi-box-arrow-right"></i> Time Out</button>
        </form>
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
    <div class="col"><div class="card border-0 shadow-sm rounded-3 text-center"><div class="card-body py-2"><small class="text-muted"><?= str_replace('_',' ',$k) ?></small><h5 class="mb-0"><?= $v ?></h5></div></div></div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="myAttendanceTable">
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

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#myAttendanceTable').DataTable({ order: [[0, 'desc']] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
