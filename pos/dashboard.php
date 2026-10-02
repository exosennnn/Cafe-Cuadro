<?php
require 'database.php';
require 'app.php';
require_login();
// H1 fix: require_login() only checks that *some* account is logged in -
// it does not check that the account's role actually has POS access.
// requireModule('pos') re-checks the MODULE_ACCESS matrix in
// config/constants.php live on every request, so an HR/Inventory/etc.
// account cannot reach the POS dashboard just by knowing the URL.
requireModule('pos');

$product_count      = (int)   $conn->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
$transaction_count  = (int)   $conn->query("SELECT COUNT(*) FROM transactions WHERE status IN ('completed', 'paid', 'processing', 'ready')")->fetchColumn();
$today_sales_count  = (int)   $conn->query("SELECT COUNT(*) FROM transactions WHERE status IN ('completed', 'paid', 'processing', 'ready') AND DATE(created_at) = CURDATE()")->fetchColumn();
$today_revenue      = (float) $conn->query("SELECT COALESCE(SUM(total),0) FROM transactions WHERE status IN ('completed', 'paid', 'processing', 'ready') AND DATE(created_at) = CURDATE()")->fetchColumn();

// Only include completed/paid sales transactions in Recent Transactions (exclude Pending Payment)
$recent = $conn->query("
    SELECT t.id, t.transaction_code, t.total, t.payment_method, t.source, t.status, t.created_at,
           CONCAT(u.first_name, ' ', u.last_name) AS cashier_name,
           u.username AS cashier_username,
           GROUP_CONCAT(CONCAT(ti.product_name, ' ×', ti.quantity) SEPARATOR ', ') AS item_summary
    FROM transactions t
    LEFT JOIN users u ON u.user_id = t.user_id
    LEFT JOIN transaction_items ti ON ti.transaction_id = t.id
    WHERE t.status IN ('completed', 'paid', 'processing', 'ready')
    GROUP BY t.id
    ORDER BY t.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$is_admin_view = is_admin();

// The extra KPIs and the analytics charts mirror the wider "insights" role
// admins already get elsewhere in the app (Sales Reports, Low Stock Alerts
// are admin-only nav items), so they're admin-only here too rather than
// shown to cashiers.
$low_stock_count    = 0;
$staff_count        = 0;
$category_labels    = [];
$category_values    = [];
$trend_labels       = [];
$trend_values       = [];
$payment_labels     = [];
$payment_values     = [];

if ($is_admin_view) {
    $low_stock_count = low_stock_notification_count();
    // Count POS-relevant staff only (Cashier / Inventory Staff / Owner) -
    // the unified `users` table now also holds HRMS employees and kiosk
    // customers, neither of which belong in a POS "active staff" KPI.
    $staff_count = (int) $conn->query("SELECT COUNT(*) FROM users WHERE role_id IN (" . ROLE_CASHIER . ", " . ROLE_INVENTORY_STAFF . ", " . ROLE_OWNER . ")")->fetchColumn();

    // Sales by Category — pie chart
    $stmt = $conn->query("
        SELECT COALESCE(c.name, 'Uncategorized') AS category_name, COALESCE(SUM(ti.subtotal), 0) AS total
        FROM transaction_items ti
        INNER JOIN transactions t ON t.id = ti.transaction_id AND t.status = 'completed'
        INNER JOIN products p ON p.id = ti.product_id
        LEFT JOIN menu_categories c ON c.id = p.category_id
        GROUP BY category_name
        ORDER BY total DESC
    ");
    $category_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $category_labels = array_column($category_rows, 'category_name');
    $category_values = array_map('floatval', array_column($category_rows, 'total'));

    // Sales Trend (Last 6 Months) — line chart
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $months[date('Y-m', strtotime("-{$i} months"))] = 0.0;
    }
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS total
        FROM transactions
        WHERE status = 'completed' AND created_at >= ?
        GROUP BY ym
    ");
    $stmt->execute([date('Y-m-01', strtotime('-5 months'))]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['ym'], $months)) {
            $months[$row['ym']] = (float) $row['total'];
        }
    }
    foreach ($months as $ym => $total) {
        $trend_labels[] = date('M Y', strtotime($ym . '-01'));
        $trend_values[] = $total;
    }

    // Payment Methods — bar chart
    $stmt = $conn->query("
        SELECT payment_method, COUNT(*) AS count
        FROM transactions
        WHERE status = 'completed'
        GROUP BY payment_method
    ");
    $payment_rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $payment_labels = array_map('ucfirst', array_column($payment_rows, 'payment_method'));
    $payment_values = array_map('intval', array_column($payment_rows, 'count'));
}

