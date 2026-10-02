<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_STAFF]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $leaveId = (int)$_POST['leave_id'];
    $decision = $_POST['decision']; // 'forward' or 'reject'
    $remarks = trim($_POST['review_remarks'] ?? '');
    $check = $pdo->prepare("SELECT leave_id FROM leave_requests WHERE leave_id=? AND status='PENDING_HR'");
    $check->execute([$leaveId]);
    if ($check->fetch()) {
        $newStatus = $decision === 'forward' ? 'PENDING_APPROVAL' : 'REJECTED';
        $pdo->prepare("UPDATE leave_requests SET status=?, hr_processed_by=?, hr_processed_at=NOW(), hr_remarks=? WHERE leave_id=?")
            ->execute([$newStatus, $userId, $remarks, $leaveId]);
        setFlash('success', $decision === 'forward' ? 'Leave request forwarded to HR Manager for final approval.' : 'Leave request rejected.');
        logAudit($pdo, $userId, 'PROCESS_LEAVE', 'Leave', "leave_id=$leaveId status=$newStatus");
    } else {
        setFlash('error', 'This leave request is no longer awaiting HR processing.');
    }
    redirect('modules/hr_staff/leaves.php');
}

$statusFilter = $_GET['status'] ?? 'PENDING_HR';
$where = '1=1'; $params = [];
if ($statusFilter !== '') { $where .= " AND lr.status=?"; $params[] = $statusFilter; }

$stmt = $pdo->prepare("SELECT lr.*, e.employee_id, e.employee_code, u.first_name, u.last_name, lt.type_name, lt.default_days
    FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id
    WHERE $where ORDER BY lr.filed_at DESC");
$stmt->execute($params);
$leaves = $stmt->fetchAll();

// For each leave, compute how many days of that leave type the employee has already used this year (approved)
$usedDaysStmt = $pdo->prepare("SELECT COALESCE(SUM(total_days),0) AS used FROM leave_requests
    WHERE employee_id=? AND leave_type_id=? AND status='APPROVED' AND YEAR(date_from)=YEAR(CURDATE())");

$pageTitle = 'Leave Request Processing';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-calendar-event-fill"></i></div>
  <div>
    <h4>Leave Request Processing</h4>
    <p>Check balances and forward manager-recommended requests to the HR Manager.</p>
  </div>
</div>
<p class="text-muted small">Requests here have already been recommended by the employee's manager. Check leave balance and requirements, then forward to the HR Manager for final approval.</p>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="PENDING_HR" <?= $statusFilter==='PENDING_HR'?'selected':'' ?>>Awaiting HR Processing</option>
      <option value="PENDING_MANAGER" <?= $statusFilter==='PENDING_MANAGER'?'selected':'' ?>>Awaiting Manager Review</option>
      <option value="PENDING_APPROVAL" <?= $statusFilter==='PENDING_APPROVAL'?'selected':'' ?>>Awaiting Final Approval</option>
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
            <?php if ($l['status'] === 'PENDING_HR'): ?>
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
  <?php if ($l['status'] === 'PENDING_HR'): ?>
    <?php
      $usedDaysStmt->execute([$l['employee_id'], $l['leave_type_id']]);
      $usedDays = (int)$usedDaysStmt->fetch()['used'];
      $remainingDays = max(0, (int)$l['default_days'] - $usedDays);
    ?>
    <div class="modal fade" id="actModal<?= $l['leave_id'] ?>" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="leave_id" value="<?= $l['leave_id'] ?>">
          <div class="modal-header"><h6 class="modal-title">Review Leave Request</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <p><?= e($l['first_name'].' '.$l['last_name']) ?> &mdash; <?= e($l['type_name']) ?> (<?= (int)$l['total_days'] ?> day/s)</p>
            <div class="alert alert-<?= $remainingDays >= $l['total_days'] ? 'info' : 'warning' ?> py-2 small">
              <i class="bi bi-info-circle"></i> Leave balance: used <strong><?= $usedDays ?></strong> of <strong><?= (int)$l['default_days'] ?></strong> days this year
              (<strong><?= $remainingDays ?></strong> remaining).
              <?php if ($remainingDays < $l['total_days']): ?>
                <br><strong>⚠ This request exceeds the employee's remaining balance for this leave type.</strong>
              <?php endif; ?>
            </div>
            <?php if (!empty($l['manager_remarks'])): ?>
              <p class="small text-muted">Manager's remarks: <?= e($l['manager_remarks']) ?></p>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label">Decision</label>
              <select name="decision" class="form-select"><option value="forward">Approve &amp; Forward to HR Manager</option><option value="reject">Reject</option></select>
            </div>
            <div class="mb-3"><label class="form-label">Remarks</label><textarea name="review_remarks" class="form-control" placeholder="e.g. balance verified, requirements complete"></textarea></div>
          </div>
          <div class="modal-footer"><button class="btn btn-primary">Submit</button></div>
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