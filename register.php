<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) redirect('dashboard');

$errors = [];
$old = ['first_name'=>'','last_name'=>'','email'=>'','phone'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $old['first_name'] = trim($_POST['first_name'] ?? '');
    $old['last_name']  = trim($_POST['last_name'] ?? '');
    $old['email']      = trim($_POST['email'] ?? '');
    $old['phone']      = trim($_POST['phone'] ?? '');
    $password  = $_POST['password'] ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';

    $phoneDigits = preg_replace('/\D+/', '', $old['phone']);

    if ($old['first_name'] === '' || $old['last_name'] === '' || $old['email'] === '') {
        $errors[] = 'Please fill in all required fields.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($old['phone'] !== '' && !preg_match('/^09\d{9}$/', $phoneDigits)) {
        $errors[] = 'Please enter a valid 11-digit Philippine mobile number starting with 09.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$old['email']]);
        if ($stmt->fetch()) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (role_id, email, password_hash, first_name, last_name, phone, status) VALUES (?,?,?,?,?,?, 'ACTIVE')");
            $stmt->execute([ROLE_APPLICANT, $old['email'], $hash, $old['first_name'], $old['last_name'], $old['phone']]);
            $userId = $pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO applicants (user_id) VALUES (?)");
            $stmt->execute([$userId]);

            $pdo->commit();
            logAudit($pdo, $userId, 'REGISTER', 'Applicant', 'New applicant account created');
            redirect('?registered=1');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('register.php DB error: ' . $e->getMessage());
            $errors[] = 'Registration failed due to a technical issue. Please try again in a moment.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Applicant Registration | <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="assets/hrms/css/style.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="assets/shared/unified.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="assets/shared/responsive.css?v=<?= defined('ASSET_VER') ? ASSET_VER : time() ?>">
<style>
  body.auth-page {
    background: linear-gradient(160deg, #f5ebdd 0%, #efe2cf 50%, #f5ebdd 100%) !important;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
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
<body class="auth-page">
  <div class="card auth-card" style="max-width:520px; width:100%;">
    <div class="card-body p-4 p-md-5">
      <div class="text-center mb-4">
        <div class="auth-icon-badge"><i class="bi bi-person-plus-fill"></i></div>
        <h4 class="fw-bold mt-1 text-dark">Create Applicant Account</h4>
        <p class="text-muted small">Register to browse and apply for job openings</p>
      </div>

      <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger small"><?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>

      <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($old['first_name']) ?>">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($old['last_name']) ?>">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($old['email']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label">Phone Number</label>
          <input type="tel" name="phone" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($old['phone']) ?>">
          <div class="form-text">Enter 11 digits, starting with 09.</div>
        </div>
        <div class="row">
          <div class="col-md-6 mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" required minlength="8">
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password" class="form-control" required minlength="8">
          </div>
        </div>
        <button type="submit" class="btn btn-primary w-100">Register</button>
      </form>
      <p class="text-center small mt-3 mb-0">Already have an account? <a href="<?= BASE_URL ?>">Login here</a></p>
    </div>
  </div>
</body>
</html>

