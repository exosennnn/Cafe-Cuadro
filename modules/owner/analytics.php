<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

/* Attendance trends - last 14 days, present vs absent vs late counts */
$attendanceTrend = $pdo->query("SELECT attendance_date,
        SUM(status='PRESENT') AS present, SUM(status='LATE') AS late, SUM(status='ABSENT') AS absent
    FROM attendance
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY attendance_date ORDER BY attendance_date")->fetchAll();

/* Leave statistics - by type */
$leaveStats = $pdo->query("SELECT lt.type_name, COUNT(lr.leave_id) AS cnt
    FROM leave_types lt LEFT JOIN leave_requests lr ON lr.leave_type_id=lt.leave_type_id
    GROUP BY lt.leave_type_id ORDER BY lt.type_name")->fetchAll();

/* Payroll expense summary - last 6 months (actually paid out only, by release month) */
$payrollExpense = $pdo->query("SELECT DATE_FORMAT(released_at,'%b %Y') AS m, SUM(net_pay) AS total
    FROM payroll WHERE status='PAID' AND released_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(released_at,'%Y-%m') ORDER BY DATE_FORMAT(released_at,'%Y-%m')")->fetchAll();

/* Employee performance summary - average overall score per department */
$perfSummary = $pdo->query("SELECT d.department_name, ROUND(AVG(pe.overall_score),2) AS avg_score
    FROM performance_evaluations pe
    JOIN employees e ON pe.employee_id=e.employee_id
    LEFT JOIN departments d ON e.department_id=d.department_id
    GROUP BY d.department_id ORDER BY d.department_name")->fetchAll();

$pageTitle = 'Company Analytics';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-1">Company Analytics</h4>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Attendance Trends (Last 14 Days)</h6>
      <canvas id="attendanceChart"></canvas>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Leave Statistics by Type</h6>
      <canvas id="leaveChart"></canvas>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Payroll Expense Summary (Last 6 Months)</h6>
      <canvas id="payrollChart"></canvas>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Employee Performance Summary by Department</h6>
      <canvas id="perfChart"></canvas>
    </div></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
Chart.defaults.color = '#2a1810';
Chart.defaults.font.family = "'DM Sans', sans-serif";

/* Shared bar-grow-in animation, staggered per bar */
const obAnimation = {
  duration: 1200,
  easing: 'easeOutQuart',
  delay: (ctx) => ctx.type === 'data' ? ctx.dataIndex * 90 + (ctx.datasetIndex || 0) * 120 : 0
};
function obGrid(extra = {}) {
  return Object.assign({
    responsive: true,
    animation: obAnimation,
    plugins: { legend: { position: 'bottom', labels: { color: '#2a1810', usePointStyle: true, font: { weight: 600 } } } },
    scales: {
      x: { grid: { display: false }, ticks: { color: '#7a6558', font: { weight: 600 } } },
      y: { beginAtZero: true, grid: { color: '#e6d8c5' }, ticks: { color: '#7a6558', font: { weight: 600 } } }
    }
  }, extra);
}
function obGrad(canvasId, c1, c2) {
  const ctx = document.getElementById(canvasId).getContext('2d');
  const g = ctx.createLinearGradient(0, 0, 0, 260);
  g.addColorStop(0, c1);
  g.addColorStop(1, c2);
  return g;
}

/* Attendance Trends — grouped bar chart */
new Chart(document.getElementById('attendanceChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => date('M d', strtotime($r['attendance_date'])), $attendanceTrend)) ?>,
    datasets: [
      { label: 'Present', data: <?= json_encode(array_column($attendanceTrend, 'present')) ?>, backgroundColor: obGrad('attendanceChart', '#16a34a', '#22c55e'), borderRadius: 6, borderSkipped: false },
      { label: 'Late', data: <?= json_encode(array_column($attendanceTrend, 'late')) ?>, backgroundColor: obGrad('attendanceChart', '#d97706', '#fbbf24'), borderRadius: 6, borderSkipped: false },
      { label: 'Absent', data: <?= json_encode(array_column($attendanceTrend, 'absent')) ?>, backgroundColor: obGrad('attendanceChart', '#dc2626', '#f87171'), borderRadius: 6, borderSkipped: false }
    ]
  },
  options: obGrid()
});

/* Leave Statistics by Type — bar chart */
new Chart(document.getElementById('leaveChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($leaveStats, 'type_name')) ?>,
    datasets: [{
      label: 'Requests',
      data: <?= json_encode(array_map('intval', array_column($leaveStats, 'cnt'))) ?>,
      backgroundColor: obGrad('leaveChart', '#5e6b46', '#7d8b62'),
      borderRadius: 8,
      borderSkipped: false,
      maxBarThickness: 46
    }]
  },
  options: obGrid({ plugins: { legend: { display: false } } })
});

/* Payroll Expense Summary — bar chart */
new Chart(document.getElementById('payrollChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($payrollExpense, 'm')) ?>,
    datasets: [{ label: 'Net Pay Disbursed', data: <?= json_encode(array_map('floatval', array_column($payrollExpense, 'total'))) ?>, backgroundColor: obGrad('payrollChart', '#2a1810', '#5a4b41'), borderRadius: 8, borderSkipped: false, maxBarThickness: 46 }]
  },
  options: obGrid({ plugins: { legend: { display: false } } })
});

/* Employee Performance Summary — horizontal bar chart */
new Chart(document.getElementById('perfChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => $r['department_name'] ?? 'Unassigned', $perfSummary)) ?>,
    datasets: [{ label: 'Avg Overall Score', data: <?= json_encode(array_map('floatval', array_column($perfSummary, 'avg_score'))) ?>, backgroundColor: obGrad('perfChart', '#5e6b46', '#8d5b4c'), borderRadius: 8, borderSkipped: false, maxBarThickness: 30 }]
  },
  options: obGrid({ indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { suggestedMax: 5, grid: { color: '#e6d8c5' }, ticks: { color: '#7a6558', font: { weight: 600 } } }, y: { grid: { display: false }, ticks: { color: '#7a6558', font: { weight: 600 } } } } })
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

