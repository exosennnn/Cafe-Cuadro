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
    $leaveId = (int)$_POST['leave_id'];
    $decision = $_POST['decision']; // 'forward' or 'reject'
    $remarks = trim($_POST['review_remarks'] ?? '');
    // Verify the leave belongs to an Employee-role staff member and is still awaiting manager review
    $check = $pdo->prepare("SELECT lr.leave_id FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
        WHERE lr.leave_id=? AND u.role_id=? AND lr.status='PENDING_MANAGER'");
    $check->execute([$leaveId, ROLE_EMPLOYEE]);
    if ($check->fetch()) {
        $newStatus = $decision === 'forward' ? 'PENDING_HR' : 'REJECTED';
        $pdo->prepare("UPDATE leave_requests SET status=?, manager_reviewed_by=?, manager_reviewed_at=NOW(), manager_remarks=? WHERE leave_id=?")
            ->execute([$newStatus, $userId, $remarks, $leaveId]);
        setFlash('success', $decision === 'forward' ? 'Leave request forwarded to HR for processing.' : 'Leave request rejected.');
        logAudit($pdo, $userId, 'REVIEW_LEAVE', 'Leave', "leave_id=$leaveId status=$newStatus");
    } else {
        setFlash('error', 'You are not authorized to review this leave request, or it has already been reviewed.');
    }
    redirect('modules/employee_manager/leaves.php');
}

$statusFilter = $_GET['status'] ?? 'PENDING_MANAGER';
$where = "u.role_id = ?"; $params = [ROLE_EMPLOYEE];
if ($statusFilter !== '') { $where .= " AND lr.status=?"; $params[] = $statusFilter; }

$stmt = $pdo->prepare("SELECT lr.*, e.employee_code, u.first_name, u.last_name, lt.type_name
    FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id
    WHERE $where ORDER BY lr.filed_at DESC");
$stmt->execute($params);
$leaves = $stmt->fetchAll();

$pageTitle = 'Leave Approvals';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-calendar-check-fill"></i></div>
  <div>
    <h4>Leave Approvals</h4>
    <p>You review leave requests first. Approving here forwards the request to HR for processing; rejecting here ends it immediately.</p>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="PENDING_MANAGER" <?= $statusFilter==='PENDING_MANAGER'?'selected':'' ?>>Awaiting My Review</option>
      <option value="PENDING_HR" <?= $statusFilter==='PENDING_HR'?'selected':'' ?>>Forwarded to HR</option>
      <option value="PENDING_APPROVAL" <?= $statusFilter==='PENDING_APPROVAL'?'selected':'' ?>>Awaiting Final Approval</option>
      <option value="APPROVED" <?= $statusFilter==='APPROVED'?'selected':'' ?>>Approved</option>
      <option value="REJECTED" <?= $statusFilter==='REJECTED'?'selected':'' ?>>Rejected</option>
      <option value="" <?= $statusFilter===''?'selected':'' ?>>All</option>
    </select>
  </div>
</form>

<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="leavesTable">
      <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($leaves as $l): ?>
        <tr>
          <td><?= e($l['first_name'].' '.$l['last_name']) ?></td>
          <td><?= e($l['type_name']) ?></td>
          <td><?= fdate($l['date_from']) ?> - <?= fdate($l['date_to']) ?></td>
          <td><?= (int)$l['total_days'] ?></td>
          <td><?= e($l['reason']) ?></td>
          <td><span class="badge bg-<?= leaveStatusColor($l['status']) ?>"><?= e(leaveStatusLabel($l['status'])) ?></span></td>
          <td>
            <?php if ($l['status'] === 'PENDING_MANAGER'): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#m<?= $l['leave_id'] ?>"><i class="bi bi-check2"></i> Review</button>
            <?php endif; ?>
          </td>
        </tr>
        <div class="modal fade" id="m<?= $l['leave_id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="leave_id" value="<?= $l['leave_id'] ?>">
              <div class="modal-header"><h6 class="modal-title">Review Leave Request</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
              <div class="modal-body">
                <p class="small text-muted">Approving here recommends this request and forwards it to HR Staff for balance/requirement checks. Rejecting here ends the request immediately &mdash; the employee will be notified.</p>
                <div class="mb-3"><label class="form-label">Decision</label>
                  <select name="decision" class="form-select"><option value="forward">Approve &amp; Forward to HR</option><option value="reject">Reject</option></select>
                </div>
                <div class="mb-3"><label class="form-label">Remarks</label><textarea name="review_remarks" class="form-control"></textarea></div>
              </div>
              <div class="modal-footer"><button class="btn btn-primary">Submit</button></div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($leaves)): ?><tr><td colspan="7" class="text-center text-muted">No leave requests found.</td></tr><?php endif; ?>
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
