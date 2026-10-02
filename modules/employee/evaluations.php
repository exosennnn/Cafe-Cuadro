<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id=?");
$emp->execute([(int)$userId]);
$emp = $emp->fetch();
$employeeId = $emp ? (int)$emp['employee_id'] : 0;

if ($employeeId > 0) {
  $evaluations = $pdo->prepare("SELECT pe.evaluation_period, pe.quality_score, pe.productivity_score,
    pe.attendance_score, pe.teamwork_score, pe.overall_score, pe.comments, pe.created_at,
    u.first_name AS evaluator_first_name, u.last_name AS evaluator_last_name
    FROM performance_evaluations pe
    LEFT JOIN users u ON pe.evaluator_id=u.user_id
    WHERE pe.employee_id=? ORDER BY pe.created_at DESC");
  $evaluations->execute([$employeeId]);
    $evaluations = $evaluations->fetchAll();
} else {
    $evaluations = [];
}
$formatScore = static fn($value): string => number_format((float)($value ?? 0), 2, '.', '');

$pageTitle = 'My Performance Evaluation';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-star-fill"></i></div>
  <div>
    <h4>My Performance Evaluation</h4>
  </div>
</div>

<?php foreach ($evaluations as $ev): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between">
        <h6 class="fw-bold"><?= e((string)($ev['evaluation_period'] ?? '')) ?></h6>
        <span class="badge bg-primary fs-6">Overall: <?= e($formatScore($ev['overall_score'] ?? null)) ?></span>
      </div>
      <?php $evaluatorName = trim((string)($ev['evaluator_first_name'] ?? '') . ' ' . (string)($ev['evaluator_last_name'] ?? '')); ?>
      <p class="small text-muted mb-2">Evaluated by <?= e($evaluatorName !== '' ? $evaluatorName : 'Unknown evaluator') ?> on <?= e(fdate($ev['created_at'] ?? null)) ?></p>
      <div class="row text-center g-2 mb-2">
        <div class="col"><div class="border rounded p-2"><small class="text-muted">Quality</small><h6><?= e($formatScore($ev['quality_score'] ?? null)) ?></h6></div></div>
        <div class="col"><div class="border rounded p-2"><small class="text-muted">Productivity</small><h6><?= e($formatScore($ev['productivity_score'] ?? null)) ?></h6></div></div>
        <div class="col"><div class="border rounded p-2"><small class="text-muted">Attendance</small><h6><?= e($formatScore($ev['attendance_score'] ?? null)) ?></h6></div></div>
        <div class="col"><div class="border rounded p-2"><small class="text-muted">Teamwork</small><h6><?= e($formatScore($ev['teamwork_score'] ?? null)) ?></h6></div></div>
      </div>
      <?php if (!empty($ev['comments'])): ?><p><strong>Comments:</strong> <?= nl2br(e((string)$ev['comments'])) ?></p><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($evaluations)): ?><div class="alert alert-info">No performance evaluations recorded yet.</div><?php endif; ?>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
