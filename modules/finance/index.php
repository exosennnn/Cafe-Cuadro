<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Finance Transactions';
$userId = $_SESSION['user_id'];

// ---------- Handle Add / Edit (Manual transactions only) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    requireCapability('finance.manage');
    $txnId       = $_POST['transaction_id'] ?? '';
    $type        = $_POST['transaction_type'] ?? '';
    $categoryId  = $_POST['fin_category_id'] ?? '';
    $amount      = (float)($_POST['amount'] ?? 0);
    $date        = $_POST['transaction_date'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if (!in_array($type, ['Income', 'Expense']) || !$categoryId || $amount <= 0 || $date === '') {
        setFlash('danger', 'Type, category, a valid amount, and date are required.');
    } else {
        if ($txnId) {
            // Only allow editing manual entries
            $check = $pdo->prepare("SELECT reference_type FROM finance_transactions WHERE transaction_id = ?");
            $check->execute([$txnId]);
            $ref = $check->fetchColumn();
            if ($ref !== 'Manual') {
                setFlash('danger', 'This transaction was posted automatically and cannot be edited here.');
                redirect('modules/finance/index.php');
            }
            $stmt = $pdo->prepare("UPDATE finance_transactions SET transaction_type=?, fin_category_id=?, amount=?, transaction_date=?, description=? WHERE transaction_id=?");
            $stmt->execute([$type, $categoryId, $amount, $date, $description, $txnId]);
            setFlash('success', 'Transaction updated successfully.');
            logActivity($pdo, $userId, 'Finance', "Updated transaction #$txnId");
        } else {
            $stmt = $pdo->prepare("INSERT INTO finance_transactions (transaction_type, fin_category_id, amount, transaction_date, description, reference_type, created_by) VALUES (?, ?, ?, ?, ?, 'Manual', ?)");
            $stmt->execute([$type, $categoryId, $amount, $date, $description, $userId]);
            setFlash('success', 'Transaction recorded successfully.');
            logActivity($pdo, $userId, 'Finance', "Recorded $type transaction: " . peso($amount));
        }
    }
    redirect('modules/finance/index.php');
}

// ---------- Handle Delete (Manual transactions only) ----------
if (isset($_GET['delete'])) {
    requireCapability('finance.manage');
    $id = (int)$_GET['delete'];
    $check = $pdo->prepare("SELECT reference_type FROM finance_transactions WHERE transaction_id = ?");
    $check->execute([$id]);
    $ref = $check->fetchColumn();

    if ($ref === false) {
        setFlash('danger', 'Transaction not found.');
    } elseif ($ref !== 'Manual') {
        setFlash('danger', 'This transaction was posted automatically (e.g. from a delivery) and cannot be deleted here.');
    } else {
        $pdo->prepare("DELETE FROM finance_transactions WHERE transaction_id = ?")->execute([$id]);
        logActivity($pdo, $userId, 'Finance', "Deleted transaction #$id");
        setFlash('success', 'Transaction deleted.');
    }
    redirect('modules/finance/index.php');
}

