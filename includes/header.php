<?php
/**
 * UNIFIED HEADER
 * Used by every module (HRMS, POS, Inventory, Procurement, Finance,
 * Reports, Dashboard, Profile). Expects $pageTitle to be set by the
 * including page. Automatically includes the unified sidebar - pages no
 * longer need (and should not) require sidebar.php separately.
 */
if (!isset($pageTitle)) $pageTitle = 'Dashboard';
$esc = function_exists('e') ? 'e' : 'clean';

$isOwnerTheme           = currentRoleId() == ROLE_OWNER;
$isEmployeeTheme        = currentRoleId() == ROLE_EMPLOYEE;
$isEmployeeManagerTheme = currentRoleId() == ROLE_EMPLOYEE_MANAGER;
$isHrManagerTheme       = currentRoleId() == ROLE_HR_MANAGER;
$isHrStaffTheme         = currentRoleId() == ROLE_HR_STAFF;
$isCafeTheme            = in_array(currentRoleId(), [ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $esc($pageTitle) ?> | <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<!-- Unified Cafe Design System (Purr'Coffee Theme) -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/hrms/css/style.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/inv/css/style.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/unified.css?v=<?= ASSET_VER ?>">
<!-- Responsive layer (mobile / tablet / desktop) - keep LAST -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/responsive.css?v=<?= ASSET_VER ?>">
<!-- Roast theme: buttons, cards, tables, forms (loads after everything else) -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/shared/roast-app.css?v=<?= ASSET_VER ?>">
</head>
<body class="unified-theme <?= $isOwnerTheme ? 'owner-theme' : ($isEmployeeTheme ? 'employee-theme' : ($isEmployeeManagerTheme ? 'employee-manager-theme' : ($isHrManagerTheme ? 'hr-manager-theme' : ($isHrStaffTheme ? 'hr-staff-theme' : ($isCafeTheme ? 'cafe-theme' : ''))))) ?>">
<nav class="navbar navbar-expand-lg app-navbar">
  <div class="container-fluid">
    <button class="btn btn-link sidebar-toggle-btn me-2" id="sidebarToggle" title="Toggle sidebar" aria-label="Toggle sidebar">
      <i class="bi bi-list fs-4"></i>
    </button>
    <a class="navbar-brand fw-bold d-flex align-items-center text-decoration-none" href="<?= BASE_URL ?>dashboard">
      <span class="brand-text" style="color:#2a1810 !important; -webkit-text-fill-color:#2a1810 !important; background:none !important; font-family:'Fraunces',Georgia,serif; font-weight:600; font-size:1.4rem; letter-spacing:-0.01em; display:inline-block;"><?= APP_NAME ?></span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
      <?php if (isLoggedIn()):
        $notifTableExists = true;
        try {
            $notifications = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
            $notifications->execute([$_SESSION['user_id']]);
            $notifications = $notifications->fetchAll();
            $unreadCount = function_exists('unreadNotificationCount') ? unreadNotificationCount($pdo, (int)$_SESSION['user_id']) : 0;
        } catch (Exception $ex) {
            $notifications = [];
            $unreadCount = 0;
        }
        $nameParts  = preg_split('/\s+/', trim($_SESSION['full_name'] ?? ''), 2);
        $firstName  = $nameParts[0] ?? '';
        $lastName   = $nameParts[1] ?? '';
      ?>
        <span class="badge role-pill d-none d-sm-inline-block"><?= $esc(currentRoleName()) ?></span>
        <?php if (currentBranchName()): ?>
          <span class="badge branch-pill d-none d-md-inline-block"><i class="bi bi-geo-alt me-1"></i><?= $esc(currentBranchName()) ?></span>
        <?php endif; ?>
        <div class="dropdown">
          <a class="position-relative notif-bell-btn text-decoration-none" href="#" role="button" data-bs-toggle="dropdown" title="Notifications">
            <i class="bi bi-bell fs-5"></i>
            <?php if ($unreadCount > 0): ?>
              <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.65rem;"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
            <?php endif; ?>
          </a>
          <div class="dropdown-menu dropdown-menu-end p-0 shadow-lg" style="width:min(320px, 92vw); max-height:380px; overflow-y:auto; border-radius:18px;">
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
              <strong class="small">Notifications</strong>
            </div>
            <?php if (empty($notifications)): ?>
              <div class="px-3 py-4 text-muted small text-center"><i class="bi bi-bell-slash d-block fs-4 mb-1 opacity-50"></i>No notifications yet.</div>
            <?php else: foreach ($notifications as $n): ?>
              <div class="px-3 py-2 border-bottom small <?= empty($n['is_read']) ? 'bg-light' : '' ?>">
                <div class="fw-bold"><?= $esc($n['title']) ?></div>
                <div class="text-muted"><?= $esc($n['message']) ?></div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="dropdown">
          <a class="d-flex align-items-center text-decoration-none navbar-user-pill" href="#" role="button" data-bs-toggle="dropdown">
            <div class="user-avatar-wrap">
              <?= renderAvatar($_SESSION['profile_photo'] ?? null, $firstName, $lastName, 38) ?>
            </div>
            <div class="ms-2 text-start d-none d-md-block lh-sm pe-1">
              <div class="user-name fw-bold"><?= $esc($_SESSION['full_name'] ?? '') ?></div>
              <div class="user-sub text-muted small"><?= $esc($_SESSION['email'] ?? currentRoleName()) ?></div>
            </div>
            <i class="bi bi-three-dots-vertical ms-1 d-none d-md-inline text-muted opacity-75"></i>
          </a>
          <ul class="dropdown-menu dropdown-menu-end shadow-lg" style="border-radius:16px;">
            <li><a class="dropdown-item" href="<?= BASE_URL ?>profile"><i class="bi bi-person me-2"></i> My Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="bi bi-box-arrow-right me-2"></i> Logout</button></li>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Logout</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body text-center"><i class="bi bi-box-arrow-right fs-1 text-danger mb-3"></i><p>Are you sure you want to logout?</p></div>
      <div class="modal-footer justify-content-center">
        <a href="<?= BASE_URL ?>logout" class="btn btn-danger text-white">Yes, Logout</a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </div>
  </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="app-wrapper d-flex">
<?php require_once __DIR__ . '/sidebar.php'; ?>

