<?php
require 'kiosk_bootstrap.php';

if (kiosk_customer_logged_in()) {
    header('Location: ' . BASE_URL . 'pos/kiosk-account');
    exit;
}

$message = '';
$full_name = '';
$username  = '';
$email     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kiosk_csrf_valid()) {
        $message = 'Your session expired. Please try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($full_name === '' || $username === '' || $email === '' || $password === '') {
            $message = 'Please fill in all fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $message = 'Password must be at least 6 characters.';
        } else {
            try {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $nameParts = preg_split('/\s+/', $full_name, 2);
                $firstName = $nameParts[0];
                $lastName  = $nameParts[1] ?? '';
                $stmt = $conn->prepare("INSERT INTO users (role_id, username, email, first_name, last_name, password_hash) VALUES (" . ROLE_CUSTOMER . ", ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $email, $firstName, $lastName, $hashed]);
                $customer_id = (int) $conn->lastInsertId();

                session_regenerate_id(true);
                $_SESSION['customer_id']        = $customer_id;
                $_SESSION['customer_username']  = $username;
                $_SESSION['customer_full_name'] = $full_name;

                header('Location: ' . BASE_URL . 'pos/kiosk');
                exit;
            } catch (PDOException $e) {
                $message = (strpos($e->getMessage(), 'Duplicate') !== false)
                    ? 'That username or email is already registered. Try logging in instead.'
                    : 'Could not create your account. Please try again.';
            }
        }
    }
}

$csrf = h(kiosk_csrf_token());
kiosk_header('Register', true, 'kiosk.php');
?>
<h1 class="kiosk-title">Create an Account</h1>
<p style="color:var(--k-ink-soft); margin-top:-12px;">Optional — save your details to view order history later. You can still order as a guest.</p>
<div class="kiosk-card" style="max-width:440px; margin:0 auto;">
    <?php if ($message): ?><div class="kiosk-alert error"><?= h($message) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:6px;">Full Name</label>
        <input name="full_name" value="<?= h($full_name) ?>" required style="width:100%; padding:13px; border-radius:12px; border:2px solid var(--k-border); margin-bottom:14px; font-size:15px;">

        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:6px;">Username</label>
        <input name="username" value="<?= h($username) ?>" required style="width:100%; padding:13px; border-radius:12px; border:2px solid var(--k-border); margin-bottom:14px; font-size:15px;">

        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:6px;">Email</label>
        <input name="email" type="email" value="<?= h($email) ?>" required style="width:100%; padding:13px; border-radius:12px; border:2px solid var(--k-border); margin-bottom:14px; font-size:15px;">

        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:6px;">Password</label>
        <input name="password" type="password" minlength="6" required style="width:100%; padding:13px; border-radius:12px; border:2px solid var(--k-border); margin-bottom:18px; font-size:15px;">

        <button type="submit" class="kiosk-btn block">Create Account</button>
    </form>
    <p style="text-align:center; margin-top:16px; font-size:14px;">Already have an account? <a href="<?= BASE_URL ?>pos/kiosk-login" style="color:var(--k-blue); font-weight:700;">Login</a></p>
</div>
<?php kiosk_footer(); ?>
