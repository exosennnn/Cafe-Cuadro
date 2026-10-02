<?php
require 'kiosk_bootstrap.php';
require_kiosk_customer_login();

$stmt = $conn->prepare("SELECT user_id AS id, username, email, CONCAT(first_name, ' ', last_name) AS full_name, created_at FROM users WHERE user_id = ? AND role_id = " . ROLE_CUSTOMER);
$stmt->execute([$_SESSION['customer_id']]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$customer) {
    // Account no longer exists — clear the stale session and bounce out.
    unset($_SESSION['customer_id'], $_SESSION['customer_username'], $_SESSION['customer_full_name']);
    header('Location: ' . BASE_URL . 'pos/kiosk-login');
    exit;
}

$stmt = $conn->prepare("SELECT id, transaction_code, total, status, order_type, created_at, guest_token FROM transactions WHERE user_id = ? AND source = 'kiosk' ORDER BY created_at DESC LIMIT 25");
$stmt->execute([$customer['id']]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

kiosk_header('My Account', true, 'kiosk');
?>
<h1 class="kiosk-title">My Account</h1>

<div class="kiosk-card" style="max-width:520px;">
    <p style="margin:0 0 4px; font-weight:800; color:var(--k-navy); font-size:18px;"><?= h($customer['full_name']) ?></p>
    <p style="margin:0; color:var(--k-ink-soft);"><?= h($customer['email']) ?></p>
    <a href="<?= BASE_URL ?>pos/kiosk-logout" class="kiosk-btn secondary" style="margin-top:16px; display:inline-flex;"><i class="bi bi-box-arrow-right"></i> Logout</a>
</div>

<h2 style="font-family:var(--font-brand); color:var(--k-navy); margin:26px 0 12px;">Order History</h2>
<?php if (!$orders): ?>
    <div class="kiosk-card" style="text-align:center; color:var(--k-ink-soft);">You haven't placed an order yet.</div>
<?php else: ?>
    <div style="display:flex; flex-direction:column; gap:12px;">
        <?php foreach ($orders as $order): ?>
            <div class="kiosk-card" style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <div>
                    <p style="margin:0; font-weight:700;"><?= h($order['transaction_code']) ?></p>
                    <p style="margin:2px 0 0; color:var(--k-ink-soft); font-size:13px;">
                        <?= h($order['order_type'] === 'dine_in' ? 'Dine-in' : 'Takeout') ?> · <?= h($order['created_at']) ?>
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:14px;">
                    <span class="<?= h(kiosk_status_badge_class($order['status'])) ?>"><?= h(kiosk_status_label($order['status'])) ?></span>
                    <strong>₱<?= money($order['total']) ?></strong>
                    <a href="<?= BASE_URL ?>pos/kiosk-receipt?id=<?= h($order['id']) ?>&t=<?= h(urlencode($order['guest_token'])) ?>" class="kiosk-btn secondary" style="min-height:40px; padding:8px 14px; font-size:13px;">
                        <i class="bi bi-receipt"></i>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php kiosk_footer(); ?>
