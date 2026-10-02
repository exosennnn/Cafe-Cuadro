<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Financial Report';

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');

$sql = "SELECT fc.category_name, ft.transaction_type, COALESCE(SUM(ft.amount),0) AS total
        FROM finance_transactions ft
        JOIN finance_categories fc ON ft.fin_category_id = fc.fin_category_id
        WHERE ft.transaction_date BETWEEN ? AND ?
        GROUP BY fc.category_name, ft.transaction_type
        ORDER BY ft.transaction_type ASC, total DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute([$dateFrom, $dateTo]);
$rows = $stmt->fetchAll();

$incomeRows = array_values(array_filter($rows, fn($r) => $r['transaction_type'] === 'Income'));
$expenseRows = array_values(array_filter($rows, fn($r) => $r['transaction_type'] === 'Expense'));

$totalIncome = array_sum(array_column($incomeRows, 'total'));
$totalExpense = array_sum(array_column($expenseRows, 'total'));
$netTotal = $totalIncome - $totalExpense;

include __DIR__ . '/../../includes/header.php';
?>

<?php $financeTab = 'report'; include __DIR__ . '/../../includes/finance_nav.php'; ?>

<div class="card-panel p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-3">
            <input type="date" name="date_from" class="form-control" value="<?= clean($dateFrom) ?>">
        </div>
        <div class="col-md-3">
            <input type="date" name="date_to" class="form-control" value="<?= clean($dateTo) ?>">
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
        <div class="col-md-2 d-grid">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Print
            </button>
        </div>
    </form>
</div>

<div class="card-panel p-3 mb-3">
    <h5 class="mb-1">Income Statement</h5>
    <p class="text-muted small mb-0"><?= formatDate($dateFrom) ?> to <?= formatDate($dateTo) ?> · Generated on <?= date('M d, Y g:i A') ?></p>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-green"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="stat-value"><?= peso($totalIncome) ?></div>
            <div class="stat-label">Total Income</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-red"><i class="bi bi-graph-down-arrow"></i></div>
            <div class="stat-value"><?= peso($totalExpense) ?></div>
            <div class="stat-label">Total Expense</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 <?= $netTotal >= 0 ? 'bg-grad-green' : 'bg-grad-red' ?>"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-value"><?= peso($netTotal) ?></div>
            <div class="stat-label">Net Income</div>
        </div>
    </div>
</div>

<div class="card-panel p-3 mb-3">
    <h6 class="mb-3 text-success"><i class="bi bi-plus-circle me-1"></i> Income</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Category</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($incomeRows)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-3">No income recorded for this range.</td></tr>
                <?php else: foreach ($incomeRows as $r): ?>
                    <tr>
                        <td><?= clean($r['category_name']) ?></td>
                        <td class="text-end"><?= peso($r['total']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td class="fw-medium">Total Income</td>
                    <td class="text-end fw-bold text-success"><?= peso($totalIncome) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card-panel p-3 mb-3">
    <h6 class="mb-3 text-danger"><i class="bi bi-dash-circle me-1"></i> Expenses</h6>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Category</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($expenseRows)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-3">No expenses recorded for this range.</td></tr>
                <?php else: foreach ($expenseRows as $r): ?>
                    <tr>
                        <td><?= clean($r['category_name']) ?></td>
                        <td class="text-end"><?= peso($r['total']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr class="table-light">
                    <td class="fw-medium">Total Expenses</td>
                    <td class="text-end fw-bold text-danger"><?= peso($totalExpense) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="card-panel p-3">
    <div class="d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Net Income (Total Income &minus; Total Expenses)</h6>
        <h5 class="mb-0 fw-bold <?= $netTotal >= 0 ? 'text-success' : 'text-danger' ?>"><?= peso($netTotal) ?></h5>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
