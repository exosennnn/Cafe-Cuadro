<?php
require 'kiosk_bootstrap.php';

if (kiosk_customer_logged_in()) {
    header('Location: ' . BASE_URL . 'pos/kiosk-account');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!kiosk_csrf_valid()) {
        $message = 'Your session expired. Please try again.';
    } else {
        $account  = trim($_POST['account'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($account === '' || $password === '') {
            $message = 'Please enter your username/email and password.';
        } else {
            $stmt = $conn->prepare("SELECT user_id, username, email, password_hash, CONCAT(first_name, ' ', last_name) AS full_name FROM users WHERE (username = ? OR email = ?) AND role_id = " . ROLE_CUSTOMER . " LIMIT 1");
            $stmt->execute([$account, $account]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($customer && password_verify($password, $customer['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['customer_id']        = $customer['user_id'];
                $_SESSION['customer_username']  = $customer['username'];
                $_SESSION['customer_full_name'] = trim($customer['full_name']);
                header('Location: ' . BASE_URL . 'pos/kiosk');
                exit;
            }
            $message = 'Invalid username/email or password.';
        }
    }
}

$csrf = h(kiosk_csrf_token());
kiosk_header('Login', true, 'kiosk.php');
?>
<h1 class="kiosk-title">Customer Login</h1>
<p style="color:var(--k-ink-soft); margin-top:-12px;">Optional — you can also <a href="<?= BASE_URL ?>pos/kiosk-order-type" style="color:var(--k-blue); font-weight:700;">order as a guest</a>.</p>
<div class="kiosk-card" style="max-width:420px; margin:0 auto;">
    <?php if ($message): ?><div class="kiosk-alert error"><?= h($message) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:6px;">Username or Email</label>
        <input name="account" required autofocus style="width:100%; padding:13px; border-radius:12px; border:2px solid var(--k-border); margin-bottom:14px; font-size:15px;">

        <label style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:6px;">Password</label>
        <input name="password" type="password" required style="width:100%; padding:13px; border-radius:12px; border:2px solid var(--k-border); margin-bottom:18px; font-size:15px;">

        <button type="submit" class="kiosk-btn block">Login</button>
    </form>
    <p style="text-align:center; margin-top:16px; font-size:14px;">No account? <a href="<?= BASE_URL ?>pos/kiosk-register" style="color:var(--k-blue); font-weight:700;">Register</a></p>
</div>
<?php kiosk_footer(); ?>
