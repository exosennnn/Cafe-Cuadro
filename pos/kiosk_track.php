<?php
require 'kiosk_bootstrap.php';

$order = null;
$not_found = false;
$code = trim($_GET['code'] ?? $_POST['code'] ?? '');

if ($code !== '') {
    $stmt = $conn->prepare("SELECT transaction_code, status, order_type, total, created_at, customer_name FROM transactions WHERE transaction_code = ? AND source = 'kiosk'");
    $stmt->execute([$code]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    $not_found = !$order;
}

kiosk_header('Track Order', true, 'kiosk.php');
?>
<h1 class="kiosk-title">Track My Order</h1>
<div class="kiosk-card" style="max-width:460px; margin:0 auto;">
    <form method="GET">
        <label for="code" style="display:block; font-weight:700; color:var(--k-navy); margin-bottom:8px;">Order Number</label>
        <input id="code" name="code" type="text" value="<?= h($code) ?>" placeholder="e.g. TXN26090712345"
               style="width:100%; padding:14px; border-radius:12px; border:2px solid var(--k-border); font-size:16px; margin-bottom:14px;" autofocus>
        <button type="submit" class="kiosk-btn block"><i class="bi bi-search"></i> Check Status</button>
    </form>

    <?php if ($not_found): ?>
        <div class="kiosk-alert error" style="margin-top:18px;">No order found with that number. Please double-check and try again.</div>
    <?php elseif ($order): ?>
        <div style="margin-top:22px; text-align:center;">
            <p style="color:var(--k-ink-soft); font-size:13px; margin:0 0 6px;">Order <?= h($order['transaction_code']) ?></p>
            <p style="margin:0 0 14px;"><span class="<?= h(kiosk_status_badge_class($order['status'])) ?>" style="font-size:15px; padding:8px 20px;"><?= h(kiosk_status_label($order['status'])) ?></span></p>
            <p style="color:var(--k-ink-soft); margin:0;"><?= h($order['order_type'] === 'dine_in' ? 'Dine-in' : 'Takeout') ?> · ₱<?= money($order['total']) ?></p>
            <?php if ($order['customer_name']): ?>
                <p style="color:var(--k-ink-soft); margin:4px 0 0;">For: <?= h($order['customer_name']) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php kiosk_footer(); ?>
