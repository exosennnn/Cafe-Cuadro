<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_APPLICANT]);

$applicant = $pdo->prepare("SELECT * FROM applicants WHERE user_id = ?");
$applicant->execute([$_SESSION['user_id']]);
$applicant = $applicant->fetch();

$stats = $pdo->prepare("SELECT
    SUM(status='SUBMITTED' OR status='UNDER_REVIEW') AS pending,
    SUM(status='SHORTLISTED' OR status='INTERVIEW_SCHEDULED') AS shortlisted,
    SUM(status='OFFERED') AS offered,
    SUM(status='HIRED') AS hired,
    COUNT(*) AS total
    FROM job_applications WHERE applicant_id = ?");
$stats->execute([$applicant['applicant_id']]);
$stats = $stats->fetch();

$openJobs = $pdo->query("SELECT COUNT(*) AS cnt FROM job_vacancies WHERE status='OPEN'")->fetch()['cnt'];

$pageTitle = 'Applicant Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Welcome, <?= e($_SESSION['full_name']) ?> 👋</h4>

<?php if (empty($applicant['resume_path'])): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle"></i> You haven't uploaded a resume yet.
  <a href="<?= BASE_URL ?>applicant/profile" class="alert-link">Upload it now</a> to start applying for jobs.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="stat-card bg-grad-blue"><div class="d-flex justify-content-between"><div><small>Total Applications</small><h3><?= (int)$stats['total'] ?></h3></div><i class="bi bi-file-earmark-text icon"></i></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card bg-grad-orange"><div class="d-flex justify-content-between"><div><small>Under Review</small><h3><?= (int)$stats['pending'] ?></h3></div><i class="bi bi-hourglass-split icon"></i></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card bg-grad-purple"><div class="d-flex justify-content-between"><div><small>Shortlisted</small><h3><?= (int)$stats['shortlisted'] ?></h3></div><i class="bi bi-star icon"></i></div></div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card bg-grad-green"><div class="d-flex justify-content-between"><div><small>Open Job Positions</small><h3><?= (int)$openJobs ?></h3></div><i class="bi bi-briefcase icon"></i></div></div>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <h6 class="card-title">Quick Actions</h6>
    <a href="<?= BASE_URL ?>applicant/jobs" class="btn btn-primary btn-sm me-2"><i class="bi bi-briefcase"></i> Browse Job Openings</a>
    <a href="<?= BASE_URL ?>applicant/my-applications" class="btn btn-outline-secondary btn-sm me-2"><i class="bi bi-file-earmark-text"></i> View My Applications</a>
    <a href="<?= BASE_URL ?>applicant/profile" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person"></i> Update Profile / Resume</a>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
