<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/employment_terms.php';
requireRole([ROLE_HR_MANAGER]);

$totalEmployees = $pdo->query("SELECT COUNT(*) AS c FROM employees WHERE employment_status='ACTIVE'")->fetch()['c'];
$totalApplicants = $pdo->query("SELECT COUNT(*) AS c FROM applicants")->fetch()['c'];
$openJobs = $pdo->query("SELECT COUNT(*) AS c FROM job_vacancies WHERE status='OPEN'")->fetch()['c'];
$pendingLeaves = $pdo->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status IN ('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL')")->fetch()['c'];
$totalPayrollThisMonth = $pdo->query("SELECT COALESCE(SUM(net_pay),0) AS s FROM payroll WHERE status='PAID' AND MONTH(released_at)=MONTH(CURDATE()) AND YEAR(released_at)=YEAR(CURDATE())")->fetch()['s'];
$payrollNeedsCorrection = $pdo->query("SELECT COUNT(*) AS c FROM payroll WHERE status='DRAFT' AND approval_remarks IS NOT NULL")->fetch()['c'];

$deptDist = $pdo->query("SELECT d.department_name, COUNT(e.employee_id) AS cnt FROM departments d
    JOIN employees e ON e.department_id=d.department_id AND e.employment_status='ACTIVE' GROUP BY d.department_id")->fetchAll();

$hireTrend = $pdo->query("SELECT DATE_FORMAT(date_hired,'%Y-%m') AS m, COUNT(*) AS c FROM employees
    WHERE date_hired >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(date_hired,'%Y-%m') ORDER BY DATE_FORMAT(date_hired,'%Y-%m')")->fetchAll();
$hireTrendByMonth = array_column($hireTrend, 'c', 'm');
$hireLabels = [];
$hireCounts = [];
$currentMonth = new DateTimeImmutable('first day of this month');
for ($monthsBack = 5; $monthsBack >= 0; $monthsBack--) {
  $month = $currentMonth->modify("-{$monthsBack} months");
  $monthKey = $month->format('Y-m');
  $hireLabels[] = $month->format('M Y');
  $hireCounts[] = (int) ($hireTrendByMonth[$monthKey] ?? 0);
}

$roleDist = $pdo->query("SELECT r.role_name, COUNT(e.employee_id) AS cnt FROM employees e JOIN users u ON e.user_id=u.user_id JOIN roles r ON u.role_id=r.role_id WHERE e.employment_status='ACTIVE' GROUP BY r.role_id")->fetchAll();

// Probationary / contract end-date alerts (flag only - never auto-terminates)
$termReviews = getEmploymentTermReviews($pdo);
syncEmploymentTermNotifications($pdo, $termReviews);

$pageTitle = 'HR Manager Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Base sizing for the KPI cards (colors/glass/animation come from hr-manager-theme.css) */
.dashboard-stat-card {
    border-radius: 14px;
    padding: 1.25rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
}

.stat-icon-badge {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

  .hrm-chart-wrap {
    position: relative;
    height: 220px;
  }
</style>

<div class="mb-4">
    <h3 class="fw-bold mb-1 hrm-page-title">Dashboard Analytics</h3>
</div>

<?= renderEmploymentTermReviewPanel($termReviews, BASE_URL . 'hr-manager/employees', true) ?>

<!-- TOP KPI STAT CARDS GRID (6 Balanced Columns) -->
<div class="row g-3 mb-4">
  <!-- 1. Active Employees -->
  <div class="col-xl-2 col-md-4 col-6">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">ACTIVE STAFF</span>
        <div class="stat-icon-badge badge-mocha"><i class="bi bi-people-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($totalEmployees) ?></h3>
    </div>
  </div>

  <!-- 2. Total Applicants -->
  <div class="col-xl-2 col-md-4 col-6">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">APPLICANTS</span>
        <div class="stat-icon-badge badge-matcha"><i class="bi bi-person-vcard-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($totalApplicants) ?></h3>
    </div>
  </div>

  <!-- 3. Open Job Posts -->
  <div class="col-xl-2 col-md-4 col-6">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">OPEN JOBS</span>
        <div class="stat-icon-badge badge-caramel"><i class="bi bi-briefcase-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($openJobs) ?></h3>
    </div>
  </div>

  <!-- 4. Pending Leaves -->
  <div class="col-xl-2 col-md-4 col-6">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">LEAVE REQS</span>
        <div class="stat-icon-badge badge-terracotta"><i class="bi bi-calendar-event"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingLeaves) ?></h3>
    </div>
  </div>

  <!-- 5. Payroll Sent Back by Owner -->
  <div class="col-xl-2 col-md-4 col-6">
    <a href="<?= BASE_URL ?>hr-manager/payroll" class="text-decoration-none">
      <div class="dashboard-stat-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-semibold">PAYROLL SENT BACK</span>
          <div class="stat-icon-badge badge-caramel"><i class="bi bi-arrow-return-left"></i></div>
        </div>
        <h3 class="fw-bold m-0 text-dark"><?= number_format($payrollNeedsCorrection) ?></h3>
      </div>
    </a>
  </div>

  <!-- 6. Disbursed This Month -->
  <div class="col-xl-2 col-md-4 col-6">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">PAID THIS MO.</span>
        <div class="stat-icon-badge badge-matcha"><i class="bi bi-cash-stack"></i></div>
      </div>
      <h4 class="fw-bold m-0 text-dark"><?= fmoney($totalPayrollThisMonth) ?></h4>
    </div>
  </div>
</div>

<!-- CHARTS ROW -->
<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-pie-chart text-secondary me-2"></i>Employees per Department</h6>
        <div class="hrm-chart-wrap"><canvas id="deptChart"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-graph-up-arrow text-secondary me-2"></i>Hiring Trend (Last 6 Months)</h6>
        <div class="hrm-chart-wrap"><canvas id="hireChart"></canvas></div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-bar-chart-steps text-secondary me-2"></i>Users by Role</h6>
        <div class="hrm-chart-wrap"><canvas id="roleChart"></canvas></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const hrmPalette = ['#5e6b46', '#16a34a', '#d97706', '#2a1810', '#8d5b4c', '#dc2626', '#7a6558', '#e6d8c5'];

function getConsistentColor(label) {
  if (!label) return hrmPalette[0];
  const lbl = label.toLowerCase();
  if (lbl.includes('cashier')) return '#5e6b46'; // Primary
  if (lbl.includes('finance')) return '#d97706'; // Warning
  if (lbl.includes('inventory')) return '#8d5b4c'; // Terracotta
  if (lbl.includes('hr') || lbl.includes('general')) return '#16a34a'; // Success
  if (lbl.includes('owner')) return '#2a1810'; // Dark Roast
  if (lbl.includes('manager')) return '#dc2626'; // Danger
  
  let hash = 0;
  for (let i = 0; i < label.length; i++) hash = label.charCodeAt(i) + ((hash << 5) - hash);
  return hrmPalette[Math.abs(hash) % hrmPalette.length];
}

Chart.defaults.color = '#2a1810';
Chart.defaults.borderColor = 'rgba(237, 228, 219, 0.6)';

Chart.register({
  id: 'emptyState',
  afterDraw(chart) {
    const hasValues = chart.data.datasets.some(dataset => (dataset.data || []).some(value => Number(value) > 0));
    if (hasValues || !chart.chartArea) return;

    const { ctx, chartArea } = chart;
    ctx.save();
    ctx.fillStyle = '#7a6558';
    ctx.font = '500 13px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('No data available', (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2);
    ctx.restore();
  }
});

new Chart(document.getElementById('deptChart'), {
  type: 'pie',
  data: { 
    labels: <?= json_encode(array_column($deptDist,'department_name')) ?>, 
    datasets:[{ 
      data: <?= json_encode(array_column($deptDist,'cnt')) ?>, 
      backgroundColor: context => getConsistentColor(context.chart.data.labels[context.dataIndex]),
      borderColor: '#ffffff',
      borderWidth: 2
    }] 
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 12 } } }
  }
});

new Chart(document.getElementById('hireChart'), {
  type: 'line',
  data: { 
    labels: <?= json_encode($hireLabels) ?>, 
    datasets:[{ 
      label:'Hires', 
      data: <?= json_encode($hireCounts) ?>, 
      borderColor: '#5e6b46', 
      backgroundColor: 'rgba(94,107,70,0.14)', 
      fill: true, 
      tension: 0.35 
    }] 
  },
  options: { responsive: true, maintainAspectRatio: false, plugins:{legend:{display:false}} }
});

new Chart(document.getElementById('roleChart'), {
  type: 'bar',
  data: { 
    labels: <?= json_encode(array_column($roleDist,'role_name')) ?>, 
    datasets:[{ 
      label:'Users', 
      data: <?= json_encode(array_column($roleDist,'cnt')) ?>, 
      backgroundColor: context => getConsistentColor(context.chart.data.labels[context.dataIndex]),
      borderRadius: 6
    }] 
  },
  options: { responsive: true, maintainAspectRatio: false, indexAxis:'y', plugins:{legend:{display:false}} }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?> 
