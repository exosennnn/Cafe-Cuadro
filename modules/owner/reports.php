<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

$reportType = $_GET['export'] ?? '';

if ($reportType !== '') {
    require_once __DIR__ . '/../../libs/simplepdf.php';

    if ($reportType === 'employees') {
        $rows = $pdo->query("SELECT e.employee_code, u.first_name, u.last_name, d.department_name, e.position, e.employment_status
            FROM employees e JOIN users u ON e.user_id=u.user_id LEFT JOIN departments d ON e.department_id=d.department_id ORDER BY u.first_name")->fetchAll();
        $pdf = new SimplePDF('Employee Report');
        $pdf->table(['Code','Name','Department','Position','Status'],
            array_map(fn($r) => [$r['employee_code'], $r['first_name'].' '.$r['last_name'], $r['department_name']??'-', $r['position']??'-', $r['employment_status']], $rows),
            [1.2, 2, 1.8, 1.8, 1.2]);
        $pdf->output('owner_employee_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'payroll') {
        $rows = $pdo->query("SELECT p.pay_period_start, p.pay_period_end, u.first_name, u.last_name, p.net_pay, p.status
            FROM payroll p JOIN employees e ON p.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id ORDER BY p.generated_at DESC")->fetchAll();
        $pdf = new SimplePDF('Payroll Report');
        $pdf->table(['Period Start','Period End','Employee','Net Pay','Status'],
            array_map(fn($r) => [fdate($r['pay_period_start']), fdate($r['pay_period_end']), $r['first_name'].' '.$r['last_name'], fmoney($r['net_pay']), $r['status']], $rows),
            [1.3, 1.3, 2, 1.5, 1]);
        $pdf->output('owner_payroll_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'attendance') {
        $rows = $pdo->query("SELECT a.attendance_date, u.first_name, u.last_name, a.status
            FROM attendance a JOIN employees e ON a.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
            WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY a.attendance_date DESC")->fetchAll();
        $pdf = new SimplePDF('Attendance Report (Last 30 Days)');
        $pdf->table(['Date','Employee','Status'],
            array_map(fn($r) => [fdate($r['attendance_date']), $r['first_name'].' '.$r['last_name'], $r['status']], $rows),
            [1.3, 2.5, 1.5]);
        $pdf->output('owner_attendance_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'leave') {
        $rows = $pdo->query("SELECT u.first_name, u.last_name, lt.type_name, lr.date_from, lr.date_to, lr.total_days, lr.status
            FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
            JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id ORDER BY lr.filed_at DESC")->fetchAll();
        $pdf = new SimplePDF('Leave Report');
        $pdf->table(['Employee','Type','From','To','Days','Status'],
            array_map(fn($r) => [$r['first_name'].' '.$r['last_name'], $r['type_name'], fdate($r['date_from']), fdate($r['date_to']), $r['total_days'], leaveStatusLabel($r['status'])], $rows),
            [1.8, 1.5, 1.1, 1.1, 0.7, 1.3]);
        $pdf->output('owner_leave_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'performance') {
        $rows = $pdo->query("SELECT u.first_name, u.last_name, pe.evaluation_period, pe.overall_score, pe.created_at
            FROM performance_evaluations pe JOIN employees e ON pe.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
            ORDER BY pe.created_at DESC")->fetchAll();
        $pdf = new SimplePDF('Performance Report');
        $pdf->table(['Employee','Period','Overall Score','Date'],
            array_map(fn($r) => [$r['first_name'].' '.$r['last_name'], $r['evaluation_period'], $r['overall_score'], fdate($r['created_at'])], $rows),
            [2.2, 1.8, 1.5, 1.5]);
        $pdf->output('owner_performance_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'department') {
        $rows = $pdo->query("SELECT d.department_name, CONCAT(u.first_name,' ',u.last_name) AS manager_name,
            (SELECT COUNT(*) FROM employees WHERE department_id=d.department_id AND employment_status='ACTIVE') AS emp_count
            FROM departments d LEFT JOIN employees e ON d.manager_id=e.employee_id LEFT JOIN users u ON e.user_id=u.user_id
            ORDER BY d.department_name")->fetchAll();
        $pdf = new SimplePDF('Department Report');
        $pdf->table(['Department','Assigned Manager','Employee Count'],
            array_map(fn($r) => [$r['department_name'], $r['manager_name'] ?? '-', $r['emp_count']], $rows),
            [2.5, 2.5, 2]);
        $pdf->output('owner_department_report_' . date('Ymd') . '.pdf');
    }
    exit;
}

$pageTitle = 'View Reports';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-1">View Reports</h4>

<div class="row g-3">
  <div class="col-md-4 col-sm-6">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-clock fs-1 text-warning"></i>
      <h6 class="mt-2">Attendance Reports</h6>
      <a href="?export=attendance" class="btn btn-sm btn-outline-warning mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-4 col-sm-6">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-calendar-check fs-1 text-primary"></i>
      <h6 class="mt-2">Leave Reports</h6>
      <a href="?export=leave" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-4 col-sm-6">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-cash-stack fs-1 text-success"></i>
      <h6 class="mt-2">Payroll Reports</h6>
      <a href="?export=payroll" class="btn btn-sm btn-outline-success mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-4 col-sm-6">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-people fs-1 text-info"></i>
      <h6 class="mt-2">Employee Reports</h6>
      <a href="?export=employees" class="btn btn-sm btn-outline-info mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-4 col-sm-6">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-star fs-1 text-warning"></i>
      <h6 class="mt-2">Performance Reports</h6>
      <a href="?export=performance" class="btn btn-sm btn-outline-warning mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-4 col-sm-6">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-diagram-3 fs-1 text-secondary"></i>
      <h6 class="mt-2">Department Reports</h6>
      <a href="?export=department" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
