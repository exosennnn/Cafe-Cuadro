<?php
require 'kiosk_bootstrap.php';

// Landing on the start screen is the canonical "reset" point: clear any
// in-progress guest order state so a walk-away customer never leaves
// their cart or order-type choice for the next person.
$_SESSION['kiosk_cart'] = [];
unset($_SESSION['kiosk_order_type']);
unset($_SESSION['kiosk_guest_name'], $_SESSION['kiosk_last_order_id'], $_SESSION['kiosk_last_guest_token']);

kiosk_header('Welcome', true, BASE_URL . '#home');
?>
<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:60vh; text-align:center; gap:26px;">
    <div>
        <div style="font-size:64px; line-height:1;">🍽️</div>
        <h1 class="kiosk-title" style="font-size:36px; margin:14px 0 6px;">Welcome to <?php echo h(CAFE_BUSINESS_NAME); ?></h1>
        <p style="color:var(--k-ink-soft); font-size:16px; max-width:440px; margin:0 auto;">
            Order fast, no account needed. Tap below to get started.
        </p>
    </div>

    <a href="<?= BASE_URL ?>pos/kiosk-order-type" class="kiosk-btn" style="font-size:20px; padding:20px 48px; min-height:64px;">
        <i class="bi bi-bag-check"></i> Start Order
    </a>

    <div style="display:flex; gap:14px; flex-wrap:wrap; justify-content:center; margin-top:6px;">
        <a href="<?= BASE_URL ?>pos/kiosk-track" class="kiosk-btn secondary"><i class="bi bi-search"></i> Track My Order</a>
    </div>
    <p class="muted" style="color:var(--k-ink-soft); font-size:13px; max-width:420px;">
        No account needed. Browse the menu, build your order, and check out as a guest.
    </p>
</div>
<script>
// Best-effort fullscreen on first tap (browsers require a user gesture).
// For true kiosk-mode browser chrome removal, launch this page with e.g.
// `chrome --kiosk https://.../kiosk.php` on the terminal/POS device.
document.addEventListener('click', function requestFs() {
    var el = document.documentElement;
    if (!document.fullscreenElement && el.requestFullscreen) {
        el.requestFullscreen().catch(function () {});
    }
    document.removeEventListener('click', requestFs);
}, { once: true });
</script>
<?php kiosk_footer('kiosk.php'); ?>
