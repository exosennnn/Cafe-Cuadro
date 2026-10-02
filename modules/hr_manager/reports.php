<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$reportType = $_GET['export'] ?? '';

if ($reportType !== '') {
    require_once __DIR__ . '/../../libs/simplepdf.php';

    if ($reportType === 'employees') {
        $rows = $pdo->query("SELECT e.employee_code, u.first_name, u.last_name, d.department_name, e.position, e.employment_status
            FROM employees e JOIN users u ON e.user_id=u.user_id LEFT JOIN departments d ON e.department_id=d.department_id ORDER BY u.first_name")->fetchAll();
        $pdf = new SimplePDF('Employee Master List');
        $pdf->table(['Code','Name','Department','Position','Status'],
            array_map(fn($r) => [$r['employee_code'], $r['first_name'].' '.$r['last_name'], $r['department_name']??'-', $r['position']??'-', $r['employment_status']], $rows),
            [1.2, 2, 1.8, 1.8, 1.2]);
        $pdf->output('employee_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'payroll') {
        $rows = $pdo->query("SELECT p.pay_period_start, p.pay_period_end, u.first_name, u.last_name, p.net_pay, p.status
            FROM payroll p JOIN employees e ON p.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id ORDER BY p.generated_at DESC")->fetchAll();
        $pdf = new SimplePDF('Payroll Summary Report');
        $pdf->table(['Period Start','Period End','Employee','Net Pay','Status'],
            array_map(fn($r) => [fdate($r['pay_period_start']), fdate($r['pay_period_end']), $r['first_name'].' '.$r['last_name'], fmoney($r['net_pay']), $r['status']], $rows),
            [1.3, 1.3, 2, 1.5, 1]);
        $pdf->output('payroll_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'attendance') {
        $rows = $pdo->query("SELECT a.attendance_date, u.first_name, u.last_name, a.status
            FROM attendance a JOIN employees e ON a.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
            WHERE a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY a.attendance_date DESC")->fetchAll();
        $pdf = new SimplePDF('Attendance Report (Last 30 Days)');
        $pdf->table(['Date','Employee','Status'],
            array_map(fn($r) => [fdate($r['attendance_date']), $r['first_name'].' '.$r['last_name'], $r['status']], $rows),
            [1.3, 2.5, 1.5]);
        $pdf->output('attendance_report_' . date('Ymd') . '.pdf');
    } elseif ($reportType === 'recruitment') {
        $rows = $pdo->query("SELECT jv.title, COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name, ja.status, ja.applied_at
            FROM job_applications ja JOIN job_vacancies jv ON ja.job_id=jv.job_id
            JOIN applicants a ON ja.applicant_id=a.applicant_id LEFT JOIN users u ON a.user_id=u.user_id ORDER BY ja.applied_at DESC")->fetchAll();
        $pdf = new SimplePDF('Recruitment Report');
        $pdf->table(['Job Title','Applicant','Status','Applied'],
            array_map(fn($r) => [$r['title'], $r['first_name'].' '.$r['last_name'], $r['status'], fdate($r['applied_at'])], $rows),
            [2, 2, 1.5, 1.3]);
        $pdf->output('recruitment_report_' . date('Ymd') . '.pdf');
    }
    exit;
}

$pageTitle = 'Reports';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">Reports</h4>

<div class="row g-3">
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-people fs-1 text-primary"></i>
      <h6 class="mt-2">Employee Master List</h6>
      <a href="?export=employees" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-cash-stack fs-1 text-success"></i>
      <h6 class="mt-2">Payroll Summary</h6>
      <a href="?export=payroll" class="btn btn-sm btn-outline-success mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-clock fs-1 text-warning"></i>
      <h6 class="mt-2">Attendance (30 days)</h6>
      <a href="?export=attendance" class="btn btn-sm btn-outline-warning mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body text-center">
      <i class="bi bi-briefcase fs-1 text-info"></i>
      <h6 class="mt-2">Recruitment Report</h6>
      <a href="?export=recruitment" class="btn btn-sm btn-outline-info mt-2"><i class="bi bi-file-earmark-pdf"></i> Export PDF</a>
    </div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
