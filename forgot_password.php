<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    redirect('dashboard');
}

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Only proceed for a real, active account — but the confirmation
        // message below is identical either way so we don't leak which
        // emails exist in the system.
        if ($user && $user['status'] === 'ACTIVE') {
            // Don't stack duplicate requests: reuse an existing PENDING one.
            $existing = $pdo->prepare("SELECT request_id FROM password_reset_requests WHERE user_id = ? AND status = 'PENDING' LIMIT 1");
            $existing->execute([$user['user_id']]);

            if (!$existing->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO password_reset_requests (user_id, unlock_at, ip_address)
                    VALUES (?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)");
                $stmt->execute([$user['user_id'], PASSWORD_RESET_LOCK_MINUTES, $_SERVER['REMOTE_ADDR'] ?? '']);
                $requestId = (int)$pdo->lastInsertId();

                notifyRole(
                    $pdo,
                    ROLE_HR_MANAGER,
                    'Password Reset Requested',
                    $user['first_name'] . ' ' . $user['last_name'] . ' (' . $user['email'] . ') forgot their password and is requesting a reset. Available for review in ' . PASSWORD_RESET_LOCK_MINUTES . ' minutes.',
                    'PASSWORD_RESET',
                    $requestId
                );
                logAudit($pdo, $user['user_id'], 'PASSWORD_RESET_REQUESTED', 'Auth', 'request_id=' . $requestId);
            }
        } else {
            // No matching/active account — log the attempt for security visibility,
            // but still show the generic confirmation message to the visitor.
            logAudit($pdo, null, 'PASSWORD_RESET_REQUEST_UNKNOWN_EMAIL', 'Auth', 'Email attempted: ' . $email);
        }
    }

    $submitted = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/hrms/css/style.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="assets/shared/unified.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="assets/shared/responsive.css?v=<?= defined('ASSET_VER') ? ASSET_VER : time() ?>">
<style>
  body.landing-page {
    background: linear-gradient(160deg, #f5ebdd 0%, #efe2cf 50%, #f5ebdd 100%) !important;
    min-height: 100vh;
  }
  .auth-card {
    background: #ffffff !important;
    border: 1px solid #efe2cf !important;
    border-top: 4px solid var(--primary, #5e6b46) !important;
    border-radius: 24px !important;
    box-shadow: 0 12px 36px rgba(42,24,16, 0.08) !important;
  }
  .auth-icon-badge {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: #eef1e4;
    color: #5e6b46;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    margin-bottom: 12px;
    border: 1px solid rgba(94,107,70, 0.2);
  }
</style>
</head>
<body class="landing-page">
  <div class="container py-5">
    <div class="text-center mb-4">
      <div class="auth-icon-badge mx-auto"><i class="bi bi-shield-lock-fill"></i></div>
      <h3 class="fw-bold mt-1 text-dark"><?= APP_NAME ?></h3>
      <p class="text-muted"><?= APP_TAGLINE ?></p>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-5 col-md-7">
        <div class="card auth-card">
          <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
              <h5 class="fw-bold text-dark mt-1">Forgot Password</h5>
              <p class="text-muted small mb-0">We'll notify HR to reset it for you</p>
            </div>

            <?php if ($submitted): ?>
              <div class="alert alert-success small">
                <i class="bi bi-check-circle"></i>
                If that email has an account with us, we've notified HR.
              </div>
              <a href="<?= BASE_URL ?>" class="btn btn-outline-secondary w-100">Back to Login</a>
            <?php else: ?>
              <p class="small text-muted">
                Enter the email address on your account to notify HR for password reset.
              </p>
              <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div class="mb-3">
                  <label class="form-label">Email Address</label>
                  <input type="email" name="email" class="form-control" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary w-100">Notify HR</button>
              </form>
              <p class="text-center small mt-3 mb-0">
                <a href="<?= BASE_URL ?>"><i class="bi bi-arrow-left"></i> Back to Login</a>
              </p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

