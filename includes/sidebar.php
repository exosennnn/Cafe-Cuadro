<?php
$role = currentRoleId();
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$baseUrlPath = parse_url(BASE_URL, PHP_URL_PATH) ?: BASE_URL;
if ($baseUrlPath !== '' && strpos($reqPath, $baseUrlPath) === 0) {
    $current = ltrim(substr($reqPath, strlen($baseUrlPath)), '/');
} else {
    $current = ltrim($reqPath, '/');
}
$current = trim($current, '/');
if ($current === '' || $current === 'index.php') {
    $current = '';
}

function navItem($url, $icon, $label, $current) {
    $clean = ltrim(cleanRoute($url), '/');
    $active = ($clean === $current || ($current === '' && $clean === '')) ? 'active' : '';
    echo '<a href="' . BASE_URL . $clean . '" class="nav-link ' . $active . '"><i class="bi ' . $icon . '"></i> <span>' . $label . '</span></a>';
}
?>
<aside class="app-sidebar" id="appSidebar">
  <div class="nav flex-column p-2">

    <?php if ($role == ROLE_OWNER): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('dashboard.php', 'bi-speedometer2', 'Main Dashboard', $current); ?>

        <div class="sidebar-group-label">HR Management</div>
        <?php navItem('modules/owner/dashboard.php', 'bi-people', 'HR Overview', $current); ?>
        <?php navItem('modules/hr_manager/employees.php', 'bi-person-badge', 'Manage Employees', $current); ?>
        <?php navItem('modules/owner/users.php', 'bi-person-gear', 'Manage Users', $current); ?>
        <?php navItem('modules/hr_manager/departments.php', 'bi-diagram-3', 'Departments', $current); ?>
        <?php navItem('modules/owner/branches.php', 'bi-geo-alt', 'Branches', $current); ?>
        <?php navItem('modules/owner/payroll.php', 'bi-cash-stack', 'Payroll Approval', $current); ?>

        <div class="sidebar-group-label">Point of Sale</div>
        <a href="<?= BASE_URL ?>pos/dashboard" class="nav-link <?= $current === 'pos/dashboard' ? 'active' : '' ?>"><i class="bi bi-shop"></i> <span>POS Dashboard</span></a>
        <a href="<?= BASE_URL ?>pos/products" class="nav-link <?= $current === 'pos/products' ? 'active' : '' ?>"><i class="bi bi-box-seam"></i> <span>Menu / Products</span></a>

        <div class="sidebar-group-label">Inventory <span class="text-muted">(view only)</span></div>
        <?php navItem('modules/inventory/index.php', 'bi-boxes', 'Ingredients & Stock', $current); ?>
        <?php navItem('modules/inventory/stock_movement.php', 'bi-arrow-left-right', 'Stock Movements', $current); ?>
        <?php navItem('modules/inventory/categories.php', 'bi-tags', 'Categories', $current); ?>
        <?php navItem('modules/inventory/production.php', 'bi-arrow-repeat', 'Restock POS Products', $current); ?>
        <?php navItem('modules/inventory/recipes.php', 'bi-journal-richtext', 'Product Recipes', $current); ?>

        <div class="sidebar-group-label">Procurement</div>
        <?php navItem('modules/procurement/purchase_requests.php', 'bi-clipboard-check', 'Purchase Requests', $current); ?>
        <?php navItem('modules/procurement/index.php', 'bi-file-earmark-text', 'Purchase Orders', $current); ?>
        <?php navItem('modules/procurement/suppliers.php', 'bi-truck', 'Suppliers', $current); ?>

        <div class="sidebar-group-label">Finance</div>
        <?php navItem('modules/finance/dashboard.php', 'bi-speedometer2', 'Finance Overview', $current); ?>
        <?php navItem('modules/finance/index.php', 'bi-cash-coin', 'Transactions', $current); ?>
        <?php navItem('modules/finance/payables.php', 'bi-receipt-cutoff', 'Supplier Payables', $current); ?>
        <?php navItem('modules/finance/payroll.php', 'bi-cash-stack', 'Payroll Payments', $current); ?>
        <?php navItem('modules/finance/categories.php', 'bi-tags', 'Finance Categories', $current); ?>
        <?php navItem('modules/finance/report.php', 'bi-file-earmark-bar-graph', 'Financial Report', $current); ?>

        <div class="sidebar-group-label">Insights</div>
        <?php navItem('modules/reports/index.php', 'bi-file-earmark-bar-graph', 'Reports', $current); ?>
        <?php navItem('modules/owner/analytics.php', 'bi-graph-up', 'Company Analytics', $current); ?>

        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/owner/announcements.php', 'bi-megaphone', 'Announcements', $current); ?>
        <?php navItem('modules/owner/settings.php', 'bi-gear', 'System Settings', $current); ?>

    <?php elseif ($role == ROLE_HR_MANAGER): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/hr_manager/dashboard.php', 'bi-speedometer2', 'Dashboard', $current); ?>
        <?php navItem('modules/hr_manager/my_attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <div class="sidebar-group-label">People</div>
        <?php navItem('modules/hr_manager/password_resets.php', 'bi-key', 'Password Resets', $current); ?>
        <?php navItem('modules/hr_manager/departments.php', 'bi-diagram-3', 'Departments', $current); ?>
        <?php navItem('modules/hr_manager/branches.php', 'bi-geo-alt', 'Branches', $current); ?>
        <?php navItem('modules/hr_manager/employees.php', 'bi-person-badge', 'Manage Employees', $current); ?>
        <div class="sidebar-group-label">Recruitment</div>
        <?php navItem('modules/hr_manager/recruitment.php', 'bi-briefcase', 'Recruitment', $current); ?>
        <?php navItem('modules/hr_manager/interviews.php', 'bi-person-video3', 'HR Interviews', $current); ?>
        <?php navItem('modules/hr_manager/hiring_approvals.php', 'bi-patch-check', 'Approve Hiring', $current); ?>
        <div class="sidebar-group-label">Requests &amp; Payroll</div>
        <?php navItem('modules/hr_manager/requests.php', 'bi-journal-check', 'Request Final Approval', $current); ?>
        <?php navItem('modules/hr_manager/payroll.php', 'bi-cash-stack', 'Prepare Payroll', $current); ?>
        <?php navItem('modules/hr_manager/contracts.php', 'bi-file-earmark-text', 'Contracts', $current); ?>
        <div class="sidebar-group-label">Admin</div>
        <?php navItem('modules/hr_manager/announcements.php', 'bi-megaphone', 'Announcements', $current); ?>
        <?php navItem('modules/reports/index.php', 'bi-file-earmark-bar-graph', 'Reports', $current); ?>
        <?php navItem('modules/hr_manager/audit_logs.php', 'bi-clock-history', 'Audit Logs', $current); ?>
        <?php navItem('modules/hr_manager/settings.php', 'bi-gear', 'System Settings', $current); ?>

    <?php elseif ($role == ROLE_HR_STAFF): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/hr_staff/dashboard.php', 'bi-speedometer2', 'Dashboard', $current); ?>
        <?php navItem('modules/hr_staff/my_attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <div class="sidebar-group-label">Recruitment</div>
        <?php navItem('modules/hr_staff/jobs.php', 'bi-briefcase', 'Job Vacancies', $current); ?>
        <?php navItem('modules/hr_staff/applications.php', 'bi-file-earmark-text', 'Review Applications', $current); ?>
        <?php navItem('modules/hr_staff/interviews.php', 'bi-calendar-event', 'Schedule Interviews', $current); ?>
        <div class="sidebar-group-label">Employees</div>
        <?php navItem('modules/hr_staff/employees.php', 'bi-person-badge', 'Employee Records', $current); ?>
        <?php navItem('modules/hr_staff/attendance.php', 'bi-clock', 'Attendance Monitoring', $current); ?>
        <?php navItem('modules/hr_staff/requests.php', 'bi-journal-text', 'Request Forms', $current); ?>
        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/hr_staff/announcements.php', 'bi-megaphone', 'Announcements', $current); ?>

    <?php elseif ($role == ROLE_EMPLOYEE_MANAGER): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/employee_manager/dashboard.php', 'bi-speedometer2', 'Dashboard', $current); ?>
        <?php navItem('modules/employee_manager/my_attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <div class="sidebar-group-label">Team</div>
        <?php navItem('modules/employee_manager/employees.php', 'bi-people', 'Employees', $current); ?>
        <?php navItem('modules/hr_staff/attendance.php', 'bi-clock', 'Monitor Attendance', $current); ?>
        <?php navItem('modules/employee_manager/requests.php', 'bi-journal-check', 'Request Approvals', $current); ?>
        <?php navItem('modules/employee_manager/interviews.php', 'bi-person-video3', 'Final Interviews', $current); ?>
        <?php navItem('modules/employee_manager/evaluations.php', 'bi-star', 'Performance Evaluation', $current); ?>
        <?php navItem('modules/employee_manager/tasks.php', 'bi-list-task', 'Assign Tasks', $current); ?>
        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/employee_manager/announcements.php', 'bi-megaphone', 'Announcements', $current); ?>

    <?php elseif ($role == ROLE_EMPLOYEE): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/employee/dashboard.php', 'bi-speedometer2', 'Dashboard', $current); ?>
        <?php navItem('modules/employee/profile.php', 'bi-person', 'My Profile', $current); ?>
        <div class="sidebar-group-label">My Work</div>
        <?php navItem('modules/employee/tasks.php', 'bi-list-task', 'My Tasks', $current); ?>
        <?php navItem('modules/employee/attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <?php navItem('modules/employee/payslips.php', 'bi-cash', 'My Payslips', $current); ?>
        <?php navItem('modules/employee/my_contract.php', 'bi-file-earmark-text', 'My Contract', $current); ?>
        <?php navItem('modules/employee/requests.php', 'bi-journal-text', 'My Requests', $current); ?>
        <?php navItem('modules/employee/evaluations.php', 'bi-star', 'My Evaluation', $current); ?>
        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/employee/announcements.php', 'bi-megaphone', 'Announcements', $current); ?>

    <?php elseif ($role == ROLE_APPLICANT): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/applicant/dashboard.php', 'bi-speedometer2', 'Dashboard', $current); ?>
        <?php navItem('modules/applicant/profile.php', 'bi-person', 'My Profile / Resume', $current); ?>
        <div class="sidebar-group-label">Jobs</div>
        <?php navItem('modules/applicant/jobs.php', 'bi-briefcase', 'Job Openings', $current); ?>
        <?php navItem('modules/applicant/my_applications.php', 'bi-file-earmark-text', 'My Applications', $current); ?>

    <?php elseif ($role == ROLE_CASHIER): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('dashboard.php', 'bi-speedometer2', 'Main Dashboard', $current); ?>
        <div class="sidebar-group-label">Point of Sale</div>
        <a href="<?= BASE_URL ?>pos/sales" class="nav-link <?= $current === 'pos/sales' ? 'active' : '' ?>"><i class="bi bi-cart-check"></i> <span>Sales</span></a>
        <div class="sidebar-group-label">HR Self-Service</div>
        <?php navItem('modules/employee/attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <?php navItem('modules/employee/requests.php', 'bi-journal-text', 'My Requests', $current); ?>
        <?php navItem('modules/employee/payslips.php', 'bi-cash', 'My Payslips', $current); ?>
        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/profile/index.php', 'bi-person', 'My Profile', $current); ?>

    <?php elseif ($role == ROLE_INVENTORY_STAFF): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/inventory/dashboard.php', 'bi-speedometer2', 'Inventory Dashboard', $current); ?>
        <div class="sidebar-group-label">Inventory</div>
        <?php navItem('modules/inventory/index.php', 'bi-boxes', 'Ingredients & Stock', $current); ?>
        <?php navItem('modules/inventory/stock_movement.php', 'bi-arrow-left-right', 'Stock Movements', $current); ?>
        <?php navItem('modules/inventory/categories.php', 'bi-tags', 'Categories', $current); ?>
        <?php navItem('modules/inventory/production.php', 'bi-arrow-repeat', 'Restock POS Products', $current); ?>
        <?php navItem('modules/inventory/recipes.php', 'bi-journal-richtext', 'Product Recipes', $current); ?>
        <div class="sidebar-group-label">Procurement</div>
        <?php navItem('modules/procurement/index.php', 'bi-file-earmark-text', 'Purchase Orders', $current); ?>
        <?php navItem('modules/procurement/purchase_requests.php', 'bi-clipboard-check', 'Purchase Requests', $current); ?>
        <?php navItem('modules/procurement/deliveries.php', 'bi-truck', 'Receiving / GRN', $current); ?>
        <?php navItem('modules/procurement/suppliers.php', 'bi-people', 'Suppliers', $current); ?>
        <div class="sidebar-group-label">Insights</div>
        <?php navItem('modules/reports/index.php', 'bi-file-earmark-bar-graph', 'Reports', $current); ?>
        <div class="sidebar-group-label">HR Self-Service</div>
        <?php navItem('modules/employee/attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <?php navItem('modules/employee/requests.php', 'bi-journal-text', 'My Requests', $current); ?>
        <?php navItem('modules/employee/payslips.php', 'bi-cash', 'My Payslips', $current); ?>
        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/profile/index.php', 'bi-person', 'My Profile', $current); ?>

    <?php elseif ($role == ROLE_FINANCE_STAFF): ?>
        <div class="sidebar-group-label">Overview</div>
        <?php navItem('modules/finance/dashboard.php', 'bi-speedometer2', 'Finance Dashboard', $current); ?>
        <div class="sidebar-group-label">Finance</div>
        <?php navItem('modules/finance/index.php', 'bi-cash-coin', 'Transactions', $current); ?>
        <?php navItem('modules/finance/payables.php', 'bi-receipt-cutoff', 'Supplier Payables', $current); ?>
        <?php navItem('modules/finance/payroll.php', 'bi-cash-stack', 'Payroll Payments', $current); ?>
        <?php navItem('modules/finance/categories.php', 'bi-tags', 'Categories', $current); ?>
        <?php navItem('modules/finance/report.php', 'bi-file-earmark-bar-graph', 'Financial Report', $current); ?>
        <div class="sidebar-group-label">HR Self-Service</div>
        <?php navItem('modules/employee/attendance.php', 'bi-clock', 'My Attendance', $current); ?>
        <?php navItem('modules/employee/requests.php', 'bi-journal-text', 'My Requests', $current); ?>
        <?php navItem('modules/employee/payslips.php', 'bi-cash', 'My Payslips', $current); ?>
        <div class="sidebar-group-label">Other</div>
        <?php navItem('modules/profile/index.php', 'bi-person', 'My Profile', $current); ?>
    <?php endif; ?>

  </div>
  <?php
    // The Cashier / Inventory Staff roles work almost entirely inside the
    // POS or Inventory module respectively, so the footer label reflects
    // the module they're in ("POS account" / "Inventory account") instead
    // of their formal role name, matching the other roles' "<Role> account" pattern.
    $sidebarFooterLabel = currentRoleName();
    if ($role === ROLE_CASHIER) $sidebarFooterLabel = 'POS';
    if ($role === ROLE_INVENTORY_STAFF) $sidebarFooterLabel = 'Inventory';
    if ($role === ROLE_FINANCE_STAFF) $sidebarFooterLabel = 'Finance';
  ?>
  <div class="sidebar-footer">
    <i class="bi bi-person-badge-fill"></i>
    <span><?= htmlspecialchars($sidebarFooterLabel) ?> account</span>
  </div>
</aside>
<main class="app-content">
  <?php if (function_exists('renderFlash')): ?>
    <?= renderFlash() ?>
  <?php elseif (function_exists('getFlash')): $f = getFlash(); if ($f): ?>
    <div class="alert alert-<?= htmlspecialchars($f['type']) ?> alert-dismissible fade show m-3" role="alert">
      <?= htmlspecialchars($f['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; endif; ?>