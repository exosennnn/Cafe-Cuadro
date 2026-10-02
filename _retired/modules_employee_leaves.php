<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF]);

$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id=?");
$emp->execute([(int)$userId]);
$emp = $emp->fetch();
$employeeId = $emp ? (int)$emp['employee_id'] : 0;
$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employeeId > 0) {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    if ($action === 'file') {
      $typeId = filter_var($_POST['leave_type_id'] ?? null, FILTER_VALIDATE_INT);
      $from = is_string($_POST['date_from'] ?? null) ? trim($_POST['date_from']) : '';
      $to = is_string($_POST['date_to'] ?? null) ? trim($_POST['date_to']) : '';
      $reasonInput = $_POST['reason'] ?? '';
      $reason = is_string($reasonInput) ? trim($reasonInput) : '';
      $fromDate = DateTime::createFromFormat('!Y-m-d', $from);
      $toDate = DateTime::createFromFormat('!Y-m-d', $to);
      $fromErrors = DateTime::getLastErrors();
      $toErrors = DateTime::getLastErrors();
      $validFrom = $fromDate && ($fromErrors === false || ($fromErrors['warning_count'] === 0 && $fromErrors['error_count'] === 0)) && $fromDate->format('Y-m-d') === $from;
      $validTo = $toDate && ($toErrors === false || ($toErrors['warning_count'] === 0 && $toErrors['error_count'] === 0)) && $toDate->format('Y-m-d') === $to;

      if ($typeId === false || $typeId < 1) {
        setFlash('error', 'Please select a valid leave type.');
      } elseif (!$validFrom || !$validTo) {
        setFlash('error', 'Leave dates must be valid YYYY-MM-DD dates.');
      } elseif ($from < $today || $to < $today) {
        setFlash('error', 'Leave dates must be today or later.');
      } elseif ($toDate < $fromDate) {
        setFlash('error', 'Leave end date cannot be earlier than the start date.');
      } elseif ($reason === '' || strlen($reason) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $reason)) {
        setFlash('error', 'Reason is required, must be 2000 characters or fewer, and cannot contain control characters.');
        } else {
        $typeCheck = $pdo->prepare('SELECT leave_type_id FROM leave_types WHERE leave_type_id=?');
        $typeCheck->execute([$typeId]);
        if (!$typeCheck->fetchColumn()) {
          setFlash('error', 'The selected leave type does not exist.');
        } else {
          $days = $fromDate->diff($toDate)->days + 1;
          $conflict = $pdo->prepare("SELECT leave_id FROM leave_requests
            WHERE employee_id=? AND status IN ('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL','APPROVED')
            AND date_from <= ? AND date_to >= ? LIMIT 1");
          $conflict->execute([$employeeId, $to, $from]);
          if ($conflict->fetch()) {
            setFlash('error', 'The requested dates overlap an existing active leave request.');
          } else {
            try {
              $insert = $pdo->prepare("INSERT INTO leave_requests (employee_id, leave_type_id, date_from, date_to, total_days, reason) VALUES (?,?,?,?,?,?)");
              $insert->execute([$employeeId, $typeId, $from, $to, $days, $reason]);
              setFlash('success', 'Leave request submitted for approval.');
              logAudit($pdo, $userId, 'FILE_LEAVE', 'Leave', "days=$days");
            } catch (Throwable $exception) {
              error_log('employee leave filing error: ' . $exception->getMessage());
              setFlash('error', 'Leave request could not be submitted.');
            }
          }
        }
        }
    } elseif ($action === 'cancel') {
      $leaveId = filter_var($_POST['leave_id'] ?? null, FILTER_VALIDATE_INT);
      if ($leaveId === false || $leaveId < 1) {
        setFlash('error', 'Invalid leave request.');
      } else {
        $cancel = $pdo->prepare("UPDATE leave_requests SET status='CANCELLED' WHERE leave_id=? AND employee_id=? AND status IN ('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL')");
        $cancel->execute([$leaveId, $employeeId]);
        if ($cancel->rowCount() === 1) {
          setFlash('success', 'Leave request cancelled.');
        } else {
          setFlash('error', 'That leave request is no longer pending or does not belong to you.');
        }
      }
    }
    redirect('modules/employee/leaves.php');
  } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    setFlash('error', 'Your employee record could not be found.');
    redirect('modules/employee/leaves.php');
}

$leaveTypes = $pdo->query("SELECT * FROM leave_types ORDER BY type_name")->fetchAll();
$myLeaves = $pdo->prepare("SELECT lr.*, lt.type_name FROM leave_requests lr JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id
    WHERE lr.employee_id=? ORDER BY lr.filed_at DESC");
$myLeaves->execute([$employeeId]);
$myLeaves = $myLeaves->fetchAll();

$pageTitle = 'Leave Requests';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-calendar-check-fill"></i></div>
    <div>
      <h4>My Leave Requests</h4>
      <p>File new requests and track approval status.</p>
    </div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#leaveModal"><i class="bi bi-plus-circle"></i> File Leave Request</button>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="leavesTable">
      <thead><tr><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($myLeaves as $l): ?>
        <?php
          $latestRemark = $l['review_remarks'] ?: ($l['hr_remarks'] ?: $l['manager_remarks']);
          $cancellable = in_array($l['status'], ['PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL']);
        ?>
        <tr>
          <td><?= e($l['type_name']) ?></td>
          <td><?= fdate($l['date_from']) ?> - <?= fdate($l['date_to']) ?></td>
          <td><?= (int)$l['total_days'] ?></td>
          <td><?= e($l['reason']) ?></td>
          <td><span class="badge bg-<?= leaveStatusColor($l['status']) ?>"><?= e(leaveStatusLabel($l['status'])) ?></span></td>
          <td><?= e($latestRemark) ?></td>
          <td>
            <?php if ($cancellable): ?>
              <form method="POST" onsubmit="return confirm('Cancel this leave request?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="leave_id" value="<?= $l['leave_id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Cancel</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($myLeaves)): ?><tr><td colspan="7" class="text-center text-muted">No leave requests filed yet.</td></tr><?php endif; ?>
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
  $('#leavesTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>

<div class="modal fade" id="leaveModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="file">
      <div class="modal-header"><h5 class="modal-title">File Leave Request</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Leave Type</label>
          <select name="leave_type_id" class="form-select" required>
            <?php foreach ($leaveTypes as $t): ?><option value="<?= $t['leave_type_id'] ?>"><?= e($t['type_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">From</label><input type="date" name="date_from" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
          <div class="col-md-6 mb-3"><label class="form-label">To</label><input type="date" name="date_to" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="2000" required></textarea></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Submit Request</button></div>
    </form>
  </div></div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
