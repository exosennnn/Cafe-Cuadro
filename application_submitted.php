<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$ref = trim($_GET['ref'] ?? '');
$app = null;
if ($ref !== '') {
    $stmt = $pdo->prepare("SELECT ja.reference_code, ja.applied_at, jv.title AS job_title,
            COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
            COALESCE(a.email, u.email) AS email
        FROM job_applications ja
        JOIN applicants a ON ja.applicant_id = a.applicant_id
        LEFT JOIN users u ON a.user_id = u.user_id
        JOIN job_vacancies jv ON ja.job_id = jv.job_id
        WHERE ja.reference_code = ?");
    $stmt->execute([$ref]);
    $app = $stmt->fetch();
}

$returnDate = date('F j, Y', strtotime('+7 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Application Submitted | <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/hrms/css/style.css?v=<?= ASSET_VER ?>">
<style>
  @media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
  }
  /* Scoped override: align this page with the Purr'Coffee warm café theme */
  body.apply-page {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    color: #2a1810;
  }
  body.apply-page h1, body.apply-page h2, body.apply-page h3,
  body.apply-page h4, body.apply-page h5, body.apply-page h6,
  body.apply-page .btn {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }
  body.apply-page.auth-page {
    background: linear-gradient(160deg, rgba(29,16,10,0.85) 0%, rgba(42,24,16,0.8) 50%, rgba(42,24,16,0.92) 100%),
      url("assets/hrms/img/bg-hero-coffee.jpg");
    background-blend-mode: multiply;
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
  }
  body.apply-page .text-white { color: #ffffff !important; }
  body.apply-page .auth-card { border-radius: 22px; border-top: 4px solid #5e6b46; }
  body.apply-page .auth-card h4, body.apply-page .auth-card h5, body.apply-page .auth-card h6 { color: #2a1810; font-weight: 800; }
  body.apply-page .btn-primary {
    background-color: #5e6b46;
    border-color: #5e6b46;
    border-radius: 50px;
    color: #fff;
    font-weight: 700;
  }
  body.apply-page .btn-primary:hover, body.apply-page .btn-primary:focus {
    background-color: #4c5838;
    border-color: #4c5838;
    color: #fff;
  }
  body.apply-page .btn-outline-secondary {
    color: #2a1810;
    border-color: #e6d8c5;
    border-radius: 50px;
    font-weight: 600;
  }
  body.apply-page .btn-outline-secondary:hover {
    background-color: #eef1e4;
    border-color: #5e6b46;
    color: #5e6b46;
  }
  body.apply-page a { color: #5e6b46; }
  body.apply-page a:hover { color: #4c5838; }
  body.apply-page .apply-brand {
    font-family: "Fraunces", Georgia, serif;
    font-weight: 600;
    font-size: 2.75rem;
    letter-spacing: -0.01em;
    text-transform: none;
    background: linear-gradient(rgba(255,255,255,0.98), rgba(255,255,255,0.55));
    -webkit-text-fill-color: transparent;
    background-clip: text;
    -webkit-background-clip: text;
    display: inline-block;
  }
  body.apply-page .auth-card p,
  body.apply-page .auth-card span,
  body.apply-page .auth-card label,
  body.apply-page .auth-card a {
    font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
  }
</style>
<link rel="stylesheet" href="assets/shared/responsive.css?v=<?= defined('ASSET_VER') ? ASSET_VER : time() ?>">
</head>
<body class="auth-page apply-page">
<div class="container py-5">
  <div class="text-center mb-4 no-print">
    <a href="<?= BASE_URL ?>" class="text-decoration-none">
      <span class="apply-brand"><?= APP_NAME ?></span>
    </a>
  </div>

  <?php if (!$app): ?>
    <div class="card auth-card shadow-lg mx-auto" style="max-width:560px;">
      <div class="card-body p-4 text-center">
        <i class="bi bi-exclamation-circle fs-1 text-warning"></i>
        <h5 class="fw-bold mt-2">Application Not Found</h5>
        <p class="text-muted">We couldn't find an application for that reference code.</p>
        <a href="<?= BASE_URL ?>" class="btn btn-primary">Back to Home</a>
      </div>
    </div>
  <?php else: ?>
    <div class="card auth-card shadow-lg mx-auto" id="receipt" style="max-width:640px;">
      <div class="card-body p-4">
        <div class="text-center mb-4">
          <i class="bi bi-check-circle-fill fs-1 text-success"></i>
          <h4 class="fw-bold mt-2 mb-1">Application Submitted!</h4>
          <p class="text-muted mb-0">Thank you, <?= e($app['first_name']) ?>. Here's your acknowledgment receipt.</p>
        </div>

        <div class="text-center p-4 mb-4" style="background:#eef1e4; border:2px dashed #5e6b46; border-radius:16px;">
          <p class="text-muted small text-uppercase mb-1">Your Unique Reference Code</p>
          <h2 class="fw-bold mb-0" style="letter-spacing:2px; color:#5e6b46;"><?= e($app['reference_code']) ?></h2>
        </div>

        <table class="table table-sm table-borderless mb-4">
          <tr><th class="text-muted" style="width:40%;">Applicant Name</th><td><?= e($app['first_name'] . ' ' . $app['last_name']) ?></td></tr>
          <tr><th class="text-muted">Email</th><td><?= e($app['email']) ?></td></tr>
          <tr><th class="text-muted">Position Applied For</th><td><?= e($app['job_title']) ?></td></tr>
          <tr><th class="text-muted">Date Submitted</th><td><?= date('F j, Y g:i A', strtotime($app['applied_at'])) ?></td></tr>
        </table>

        <div class="alert alert-warning small">
          <i class="bi bi-info-circle"></i>
          Please keep this Reference Code. Return on or after <strong><?= $returnDate ?></strong> to check the status of
          your application using your Unique Reference Code on the <strong>Check Application Status</strong> page.
          If your application is shortlisted, your interview schedule and further instructions will be available there.
        </div>

        <div class="d-flex gap-2 no-print">
          <button onclick="window.print()" class="btn btn-outline-secondary flex-fill"><i class="bi bi-printer"></i> Print</button>
          <a href="<?= BASE_URL ?>apply-receipt?ref=<?= urlencode($app['reference_code']) ?>" class="btn btn-outline-secondary flex-fill"><i class="bi bi-download"></i> Download</a>
          <a href="<?= BASE_URL ?>check-status?ref=<?= urlencode($app['reference_code']) ?>" class="btn btn-primary flex-fill"><i class="bi bi-search"></i> Check Status</a>
        </div>
        <p class="text-center small mt-3 mb-0 no-print"><a href="<?= BASE_URL ?>">&larr; Back to Home</a></p>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
