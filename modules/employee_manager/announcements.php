<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
// Employee Manager oversees ALL employees/departments company-wide, so they
// see every announcement (not just ones targeted at their own department).
$today = date('Y-m-d');

$announcements = $pdo->prepare("SELECT a.announcement_id, a.title, a.content, a.posted_at, a.expires_at,
  a.target_department, u.first_name, u.last_name, d.department_name
  FROM announcements a LEFT JOIN users u ON a.posted_by=u.user_id LEFT JOIN departments d ON a.target_department=d.department_id
  WHERE (a.expires_at IS NULL OR a.expires_at >= ?)
    ORDER BY a.posted_at DESC");
$announcements->execute([$today]);
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
      <h6 class="fw-bold"><?= e((string)($a['title'] ?? '')) ?></h6>
      <?php $posterName = trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['last_name'] ?? '')); ?>
      <p class="small text-muted mb-2">Posted by <?= e($posterName !== '' ? $posterName : 'Unknown') ?> on <?= e(fdate($a['posted_at'] ?? null)) ?>
        &middot; Target: <?= e($a['department_name'] ?? 'All Departments') ?>
        <?php if (!empty($a['expires_at'])): ?> &middot; Expires <?= e(fdate($a['expires_at'])) ?><?php endif; ?></p>
      <p><?= nl2br(e((string)($a['content'] ?? ''))) ?></p>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($announcements)): ?><div class="alert alert-info">No announcements at this time.</div><?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
