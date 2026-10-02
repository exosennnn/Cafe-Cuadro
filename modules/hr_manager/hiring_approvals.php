<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

// HR Manager's role in this step: after the Department/Employee Manager
// gives a final recommendation of HIRE (status RECOMMENDED_FOR_HIRE), the
// HR Manager reviews and either APPROVES the hiring (-> HIRE_APPROVED, which
// unlocks HR Staff to send the job offer) or REJECTS it (-> REJECTED).

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $appId = (int)($_POST['application_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $notesInput = $_POST['approval_notes'] ?? '';
    $notes = is_string($notesInput) ? trim($notesInput) : '';

    if (!in_array($decision, ['approve', 'reject'], true)) {
        setFlash('error', 'Please select a valid decision.');
    } elseif ($notes === '' || strlen($notes) > 2000) {
      setFlash('error', 'Remarks are required and must be 2000 characters or fewer.');
    } else {
        $check = $pdo->prepare("SELECT * FROM job_applications WHERE application_id=? AND status='RECOMMENDED_FOR_HIRE'");
        $check->execute([$appId]);
        $app = $check->fetch();

        if (!$app) {
            setFlash('error', 'This application is no longer awaiting hiring approval.');
        } else {
            $newStatus = $decision === 'approve' ? 'HIRE_APPROVED' : 'REJECTED';
          $update = $pdo->prepare("UPDATE job_applications SET status=?, hiring_approved_by=?, hiring_approved_at=NOW(), hiring_approval_notes=? WHERE application_id=? AND status='RECOMMENDED_FOR_HIRE'");
          $update->execute([$newStatus, $userId, $notes, $appId]);

          if ($update->rowCount() !== 1) {
            setFlash('error', 'This application is no longer awaiting hiring approval.');
          } else {
            setFlash('success', 'Hiring ' . ($decision === 'approve' ? 'approved.' : 'rejected.'));
            logAudit($pdo, $userId, 'HIRING_APPROVAL_DECISION', 'Recruitment', "application_id=$appId decision=$decision");

            $info = $pdo->prepare("SELECT u.user_id AS applicant_user_id,
                COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
                jv.title AS job_title
              FROM job_applications ja
              JOIN applicants a ON ja.applicant_id = a.applicant_id
              LEFT JOIN users u ON a.user_id = u.user_id
              JOIN job_vacancies jv ON ja.job_id = jv.job_id
              WHERE ja.application_id=?");
            $info->execute([$appId]);
            $info = $info->fetch();

            if ($info) {
              if ($decision === 'approve') {
                notifyRole($pdo, ROLE_HR_STAFF, 'Hiring Approved - Ready for Job Offer',
                  "{$info['first_name']} {$info['last_name']} ({$info['job_title']}) has been approved for hiring by the HR Manager. Please send the job offer.",
                  'INTERVIEW', $appId);
              } elseif ($info['applicant_user_id']) {
                createNotification($pdo, (int)$info['applicant_user_id'], 'Application Update',
                  "Thank you for interviewing for \"{$info['job_title']}\". We will not be moving forward with your application at this time.",
                  'INTERVIEW', $appId);
              }
                }
            }
        }
    }
    redirect('modules/hr_manager/hiring_approvals.php');
}

$allowedStatusFilters = ['RECOMMENDED_FOR_HIRE', 'HIRE_APPROVED', 'REJECTED', ''];
$statusFilter = $_GET['status'] ?? 'RECOMMENDED_FOR_HIRE';
if (!is_string($statusFilter) || !in_array($statusFilter, $allowedStatusFilters, true)) {
  $statusFilter = 'RECOMMENDED_FOR_HIRE';
}
$where = '1=1'; $params = [];
if ($statusFilter !== '') { $where .= " AND ja.status=?"; $params[] = $statusFilter; }

$stmt = $pdo->prepare("SELECT ja.*,
        COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
        COALESCE(a.email, u.email) AS email, jv.title AS job_title,
        approver.first_name AS approver_first_name, approver.last_name AS approver_last_name,
        fi.evaluation_notes AS final_interview_notes, fi.evaluated_at AS final_interview_evaluated_at
    FROM job_applications ja
    JOIN applicants a ON ja.applicant_id = a.applicant_id
    LEFT JOIN users u ON a.user_id = u.user_id
    JOIN job_vacancies jv ON ja.job_id = jv.job_id
    LEFT JOIN users approver ON ja.hiring_approved_by = approver.user_id
    LEFT JOIN interviews fi ON fi.application_id = ja.application_id AND fi.stage = 'FINAL_DEPARTMENT'
    WHERE $where ORDER BY ja.updated_at DESC");
$stmt->execute($params);
$applications = $stmt->fetchAll();

$pageTitle = 'Approve Hiring';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Approve Hiring</h4>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="RECOMMENDED_FOR_HIRE" <?= $statusFilter==='RECOMMENDED_FOR_HIRE'?'selected':'' ?>>Awaiting My Approval</option>
      <option value="HIRE_APPROVED" <?= $statusFilter==='HIRE_APPROVED'?'selected':'' ?>>Approved (Awaiting Offer)</option>
      <option value="REJECTED" <?= $statusFilter==='REJECTED'?'selected':'' ?>>Rejected</option>
      <option value="" <?= $statusFilter===''?'selected':'' ?>>All</option>
    </select>
  </div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="hiringApprovalsTable">
      <thead><tr><th>Applicant</th><th>Position</th><th>Final Interview Notes</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($applications as $app): ?>
        <tr>
          <td><?= e($app['first_name'].' '.$app['last_name']) ?><br><small class="text-muted"><?= e($app['email']) ?></small></td>
          <td><?= e($app['job_title']) ?></td>
          <td><small><?= e($app['final_interview_notes'] ?: '-') ?></small></td>
          <td>
            <span class="badge bg-<?= $app['status']==='HIRE_APPROVED'?'success':($app['status']==='REJECTED'?'danger':'warning text-dark') ?>"><?= str_replace('_',' ',$app['status']) ?></span>
            <?php if ($app['status'] !== 'RECOMMENDED_FOR_HIRE' && $app['hiring_approved_by']): ?>
              <br><small class="text-muted">by <?= e($app['approver_first_name'].' '.$app['approver_last_name']) ?> on <?= fdate($app['hiring_approved_at']) ?></small>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($app['status'] === 'RECOMMENDED_FOR_HIRE'): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#decisionModal<?= $app['application_id'] ?>"><i class="bi bi-check2"></i> Decide</button>
            <?php else: ?>
              <small class="text-muted"><?= e($app['hiring_approval_notes'] ?: '') ?></small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($applications)): ?><tr><td colspan="5" class="text-center text-muted">No applications found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($applications as $app): ?>
  <?php if ($app['status'] === 'RECOMMENDED_FOR_HIRE'): ?>
    <div class="modal fade" id="decisionModal<?= $app['application_id'] ?>" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="application_id" value="<?= $app['application_id'] ?>">
          <div class="modal-header"><h6 class="modal-title">Hiring Decision</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
          <div class="modal-body">
            <p><?= e($app['first_name'].' '.$app['last_name']) ?> &mdash; <?= e($app['job_title']) ?></p>
            <?php if (!empty($app['final_interview_notes'])): ?><p class="small text-muted">Final interview notes: <?= e($app['final_interview_notes']) ?></p><?php endif; ?>
            <div class="mb-3"><label class="form-label">Decision</label>
              <select name="decision" class="form-select" required><option value="approve">Approve Hiring</option><option value="reject">Reject</option></select>
            </div>
            <div class="mb-3"><label class="form-label">Remarks</label><textarea name="approval_notes" class="form-control" maxlength="2000" required></textarea></div>
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
  $('#hiringApprovalsTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