$pageTitle = 'POS Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/pos-legacy.css">
<div class="mb-4">
    <h1 class="h4 fw-bold mb-1">POS Dashboard</h1>
</div>
<?php

if (isset($_GET['denied'])): ?>
    <div class="alert error">Access denied. You do not have permission to view that page.</div>
<?php endif;

$kpis = [
    ['icon' => 'bi-box-seam',  'label' => 'ACTIVE PRODUCTS',   'value' => $product_count],
    ['icon' => 'bi-receipt',   'label' => 'COMPLETED SALES',   'value' => $transaction_count],
    ['icon' => 'bi-cart-check','label' => "TODAY'S SALES",     'value' => $today_sales_count],
    ['icon' => 'bi-cash-coin', 'label' => "TODAY'S REVENUE",   'value' => '₱' . money($today_revenue)],
];
if ($is_admin_view) {
    $kpis[] = ['icon' => 'bi-exclamation-triangle', 'label' => 'LOW STOCK ALERTS', 'value' => $low_stock_count];
    $kpis[] = ['icon' => 'bi-people',               'label' => 'ACTIVE STAFF',     'value' => $staff_count];
}
?>

<div class="grid cards mb-4">
    <?php foreach ($kpis as $index => $kpi): $accent = pos_kpi_accent($index); ?>
        <div class="kpi-card">
            <div class="kpi-icon <?= h($accent) ?>"><i class="bi <?= h($kpi['icon']) ?>"></i></div>
            <small><?= h($kpi['label']) ?></small>
            <h3><?= h($kpi['value']) ?></h3>
        </div>
    <?php endforeach; ?>
</div>

<div class="card" id="recent-transactions">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php if (userCanDo('pos.sell')): ?>
                <a class="button" href="<?= BASE_URL ?>pos/sales"><i class="bi bi-plus-lg"></i> New Sale</a>
            <?php endif; ?>
            <?php if ($is_admin_view): ?>
                <a class="button secondary" href="<?= BASE_URL ?>pos/products"><i class="bi bi-box-seam"></i> Manage Products</a>
                <a class="button secondary" href="<?= BASE_URL ?>pos/sales-reports"><i class="bi bi-graph-up"></i> View Reports</a>
                <a class="button secondary" href="<?= BASE_URL ?>pos/users"><i class="bi bi-people"></i> Manage Users</a>
            <?php endif; ?>
        </div>
        <div class="chart-card-title"><i class="bi bi-clock-history"></i> Recent Transactions</div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Order Number</th>
                        <th>Date / Time</th>
                        <th>Items</th>
                        <th>Total Amount</th>
                        <th>Payment Method</th>
                        <th>Source</th>
                        <th>Cashier</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$recent): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No completed transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $row): ?>
                    <?php
                        $pm = strtolower($row['payment_method'] ?? 'cash');
                        $isKiosk = strtolower($row['source'] ?? '') === 'kiosk';
                        $cashierDisplay = trim($row['cashier_name'] ?? '');
                        if (!$cashierDisplay) {
                            $cashierDisplay = $row['cashier_username'] ?? ($isKiosk ? 'Self-Service Kiosk' : 'Cashier');
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?= h($row['transaction_code']) ?></strong>
                        </td>
                        <td>
                            <small class="text-muted"><?= date('M d, Y h:i A', strtotime($row['created_at'])) ?></small>
                        </td>
                        <td>
                            <span title="<?= h($row['item_summary'] ?? '') ?>" style="max-width:240px; display:inline-block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:middle;">
                                <?= h($row['item_summary'] ?: 'No items') ?>
                            </span>
                        </td>
                        <td>
                            <strong style="color:var(--brand-primary, #5e6b46);">₱<?= money($row['total']) ?></strong>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border">
                                <?= ($pm === 'gcash' ? '📱 GCash' : '💵 Cash') ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isKiosk): ?>
                                <span class="badge" style="background:#e0f2fe; color:#0369a1; font-weight:700;"><i class="bi bi-tablet me-1"></i>Kiosk</span>
                            <?php else: ?>
                                <span class="badge" style="background:#f3f4f6; color:#3b241a; font-weight:700;"><i class="bi bi-display me-1"></i>POS</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="fw-semibold"><i class="bi bi-person me-1"></i><?= h($cashierDisplay) ?></small>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>pos/receipt?id=<?= (int)$row['id'] ?>" target="_blank" class="button secondary" style="padding:4px 10px; font-size:12px;" title="View Official Receipt">
                                <i class="bi bi-receipt"></i> Slip
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($is_admin_view): ?>