// ---------- Filters ----------
$typeFilter = $_GET['type'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

$sql = "SELECT ft.*, fc.category_name, fc.type AS category_type, pp.delivery_id AS payable_delivery_id, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
        FROM finance_transactions ft
        JOIN finance_categories fc ON ft.fin_category_id = fc.fin_category_id
    LEFT JOIN purchase_payables pp ON ft.reference_type='PurchasePayable' AND pp.payable_id=ft.reference_id
        JOIN users u ON ft.created_by = u.user_id
        WHERE 1=1";
$params = [];

if ($typeFilter !== '') {
    $sql .= " AND ft.transaction_type = ?";
    $params[] = $typeFilter;
}
if ($categoryFilter !== '') {
    $sql .= " AND ft.fin_category_id = ?";
    $params[] = $categoryFilter;
}
if ($dateFrom !== '') {
    $sql .= " AND ft.transaction_date >= ?";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $sql .= " AND ft.transaction_date <= ?";
    $params[] = $dateTo;
}

$sql .= " ORDER BY ft.transaction_date DESC, ft.transaction_id DESC LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// ---------- Summary (respects date filters if set, ignores type/category filter so all totals are visible) ----------
$sumSql = "SELECT transaction_type, COALESCE(SUM(amount),0) AS total FROM finance_transactions WHERE 1=1";
$sumParams = [];
if ($dateFrom !== '') {
    $sumSql .= " AND transaction_date >= ?";
    $sumParams[] = $dateFrom;
}
if ($dateTo !== '') {
    $sumSql .= " AND transaction_date <= ?";
    $sumParams[] = $dateTo;
}
$sumSql .= " GROUP BY transaction_type";
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($sumParams);
$totals = ['Income' => 0, 'Expense' => 0];
foreach ($sumStmt->fetchAll() as $row) {
    $totals[$row['transaction_type']] = (float)$row['total'];
}
$netTotal = $totals['Income'] - $totals['Expense'];

$categories = $pdo->query("SELECT * FROM finance_categories ORDER BY type ASC, category_name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
$financeTab = 'transactions';
include __DIR__ . '/../../includes/finance_nav.php';
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-green"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="stat-value"><?= peso($totals['Income']) ?></div>
            <div class="stat-label">Total Income</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 bg-grad-red"><i class="bi bi-graph-down-arrow"></i></div>
            <div class="stat-value"><?= peso($totals['Expense']) ?></div>
            <div class="stat-label">Total Expense</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon mb-2 <?= $netTotal >= 0 ? 'bg-grad-green' : 'bg-grad-red' ?>"><i class="bi bi-cash-coin"></i></div>
            <div class="stat-value"><?= peso($netTotal) ?></div>
            <div class="stat-label">Net (Income - Expense)</div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-end align-items-center mb-3 gap-2">
    <button class="btn btn-brand" data-bs-toggle="modal" data-bs-target="#txnModal" onclick="openAddModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Transaction
    </button>
</div>

<!-- Filters -->
<div class="card-panel p-3 mb-3">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-2">
            <select name="type" class="form-select">
                <option value="">All Types</option>
                <option value="Income" <?= $typeFilter === 'Income' ? 'selected' : '' ?>>Income</option>
                <option value="Expense" <?= $typeFilter === 'Expense' ? 'selected' : '' ?>>Expense</option>
            </select>
        </div>
        <div class="col-md-3">
            <select name="category" class="form-select">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= $c['fin_category_id'] ?>" <?= $categoryFilter == $c['fin_category_id'] ? 'selected' : '' ?>>
                        <?= clean($c['category_name']) ?> (<?= $c['type'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <input type="date" name="date_from" class="form-control" value="<?= clean($dateFrom) ?>">
        </div>
        <div class="col-md-2">
            <input type="date" name="date_to" class="form-control" value="<?= clean($dateTo) ?>">
        </div>
        <div class="col-md-2 d-grid">
            <button type="submit" class="btn btn-brand"><i class="bi bi-search me-1"></i>Filter</button>
        </div>
        <div class="col-md-1 d-grid">
            <a href="<?= BASE_URL ?>finance" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

<div class="card-panel p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Source</th>
                    <th class="text-end">Amount</th>
                    <th>Recorded By</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No transactions found.</td></tr>
                <?php else: foreach ($transactions as $t):
                    $isManual = $t['reference_type'] === 'Manual';
                ?>
                    <tr>
                        <td><?= formatDate($t['transaction_date']) ?></td>
                        <td>
                            <span class="badge <?= $t['transaction_type'] === 'Income' ? 'bg-success' : 'bg-danger' ?>"><?= $t['transaction_type'] ?></span>
                        </td>
                        <td><?= clean($t['category_name']) ?></td>
                        <td class="text-muted small"><?= clean($t['description']) ?: '-' ?></td>
                        <td>
                            <?php /* Source of the entry. Every non-Manual row was posted automatically
                                     and is read-only here; each type links back to where it came from. */ ?>
                            <?php if ($isManual): ?>
                                <span class="badge bg-light text-dark border">Manual</span>
                            <?php elseif ($t['reference_type'] === 'Delivery'): ?>
                                <a href="<?= BASE_URL ?>procurement/delivery-view?id=<?= (int)$t['reference_id'] ?>" class="badge bg-light text-dark border text-decoration-none">
                                    <i class="bi bi-truck me-1"></i>Delivery
                                </a>
                            <?php elseif ($t['reference_type'] === 'StockIn'): ?>
                                <a href="<?= BASE_URL ?>inventory/stock-movement" class="badge bg-light text-dark border text-decoration-none">
                                    <i class="bi bi-box-arrow-in-down me-1"></i>Stock In
                                </a>
                            <?php elseif ($t['reference_type'] === 'PurchasePayable' && $t['payable_delivery_id']): ?>
                                <a href="<?= BASE_URL ?>procurement/delivery-view?id=<?= (int)$t['payable_delivery_id'] ?>" class="badge bg-light text-dark border text-decoration-none">
                                    <i class="bi bi-truck me-1"></i>Paid Delivery
                                </a>
                            <?php elseif ($t['reference_type'] === 'Payroll'): ?>
                                <a href="<?= BASE_URL ?>finance/payroll" class="badge bg-light text-dark border text-decoration-none">
                                    <i class="bi bi-cash-stack me-1"></i>Payroll
                                </a>
                            <?php elseif ($t['reference_type'] === 'Sale'): ?>
                                <span class="badge bg-light text-dark border"><i class="bi bi-shop me-1"></i>POS Sale</span>
                            <?php else: ?>
                                <span class="badge bg-light text-dark border"><?= clean($t['reference_type']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end fw-medium <?= $t['transaction_type'] === 'Income' ? 'text-success' : 'text-danger' ?>">
                            <?= $t['transaction_type'] === 'Income' ? '+' : '-' ?><?= peso($t['amount']) ?>
                        </td>
                        <td class="text-muted small"><?= clean($t['created_by_name']) ?></td>
                        <td class="text-end">
                            <?php if ($isManual): ?>
                                <button class="btn btn-sm btn-outline-primary" onclick='openEditModal(<?= json_encode($t) ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="?delete=<?= $t['transaction_id'] ?>" class="btn btn-sm btn-outline-danger"
                                    onclick="return confirm('Delete this transaction?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            <?php else: ?>
                                <i class="bi bi-lock text-muted" title="Auto-posted, cannot be edited here"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <p class="text-muted small mt-2 mb-0">Showing latest 300 records. Use filters to narrow down further.</p>
</div>

<!-- Transaction Modal -->
<div class="modal fade" id="txnModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="">
            <div class="modal-header">
                <h5 class="modal-title" id="txnModalLabel">Add Transaction</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="transaction_id" id="transaction_id">

                <div class="mb-3">
                    <label class="form-label">Type</label>
                    <select name="transaction_type" id="transaction_type" class="form-select" required onchange="filterCategories()">
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select name="fin_category_id" id="fin_category_id" class="form-select" required></select>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" step="0.01" min="0.01" name="amount" id="amount" class="form-control" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="transaction_date" id="transaction_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="description" class="form-control" placeholder="Optional notes">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-brand">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
const FIN_CATEGORIES = <?= json_encode($categories) ?>;

function filterCategories(selectedId) {
    const type = document.getElementById('transaction_type').value;
    const catSelect = document.getElementById('fin_category_id');
    catSelect.innerHTML = '';
    FIN_CATEGORIES.filter(c => c.type === type).forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.fin_category_id;
        opt.textContent = c.category_name;
        if (selectedId && selectedId == c.fin_category_id) opt.selected = true;
        catSelect.appendChild(opt);
    });
}

function openAddModal() {
    document.getElementById('txnModalLabel').innerText = 'Add Transaction';
    document.getElementById('transaction_id').value = '';
    document.getElementById('transaction_type').value = 'Income';
    document.getElementById('amount').value = '';
    document.getElementById('transaction_date').value = new Date().toISOString().slice(0,10);
    document.getElementById('description').value = '';
    filterCategories();
}
function openEditModal(data) {
    document.getElementById('txnModalLabel').innerText = 'Edit Transaction';
    document.getElementById('transaction_id').value = data.transaction_id;
    document.getElementById('transaction_type').value = data.transaction_type;
    document.getElementById('amount').value = data.amount;
    document.getElementById('transaction_date').value = data.transaction_date;
    document.getElementById('description').value = data.description ?? '';
    filterCategories(data.fin_category_id);
    new bootstrap.Modal(document.getElementById('txnModal')).show();
}

// Initialize category options on first load
filterCategories();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
