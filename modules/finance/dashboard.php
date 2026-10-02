<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

/**
 * FINANCE DASHBOARD
 * Landing page for the Finance Staff role (and the Owner's "Finance
 * Overview"). Finance figures only - stock and purchase-order numbers live
 * on the Inventory dashboard (modules/inventory/dashboard.php).
 */
$pageTitle = 'Finance Dashboard';

// ---------- This month: income / expense / net / count ----------
$monthTotals = ['Income' => 0.0, 'Expense' => 0.0];
$monthCount  = 0;
$stmt = $pdo->query("
    SELECT transaction_type, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS n
    FROM finance_transactions
    WHERE MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())
    GROUP BY transaction_type
");
foreach ($stmt->fetchAll() as $row) {
    $monthTotals[$row['transaction_type']] = (float)$row['total'];
    $monthCount += (int)$row['n'];
}
$monthNet = $monthTotals['Income'] - $monthTotals['Expense'];

// ---------- Last 6 months trend (always 6 rows, zero-filled) ----------
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("first day of -$i month"));
    $trend[$key] = ['label' => date('M Y', strtotime($key . '-01')), 'Income' => 0.0, 'Expense' => 0.0];
}
$stmt = $pdo->query("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym, transaction_type, COALESCE(SUM(amount), 0) AS total
    FROM finance_transactions
    WHERE transaction_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
    GROUP BY ym, transaction_type
");
foreach ($stmt->fetchAll() as $row) {
    if (isset($trend[$row['ym']])) {
        $trend[$row['ym']][$row['transaction_type']] = (float)$row['total'];
    }
}
$trendMax = 0.0;
foreach ($trend as $t) { $trendMax = max($trendMax, $t['Income'], $t['Expense']); }

// ---------- Top expense categories this month ----------
$topExpenses = $pdo->query("
    SELECT fc.category_name, COALESCE(SUM(ft.amount), 0) AS total
    FROM finance_transactions ft
    JOIN finance_categories fc ON ft.fin_category_id = fc.fin_category_id
    WHERE ft.transaction_type = 'Expense'
      AND MONTH(ft.transaction_date) = MONTH(CURDATE()) AND YEAR(ft.transaction_date) = YEAR(CURDATE())
    GROUP BY fc.fin_category_id, fc.category_name
    ORDER BY total DESC
    LIMIT 5
")->fetchAll();

// ---------- Recent transactions ----------
$recentTxns = $pdo->query("
    SELECT ft.transaction_id, ft.transaction_type, ft.amount, ft.transaction_date, ft.description,
           ft.reference_type, fc.category_name
    FROM finance_transactions ft
    JOIN finance_categories fc ON ft.fin_category_id = fc.fin_category_id
    ORDER BY ft.transaction_date DESC, ft.transaction_id DESC
    LIMIT 8
")->fetchAll();

// ---------- Recent finance activity (Finance module only) ----------
$recentActivity = $pdo->query("
    SELECT al.action, al.created_at, CONCAT(u.first_name, ' ', u.last_name) AS full_name
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    WHERE al.module = 'Finance'
    ORDER BY al.created_at DESC
    LIMIT 6
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
$financeTab = 'dashboard';
include __DIR__ . '/../../includes/finance_nav.php';
?>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>finance?type=Income&date_from=<?= date('Y-m-01') ?>" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 bg-grad-green"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-value"><?= peso($monthTotals['Income']) ?></div>
                <div class="stat-label">Income (This Month)</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>finance?type=Expense&date_from=<?= date('Y-m-01') ?>" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 bg-grad-red"><i class="bi bi-graph-down-arrow"></i></div>
                <div class="stat-value"><?= peso($monthTotals['Expense']) ?></div>
                <div class="stat-label">Expenses (This Month)</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>finance/report" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 <?= $monthNet >= 0 ? 'bg-grad-green' : 'bg-grad-red' ?>"><i class="bi bi-cash-coin"></i></div>
                <div class="stat-value"><?= peso($monthNet) ?></div>
                <div class="stat-label">Net (This Month)</div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="<?= BASE_URL ?>finance?date_from=<?= date('Y-m-01') ?>" class="text-decoration-none">
            <div class="stat-card">
                <div class="stat-icon mb-2 bg-grad-orange"><i class="bi bi-receipt"></i></div>
                <div class="stat-value"><?= number_format($monthCount) ?></div>
                <div class="stat-label">Transactions (This Month)</div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- 6-month trend -->
    <div class="col-lg-7">
        <div class="card-panel p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-bar-chart-line me-1"></i> Income vs Expenses &middot; Last 6 Months</h6>
                <a href="<?= BASE_URL ?>finance/report" class="small text-decoration-none">Full report</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th style="width:34%;">Income / Expense</th>
                            <th class="text-end">Income</th>
                            <th class="text-end">Expense</th>
                            <th class="text-end">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($trend, true) as $t):
                            $net = $t['Income'] - $t['Expense'];
                            $incW = $trendMax > 0 ? round($t['Income']  / $trendMax * 100) : 0;
                            $expW = $trendMax > 0 ? round($t['Expense'] / $trendMax * 100) : 0;
                        ?>
                            <tr>
                                <td class="fw-medium"><?= clean($t['label']) ?></td>
                                <td>
                                    <div class="progress mb-1" style="height:6px;" title="Income">
                                        <div class="progress-bar bg-success" style="width:<?= $incW ?>%"></div>
                                    </div>
                                    <div class="progress" style="height:6px;" title="Expense">
                                        <div class="progress-bar bg-danger" style="width:<?= $expW ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-end text-success"><?= peso($t['Income']) ?></td>
                                <td class="text-end text-danger"><?= peso($t['Expense']) ?></td>
                                <td class="text-end fw-medium <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= peso($net) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top expense categories -->
    <div class="col-lg-5">
        <div class="card-panel p-3 h-100">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-1"></i> Top Expenses &middot; This Month</h6>
            <?php if (empty($topExpenses)): ?>
                <p class="text-muted small mb-0">No expenses recorded this month.</p>
            <?php else: foreach ($topExpenses as $c):
                $share = $monthTotals['Expense'] > 0 ? round($c['total'] / $monthTotals['Expense'] * 100) : 0;
            ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between small">
                        <span><?= clean($c['category_name']) ?></span>
                        <span class="fw-medium"><?= peso($c['total']) ?> <span class="text-muted">(<?= $share ?>%)</span></span>
                    </div>
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar bg-danger" style="width:<?= $share ?>%"></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Recent transactions -->
    <div class="col-lg-7">
        <div class="card-panel p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0"><i class="bi bi-receipt me-1"></i> Recent Transactions</h6>
                <a href="<?= BASE_URL ?>finance" class="small text-decoration-none">View all</a>
            </div>
            <?php if (empty($recentTxns)): ?>
                <p class="text-muted small mb-0">No transactions recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Source</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTxns as $t): $isInc = $t['transaction_type'] === 'Income'; ?>
                                <tr>
                                    <td><?= formatDate($t['transaction_date']) ?></td>
                                    <td>
                                        <span class="badge <?= $isInc ? 'bg-success' : 'bg-danger' ?> me-1"><?= clean($t['transaction_type']) ?></span>
                                        <?= clean($t['category_name']) ?>
                                    </td>
                                    <td class="text-muted small"><?= $t['reference_type'] === 'StockIn' ? 'Stock In' : clean($t['reference_type']) ?></td>
                                    <td class="text-end fw-medium <?= $isInc ? 'text-success' : 'text-danger' ?>">
                                        <?= $isInc ? '+' : '-' ?><?= peso($t['amount']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent finance activity -->
    <div class="col-lg-5">
        <div class="card-panel p-3 h-100">
            <h6 class="mb-3"><i class="bi bi-clock-history me-1"></i> Recent Finance Activity</h6>
            <?php if (empty($recentActivity)): ?>
                <p class="text-muted small mb-0">No finance activity recorded yet.</p>
            <?php else: ?>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($recentActivity as $log): ?>
                        <li class="d-flex align-items-start gap-2 mb-3">
                            <i class="bi bi-cash-coin text-secondary mt-1"></i>
                            <div>
                                <div class="small"><?= clean($log['action']) ?></div>
                                <div class="text-muted" style="font-size:0.75rem;">
                                    <?= clean($log['full_name']) ?> &middot; <?= formatDate($log['created_at'], 'M d, Y g:i A') ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
