<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT department_id FROM employees WHERE user_id=?");
$emp->execute([$userId]);
$emp = $emp->fetch();

$departmentId = $emp['department_id'] ?? null;
$announcements = $pdo->prepare("SELECT a.*, u.first_name, u.last_name FROM announcements a JOIN users u ON a.posted_by=u.user_id
    WHERE (a.target_department IS NULL OR a.target_department = ?) AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
    ORDER BY a.posted_at DESC");
$announcements->execute([$departmentId]);
$announcements = $announcements->fetchAll();

$pageTitle = 'Announcements';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-megaphone-fill"></i></div>
  <div>
    <h4>Announcements</h4>
  </div>
</div>

<?php foreach ($announcements as $a): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="fw-bold"><?= e($a['title']) ?></h6>
      <p class="small text-muted mb-2">Posted by <?= e($a['first_name'].' '.$a['last_name']) ?> on <?= fdate($a['posted_at']) ?></p>
      <p><?= nl2br(e($a['content'])) ?></p>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($announcements)): ?><div class="alert alert-info">No announcements at this time.</div><?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