<!-- Row 1: Pie + Line (side by side) -->
<div style="display:grid; grid-template-columns: 1fr 2fr; gap:18px; margin-top:18px;">
    <div class="panel">
        <div class="chart-card-title"><i class="bi bi-pie-chart"></i> Sales by Category</div>
        <?php if ($category_labels): ?>
            <div style="position:relative; height:220px;">
                <canvas id="categoryChart"></canvas>
            </div>
        <?php else: ?>
            <p class="muted" style="text-align:center; padding:40px 0;">No sales data yet.</p>
        <?php endif; ?>
    </div>
    <div class="panel">
        <div class="chart-card-title"><i class="bi bi-graph-up"></i> Sales Trend (Last 6 Months)</div>
        <div style="position:relative; height:220px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
</div>

<!-- Row 2: Payment Methods (full width) -->
<div style="margin-top:18px;">
    <div class="panel">
        <div class="chart-card-title"><i class="bi bi-bar-chart"></i> Payment Methods</div>
        <?php if ($payment_labels): ?>
            <div style="position:relative; height:200px;">
                <canvas id="paymentChart"></canvas>
            </div>
        <?php else: ?>
            <p class="muted" style="text-align:center; padding:30px 0;">No payment data yet.</p>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') {
        return; // Chart.js CDN didn't load — charts just stay blank rather than throwing.
    }

    // Global defaults — override any dark-mode colours that unified.css may inject
    Chart.defaults.color = '#7a6558';
    Chart.defaults.borderColor = 'rgba(237,228,219,0.8)';
    Chart.defaults.backgroundColor = 'rgba(255,255,255,0)';
    Chart.defaults.font.family = "'DM Sans', sans-serif";
    Chart.defaults.font.size = 12;
    Chart.defaults.plugins.legend.labels.color = '#5a4638';

    // Bright, readable palette aligned with Unified Cafe design
    var palette  = ['#5e6b46', '#16a34a', '#d97706', '#2a1810', '#8d5b4c', '#dc2626'];
    // White background plugin (prevents dark canvas fill on some setups)
    var whiteBackground = {
        id: 'customCanvasBackgroundColor',
        beforeDraw: function(chart) {
            var ctx = chart.canvas.getContext('2d');
            ctx.save();
            ctx.globalCompositeOperation = 'destination-over';
            ctx.fillStyle = 'rgba(255,255,255,0)';
            ctx.fillRect(0, 0, chart.width, chart.height);
            ctx.restore();
        }
    };

    var categoryLabels = <?= json_encode($category_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var categoryValues = <?= json_encode($category_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var trendLabels     = <?= json_encode($trend_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var trendValues      = <?= json_encode($trend_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var paymentLabels   = <?= json_encode($payment_labels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var paymentValues    = <?= json_encode($payment_values, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    var categoryCanvas = document.getElementById('categoryChart');
    if (categoryCanvas && categoryLabels.length) {
        new Chart(categoryCanvas, {
            type: 'pie',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryValues,
                    backgroundColor: categoryLabels.map(function (_, i) { return palette[i % palette.length]; }),
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 12, font: { size: 11 }, boxWidth: 12 } }
                }
            },
            plugins: [whiteBackground]
        });
    }

    var trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Revenue (₱)',
                    data: trendValues,
                    borderColor: '#5e6b46',
                    borderWidth: 2.5,
                    backgroundColor: 'rgba(94,107,70,0.10)',
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#5e6b46',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#2a1810',
                        titleColor: '#ffffff',
                        bodyColor: '#5e6b46',
                        padding: 10,
                        cornerRadius: 10,
                        callbacks: {
                            label: function(ctx) { return ' ₱' + ctx.parsed.y.toLocaleString(); }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(237,228,219,0.6)' },
                        ticks: { color: '#7a6558', callback: function(v) { return '₱' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v); } },
                        border: { display: false }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#7a6558', maxRotation: 0 },
                        border: { display: false }
                    }
                }
            },
            plugins: [whiteBackground]
        });
    }

    var paymentCanvas = document.getElementById('paymentChart');
    if (paymentCanvas && paymentLabels.length) {
        new Chart(paymentCanvas, {
            type: 'bar',
            data: {
                labels: paymentLabels,
                datasets: [{
                    label: 'Transactions',
                    data: paymentValues,
                    backgroundColor: paymentLabels.map(function (_, i) { return palette[i % palette.length]; }),
                    borderRadius: 10,
                    borderSkipped: false,
                    maxBarThickness: 72
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#2a1810',
                        titleColor: '#ffffff',
                        bodyColor: '#5e6b46',
                        padding: 10,
                        cornerRadius: 10
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#7a6558' },
                        grid: { color: 'rgba(237,228,219,0.6)' },
                        border: { display: false }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#7a6558' },
                        border: { display: false }
                    }
                }
            },
            plugins: [whiteBackground]
        });
    }
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>