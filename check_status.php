  <?php
  require_once __DIR__ . '/config/db.php';
  require_once __DIR__ . '/includes/auth.php';
  require_once __DIR__ . '/includes/functions.php';

  $ref = trim($_GET['ref'] ?? $_POST['ref'] ?? '');
  $app = null;
  $notFound = false;

  if ($ref !== '') {
      $stmt = $pdo->prepare("SELECT ja.application_id, ja.reference_code, ja.status, ja.applied_at,
              jv.title AS job_title, d.department_name,
              COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
              COALESCE(a.email, u.email) AS login_email
          FROM job_applications ja
          JOIN applicants a ON ja.applicant_id = a.applicant_id
          LEFT JOIN users u ON a.user_id = u.user_id
          JOIN job_vacancies jv ON ja.job_id = jv.job_id
          LEFT JOIN departments d ON jv.department_id = d.department_id
          WHERE ja.reference_code = ?");
      $stmt->execute([$ref]);
      $app = $stmt->fetch();
      $notFound = !$app;
  }

  $interviews = [];
  $offer = null;
  if ($app) {
      $ivStmt = $pdo->prepare("SELECT * FROM interviews WHERE application_id = ? ORDER BY schedule_date ASC");
      $ivStmt->execute([$app['application_id']]);
      foreach ($ivStmt->fetchAll() as $iv) {
          $interviews[$iv['stage']] = $iv;
      }

      $offerStmt = $pdo->prepare("SELECT jo.*, jo.remarks AS offer_details, jo.status AS offer_response FROM job_offers jo WHERE jo.application_id = ?");
      $offerStmt->execute([$app['application_id']]);
      $offer = $offerStmt->fetch() ?: null;
  }

  function appStatusMeta(string $status): array {
      $map = [
          'SUBMITTED'                 => ['secondary', 'Submitted', 'Your application has been received and is waiting to be reviewed.'],
          'UNDER_REVIEW'              => ['info', 'Under Review', 'HR is currently reviewing your application.'],
          'SHORTLISTED'               => ['primary', 'Shortlisted', 'You have been shortlisted! Watch this page for your interview schedule.'],
          'INTERVIEW_SCHEDULED'       => ['warning', 'HR Interview Scheduled', 'See your interview details below.'],
          'HR_INTERVIEW_PASSED'       => ['primary', 'Passed HR Interview', 'You passed the HR Initial Interview. The final department interview will be scheduled soon.'],
          'FINAL_INTERVIEW_SCHEDULED' => ['warning', 'Final Interview Scheduled', 'See your interview details below.'],
          'RECOMMENDED_FOR_HIRE'      => ['success', 'Recommended for Hire', 'Congratulations! Your application is awaiting final approval from HR.'],
          'HIRE_APPROVED'             => ['success', 'Hiring Approved', 'Great news! Your hiring has been approved. HR will follow up with a job offer soon.'],
          'OFFERED'                   => ['success', 'Job Offer Sent', 'A job offer has been prepared for you. Please contact HR for next steps.'],
          'ACCEPTED'                  => ['success', 'Offer Accepted', 'You have accepted the job offer. HR will finalize your employment account and contract onboarding shortly.'],
          'DECLINED'                  => ['danger', 'Offer Declined', 'You have declined the job offer.'],
          'REJECTED'                  => ['danger', 'Not Selected', 'Thank you for your interest. You were not selected for this position.'],
          'HIRED'                     => ['success', 'Hired', 'Welcome aboard! Your employee account has been created - please contact HR for your onboarding schedule.'],
      ];
      return $map[$status] ?? ['secondary', str_replace('_',' ',$status), ''];
  }

  $pageTitle = 'Check Application Status';
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Check Application Status | <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/hrms/css/style.css?v=<?= ASSET_VER ?>">
  <style>
    /* Scoped override: align this page with the landing page's blue glass theme */
    body.status-page {
      font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
      color: #2a1810;
    }
    body.status-page h1, body.status-page h2, body.status-page h3,
    body.status-page h4, body.status-page h5, body.status-page h6,
    body.status-page .btn {
      font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
    body.status-page.auth-page {
      background: linear-gradient(160deg, rgba(29,16,10,0.85) 0%, rgba(42,24,16,0.8) 50%, rgba(42,24,16,0.92) 100%),
        url("assets/hrms/img/bg-hero-coffee.jpg");
      background-blend-mode: multiply;
      background-position: center;
      background-repeat: no-repeat;
      background-size: cover;
    }
    body.status-page .text-white { color: #ffffff !important; }
    body.status-page .auth-card { border-radius: 22px; border-top: 4px solid #5e6b46; }
    body.status-page .auth-card h5 { color: #2a1810; font-weight: 800; }
    body.status-page .auth-card .bi-search.text-primary { color: #5e6b46 !important; }
    body.status-page .btn-primary {
      background-color: #5e6b46;
      border-color: #5e6b46;
      border-radius: 50px;
      color: #fff;
      font-weight: 700;
    }
    body.status-page .btn-primary:hover, body.status-page .btn-primary:focus {
      background-color: #4c5838;
      border-color: #4c5838;
      color: #fff;
    }
    body.status-page .btn-outline-secondary {
      color: #2a1810;
      border-color: #e6d8c5;
      border-radius: 50px;
      font-weight: 600;
    }
    body.status-page .btn-outline-secondary:hover {
      background-color: #eef1e4;
      border-color: #5e6b46;
      color: #5e6b46;
    }
    body.status-page a { color: #5e6b46; }
    body.status-page a:hover { color: #4c5838; }
    body.status-page .form-control:focus {
      border-color: #5e6b46;
      box-shadow: 0 0 0 0.2rem rgba(94,107,70, 0.25);
    }
    body.status-page .status-brand {
      font-family: "Fraunces", Georgia, serif;
      font-weight: 700;
      font-size: 2.75rem;
      letter-spacing: 0.2rem;
      text-transform: uppercase;
      background: linear-gradient(rgba(255,255,255,0.98), rgba(255,255,255,0.55));
      -webkit-text-fill-color: transparent;
      background-clip: text;
      -webkit-background-clip: text;
      display: inline-block;
    }
    body.status-page .auth-card p,
    body.status-page .auth-card span,
    body.status-page .auth-card label,
    body.status-page .auth-card a {
      font-family: "DM Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    }
  </style>
  <link rel="stylesheet" href="assets/shared/responsive.css?v=<?= defined('ASSET_VER') ? ASSET_VER : time() ?>">
  </head>
  <body class="auth-page status-page">
  <div class="container py-5">
    <div class="text-center mb-4">
      <a href="<?= BASE_URL ?>" class="text-decoration-none">
        <span class="status-brand"><?= APP_NAME ?></span>
      </a>
    </div>

    <div class="card auth-card shadow-lg mx-auto" style="max-width:640px;">
      <div class="card-body p-4">
        <div class="text-center mb-4">
          <i class="bi bi-search fs-2 text-primary"></i>
          <h5 class="fw-bold mt-2">Check Application Status</h5>
          <p class="text-muted small mb-0">Enter the Unique Reference Code you received when you applied.</p>
        </div>

        <form method="GET" class="d-flex gap-2 mb-4">
          <input type="text" name="ref" class="form-control text-uppercase" placeholder="e.g. APP-20260728-K7F2" value="<?= e($ref) ?>" required>
          <button class="btn btn-primary flex-shrink-0"><i class="bi bi-search"></i> Check</button>
        </form>

        <?php if ($notFound): ?>
          <div class="alert alert-danger small"><i class="bi bi-exclamation-circle"></i> No application found for that reference code. Please double-check and try again.</div>
        <?php endif; ?>

        <?php if ($app): [$color, $label, $desc] = appStatusMeta($app['status']); ?>
          <div class="p-3 mb-3" style="background:var(--bg-light,#f5f8ff); border-radius:10px;">
            <p class="text-muted small text-uppercase mb-1">Reference Code</p>
            <h5 class="fw-bold mb-3"><?= e($app['reference_code']) ?></h5>
            <p class="mb-1"><strong><?= e($app['first_name'] . ' ' . $app['last_name']) ?></strong></p>
            <p class="text-muted small mb-2"><?= e($app['job_title']) ?> &middot; <?= e($app['department_name'] ?? 'General') ?> &middot; Applied <?= fdate($app['applied_at']) ?></p>
            <span class="badge bg-<?= $color ?> fs-6"><?= e($label) ?></span>
            <?php if ($desc): ?><p class="small text-muted mt-2 mb-0"><?= e($desc) ?></p><?php endif; ?>
          </div>

          <?php foreach (['HR_INITIAL' => 'HR Initial Interview', 'FINAL_DEPARTMENT' => 'Final Department Interview'] as $stage => $stageLabel): ?>
            <?php if (!empty($interviews[$stage])): $iv = $interviews[$stage]; ?>
              <div class="alert alert-warning py-3 mb-3">
                <p class="fw-bold mb-2"><i class="bi bi-calendar-event"></i> <?= e($stageLabel) ?></p>
                <p class="mb-1"><strong>Date &amp; Time:</strong> <?= date('F j, Y g:i A', strtotime($iv['schedule_date'])) ?></p>
                <p class="mb-1"><strong>Mode:</strong> <?= e(ucfirst(strtolower($iv['mode']))) ?></p>
                <?php if (!empty($iv['location'])): ?><p class="mb-1"><strong>Venue / Meeting Link:</strong> <?= e($iv['location']) ?></p><?php endif; ?>
                <?php if (!empty($iv['interviewer'])): ?><p class="mb-1"><strong>Interviewer:</strong> <?= e($iv['interviewer']) ?></p><?php endif; ?>
                <?php if (!empty($iv['notes'])): ?><p class="mb-0"><strong>Instructions:</strong> <?= nl2br(e($iv['notes'])) ?></p><?php endif; ?>
                <p class="small text-muted mt-2 mb-0">Status: <?= e(ucfirst(strtolower($iv['status']))) ?></p>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>

          <?php if ($offer): ?>
            <div class="alert alert-success py-3 mb-3">
              <p class="fw-bold mb-2"><i class="bi bi-gift"></i> Job Offer</p>
              <p class="mb-1"><strong>Offered Salary:</strong> <?= fmoney($offer['offered_salary']) ?></p>
              <p class="mb-1"><strong>Employment Type:</strong> <?= e(EMPLOYMENT_TYPE_LABELS[$offer['employment_type']] ?? str_replace('_',' ',$offer['employment_type'])) ?></p>
              <?php if (!empty($offer['offer_details'])): ?><p class="mb-1"><?= nl2br(e($offer['offer_details'])) ?></p><?php endif; ?>
              <p class="small text-muted mt-2 mb-0">
               <?php if (($offer['offer_response'] ?? null) === 'PENDING'): ?>
  Please visit or call the HR office to formally accept or decline this offer and complete your requirements.
<?php else: ?>
  Your response on file: <strong><?= e($offer['offer_response'] ?? 'N/A') ?></strong>
<?php endif; ?>
              </p>
            </div>
          <?php endif; ?>

          <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>apply-receipt?ref=<?= urlencode($app['reference_code']) ?>" class="btn btn-outline-secondary flex-fill"><i class="bi bi-download"></i> Download Receipt</a>
          </div>
        <?php endif; ?>

        <p class="text-center small mt-4 mb-0"><a href="<?= BASE_URL ?>">&larr; Back to Home</a></p>
      </div>
    </div>
  </div>
  </body>
  </html>3x
