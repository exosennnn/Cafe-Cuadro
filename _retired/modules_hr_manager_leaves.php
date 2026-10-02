<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
  $leaveId = (int)($_POST['leave_id'] ?? 0);
  $decision = $_POST['decision'] ?? '';
  $remarksInput = $_POST['review_remarks'] ?? '';
  $remarks = is_string($remarksInput) ? trim($remarksInput) : '';

  if (!in_array($decision, ['approve', 'reject'], true)) {
    setFlash('error', 'Please select a valid decision.');
  } elseif (strlen($remarks) > 2000) {
    setFlash('error', 'Remarks must be 2000 characters or fewer.');
  } else {
    try {
      $pdo->beginTransaction();

      $check = $pdo->prepare("SELECT * FROM leave_requests WHERE leave_id=? AND status='PENDING_APPROVAL'");
      $check->execute([$leaveId]);
      $leaveRow = $check->fetch();

      if (!$leaveRow) {
        $pdo->rollBack();
        setFlash('error', 'This leave request is no longer awaiting final approval.');
      } else {
        $newStatus = $decision === 'approve' ? 'APPROVED' : 'REJECTED';
        $update = $pdo->prepare("UPDATE leave_requests SET status=?, reviewed_by=?, reviewed_at=NOW(), review_remarks=? WHERE leave_id=? AND status='PENDING_APPROVAL'");
        $update->execute([$newStatus, $userId, $remarks, $leaveId]);

        if ($update->rowCount() !== 1) {
          $pdo->rollBack();
          setFlash('error', 'This leave request is no longer awaiting final approval.');
        } else {
          if ($newStatus === 'APPROVED') {
            // Mark attendance as ON_LEAVE for every date in the approved range, unless the employee already timed in that day
            $period = new DatePeriod(new DateTime($leaveRow['date_from']), new DateInterval('P1D'), (new DateTime($leaveRow['date_to']))->modify('+1 day'));
            $upsert = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status, remarks) VALUES (?,?,'ON_LEAVE','Auto-marked: approved leave')
              ON DUPLICATE KEY UPDATE status = IF(time_in IS NULL, 'ON_LEAVE', status), remarks = IF(time_in IS NULL, 'Auto-marked: approved leave', remarks)");
            foreach ($period as $day) {
              $upsert->execute([$leaveRow['employee_id'], $day->format('Y-m-d')]);
            }
          }

          $pdo->commit();
          setFlash('success', 'Leave request ' . strtolower($newStatus) . '.');
          logAudit($pdo, $userId, 'FINAL_APPROVE_LEAVE', 'Leave', "leave_id=$leaveId status=$newStatus");
        }
            }
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      error_log('leaves.php approval error: ' . $exception->getMessage());
      setFlash('error', 'Leave request could not be processed. No changes were made.');
        }
    }
    redirect('modules/hr_manager/leaves.php');
}

$allowedStatusFilters = ['PENDING_APPROVAL', 'PENDING_MANAGER', 'PENDING_HR', 'APPROVED', 'REJECTED', ''];
$statusFilter = $_GET['status'] ?? 'PENDING_APPROVAL';
if (!is_string($statusFilter) || !in_array($statusFilter, $allowedStatusFilters, true)) {
  $statusFilter = 'PENDING_APPROVAL';
}
$where = '1=1'; $params = [];
if ($statusFilter !== '') { $where .= " AND lr.status=?"; $params[] = $statusFilter; }

$stmt = $pdo->prepare("SELECT lr.*, e.employee_code, u.first_name, u.last_name, lt.type_name
    FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id
    WHERE $where ORDER BY lr.filed_at DESC");
$stmt->execute($params);
$leaves = $stmt->fetchAll();

$pageTitle = 'Leave Final Approval';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Leave Final Approval</h4>
<p class="text-muted small">These requests have already been reviewed by the employee's manager and processed by HR Staff. This is the final decision — the employee will see the result immediately.</p>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="PENDING_APPROVAL" <?= $statusFilter==='PENDING_APPROVAL'?'selected':'' ?>>Awaiting My Final Approval</option>
      <option value="PENDING_MANAGER" <?= $statusFilter==='PENDING_MANAGER'?'selected':'' ?>>Awaiting Manager Review</option>
      <option value="PENDING_HR" <?= $statusFilter==='PENDING_HR'?'selected':'' ?>>Awaiting HR Processing</option>
      <option value="APPROVED" <?= $statusFilter==='APPROVED'?'selected':'' ?>>Approved</option>
      <option value="REJECTED" <?= $statusFilter==='REJECTED'?'selected':'' ?>>Rejected</option>
      <option value="" <?= $statusFilter===''?'selected':'' ?>>All</option>
    </select>
  </div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="leavesTable">
      <thead><tr><th>Employee</th><th>Leave Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($leaves as $l): ?>
        <tr>
          <td><?= e($l['first_name'].' '.$l['last_name']) ?> <small class="text-muted">(<?= e($l['employee_code']) ?>)</small></td>
          <td><?= e($l['type_name']) ?></td>
          <td><?= fdate($l['date_from']) ?> - <?= fdate($l['date_to']) ?></td>
          <td><?= (int)$l['total_days'] ?></td>
          <td><?= e($l['reason']) ?></td>
          <td><span class="badge bg-<?= leaveStatusColor($l['status']) ?>"><?= e(leaveStatusLabel($l['status'])) ?></span></td>
          <td>
            <?php if ($l['status'] === 'PENDING_APPROVAL'): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#actModal<?= $l['leave_id'] ?>"><i class="bi bi-check2"></i> Review</button>
            <?php else: ?>
              <small class="text-muted">Filed <?= fdate($l['filed_at']) ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($leaves)): ?><tr><td colspan="7" class="text-center text-muted">No leave requests found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($leaves as $l): ?>
  <?php if ($l['status'] === 'PENDING_APPROVAL'): ?>
    <div class="modal fade" id="actModal<?= $l['leave_id'] ?>" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="leave_id" value="<?= $l['leave_id'] ?>">
          <div class="modal-header"><h6 class="modal-title">Final Decision</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <p><?= e($l['first_name'].' '.$l['last_name']) ?> &mdash; <?= e($l['type_name']) ?> (<?= (int)$l['total_days'] ?> day/s)</p>
            <?php if (!empty($l['manager_remarks'])): ?><p class="small text-muted mb-1">Manager: <?= e($l['manager_remarks']) ?></p><?php endif; ?>
            <?php if (!empty($l['hr_remarks'])): ?><p class="small text-muted">HR Staff: <?= e($l['hr_remarks']) ?></p><?php endif; ?>
            <div class="mb-3"><label class="form-label">Decision</label>
              <select name="decision" class="form-select" required><option value="approve">Approve (Final)</option><option value="reject">Reject</option></select>
            </div>
            <div class="mb-3"><label class="form-label">Remarks</label><textarea name="review_remarks" class="form-control" maxlength="2000"></textarea></div>
          </div>
          <div class="modal-footer"><button type="submit" class="btn btn-primary">Submit</button></div>
        </form>
      </div></div>
    </div>
  <?php endif; ?>
<?php endforeach; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#leavesTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
