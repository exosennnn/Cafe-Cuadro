<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
// Employee Manager oversees ALL employees company-wide, regardless of
// department/position (Barista, Cook, Cashier, etc. all report to them).

$attSummary = $pdo->prepare("SELECT a.status AS status, COUNT(*) AS cnt FROM attendance a JOIN employees e ON a.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE u.role_id=? AND a.attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY a.status");
$attSummary->execute([ROLE_EMPLOYEE]);
$attSummary = $attSummary->fetchAll();

$taskSummary = $pdo->prepare("SELECT t.status AS status, COUNT(*) AS cnt FROM tasks t JOIN employees e ON t.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? GROUP BY t.status");
$taskSummary->execute([ROLE_EMPLOYEE]);
$taskSummary = $taskSummary->fetchAll();

// Same brand palette used elsewhere in the app, so each bar keeps the
// same meaning-to-color mapping as the status badges (no random colors).
$attStatusColors = ['PRESENT' => '#16a34a', 'LATE' => '#d97706', 'HALF_DAY' => '#5e6b46', 'ABSENT' => '#dc2626', 'ON_LEAVE' => '#8d5b4c'];
$attChartColors = array_map(fn($s) => $attStatusColors[$s] ?? '#8996ad', array_column($attSummary, 'status'));

$taskStatusColors = ['PENDING' => '#8996ad', 'IN_PROGRESS' => '#5e6b46', 'COMPLETED' => '#16a34a', 'OVERDUE' => '#dc2626'];
$taskChartColors = array_map(fn($s) => $taskStatusColors[$s] ?? '#5e6b46', array_column($taskSummary, 'status'));

$evalAvg = $pdo->prepare("SELECT u.first_name, u.last_name, AVG(pe.overall_score) AS avg_score FROM performance_evaluations pe
    JOIN employees e ON pe.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE u.role_id=? GROUP BY pe.employee_id ORDER BY avg_score DESC");
$evalAvg->execute([ROLE_EMPLOYEE]);
$evalAvg = $evalAvg->fetchAll();

$pageTitle = 'Reports';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-graph-up"></i></div>
  <div>
    <h4>Reports</h4>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card border-0 shadow-sm rounded-3 emp-chart-card h-100"><div class="card-body p-4">
      <h6 class="card-title fw-semibold mb-3"><i class="bi bi-bar-chart-fill text-secondary me-2"></i>Attendance Summary <span class="text-muted fw-normal small">— Last 30 Days</span></h6>
      <canvas id="attChart" height="220"></canvas>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card border-0 shadow-sm rounded-3 emp-chart-card h-100"><div class="card-body p-4">
      <h6 class="card-title fw-semibold mb-3"><i class="bi bi-bar-chart-fill text-secondary me-2"></i>Task Status Breakdown</h6>
      <canvas id="taskChart" height="220"></canvas>
    </div></div>
  </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body p-4 table-responsive">
    <h6 class="card-title fw-semibold mb-3"><i class="bi bi-award text-secondary me-2"></i>Average Performance Score per Employee</h6>
    <table class="table table-hover" id="evalAvgTable">
      <thead><tr><th>Employee</th><th>Average Score</th></tr></thead>
      <tbody>
      <?php foreach ($evalAvg as $ev): ?>
        <tr><td><?= e($ev['first_name'].' '.$ev['last_name']) ?></td><td><span class="badge bg-primary"><?= round($ev['avg_score'],2) ?></span></td></tr>
      <?php endforeach; ?>
      <?php if (empty($evalAvg)): ?><tr><td colspan="2" class="text-center text-muted">No evaluation data yet.</td></tr><?php endif; ?>
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
  $('#evalAvgTable').DataTable({ order: [] });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const chartAnimation = { duration: 700, easing: 'easeOutQuart' };
const chartGrid = { color: 'rgba(111,78,55,0.08)' };

new Chart(document.getElementById('attChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($attSummary, 'status')) ?>,
    datasets: [{
      label: 'Days',
      data: <?= json_encode(array_column($attSummary, 'cnt')) ?>,
      backgroundColor: <?= json_encode($attChartColors) ?>,
      borderRadius: 8,
      maxBarThickness: 52
    }]
  },
  options: {
    animation: chartAnimation,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: chartGrid },
      x: { grid: { display: false } }
    }
  }
});

new Chart(document.getElementById('taskChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($taskSummary, 'status')) ?>,
    datasets: [{
      label: 'Tasks',
      data: <?= json_encode(array_column($taskSummary, 'cnt')) ?>,
      backgroundColor: <?= json_encode($taskChartColors) ?>,
      borderRadius: 8,
      maxBarThickness: 52
    }]
  },
  options: {
    animation: chartAnimation,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: chartGrid },
      x: { grid: { display: false } }
    }
  }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

