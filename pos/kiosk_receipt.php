<?php
require 'kiosk_bootstrap.php';

$transaction_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token          = $_GET['t'] ?? '';

if (!$transaction_id) {
    header('Location: ' . BASE_URL . 'pos/kiosk');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND source = 'kiosk'");
$stmt->execute([$transaction_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

$authorized = $transaction && (
    ($token !== '' && hash_equals((string) $transaction['guest_token'], (string) $token))
    || (kiosk_customer_logged_in() && (int) $transaction['user_id'] === (int) $_SESSION['customer_id'])
    || is_admin() // staff viewing from the kiosk order queue
);

if (!$transaction || !$authorized) {
    header('Location: ' . BASE_URL . 'pos/kiosk');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id");
$stmt->execute([$transaction_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$receipt_number = (string) $transaction['transaction_code'];

function kiosk_code39_barcode($value) {
    $patterns = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn', 'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn', 'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn', '*' => 'nwnnwnwnn',
    ];
    $value = strtoupper($value);
    $value = preg_replace('/[^0-9A-Z .\-\/+$%]/', '', $value);
    $encoded = '*' . $value . '*';
    $narrow = 2; $wide = 6; $height = 72; $quiet_zone = 24; $x = $quiet_zone; $bars = '';
    foreach (str_split($encoded) as $character) {
        if (!isset($patterns[$character])) { continue; }
        $pattern = $patterns[$character];
        for ($i = 0; $i < 9; $i++) {
            $width = ($pattern[$i] === 'w') ? $wide : $narrow;
            if ($i % 2 === 0) { $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"></rect>'; }
            $x += $width;
        }
        $x += $narrow;
    }
    $total_width = $x + $quiet_zone;
    return '<svg class="barcode" viewBox="0 0 ' . $total_width . ' ' . $height . '" role="img" aria-label="Barcode ' . h($value) . '" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="' . $total_width . '" height="' . $height . '" fill="#fff"></rect>'
        . '<g fill="#000" shape-rendering="crispEdges">' . $bars . '</g></svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt - <?= h($receipt_number) ?></title>
<style>
body{ font-family:'Courier New', monospace; color:#111827; background:#fff; margin:0; padding:20px; }
.receipt{ max-width:360px; margin:0 auto; }
.receipt h2, .receipt h3, .thanks{ text-align:center; }
.receipt .muted{ text-align:center; color:#7a6558; margin-top:0; }
.store-head{ text-align:center; margin-bottom:2px; }
.store-head h2{ margin:0 0 2px; letter-spacing:0.5px; }
.store-head .addr{ margin:0; font-size:11px; color:#7a6558; line-height:1.4; }
.doc-title{ text-align:center; margin:8px 0 0; font-weight:bold; letter-spacing:1px; text-transform:uppercase; font-size:12px; }
.rule{ border-top:1px dashed #cbd5e1; margin:12px 0; }
.barcode{ display:block; width:100%; height:72px; margin:12px auto 6px; }
.barcode-text{ margin:0 0 12px; text-align:center; font-size:13px; }
table{ width:100%; border-collapse:collapse; font-size:12px; }
th, td{ padding:6px 4px; text-align:left; border-bottom:1px solid #e5e7eb; }
th.num, td.num{ text-align:right; }
.totals p{ display:flex; justify-content:space-between; margin:6px 0; }
.totals .grand{ font-size:16px; font-weight:bold; border-top:1px solid #e5e7eb; padding-top:8px; }
.no-print{ display:flex; align-items:center; gap:14px; }
.no-print button{ padding:10px 24px; font-size:14px; font-weight:700; border-radius:50px; border:0; background:#5e6b46; color:#fff; cursor:pointer; box-shadow:0 4px 14px rgba(94,107,70,0.30); transition:background .15s ease; }
.no-print button:hover{ background:#d96828; }
.return-countdown{ color:#7a6558; font-size:13px; }
</style>
</head>
<body>
<div class="no-print">
    <button onclick="window.print()">Print</button>
    <span class="return-countdown" role="status" aria-live="polite">After printing, this page will return to the home page.</span>
</div>
<?php
    $item_count = 0;
    foreach ($items as $item) { $item_count += (int) $item['quantity']; }

    // Clear kiosk cart and session so next customer gets a fresh session
    $_SESSION['kiosk_cart'] = [];
    unset($_SESSION['kiosk_order_type']);
    unset($_SESSION['kiosk_guest_name'], $_SESSION['kiosk_last_order_id'], $_SESSION['kiosk_last_guest_token']);
?>
<div class="no-print">
    <button onclick="window.print()">Print Order Slip</button>
    <a href="<?= BASE_URL ?>#home" style="margin-left:10px; color:#5e6b46; font-weight:700; text-decoration:none; font-size:14px;">Return to Home</a>
    <span class="return-countdown" role="status" aria-live="polite" style="margin-left:auto;">After printing, this will return to the landing page.</span>
</div>

<div class="receipt">
    <div class="store-head">
        <h2><?= h(CAFE_BUSINESS_NAME) ?></h2>
        <p class="addr"><?= h(CAFE_ADDRESS) ?></p>
        <p class="addr"><?= h(CAFE_CONTACT) ?></p>
    </div>
    <p class="doc-title">Self-Order Kiosk Slip</p>
    <?= kiosk_code39_barcode($receipt_number) ?>
    <p class="barcode-text" style="font-size:16px; font-weight:bold; margin-bottom:14px;"><?= h($receipt_number) ?></p>
    <table>
        <tbody>
            <tr><th>Order No.</th><td style="font-weight:bold; font-size:14px;"><?= h($receipt_number) ?></td></tr>
            <tr><th>Date</th><td><?= h(date('M d, Y g:i A', strtotime($transaction['created_at']))) ?></td></tr>
            <tr><th>Order Type</th><td><?= h($transaction['order_type'] === 'dine_in' ? 'Dine-in' : 'Takeout') ?></td></tr>
            <?php if ($transaction['customer_name']): ?>
                <tr><th>Customer Name</th><td><?= h($transaction['customer_name']) ?></td></tr>
            <?php endif; ?>
            <tr><th>Payment Method</th><td style="font-weight:bold;"><?= h(kiosk_payment_method_label($transaction['payment_method'])) ?> (Pay at Counter)</td></tr>
            <tr><th>Status</th><td><span style="font-weight:bold; color:#b45309;"><?= h(kiosk_status_label($transaction['status'])) ?></span></td></tr>
        </tbody>
    </table>
    <div class="rule"></div>
    <table>
        <thead>
            <tr>
                <th style="font-weight:bold;">Ordered Items</th>
                <th class="num" style="font-weight:bold;">Qty</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td style="font-weight:600; font-size:13px;"><?= h($item['product_name']) ?></td>
                <td class="num" style="font-weight:700; font-size:13px;">× <?= h($item['quantity']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="rule"></div>
    <div style="text-align:center; padding:4px 0 8px;">
        <p style="margin:4px 0; font-weight:bold; font-size:13px; text-transform:uppercase;">
            Total Items: <?= (int) $item_count ?>
        </p>
    </div>
    <div class="rule"></div>
    <p class="reprint-note" style="text-align:center; font-weight:bold; font-size:12px; margin:10px 0 6px; line-height:1.4;">
        👉 Please bring this slip to the cashier counter to pay and claim your order.
    </p>
    <p class="thanks" style="text-align:center; font-size:11px; color:#7a6558; margin:10px 0 0;">Thank you for ordering at <?= h(CAFE_BUSINESS_NAME) ?>!</p>
</div>
<script>
window.addEventListener('afterprint', function () {
    var homeUrl = <?= json_encode(BASE_URL . '#home') ?>;
    if (window.opener && !window.opener.closed) {
        window.opener.location.href = homeUrl;
    }
    window.location.href = homeUrl;
});
// Auto-open print dialog when slip opens
window.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        window.print();
    }, 400);
});
</script>
</body>
</html>
