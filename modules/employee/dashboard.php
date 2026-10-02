<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE]);

$userId = $_SESSION['user_id'];
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$nextMonthStart = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$emp = $pdo->prepare("SELECT e.employee_id, e.employee_code, e.employment_status, e.department_id,
  d.department_name FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id
  WHERE e.user_id=?");
$emp->execute([$userId]);
$emp = $emp->fetch();
if (!$emp) {
    $emp = [
        'employee_id' => 0,
        'employee_code' => '-',
        'employment_status' => 'N/A',
        'department_id' => null,
        'department_name' => '-',
    ];
}
$employeeId = (int)($emp['employee_id'] ?? 0);

$leaveBalance = $pdo->prepare("SELECT lt.type_name, lt.default_days,
    COALESCE(SUM(CASE WHEN lr.status='APPROVED' THEN lr.total_days ELSE 0 END),0) AS used
    FROM leave_types lt LEFT JOIN leave_requests lr ON lr.leave_type_id=lt.leave_type_id AND lr.employee_id=?
    GROUP BY lt.leave_type_id, lt.type_name, lt.default_days");
  $leaveBalance->execute([$employeeId]);
$leaveBalance = $leaveBalance->fetchAll();

$recentAnnouncements = $pdo->prepare("SELECT a.* FROM announcements a
    WHERE (a.target_department IS NULL OR a.target_department = ?) AND (a.expires_at IS NULL OR a.expires_at >= ?)
    ORDER BY a.posted_at DESC LIMIT 3");
  $recentAnnouncements->execute([$emp['department_id'], $today]);
$recentAnnouncements = $recentAnnouncements->fetchAll();

$thisMonthAttendance = $pdo->prepare("SELECT COUNT(*) AS c FROM attendance WHERE employee_id=? AND status='PRESENT' AND attendance_date >= ? AND attendance_date < ?");
$thisMonthAttendance->execute([$employeeId, $monthStart, $nextMonthStart]);
$thisMonthAttendance = (int)($thisMonthAttendance->fetchColumn() ?: 0);

// Attendance breakdown for this month, used by the Employee Activity bar chart
$monthlyStatusBreakdown = $pdo->prepare("SELECT status, COUNT(*) AS c FROM attendance
  WHERE employee_id=? AND attendance_date >= ? AND attendance_date < ?
    GROUP BY status");
$monthlyStatusBreakdown->execute([$employeeId, $monthStart, $nextMonthStart]);
$monthlyStatusBreakdown = $monthlyStatusBreakdown->fetchAll(PDO::FETCH_KEY_PAIR);
$activityLabels = ['PRESENT' => 'Present', 'LATE' => 'Late', 'HALF_DAY' => 'Half Day', 'ABSENT' => 'Absent', 'ON_LEAVE' => 'On Leave'];
// Same brand palette used for the status badges below, so each bar keeps the
// same meaning-to-color mapping as the rest of the dashboard (no random colors).
$activityColors = ['PRESENT' => '#16a34a', 'LATE' => '#d97706', 'HALF_DAY' => '#5e6b46', 'ABSENT' => '#dc2626', 'ON_LEAVE' => '#8d5b4c'];
$activityChartLabels = [];
$activityChartData = [];
$activityChartColors = [];
foreach ($activityLabels as $key => $label) {
    $activityChartLabels[] = $label;
    $activityChartData[] = (int)($monthlyStatusBreakdown[$key] ?? 0);
    $activityChartColors[] = $activityColors[$key];
}

$pendingTasks = $pdo->prepare("SELECT COUNT(*) AS c FROM tasks WHERE employee_id=? AND status IN ('PENDING','IN_PROGRESS')");
$pendingTasks->execute([$employeeId]);
$pendingTasks = (int)($pendingTasks->fetchColumn() ?: 0);

$latestLeave = $pdo->prepare("SELECT lr.*, lt.type_name FROM leave_requests lr JOIN leave_types lt ON lr.leave_type_id=lt.leave_type_id
    WHERE lr.employee_id=? ORDER BY lr.filed_at DESC LIMIT 1");
$latestLeave->execute([$employeeId]);
$latestLeave = $latestLeave->fetch();

$pageTitle = 'Employee Dashboard';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* Modern Dashboard Styling */
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

/* Cafe Theme Badge Palette — kept consistent with the Employee Activity chart colors below */
.badge-mocha { background-color: #f3efe9; color: #5c4033; border: 1px solid #ded5cb; }
.badge-matcha { background-color: #eaf2e8; color: #3b6346; border: 1px solid #cce0c9; }
.badge-caramel { background-color: #fdf5e6; color: #b87b28; border: 1px solid #f2dfbd; }
.badge-terracotta { background-color: #fcebe6; color: #4c5838; border: 1px solid #f3c7bc; }
</style>

<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-house-door-fill"></i></div>
  <div>
    <h4>Welcome, <?= e($_SESSION['full_name'] ?? 'Employee') ?> 👋</h4>
  </div>
</div>

<!-- TOP KPI STAT CARDS GRID -->
<div class="row g-3 mb-4">
  <!-- 1. Days Present This Month -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">PRESENT THIS MONTH</span>
        <div class="stat-icon-badge badge-matcha"><i class="bi bi-calendar-check-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($thisMonthAttendance) ?></h3>
    </div>
  </div>

  <!-- 2. Employee Code -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">EMPLOYEE CODE</span>
        <div class="stat-icon-badge badge-mocha"><i class="bi bi-person-badge"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= e($emp['employee_code']) ?></h3>
    </div>
  </div>

  <!-- 3. Employment Status -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">EMPLOYMENT STATUS</span>
        <div class="stat-icon-badge badge-caramel"><i class="bi bi-shield-check"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= e($emp['employment_status']) ?></h3>
    </div>
  </div>

  <!-- 4. Pending Tasks -->
  <div class="col-xl-3 col-md-6 col-12">
    <a href="<?= BASE_URL ?>employee/tasks" class="text-decoration-none">
      <div class="dashboard-stat-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-semibold">PENDING TASKS</span>
          <div class="stat-icon-badge badge-terracotta"><i class="bi bi-list-task"></i></div>
        </div>
        <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingTasks) ?></h3>
      </div>
    </a>
  </div>
</div>

<!-- DASHBOARD CONTENT ROW -->
<div class="row g-3">
  <!-- Latest Leave Request -->
  <div class="col-md-6">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-calendar2-week text-secondary me-2"></i>Latest Leave Request</h6>
        <?php if ($latestLeave): ?>
          <p class="mb-1 fw-medium text-dark">
            <strong><?= e($latestLeave['type_name']) ?></strong> &middot; <?= fdate($latestLeave['date_from']) ?> - <?= fdate($latestLeave['date_to']) ?>
          </p>
          <span class="badge bg-<?= leaveStatusColor($latestLeave['status']) ?> mb-2"><?= e(leaveStatusLabel($latestLeave['status'])) ?></span>
          <?php $lr = $latestLeave['review_remarks'] ?: ($latestLeave['hr_remarks'] ?: $latestLeave['manager_remarks']); ?>
          <?php if ($lr): ?>
            <div class="p-2 bg-light rounded text-muted small mt-2">"<?= e($lr) ?>"</div>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-muted small mb-0">You haven't filed a leave request yet.</p>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>employee/requests" class="small text-decoration-none text-cafe-link d-block mt-3">View all leave requests &raquo;</a>
      </div>
    </div>
  </div>

  <!-- Leave Balance Table -->
  <div class="col-md-6">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-pie-chart text-secondary me-2"></i>Leave Balance</h6>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="leaveBalanceTable">
            <thead>
              <tr class="text-muted small">
                <th>Type</th>
                <th>Allotted</th>
                <th>Used</th>
                <th>Remaining</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($leaveBalance as $lb): ?>
              <tr>
                <td class="fw-medium text-dark"><?= e($lb['type_name']) ?></td>
                <td><?= (int)($lb['default_days'] ?? 0) ?></td>
                <td><?= (int)($lb['used'] ?? 0) ?></td>
                <td class="fw-bold text-dark"><?= max(0, (int)($lb['default_days'] ?? 0) - (int)($lb['used'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Employee Activity (This Month's Attendance Breakdown) -->
  <div class="col-md-12">
    <div class="card border-0 shadow-sm rounded-3 emp-chart-card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="card-title fw-semibold m-0"><i class="bi bi-bar-chart-fill text-secondary me-2"></i>Employee Activity <span class="text-muted fw-normal small">— <?= e(date('F Y')) ?></span></h6>
        </div>
        <canvas id="employeeActivityChart" height="90"></canvas>
      </div>
    </div>
  </div>

  <!-- Recent Announcements -->
  <div class="col-md-12">
    <div class="card border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-megaphone text-secondary me-2"></i>Recent Announcements</h6>
        <?php foreach ($recentAnnouncements as $a): ?>
          <div class="mb-2 pb-2 border-bottom">
            <strong class="text-dark d-block"><?= e($a['title']) ?></strong>
            <span class="small text-muted"><i class="bi bi-clock me-1"></i><?= fdate($a['posted_at']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (empty($recentAnnouncements)): ?>
          <p class="text-muted small m-0">No active announcements right now.</p>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>employee/announcements" class="small text-decoration-none text-cafe-link d-block mt-3">View all announcements &raquo;</a>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#leaveBalanceTable').DataTable({ order: [], paging: false, searching: false, info: false });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('employeeActivityChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($activityChartLabels) ?>,
    datasets: [{
      label: 'Days',
      data: <?= json_encode($activityChartData) ?>,
      backgroundColor: <?= json_encode($activityChartColors) ?>,
      borderRadius: 8,
      maxBarThickness: 52
    }]
  },
  options: {
    animation: { duration: 700, easing: 'easeOutQuart' },
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(111,78,55,0.08)' } },
      x: { grid: { display: false } }
    }
  }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
