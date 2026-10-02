<?php
require 'kiosk_bootstrap.php';

$transaction_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token          = $_GET['t'] ?? '';

if (!$transaction_id) {
    header('Location: ' . BASE_URL . 'pos/kiosk');
    exit;
}

$stmt = $conn->prepare("SELECT id, transaction_code, total, status, order_type, customer_name, payment_method, guest_token, user_id FROM transactions WHERE id = ? AND source = 'kiosk'");
$stmt->execute([$transaction_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

$authorized = $order && (
    ($token !== '' && hash_equals((string) $order['guest_token'], (string) $token))
    || (kiosk_customer_logged_in() && (int) $order['user_id'] === (int) $_SESSION['customer_id'])
);

if (!$order || !$authorized) {
    header('Location: ' . BASE_URL . 'pos/kiosk');
    exit;
}

kiosk_header('Order Placed', false, 'kiosk');
?>
<div style="display:flex; flex-direction:column; align-items:center; text-align:center; gap:18px; padding:20px 0;">
    <div style="font-size:64px;">✅</div>
    <h1 class="kiosk-title" style="margin:0;">Order Placed!</h1>
    <p style="color:var(--k-ink-soft); font-size:15px; margin:0; max-width:440px;">
        Your order has been sent to the cashier counter. Please proceed to the cashier to settle payment.
    </p>

    <div class="kiosk-card" style="max-width:440px; width:100%; border-radius:24px; padding:26px 20px;">
        <p style="margin:0 0 6px; color:var(--k-ink-soft); font-size:13px; letter-spacing:1px; text-transform:uppercase;">Order Number</p>
        <p style="font-family:var(--font-brand); font-size:32px; font-weight:800; color:var(--k-navy); margin:0 0 10px; word-break:break-all;"><?= h($order['transaction_code']) ?></p>
        <p style="margin:0 0 14px;"><span class="<?= h(kiosk_status_badge_class($order['status'])) ?>" style="font-size:14px; padding:6px 18px;"><?= h(kiosk_status_label($order['status'])) ?></span></p>

        <div style="background:#faf7f2; border:1px solid var(--k-border); border-radius:14px; padding:14px; text-align:left; font-size:14px; margin-top:10px;">
            <p style="margin:0 0 6px; display:flex; justify-content:space-between;">
                <span style="color:var(--k-ink-soft);">Type:</span>
                <strong><?= h($order['order_type'] === 'dine_in' ? 'Dine-in' : 'Takeout') ?></strong>
            </p>
            <p style="margin:0 0 6px; display:flex; justify-content:space-between;">
                <span style="color:var(--k-ink-soft);">Payment Method:</span>
                <strong><?= h(kiosk_payment_method_label($order['payment_method'])) ?> (at Counter)</strong>
            </p>
            <?php if ($order['customer_name']): ?>
                <p style="margin:0 0 6px; display:flex; justify-content:space-between;">
                    <span style="color:var(--k-ink-soft);">Name:</span>
                    <strong><?= h($order['customer_name']) ?></strong>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <a href="<?= BASE_URL ?>#home" class="kiosk-btn" style="max-width:440px; width:100%; margin-top:8px;"><i class="bi bi-house"></i> Start a New Order</a>
    <p style="color:var(--k-ink-soft); font-size:12px;">This screen will return to the start in 30 seconds.</p>
</div>
<?php kiosk_footer(BASE_URL . '#home', 30); ?>
