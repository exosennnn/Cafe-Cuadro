<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_APPLICANT]);

$userId = $_SESSION['user_id'];
$applicant = $pdo->prepare("SELECT * FROM applicants WHERE user_id = ?");
$applicant->execute([$userId]);
$applicant = $applicant->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $offerId = (int)($_POST['offer_id'] ?? 0);
    $redirectTo = 'modules/applicant/my_applications.php';

    if (in_array($action, ['accept_offer', 'decline_offer'])) {
      try {
        $pdo->beginTransaction();
        $offer = $pdo->prepare("SELECT jo.*, ja.applicant_id, ja.status AS application_status FROM job_offers jo
            JOIN job_applications ja ON jo.application_id = ja.application_id
        WHERE jo.offer_id = ? AND ja.applicant_id = ? FOR UPDATE");
        $offer->execute([$offerId, $applicant['applicant_id']]);
        $offer = $offer->fetch();

        if (!$offer || $offer['application_status'] !== 'OFFERED' || !in_array($offer['status'], ['PENDING', 'SENT'], true)) {
          throw new RuntimeException('This offer is no longer available for a response.');
        }
            $isAccept = $action === 'accept_offer';
            $response = $isAccept ? 'ACCEPTED' : 'DECLINED';
            $appStatus = $isAccept ? 'ACCEPTED' : 'DECLINED';
        $offerUpdate = $pdo->prepare("UPDATE job_offers SET status=? WHERE offer_id=? AND status IN ('PENDING','SENT')");
        $offerUpdate->execute([$response, $offerId]);
        if ($offerUpdate->rowCount() !== 1) {
          throw new RuntimeException('This offer has already been answered.');
        }
        $applicationUpdate = $pdo->prepare("UPDATE job_applications SET status=? WHERE application_id=? AND status='OFFERED'");
        $applicationUpdate->execute([$appStatus, $offer['application_id']]);
        if ($applicationUpdate->rowCount() !== 1) {
          throw new RuntimeException('The application is no longer awaiting an offer response.');
        }
        $pdo->commit();
            logAudit($pdo, $userId, 'OFFER_RESPONSE', 'Applicant', $response . ' offer_id=' . $offerId);

            if ($isAccept) {
              // Acceptance does not create or upgrade an account. The
              // applicant remains a guest until HR completes onboarding
              // alongside the employment contract.
              setFlash('success', 'Offer accepted! HR will finalize your employment account and contract onboarding shortly.');
            } else {
                setFlash('success', 'Your response has been recorded: ' . $response);
            }
          } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            setFlash('error', 'Offer response could not be saved. Please try again.');
        }
    }
    redirect($redirectTo);
}

