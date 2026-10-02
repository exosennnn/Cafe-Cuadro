<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER]);

$openJobs = $pdo->query("SELECT COUNT(*) AS c FROM job_vacancies WHERE status='OPEN'")->fetch()['c'];
$totalApplicants = $pdo->query("SELECT COUNT(*) AS c FROM applicants")->fetch()['c'];
$pendingApps = $pdo->query("SELECT COUNT(*) AS c FROM job_applications WHERE status IN ('SUBMITTED','UNDER_REVIEW')")->fetch()['c'];
$pendingLeaves = $pdo->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status='PENDING_HR'")->fetch()['c'];
$totalEmployees = $pdo->query("SELECT COUNT(*) AS c FROM employees WHERE employment_status='ACTIVE'")->fetch()['c'];

$appTrend = $pdo->query("SELECT DATE_FORMAT(applied_at,'%b %d') AS d, COUNT(*) AS c FROM job_applications
    WHERE applied_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(applied_at) ORDER BY DATE(applied_at)")->fetchAll();

$pageTitle = 'HR Staff Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.dashboard-stat-card {
    background: #ffffff;
    border: 1px solid rgba(74, 48, 34, 0.12);
    border-radius: 14px;
    padding: 1.25rem;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
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
.btn-cafe-action {
    background-color: #eef1e4;
    color: #5e6b46;
    border: 1px solid #cfd8bd;
    border-radius: 50px;
    font-weight: 600;
    transition: all 0.2s ease;
}
.btn-cafe-action:hover {
    background-color: #5e6b46;
    color: #ffffff;
    border-color: #5e6b46;
    transform: translateY(-1px);
}
.dashboard-stat-card .stat-label {
    font-size: 0.68rem;
    letter-spacing: 0.02em;
    line-height: 1.2;
    white-space: normal;
    padding-right: 6px;
}
</style>

<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-speedometer2"></i></div>
  <div>
    <h4>HR Staff Dashboard</h4>
  </div>
</div>

<!-- TOP KPI STAT CARDS GRID (all 5 on one line, like Employee dashboard) -->
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
  <!-- 1. Open Job Posts -->
  <div class="col">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted stat-label fw-semibold">OPEN JOBS</span>
        <div class="stat-icon-badge badge-caramel"><i class="bi bi-briefcase-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($openJobs) ?></h3>
    </div>
  </div>

  <!-- 2. Total Applicants -->
  <div class="col">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted stat-label fw-semibold">APPLICANTS</span>
        <div class="stat-icon-badge badge-matcha"><i class="bi bi-person-vcard-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($totalApplicants) ?></h3>
    </div>
  </div>

  <!-- 3. Pending Applications -->
  <div class="col">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted stat-label fw-semibold">PENDING APPS</span>
        <div class="stat-icon-badge badge-terracotta"><i class="bi bi-file-earmark-person"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingApps) ?></h3>
    </div>
  </div>

  <!-- 4. Pending Leave Requests -->
  <div class="col">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted stat-label fw-semibold">PENDING LEAVES</span>
        <div class="stat-icon-badge badge-caramel"><i class="bi bi-calendar-event"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingLeaves) ?></h3>
    </div>
  </div>

  <!-- 5. Active Employees -->
  <div class="col">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted stat-label fw-semibold">ACTIVE STAFF</span>
        <div class="stat-icon-badge badge-mocha"><i class="bi bi-people-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($totalEmployees) ?></h3>
    </div>
  </div>
</div>

<!-- MAIN CONTENT ROW -->
<div class="row g-3">
  <!-- Application Trend Chart -->
  <div class="col-md-8">
    <div class="card h-100 border-0 shadow-sm rounded-3 emp-chart-card">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-bar-chart-fill text-secondary me-2"></i>Applications Received (Last 14 Days)</h6>
        <div style="height:250px;">
          <canvas id="appChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Links Action Menu -->
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-lightning-charge text-secondary me-2"></i>Quick Actions</h6>
        <div class="d-grid gap-2">
          <a href="<?= BASE_URL ?>hr-staff/jobs" class="btn btn-sm btn-cafe-action py-2 text-start"><i class="bi bi-briefcase me-2"></i> Manage Job Vacancies</a>
          <a href="<?= BASE_URL ?>hr-staff/applications" class="btn btn-sm btn-cafe-action py-2 text-start"><i class="bi bi-file-earmark-check me-2"></i> Review Applications</a>
          <a href="<?= BASE_URL ?>hr-staff/requests" class="btn btn-sm btn-cafe-action py-2 text-start"><i class="bi bi-calendar-check me-2"></i> Process Leave Requests</a>
          <a href="<?= BASE_URL ?>hr-staff/announcements" class="btn btn-sm btn-cafe-action py-2 text-start"><i class="bi bi-megaphone me-2"></i> Post Announcement</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('appChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($appTrend, 'd')) ?>,
    datasets: [{
      label: 'Applications',
      data: <?= json_encode(array_column($appTrend, 'c')) ?>,
      backgroundColor: 'rgba(94,107,70, 0.65)',
      hoverBackgroundColor: '#5e6b46',
      borderRadius: 6,
      maxBarThickness: 34
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 900, easing: 'easeOutQuart' },
    plugins: { legend: { display: false } },
    scales: {
      y: { ticks: { stepSize: 1, precision: 0 }, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.06)' } },
      x: { grid: { display: false } }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
