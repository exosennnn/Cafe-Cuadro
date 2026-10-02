<?php
/**
 * OWNER: PAYROLL APPROVAL
 * INTEGRATION: HRMS -> Finance.
 *
 * This page did not exist yet even though the Owner dashboard already
 * The Owner approves or returns HR-submitted payroll. Finance owns the
 * later payment and Finance transaction posting.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action     = $_POST['action'] ?? '';
    $payrollId  = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT);

    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE payroll SET status='PROCESSED', approved_by=?, approved_at=NOW(), approval_remarks=NULL WHERE payroll_id=? AND status='SUBMITTED'");
        $stmt->execute([$userId, $payrollId]);
        if ($stmt->rowCount()) {
            logAudit($pdo, $userId, 'APPROVE_PAYROLL', 'Payroll', "payroll_id=$payrollId");
            setFlash('success', 'Payroll approved and sent to Finance for payment.');
        } else {
            setFlash('error', 'That payslip is no longer waiting for approval.');
        }

    } elseif ($action === 'reject') {
        $remarksInput = $_POST['remarks'] ?? '';
        $remarks = is_string($remarksInput) ? trim($remarksInput) : '';
        if ($remarks === '' || strlen($remarks) > 500) {
            setFlash('error', 'Please explain why this payslip is being sent back.');
        } else {
            $stmt = $pdo->prepare("UPDATE payroll SET status='DRAFT', approval_remarks=?, approved_by=?, approved_at=NOW() WHERE payroll_id=? AND status='SUBMITTED'");
            $stmt->execute([$remarks, $userId, $payrollId]);
            if ($stmt->rowCount() === 1) {
                logAudit($pdo, $userId, 'REJECT_PAYROLL', 'Payroll', "payroll_id=$payrollId remarks=$remarks");
                setFlash('success', 'Sent back to the HR Manager for correction.');
            } else {
                setFlash('error', 'That payroll is no longer waiting for approval.');
            }
        }
    }
    redirect('modules/owner/payroll.php');
}

$awaitingApproval = $pdo->query("SELECT p.*, e.employee_code, u.first_name, u.last_name, b.branch_name
    FROM payroll p JOIN employees e ON e.employee_id=p.employee_id JOIN users u ON e.user_id=u.user_id
    LEFT JOIN branches b ON b.branch_id=p.branch_id
    WHERE p.status='SUBMITTED' ORDER BY p.generated_at ASC")->fetchAll();

$approvedForFinance = $pdo->query("SELECT p.*, e.employee_code, u.first_name, u.last_name, b.branch_name
    FROM payroll p JOIN employees e ON p.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    LEFT JOIN branches b ON b.branch_id=p.branch_id
    WHERE p.status='PROCESSED' ORDER BY p.approved_at ASC")->fetchAll();

$recentlyPaid = $pdo->query("SELECT p.*, e.employee_code, u.first_name, u.last_name
    FROM payroll p JOIN employees e ON p.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE p.status='PAID' ORDER BY p.released_at DESC LIMIT 15")->fetchAll();

$pageTitle = 'Payroll Approval';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header mb-4">
    <h4><i class="bi bi-cash-stack"></i> Payroll Approval &amp; Release</h4>
</div>

<div class="card mb-4">
    <div class="card-header">Awaiting Your Approval (<?= count($awaitingApproval) ?>)</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Employee</th><th>Branch</th><th>Period</th><th>Reg. / OT Hrs</th><th>Basic</th><th>OT Pay</th><th>Allowances</th><th>Attendance / Absence Ded.</th><th>Tax</th><th>Net Pay</th><th>HR Adjustment Remarks</th><th style="width:220px;"></th></tr></thead>
            <tbody>
            <?php if (!$awaitingApproval): ?><tr><td colspan="12" class="text-center text-muted py-3">Nothing waiting for approval.</td></tr><?php endif; ?>
            <?php foreach ($awaitingApproval as $p): ?>
                <tr>
                    <td><?= e($p['first_name'].' '.$p['last_name']) ?> <small class="text-muted">(<?= e($p['employee_code']) ?>)</small></td>
                    <td><?= e($p['branch_name'] ?? '-') ?></td>
                    <td><?= fdate($p['pay_period_start']) ?> - <?= fdate($p['pay_period_end']) ?></td>
                    <td><?= number_format((float)$p['regular_hours'], 2) ?> / <?= number_format((float)$p['overtime_hours'], 2) ?></td>
                    <td><?= fmoney($p['basic_pay']) ?></td>
                    <td><?= fmoney($p['overtime_pay']) ?></td>
                    <td><?= fmoney($p['allowances']) ?></td>
                    <td><?= fmoney($p['attendance_deductions']) ?> / <?= fmoney($p['absence_deductions']) ?></td>
                    <td><?= fmoney($p['tax']) ?></td>
                    <td><strong><?= fmoney($p['net_pay']) ?></strong></td>
                    <td><?= e($p['adjustment_remarks'] ?? '') ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="payroll_id" value="<?= $p['payroll_id'] ?>">
                            <button class="btn btn-sm btn-success">Approve</button>
                        </form>
                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $p['payroll_id'] ?>">Send Back</button>
                        <div class="modal fade" id="rejectModal<?= $p['payroll_id'] ?>" tabindex="-1">
                            <div class="modal-dialog"><div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="payroll_id" value="<?= $p['payroll_id'] ?>">
                                    <div class="modal-header"><h6 class="modal-title">Send back to HR Manager</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                    <div class="modal-body"><textarea name="remarks" class="form-control" rows="3" maxlength="500" placeholder="What needs to be corrected?" required></textarea></div>
                                    <div class="modal-footer"><button class="btn btn-danger btn-sm">Send Back</button></div>
                                </form>
                            </div></div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Approved - Sent to Finance (<?= count($approvedForFinance) ?>)</div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Employee</th><th>Branch</th><th>Period</th><th>Net Pay</th><th>Approved</th></tr></thead>
            <tbody>
            <?php if (!$approvedForFinance): ?><tr><td colspan="5" class="text-center text-muted py-3">Nothing waiting for Finance payment.</td></tr><?php endif; ?>
            <?php foreach ($approvedForFinance as $p): ?>
                <tr>
                    <td><?= e($p['first_name'].' '.$p['last_name']) ?> <small class="text-muted">(<?= e($p['employee_code']) ?>)</small></td>
                    <td><?= e($p['branch_name'] ?? '-') ?></td>
                    <td><?= fdate($p['pay_period_start']) ?> - <?= fdate($p['pay_period_end']) ?></td>
                    <td><strong><?= fmoney($p['net_pay']) ?></strong></td>
                    <td><?= fdate($p['approved_at'], 'M d, Y g:i A') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Recently Paid</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Employee</th><th>Period</th><th>Net Pay</th><th>Released</th></tr></thead>
            <tbody>
            <?php if (!$recentlyPaid): ?><tr><td colspan="4" class="text-center text-muted py-3">No paid payroll yet.</td></tr><?php endif; ?>
            <?php foreach ($recentlyPaid as $p): ?>
                <tr>
                    <td><?= e($p['first_name'].' '.$p['last_name']) ?></td>
                    <td><?= fdate($p['pay_period_start']) ?> - <?= fdate($p['pay_period_end']) ?></td>
                    <td><?= fmoney($p['net_pay']) ?></td>
                    <td><?= fdate($p['released_at'], 'M d, Y g:i A') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
