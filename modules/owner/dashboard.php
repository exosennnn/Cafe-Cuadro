<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

/* ---- Company overview stats (read-only) ---- */
$totalEmployees = $pdo->query("SELECT COUNT(*) AS c FROM employees WHERE employment_status='ACTIVE'")->fetch()['c'];
$totalDepartments = $pdo->query("SELECT COUNT(*) AS c FROM departments")->fetch()['c'];

/* Attendance summary - last 30 days */
$attendanceSummary = $pdo->query("SELECT status, COUNT(*) AS c FROM attendance
    WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY status")->fetchAll();
$attendanceMap = ['PRESENT' => 0, 'LATE' => 0, 'ABSENT' => 0, 'HALF_DAY' => 0, 'ON_LEAVE' => 0];
foreach ($attendanceSummary as $row) { $attendanceMap[$row['status']] = (int)$row['c']; }

/* Leave summary */
$pendingLeaves = $pdo->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status IN ('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL')")->fetch()['c'];
$approvedLeaves = $pdo->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status='APPROVED' AND MONTH(filed_at)=MONTH(CURDATE()) AND YEAR(filed_at)=YEAR(CURDATE())")->fetch()['c'];
$rejectedLeaves = $pdo->query("SELECT COUNT(*) AS c FROM leave_requests WHERE status='REJECTED' AND MONTH(filed_at)=MONTH(CURDATE()) AND YEAR(filed_at)=YEAR(CURDATE())")->fetch()['c'];

/* Payroll summary - this month */
$payslipsGenerated = $pdo->query("SELECT COUNT(*) AS c FROM payroll WHERE MONTH(generated_at)=MONTH(CURDATE()) AND YEAR(generated_at)=YEAR(CURDATE())")->fetch()['c'];
$payrollThisMonth = $pdo->query("SELECT COALESCE(SUM(net_pay),0) AS s, COUNT(*) AS c FROM payroll WHERE status='PAID' AND MONTH(released_at)=MONTH(CURDATE()) AND YEAR(released_at)=YEAR(CURDATE())")->fetch();
$payrollPaid = $payrollThisMonth['c'];
$payrollAwaitingApproval = $pdo->query("SELECT COUNT(*) AS c FROM payroll WHERE status='SUBMITTED'")->fetch()['c'];

/* Recent system activities */
$recentActivity = $pdo->query("SELECT al.*, u.first_name, u.last_name FROM audit_logs al
    LEFT JOIN users u ON al.user_id=u.user_id ORDER BY al.created_at DESC LIMIT 8")->fetchAll();

$pageTitle = 'Owner Dashboard';
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
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    height: 100%;
}

.dashboard-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(74, 48, 34, 0.08);
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

/* Cafe Theme Badge Palette */
.badge-mocha { background-color: #f3efe9; color: #5c4033; border: 1px solid #ded5cb; }
.badge-matcha { background-color: #eaf2e8; color: #3b6346; border: 1px solid #cce0c9; }
.badge-caramel { background-color: #fdf5e6; color: #b87b28; border: 1px solid #f2dfbd; }
.badge-terracotta { background-color: #fcebe6; color: #4c5838; border: 1px solid #f3c7bc; }
</style>

<div class="mb-4">
    <h3 class="fw-bold mb-1">Company Overview</h3>
</div>

<!-- TOP KPI STAT CARDS GRID -->
<div class="row g-3 mb-4">
  <!-- 1. Total Employees -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">TOTAL EMPLOYEES</span>
        <div class="stat-icon-badge badge-mocha"><i class="bi bi-people-fill"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($totalEmployees) ?></h3>
    </div>
  </div>

  <!-- 2. Total Departments -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">DEPARTMENTS</span>
        <div class="stat-icon-badge badge-matcha"><i class="bi bi-building"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($totalDepartments) ?></h3>
    </div>
  </div>

  <!-- 3. Pending Leaves -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">PENDING LEAVES</span>
        <div class="stat-icon-badge badge-terracotta"><i class="bi bi-calendar-event"></i></div>
      </div>
      <h3 class="fw-bold m-0 text-dark"><?= number_format($pendingLeaves) ?></h3>
    </div>
  </div>

  <!-- 4. Payroll Disbursed -->
  <div class="col-xl-3 col-md-6 col-12">
    <div class="dashboard-stat-card">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-muted small fw-semibold">PAID THIS MO.</span>
        <div class="stat-icon-badge badge-caramel"><i class="bi bi-cash-stack"></i></div>
      </div>
      <h4 class="fw-bold m-0 text-dark"><?= fmoney($payrollThisMonth['s']) ?></h4>
    </div>
  </div>

  <!-- 5. Payroll Awaiting Approval -->
  <div class="col-xl-3 col-md-6 col-12">
    <a href="<?= BASE_URL ?>owner/payroll" class="text-decoration-none">
      <div class="dashboard-stat-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <span class="text-muted small fw-semibold">PAYROLL FOR APPROVAL</span>
          <div class="stat-icon-badge badge-terracotta"><i class="bi bi-clock-history"></i></div>
        </div>
        <h3 class="fw-bold m-0 text-dark"><?= number_format($payrollAwaitingApproval) ?></h3>
      </div>
    </a>
  </div>
</div>

<!-- OVERVIEW CARDS ROW — animated bar charts -->
<div class="row g-3 mb-4">
  <!-- Attendance Summary -->
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-clock text-secondary me-2"></i>Attendance Summary <small class="text-muted fw-normal">(30 days)</small></h6>
        <div style="height:220px;"><canvas id="attendanceBarChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Leave Summary -->
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-calendar-check text-secondary me-2"></i>Leave Summary <small class="text-muted fw-normal">(this month)</small></h6>
        <div style="height:220px;"><canvas id="leaveBarChart"></canvas></div>
      </div>
    </div>
  </div>

  <!-- Payroll Summary -->
  <div class="col-md-4">
    <div class="card h-100 border-0 shadow-sm rounded-3">
      <div class="card-body">
        <h6 class="card-title fw-semibold mb-3"><i class="bi bi-wallet2 text-secondary me-2"></i>Payroll Summary <small class="text-muted fw-normal">(this month)</small></h6>
        <div style="height:220px;"><canvas id="payrollBarChart"></canvas></div>
        <div class="text-center mt-2 small text-muted">Total Disbursed: <span class="fw-bold" style="color:var(--primary, #5e6b46);"><?= fmoney($payrollThisMonth['s']) ?></span></div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
Chart.defaults.color = '#2a1810';
Chart.defaults.font.family = "'DM Sans', sans-serif";

const obBarAnimation = {
  duration: 1200,
  easing: 'easeOutQuart',
  delay: (ctx) => ctx.type === 'data' ? ctx.dataIndex * 120 : 0
};
const obBarOptions = (extra = {}) => Object.assign({
  responsive: true,
  maintainAspectRatio: false,
  animation: obBarAnimation,
  plugins: { legend: { display: false } },
  scales: {
    x: { grid: { display: false }, ticks: { color: '#7a6558', font: { weight: 600 } } },
    y: { beginAtZero: true, grid: { color: '#e6d8c5' }, ticks: { color: '#7a6558', font: { weight: 600 }, precision: 0 } }
  }
}, extra);

function obGradient(ctx, c1, c2) {
  const g = ctx.createLinearGradient(0, 0, 0, 220);
  g.addColorStop(0, c1);
  g.addColorStop(1, c2);
  return g;
}

const attCtx = document.getElementById('attendanceBarChart').getContext('2d');
new Chart(attCtx, {
  type: 'bar',
  data: {
    labels: ['Present', 'Late', 'Absent', 'Half Day', 'On Leave'],
    datasets: [{
      data: [
        <?= (int)$attendanceMap['PRESENT'] ?>, <?= (int)$attendanceMap['LATE'] ?>,
        <?= (int)$attendanceMap['ABSENT'] ?>, <?= (int)$attendanceMap['HALF_DAY'] ?>,
        <?= (int)$attendanceMap['ON_LEAVE'] ?>
      ],
      backgroundColor: [
        obGradient(attCtx, '#16a34a', '#22c55e'),
        obGradient(attCtx, '#d97706', '#fbbf24'),
        obGradient(attCtx, '#dc2626', '#f87171'),
        obGradient(attCtx, '#5e6b46', '#7d8b62'),
        obGradient(attCtx, '#8d5b4c', '#b37d6f')
      ],
      borderRadius: 8,
      borderSkipped: false,
      maxBarThickness: 40
    }]
  },
  options: obBarOptions()
});

const leaveCtx = document.getElementById('leaveBarChart').getContext('2d');
new Chart(leaveCtx, {
  type: 'bar',
  data: {
    labels: ['Pending', 'Approved', 'Rejected'],
    datasets: [{
      data: [<?= (int)$pendingLeaves ?>, <?= (int)$approvedLeaves ?>, <?= (int)$rejectedLeaves ?>],
      backgroundColor: [
        obGradient(leaveCtx, '#d97706', '#fbbf24'),
        obGradient(leaveCtx, '#16a34a', '#22c55e'),
        obGradient(leaveCtx, '#dc2626', '#f87171')
      ],
      borderRadius: 8,
      borderSkipped: false,
      maxBarThickness: 50
    }]
  },
  options: obBarOptions()
});

const payCtx = document.getElementById('payrollBarChart').getContext('2d');
new Chart(payCtx, {
  type: 'bar',
  data: {
    labels: ['Payslips Generated', 'Marked Paid'],
    datasets: [{
      data: [<?= (int)$payslipsGenerated ?>, <?= (int)$payrollPaid ?>],
      backgroundColor: [
        obGradient(payCtx, '#5e6b46', '#7d8b62'),
        obGradient(payCtx, '#2a1810', '#7a6558')
      ],
      borderRadius: 8,
      borderSkipped: false,
      maxBarThickness: 50
    }]
  },
  options: obBarOptions()
});
</script>

<!-- RECENT ACTIVITY TABLE -->
<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body">
    <h6 class="card-title fw-semibold mb-3"><i class="bi bi-clock-history text-secondary me-2"></i>Recent System Activities</h6>
    <div class="table-responsive">
      <table id="recentActivityTable" class="table table-sm table-hover align-middle mb-0">
        <thead>
          <tr class="text-muted small">
            <th>Timestamp</th>
            <th>User</th>
            <th>Action</th>
            <th>Module</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recentActivity as $log): ?>
          <tr>
            <td class="small text-nowrap"><?= fdate($log['created_at'], 'M d, Y g:i A') ?></td>
            <td class="small fw-medium text-dark"><?= $log['user_id'] ? e($log['first_name'].' '.$log['last_name']) : 'System' ?></td>
            <td><span class="badge badge-mocha"><?= e($log['action']) ?></span></td>
            <td class="small text-muted"><?= e($log['module']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($recentActivity)): ?>
          <tr><td colspan="4" class="text-center text-muted">No recent activity logged.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $('#recentActivityTable').DataTable({
    order: [[0, 'desc']],
    pageLength: 8,
    language: { search: '_INPUT_', searchPlaceholder: 'Search activity...' }
  });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
