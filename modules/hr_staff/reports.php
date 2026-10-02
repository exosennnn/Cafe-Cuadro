<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER]);

$byDept = $pdo->query("SELECT d.department_name, COUNT(e.employee_id) AS cnt FROM departments d
    LEFT JOIN employees e ON e.department_id=d.department_id AND e.employment_status='ACTIVE'
    GROUP BY d.department_id")->fetchAll();

$appStatus = $pdo->query("SELECT status, COUNT(*) AS cnt FROM job_applications GROUP BY status")->fetchAll();

$attendanceToday = $pdo->query("SELECT status, COUNT(*) AS cnt FROM attendance WHERE attendance_date = CURDATE() GROUP BY status")->fetchAll();

$pageTitle = 'Basic Reports';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-graph-up"></i></div>
  <div>
    <h4>Basic Reports</h4>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card h-100 border-0 shadow-sm rounded-3 emp-chart-card"><div class="card-body">
      <h6 class="card-title fw-semibold mb-3"><i class="bi bi-bar-chart-fill text-secondary me-2"></i>Employees per Department</h6>
      <canvas id="deptChart"></canvas>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card h-100 border-0 shadow-sm rounded-3 emp-chart-card"><div class="card-body">
      <h6 class="card-title fw-semibold mb-3"><i class="bi bi-pie-chart-fill text-secondary me-2"></i>Applications by Status</h6>
      <canvas id="appChart"></canvas>
    </div></div>
  </div>
  <div class="col-md-6">
    <div class="card h-100 border-0 shadow-sm rounded-3 emp-chart-card"><div class="card-body">
      <h6 class="card-title fw-semibold mb-3"><i class="bi bi-pie-chart text-secondary me-2"></i>Today's Attendance Breakdown</h6>
      <canvas id="attChart"></canvas>
    </div></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('deptChart'), {
  type: 'bar',
  data: { labels: <?= json_encode(array_column($byDept,'department_name')) ?>,
    datasets: [{ label:'Employees', data: <?= json_encode(array_column($byDept,'cnt')) ?>, backgroundColor:'rgba(94,107,70, 0.65)', hoverBackgroundColor:'#5e6b46', borderRadius:6, maxBarThickness:40 }] },
  options: { responsive:true, animation:{ duration: 900, easing:'easeOutQuart' }, plugins:{legend:{display:false}}, scales:{ y:{ beginAtZero:true, ticks:{ stepSize:1, precision:0 }, grid:{ color:'rgba(42,24,16,0.06)' } }, x:{ grid:{ display:false } } } }
});
new Chart(document.getElementById('appChart'), {
  type: 'doughnut',
  data: { labels: <?= json_encode(array_column($appStatus,'status')) ?>,
    datasets: [{ data: <?= json_encode(array_column($appStatus,'cnt')) ?>, backgroundColor:['#5e6b46','#2a1810','#d97706','#8d5b4c','#16a34a','#dc2626','#8996ad','#7d8b62','#eab308'] }] },
  options: { responsive:true, animation:{ duration: 900, easing:'easeOutQuart' } }
});
new Chart(document.getElementById('attChart'), {
  type: 'pie',
  data: { labels: <?= json_encode(array_column($attendanceToday,'status')) ?>,
    datasets: [{ data: <?= json_encode(array_column($attendanceToday,'cnt')) ?>, backgroundColor:['#16a34a','#d97706','#dc2626','#5e6b46','#8d5b4c'] }] },
  options: { responsive:true, animation:{ duration: 900, easing:'easeOutQuart' } }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