$applications = $pdo->prepare("SELECT ja.*, jv.title, jv.employment_type AS job_employment_type, d.department_name,
  jo.offer_id, jo.offered_salary, jo.employment_type AS offer_employment_type,
  jo.remarks AS offer_details, jo.status AS offer_response
    FROM job_applications ja
    JOIN job_vacancies jv ON ja.job_id = jv.job_id
    LEFT JOIN departments d ON jv.department_id = d.department_id
    LEFT JOIN job_offers jo ON jo.application_id = ja.application_id
    WHERE ja.applicant_id = ?
    ORDER BY ja.applied_at DESC");
$applications->execute([$applicant['applicant_id']]);
$applications = $applications->fetchAll();

// The interview workflow now has up to two stages per application (HR Initial,
// Final Department), so interviews are fetched separately and grouped by stage
// instead of a single-row LEFT JOIN.
$interviewsByApp = [];
if (!empty($applications)) {
    $appIds = array_column($applications, 'application_id');
    $placeholders = implode(',', array_fill(0, count($appIds), '?'));
    $ivStmt = $pdo->prepare("SELECT * FROM interviews WHERE application_id IN ($placeholders) ORDER BY schedule_date ASC");
    $ivStmt->execute($appIds);
    foreach ($ivStmt->fetchAll() as $iv) {
        $interviewsByApp[$iv['application_id']][$iv['stage']] = $iv;
    }
}

function statusBadge($status) {
    $map = [
        'SUBMITTED' => 'secondary', 'UNDER_REVIEW' => 'info', 'SHORTLISTED' => 'primary',
        'INTERVIEW_SCHEDULED' => 'warning', 'HR_INTERVIEW_PASSED' => 'primary',
        'FINAL_INTERVIEW_SCHEDULED' => 'warning', 'RECOMMENDED_FOR_HIRE' => 'success',
        'HIRE_APPROVED' => 'success',
        'OFFERED' => 'success', 'ACCEPTED' => 'success',
        'DECLINED' => 'danger', 'REJECTED' => 'danger', 'HIRED' => 'success',
    ];
    $color = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $color . '">' . str_replace('_', ' ', $status) . '</span>';
}

$pageTitle = 'My Applications';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">My Applications</h4>

<?php if (empty($applications)): ?>
  <div class="alert alert-info">You haven't applied to any jobs yet. <a href="<?= BASE_URL ?>applicant/jobs">Browse job openings</a>.</div>
<?php endif; ?>

<?php foreach ($applications as $app): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between flex-wrap">
        <div>
          <h6 class="fw-bold mb-1"><?= e($app['title']) ?></h6>
          <p class="small text-muted mb-2"><?= e($app['department_name'] ?? 'General') ?> &middot; Applied <?= fdate($app['applied_at'], 'M d, Y') ?></p>
        </div>
        <div><?= statusBadge($app['status']) ?></div>
      </div>

      <?php foreach (['HR_INITIAL', 'FINAL_DEPARTMENT'] as $stage): ?>
        <?php if (!empty($interviewsByApp[$app['application_id']][$stage])): $iv = $interviewsByApp[$app['application_id']][$stage]; ?>
          <?php if ($iv['status'] === 'PENDING_SCHEDULE' || empty($iv['schedule_date'])): ?>
            <div class="alert alert-warning py-2 mb-2">
              <i class="bi bi-calendar-event"></i> <strong><?= e(interviewStageLabel($stage)) ?>:</strong>
              Passed to the hiring department &mdash; awaiting a schedule from the department manager.
            </div>
          <?php else: ?>
            <div class="alert alert-warning py-2 mb-2">
              <i class="bi bi-calendar-event"></i> <strong><?= e(interviewStageLabel($stage)) ?>:</strong>
              <?= date('M d, Y g:i A', strtotime($iv['schedule_date'])) ?>
              &middot; <?= e($iv['mode']) ?> <?= $iv['location'] ? '(' . e($iv['location']) . ')' : '' ?>
              &middot; Status: <?= e($iv['status']) ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      <?php endforeach; ?>

      <?php if (!empty($app['offer_id'])): ?>
        <div class="alert alert-success py-2 mb-2">
          <i class="bi bi-gift"></i> <strong>Job Offer Received</strong><br>
          Offered Salary: <?= fmoney($app['offered_salary']) ?><br>
          Employment Type: <?= e(EMPLOYMENT_TYPE_LABELS[$app['offer_employment_type']] ?? str_replace('_',' ',$app['offer_employment_type'])) ?><br>
          Work Schedule: <?= (int)WORK_DAYS_PER_WEEK ?> days a week, <?= (int)WORK_HOURS_PER_DAY ?> hours a day<br>
          Payroll Cut-off: Every <?= (int)PAYROLL_CUTOFF_DAYS ?> days<br>
          <span class="text-muted small">Your shift schedule will be assigned once you're hired.</span><br>
          <?= nl2br(e($app['offer_details'])) ?><br>
          <a href="<?= BASE_URL ?>hr-staff/contract?application_id=<?= $app['application_id'] ?>" class="btn btn-sm btn-outline-dark mt-1"><i class="bi bi-file-earmark-text"></i> View Employment Contract</a><br>
          <strong>Your Response:</strong> <?= e($app['offer_response']) ?>
          <?php if (in_array($app['offer_response'], ['PENDING', 'SENT'], true)): ?>
            <div class="mt-2">
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="accept_offer">
                <input type="hidden" name="offer_id" value="<?= $app['offer_id'] ?>">
                <button class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Accept Offer</button>
              </form>
              <form method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="decline_offer">
                <input type="hidden" name="offer_id" value="<?= $app['offer_id'] ?>">
                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to decline this offer?')"><i class="bi bi-x-circle"></i> Decline Offer</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
