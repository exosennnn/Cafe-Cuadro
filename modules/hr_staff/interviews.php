<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole([ROLE_HR_STAFF]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'schedule') {
      setFlash('error', 'Schedule an HR Initial Interview from the shortlisted applicant list.');
    } elseif ($action === 'update_interview_status') {
    $interviewId = filter_var($_POST['interview_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $_POST['status'] ?? '';

    if (
        $interviewId === false ||
        $interviewId < 1 ||
        !in_array($status, ['SCHEDULED', 'COMPLETED', 'CANCELLED', 'NO_SHOW'], true)
    ) {
        setFlash('error', 'Invalid interview status update.');
    } else {
        // Get the interview and its scheduled date/time first.
        $interviewStmt = $pdo->prepare("
            SELECT interview_id, schedule_date, status
            FROM interviews
            WHERE interview_id = ?
              AND stage = 'HR_INITIAL'
        ");
        $interviewStmt->execute([$interviewId]);
        $interview = $interviewStmt->fetch();

        if (!$interview) {
            setFlash('error', 'That interview could not be found.');
        } elseif (
            $status === 'COMPLETED' &&
            (
                empty($interview['schedule_date']) ||
                strtotime($interview['schedule_date']) > time()
            )
        ) {
            setFlash(
                'error',
                'This interview cannot be marked completed before its scheduled date and time.'
            );
        } else {
            $update = $pdo->prepare("
                UPDATE interviews
                SET status = ?
                WHERE interview_id = ?
                  AND stage = 'HR_INITIAL'
            ");
            $update->execute([$status, $interviewId]);

            if ($update->rowCount() === 1) {
                setFlash('success', 'Interview status updated.');
                logAudit(
                    $pdo,
                    $userId,
                    'UPDATE_INTERVIEW_STATUS',
                    'Recruitment',
                    "interview_id=$interviewId status=$status"
                );
            } else {
                setFlash('error', 'That interview could not be updated.');
            }
        }
    }
}
    redirect('modules/hr_staff/interviews.php');
}

$interviews = $pdo->query("SELECT i.*, ja.application_id, ja.status AS app_status,
    COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
    u.profile_photo, jv.title AS job_title
    FROM interviews i
    JOIN job_applications ja ON i.application_id=ja.application_id
    JOIN applicants a ON ja.applicant_id=a.applicant_id
    LEFT JOIN users u ON a.user_id=u.user_id
    JOIN job_vacancies jv ON ja.job_id=jv.job_id
    WHERE i.stage='HR_INITIAL'
    ORDER BY i.schedule_date DESC")->fetchAll();

$pageTitle = 'Schedule Interviews';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-mic-fill"></i></div>
    <div>
      <h4>Schedule Interviews</h4>
    </div>
  </div>
</div>

<style>
  #interviewsTable th:last-child,
  #interviewsTable td:last-child { min-width: 170px; }
  #interviewsTable td:last-child form { width: 160px; }
  #interviewsTable td:last-child .form-select { min-width: 160px; white-space: nowrap; }
</style>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="interviewsTable">
      <thead><tr><th>Photo</th><th>Applicant</th><th>Job</th><th>Stage</th><th>Schedule</th><th>Mode</th><th>Location</th><th>Interviewer</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($interviews as $i): ?>
        <tr>
          <td><?= renderAvatar($i['profile_photo'], $i['first_name'], $i['last_name'], 36) ?></td>
          <td><?= e($i['first_name'].' '.$i['last_name']) ?></td>
          <td><?= e($i['job_title']) ?></td>
          <td><span class="badge bg-secondary"><?= e(interviewStageLabel($i['stage'])) ?></span></td>
          <td><?= $i['schedule_date'] ? date('M d, Y g:i A', strtotime($i['schedule_date'])) : '<span class="text-muted">Not yet set</span>' ?></td>
          <td><?= e($i['mode']) ?></td>
          <td><?= e($i['location']) ?></td>
          <td><?= e($i['interviewer']) ?></td>
          <td><span class="badge bg-secondary"><?= e($i['status']) ?></span></td>
          <td>
            <?php if ($i['status'] !== 'PENDING_SCHEDULE'): ?>
              <form method="POST" class="d-flex gap-1">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="update_interview_status">
                <input type="hidden" name="interview_id" value="<?= (int)$i['interview_id'] ?>">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php foreach (['SCHEDULED','COMPLETED','CANCELLED','NO_SHOW'] as $s): ?>
                    <option value="<?= $s ?>" <?= $i['status']===$s?'selected':'' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $('#interviewsTable').DataTable({
    order: [],
    columnDefs: [{ orderable: false, targets: [0, -1] }],
    language: { emptyTable: 'No HR Initial Interviews found.' }
  });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>