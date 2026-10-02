<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
// Employee Manager oversees ALL employees company-wide, regardless of
// department/position (Barista, Cook, Cashier, etc. all report to them).

$teamCount = $pdo->prepare("SELECT COUNT(*) AS c FROM employees e JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? AND e.employment_status='ACTIVE'");
$teamCount->execute([ROLE_EMPLOYEE]);
$teamCount = $teamCount->fetch()['c'];

$pendingLeaves = $pdo->prepare("SELECT COUNT(*) AS c FROM leave_requests lr JOIN employees e ON lr.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? AND lr.status='PENDING_MANAGER'");
$pendingLeaves->execute([ROLE_EMPLOYEE]);
$pendingLeaves = $pendingLeaves->fetch()['c'];

$pendingTasks = $pdo->prepare("SELECT COUNT(*) AS c FROM tasks t JOIN employees e ON t.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? AND t.status IN ('PENDING','IN_PROGRESS')");
$pendingTasks->execute([ROLE_EMPLOYEE]);
$pendingTasks = $pendingTasks->fetch()['c'];

$presentToday = $pdo->prepare("SELECT COUNT(*) AS c FROM attendance a JOIN employees e ON a.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? AND a.attendance_date=CURDATE() AND a.status='PRESENT'");
$presentToday->execute([ROLE_EMPLOYEE]);
$presentToday = $presentToday->fetch()['c'];

$pageTitle = 'Employee Manager Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Structural sizing for KPI stat cards & icon badges.
   Colors are retinted by employee-manager-theme.css (!important),
   this block only supplies the shape/size these classes need. */
.dashboard-stat-card {
    border-radius: 14px;
    padding: 1.25rem;
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
</style>

<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-speedometer2"></i></div>
  <div>
    <h4>Employee Manager Dashboard</h4>
  </div>
</div>

<!-- TOP KPI STAT CARDS GRID (4 Balanced Columns) -->
<div class="row g-3 mb-4">
  <!-- 1. Team Members -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">TEAM MEMBERS</span>
        <div class="stat-icon-badge badge-mocha"><i class="bi bi-people-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($teamCount) ?></h3>
    </div>
  </div>

  <!-- 2. Present Today -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">PRESENT TODAY</span>
        <div class="stat-icon-badge badge-matcha"><i class="bi bi-check-circle-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($presentToday) ?></h3>
    </div>
  </div>

  <!-- 3. Pending Leave Requests -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">PENDING LEAVES</span>
        <div class="stat-icon-badge badge-terracotta"><i class="bi bi-calendar-event"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingLeaves) ?></h3>
    </div>
  </div>

  <!-- 4. Open Tasks -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">OPEN TASKS</span>
        <div class="stat-icon-badge badge-caramel"><i class="bi bi-list-task"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingTasks) ?></h3>
    </div>
  </div>
</div>

<!-- QUICK ACTIONS SECTION -->
<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body p-4">
    <h6 class="card-title fw-semibold mb-3"><i class="bi bi-lightning-charge text-secondary me-2"></i>Quick Actions</h6>
    <div class="row g-2">
      <div class="col-md-3 col-6">
        <a href="<?= BASE_URL ?>employee-manager/employees" class="btn btn-sm btn-outline-primary w-100 py-2 text-center"><i class="bi bi-people me-2"></i> View Team</a>
      </div>
      <div class="col-md-3 col-6">
        <a href="<?= BASE_URL ?>employee-manager/requests" class="btn btn-sm btn-outline-primary w-100 py-2 text-center"><i class="bi bi-calendar-check me-2"></i> Review Leave Requests</a>
      </div>
      <div class="col-md-3 col-6">
        <a href="<?= BASE_URL ?>employee-manager/tasks" class="btn btn-sm btn-outline-primary w-100 py-2 text-center"><i class="bi bi-plus-circle me-2"></i> Assign Task</a>
      </div>
      <div class="col-md-3 col-6">
        <a href="<?= BASE_URL ?>employee-manager/evaluations" class="btn btn-sm btn-outline-primary w-100 py-2 text-center"><i class="bi bi-star me-2"></i> Evaluate Performance</a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>