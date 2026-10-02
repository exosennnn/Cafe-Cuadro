<?php
require 'kiosk_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['order_type'] ?? '';
    if (in_array($type, ['dine_in', 'takeout'], true)) {
        $_SESSION['kiosk_order_type'] = $type;
        header('Location: ' . BASE_URL . 'pos/kiosk-menu');
        exit;
    }
}

kiosk_header('Order Type', true, 'kiosk');
?>
<h1 class="kiosk-title">How would you like your order?</h1>
<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:20px; max-width:640px; margin:0 auto;">
    <form method="POST">
        <input type="hidden" name="order_type" value="dine_in">
        <button type="submit" class="kiosk-card kiosk-btn block" style="flex-direction:column; height:220px; background:#fff; color:var(--k-navy); box-shadow:var(--k-shadow); border:2px solid var(--k-border);">
            <span style="font-size:52px;">🍽️</span>
            <span style="font-size:20px; margin-top:10px;">Dine-in</span>
        </button>
    </form>
    <form method="POST">
        <input type="hidden" name="order_type" value="takeout">
        <button type="submit" class="kiosk-card kiosk-btn block" style="flex-direction:column; height:220px; background:#fff; color:var(--k-navy); box-shadow:var(--k-shadow); border:2px solid var(--k-border);">
            <span style="font-size:52px;">🥡</span>
            <span style="font-size:20px; margin-top:10px;">Takeout</span>
        </button>
    </form>
</div>
<style>
.kiosk-card.kiosk-btn:hover{ border-color:var(--k-blue-light); transform:translateY(-2px); box-shadow:0 14px 30px rgba(29,78,216,0.18); }
</style>
<?php kiosk_footer(); ?>
