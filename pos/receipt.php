<?php
require 'database.php';
require 'app.php';
require_login();
// H1 fix: require_login() alone does not enforce the POS module RBAC -
// any authenticated user (HR, Inventory Staff, Employee, etc.) could
// otherwise view any transaction receipt by guessing/entering an id in
// the URL. requireModule('pos') restricts this to Owner/Cashier only.
requireModule('pos');

$transaction_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$transaction_id) {
    header("Location: " . BASE_URL . "pos/sales");
    exit;
}

$stmt = $conn->prepare("
    SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.username,
           b.branch_name, b.address AS branch_address
    FROM transactions t
    LEFT JOIN users u ON u.user_id = t.user_id
    LEFT JOIN branches b ON b.branch_id = t.branch_id
    WHERE t.id = ? AND t.status != 'cancelled'
");
$stmt->execute([$transaction_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    header("Location: " . BASE_URL . "pos/sales");
    exit;
}

$stmt = $conn->prepare("SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id");
$stmt->execute([$transaction_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$receipt_number = (string) $transaction['transaction_code'];

function code39_barcode($value) {
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
    $narrow = 2;
    $wide = 6;
    $height = 72;
    $quiet_zone = 24;
    $x = $quiet_zone;
    $bars = '';

    foreach (str_split($encoded) as $character) {
        if (!isset($patterns[$character])) {
            continue;
        }

        $pattern = $patterns[$character];
        for ($i = 0; $i < 9; $i++) {
            $width = ($pattern[$i] === 'w') ? $wide : $narrow;
            if ($i % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="' . $height . '"></rect>';
            }
            $x += $width;
        }
        $x += $narrow;
    }

    $total_width = $x + $quiet_zone;

    return '<svg class="barcode" viewBox="0 0 ' . $total_width . ' ' . $height . '" role="img" aria-label="Barcode ' . h($value) . '" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="' . $total_width . '" height="' . $height . '" fill="#fff"></rect>'
        . '<g fill="#000" shape-rendering="crispEdges">' . $bars . '</g>'
        . '</svg>';
}

$pageTitle = 'Receipt';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">
<div class="mb-4 no-print">
    <h1 class="h4 fw-bold mb-1">Receipt</h1>
</div>
<section class="panel receipt-wrap">
    <div class="actions no-print" style="margin-bottom:16px;">
        <button type="button" onclick="window.print()">Print Receipt</button>
        <a class="button secondary" href="<?= BASE_URL ?>pos/sales">New Sale</a>
        <a class="button secondary" href="<?= BASE_URL ?>pos/sales-reports">Sales Reports</a>
    </div>
    <div class="actions no-print" id="auto-redirect-notice" style="margin-bottom:16px; font-size:13px; color:#7a6558;">
        Redirecting to New Sale in <span id="redirect-countdown">3</span>s...
        <button type="button" id="cancel-redirect-btn" style="margin-left:8px; background:none; border:1px solid #d1d5db; border-radius:6px; padding:2px 10px; cursor:pointer;">Cancel</button>
    </div>

    <?php
        $item_count = 0;
        foreach ($items as $item) { $item_count += (int) $item['quantity']; }
        $vatable_sales = max(0, (float) $transaction['total'] - (float) $transaction['tax']);
    ?>
    <div class="receipt">
        <div class="store-head">
            <h2><?php echo h(CAFE_BUSINESS_NAME); ?></h2>
            <?php if (!empty($transaction['branch_name'])): ?>
                <p class="addr branch-name"><?php echo h($transaction['branch_name']); ?></p>
            <?php endif; ?>
            <p class="addr"><?php echo h($transaction['branch_address'] ?: CAFE_ADDRESS); ?></p>
            <p class="addr"><?php echo h(CAFE_CONTACT); ?></p>
            <p class="addr">TIN: <?php echo h(CAFE_TIN); ?></p>
        </div>
        <p class="doc-title">Official Receipt</p>
        <?php echo code39_barcode($receipt_number); ?>
        <p class="barcode-text"><?php echo h($receipt_number); ?></p>

        <table class="meta-table">
            <tbody>
                <tr><th>OR No.</th><td><?php echo h($receipt_number); ?></td></tr>
                <tr><th>Date</th><td><?php echo h(date('M d, Y g:i A', strtotime($transaction['created_at']))); ?></td></tr>
                <tr><th>Cashier</th><td><?php echo h($transaction['full_name'] ?: $transaction['username']); ?></td></tr>
                <tr><th>Payment</th><td><?php echo h(ucfirst($transaction['payment_method'])); ?></td></tr>
            </tbody>
        </table>

        <div class="rule"></div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="num">Qty</th>
                    <th class="num">Price</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><?php echo h($item['product_name']); ?></td>
                    <td class="num"><?php echo h($item['quantity']); ?></td>
                    <td class="num"><?php echo money($item['price']); ?></td>
                    <td class="num"><?php echo money($item['subtotal']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="rule"></div>

        <div class="totals">
            <p><span><?php echo (int) $item_count; ?> item<?php echo $item_count === 1 ? '' : 's'; ?></span><span></span></p>
            <p><span>Subtotal</span><strong>PHP <?php echo money($transaction['subtotal']); ?></strong></p>
            <?php if ((float) $transaction['discount'] > 0): ?>
                <p><span>Discount</span><strong>-PHP <?php echo money($transaction['discount']); ?></strong></p>
            <?php endif; ?>
            <p class="grand"><span>Total Due</span><strong>PHP <?php echo money($transaction['total']); ?></strong></p>
            <p><span>Amount Paid</span><strong>PHP <?php echo money($transaction['amount_paid']); ?></strong></p>
            <p><span>Change</span><strong>PHP <?php echo money($transaction['change_due']); ?></strong></p>
        </div>

        <div class="rule"></div>

        <div class="vat-note">
            <p><span>VATable Sales</span><span>PHP <?php echo money($vatable_sales); ?></span></p>
            <p><span>VAT (12%)</span><span>PHP <?php echo money($transaction['tax']); ?></span></p>
            <p class="tin">VAT Reg. TIN: <?php echo h(CAFE_TIN); ?></p>
        </div>

        <p class="thanks">Thank you for your purchase!</p>
        <p class="reprint-note">This receipt is system-generated and reprintable from Sales Reports.</p>
    </div>
</section>

<style>
    .receipt-wrap { max-width: 760px; }
    .receipt { max-width: 520px; margin: 0 auto; background: #fff; color: #2a1810; }
    .receipt h2, .receipt h3, .thanks { text-align: center; }
    .receipt h2 { margin-bottom: 4px; }
    .receipt .muted { text-align: center; margin-top: 0; }
    .store-head { text-align: center; margin-bottom: 2px; }
    .store-head h2 { margin: 0 0 2px; letter-spacing: 0.5px; }
    .store-head .addr { margin: 0; font-size: 12px; color: #7a6558; line-height: 1.4; }
    .store-head .branch-name { font-weight: 600; color: #3b241a; }
    .doc-title { text-align: center; margin: 10px 0 0; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; font-size: 13px; }
    .rule { border-top: 1px dashed #cbd5e1; margin: 14px 0; }
    .meta-table th, .items-table th { text-align: left; }
    .items-table .num, .items-table td.num { text-align: right; }
    .vat-note p { display: flex; justify-content: space-between; margin: 4px 0; font-size: 12px; color: #4b5563; }
    .vat-note .tin { text-align: center; margin: 6px 0 0; font-size: 11px; color: #7a6558; }
    .reprint-note { text-align: center; font-size: 11px; color: #9ca3af; margin-top: 4px; font-weight: normal; }
    .barcode {
        display: block;
        width: 100%;
        max-width: 480px;
        height: 72px;
        margin: 12px auto 6px;
        background: #fff;
    }
    .barcode-text {
        margin: 0 0 12px;
        text-align: center;
        font-family: "Courier New", monospace;
        font-size: 13px;
        letter-spacing: 0;
    }
    .totals { margin-top: 4px; }
    .totals p { display: flex; justify-content: space-between; margin: 8px 0; }
    .totals .grand { font-size: 18px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
    .thanks { margin-top: 22px; font-weight: bold; }

    @media print {
        body { background: #fff; }
        .app-navbar, .app-sidebar, .no-print { display: none !important; }
        .app-wrapper { display: block; }
        .app-content { padding: 0; }
        .panel { border: 0; box-shadow: none; padding: 0; }
        .receipt { width: 80mm; max-width: 80mm; margin: 0; font-size: 12px; }
        .barcode { height: 56px; }
        table { font-size: 12px; }
        th, td { padding: 6px 4px; }
    }
</style>
<script>
(function () {
    var seconds = 3;
    var countdownEl = document.getElementById('redirect-countdown');
    var noticeEl = document.getElementById('auto-redirect-notice');
    var cancelBtn = document.getElementById('cancel-redirect-btn');
    var timer = null;

    function tick() {
        seconds -= 1;
        if (countdownEl) {
            countdownEl.textContent = seconds;
        }
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = '<?= BASE_URL ?>pos/sales';
        }
    }

    timer = setInterval(tick, 1000);

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            clearInterval(timer);
            if (noticeEl) {
                noticeEl.style.display = 'none';
            }
        });
    }
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>