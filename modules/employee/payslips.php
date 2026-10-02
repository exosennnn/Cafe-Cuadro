<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id=?");
$emp->execute([(int)$userId]);
$emp = $emp->fetch();
$employeeId = $emp ? (int)$emp['employee_id'] : 0;

if ($employeeId > 0) {
    // DRAFT payslips are not yet approved by the HR Manager and may still change - don't show them to the employee.
  $payslips = $pdo->prepare("SELECT payroll_id, pay_period_start, pay_period_end, basic_pay, regular_hours, overtime_hours, attendance_deductions, absence_deductions, overtime_pay,
    allowances, deductions, tax, net_pay, status
    FROM payroll WHERE employee_id=? AND status='PAID' ORDER BY pay_period_end DESC");
  $payslips->execute([$employeeId]);
    $payslips = $payslips->fetchAll();
} else {
    $payslips = [];
}

$pageTitle = 'My Payslips';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-cash-coin"></i></div>
  <div>
    <h4>My Payslips</h4>
  </div>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="payslipsTable">
      <thead><tr><th>Period</th><th>Regular Hrs</th><th>OT Hrs</th><th>Basic Pay</th><th>Overtime Pay</th><th>Allowances</th><th>Attendance Ded.</th><th>Absence Ded.</th><th>Total Deductions</th><th>Tax</th><th>Net Pay</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($payslips as $p): ?>
        <tr>
          <td><?= e(fdate($p['pay_period_start'] ?? null)) ?> - <?= e(fdate($p['pay_period_end'] ?? null)) ?></td>
          <td><?= e(number_format((float)($p['regular_hours'] ?? 0), 2)) ?></td>
          <td><?= e(number_format((float)($p['overtime_hours'] ?? 0), 2)) ?></td>
          <td><?= e(fmoney($p['basic_pay'] ?? 0)) ?></td>
          <td><?= e(fmoney($p['overtime_pay'] ?? 0)) ?></td>
          <td><?= e(fmoney($p['allowances'] ?? 0)) ?></td>
          <td><?= e(fmoney($p['attendance_deductions'] ?? 0)) ?></td>
          <td><?= e(fmoney($p['absence_deductions'] ?? 0)) ?></td>
          <td><?= e(fmoney($p['deductions'] ?? 0)) ?></td>
          <td><?= e(fmoney($p['tax'] ?? 0)) ?></td>
          <td><strong><?= e(fmoney($p['net_pay'] ?? 0)) ?></strong></td>
          <td><span class="badge bg-success">Paid</span></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($payslips)): ?><tr><td colspan="12" class="text-center text-muted">No paid payslip records yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#payslipsTable').DataTable({ order: [] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
