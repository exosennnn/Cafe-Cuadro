<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Payroll Payments';
$userId = (int)$_SESSION['user_id'];
$branchId = currentRoleId() === ROLE_FINANCE_STAFF ? inventoryAssignedBranchId($pdo, $userId) : null;
if (currentRoleId() === ROLE_FINANCE_STAFF && !$branchId) {
    http_response_code(403);
    die('Finance Staff must be assigned to an active branch before paying payroll.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payroll_payment') {
    requireCapability('finance.manage');
    csrfVerify();
    $payrollId = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT);
    if ($payrollId === false || $payrollId < 1) {
        setFlash('error', 'Select a valid approved payroll record.');
        redirect('modules/finance/payroll.php');
    }
    try {
        $payment = payApprovedPayroll($pdo, $payrollId, $userId, $branchId);
        logAudit($pdo, $userId, 'PAY_PAYROLL', 'Payroll', "payroll_id=$payrollId employee={$payment['employee_name']} amount={$payment['amount']}");
        setFlash('success', "Paid payroll for {$payment['employee_name']} (" . fmoney($payment['amount']) . '). A single salary expense was recorded.');
    } catch (Throwable $exception) {
        error_log('payroll payment failed: ' . $exception->getMessage());
        setFlash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Payroll payment failed. No Finance transaction or status change was saved.');
    }
    redirect('modules/finance/payroll.php');
}

$approvedSql = "SELECT p.payroll_id, p.branch_id, p.pay_period_start, p.pay_period_end, p.basic_pay, p.regular_hours, p.overtime_hours, p.overtime_pay, p.allowances, p.deductions, p.tax, p.net_pay, p.approved_at, p.adjustment_remarks,
        e.employee_code, u.first_name, u.last_name, b.branch_name
    FROM payroll p
    JOIN employees e ON e.employee_id=p.employee_id
    JOIN users u ON u.user_id=e.user_id
    LEFT JOIN branches b ON b.branch_id=p.branch_id
    WHERE p.status='PROCESSED' AND p.finance_transaction_id IS NULL";
$params = [];
if ($branchId) { $approvedSql .= ' AND p.branch_id=?'; $params[] = $branchId; }
$approvedSql .= ' ORDER BY p.approved_at ASC, p.payroll_id ASC';
$approvedStmt = $pdo->prepare($approvedSql);
$approvedStmt->execute($params);
$approvedPayroll = $approvedStmt->fetchAll();

$paidSql = "SELECT p.payroll_id, p.pay_period_start, p.pay_period_end, p.net_pay, p.released_at, e.employee_code, u.first_name, u.last_name, b.branch_name
    FROM payroll p JOIN employees e ON e.employee_id=p.employee_id JOIN users u ON u.user_id=e.user_id
    LEFT JOIN branches b ON b.branch_id=p.branch_id
    WHERE p.status='PAID'";
$paidParams = [];
if ($branchId) { $paidSql .= ' AND p.branch_id=?'; $paidParams[] = $branchId; }
$paidSql .= ' ORDER BY p.released_at DESC LIMIT 25';
$paidStmt = $pdo->prepare($paidSql);
$paidStmt->execute($paidParams);
$paidPayroll = $paidStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
$financeTab = 'payroll';
include __DIR__ . '/../../includes/finance_nav.php';
?>

<div class="card-panel p-3 mb-4">
    <h5 class="mb-1">Approved Payroll</h5>
    <p class="text-muted small mb-0">Only Owner-approved payroll is available here. Marking a row paid updates the same payroll record and creates one linked salary expense.</p>
</div>

<div class="card-panel p-3 mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Employee</th><th>Branch</th><th>Pay Period</th><th>Regular / OT Hours</th><th class="text-end">Net Pay</th><th>HR Remarks</th><th>Approved</th><th>Action</th></tr></thead>
            <tbody>
            <?php if (!$approvedPayroll): ?><tr><td colspan="8" class="text-center text-muted py-4">No approved payroll is waiting for payment.</td></tr><?php endif; ?>
            <?php foreach ($approvedPayroll as $payroll): ?>
                <tr>
                    <td><?= e($payroll['first_name'] . ' ' . $payroll['last_name']) ?> <small class="text-muted">(<?= e($payroll['employee_code']) ?>)</small></td>
                    <td><?= e($payroll['branch_name'] ?? '-') ?></td>
                    <td><?= fdate($payroll['pay_period_start']) ?> - <?= fdate($payroll['pay_period_end']) ?></td>
                    <td><?= number_format((float)$payroll['regular_hours'], 2) ?> / <?= number_format((float)$payroll['overtime_hours'], 2) ?></td>
                    <td class="text-end fw-semibold"><?= fmoney($payroll['net_pay']) ?></td>
                    <td><?= e($payroll['adjustment_remarks'] ?? '') ?></td>
                    <td><?= fdate($payroll['approved_at'], 'M d, Y g:i A') ?></td>
                    <td><form method="POST" onsubmit="return confirm('Record payment and mark this payroll PAID?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="payroll_payment"><input type="hidden" name="payroll_id" value="<?= (int)$payroll['payroll_id'] ?>">
                        <button class="btn btn-sm btn-success"><i class="bi bi-cash-coin me-1"></i>Mark Paid</button>
                    </form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-panel p-3">
    <h6 class="mb-3">Recently Paid</h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Employee</th><th>Branch</th><th>Pay Period</th><th class="text-end">Net Pay</th><th>Paid</th></tr></thead>
            <tbody>
            <?php if (!$paidPayroll): ?><tr><td colspan="5" class="text-center text-muted py-3">No payroll payments yet.</td></tr><?php endif; ?>
            <?php foreach ($paidPayroll as $payroll): ?>
                <tr><td><?= e($payroll['first_name'] . ' ' . $payroll['last_name']) ?> <small class="text-muted">(<?= e($payroll['employee_code']) ?>)</small></td><td><?= e($payroll['branch_name'] ?? '-') ?></td><td><?= fdate($payroll['pay_period_start']) ?> - <?= fdate($payroll['pay_period_end']) ?></td><td class="text-end"><?= fmoney($payroll['net_pay']) ?></td><td><?= fdate($payroll['released_at'], 'M d, Y g:i A') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>