<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

$userId = $_SESSION['user_id'];
// Employee Manager oversees ALL employees company-wide, regardless of
// department/position (Barista, Cook, Cashier, etc. all report to them).

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $empId = (int)$_POST['employee_id'];
    $period = trim($_POST['evaluation_period']);
    $q = (float)$_POST['quality_score'];
    $p = (float)$_POST['productivity_score'];
    $a = (float)$_POST['attendance_score'];
    $t = (float)$_POST['teamwork_score'];
    $overall = round(($q + $p + $a + $t) / 4, 2);
    $comments = trim($_POST['comments']);

    $belongsCheck = $pdo->prepare("SELECT e.employee_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=? AND u.role_id=? AND e.employment_status='ACTIVE'");
    $belongsCheck->execute([$empId, ROLE_EMPLOYEE]);
    if ($belongsCheck->fetch()) {
        $pdo->prepare("INSERT INTO performance_evaluations (employee_id, evaluator_id, evaluation_period, quality_score, productivity_score, attendance_score, teamwork_score, overall_score, comments) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$empId, $userId, $period, $q, $p, $a, $t, $overall, $comments]);
        setFlash('success', 'Performance evaluation submitted.');
        logAudit($pdo, $userId, 'EVALUATE_PERFORMANCE', 'Evaluation', "employee_id=$empId period=$period");
    } else {
        setFlash('error', 'You can only evaluate active employees.');
    }
    redirect('modules/employee_manager/evaluations.php');
}

$employees = $pdo->prepare("SELECT e.employee_id, u.first_name, u.last_name FROM employees e JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? AND e.employment_status='ACTIVE' ORDER BY u.first_name");
$employees->execute([ROLE_EMPLOYEE]);
$employees = $employees->fetchAll();

$evaluations = $pdo->prepare("SELECT pe.*, u.first_name, u.last_name FROM performance_evaluations pe
    JOIN employees e ON pe.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE u.role_id=? ORDER BY pe.created_at DESC LIMIT 50");
$evaluations->execute([ROLE_EMPLOYEE]);
$evaluations = $evaluations->fetchAll();

$pageTitle = 'Performance Evaluation';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-star-fill"></i></div>
    <div>
      <h4>Performance Evaluation</h4>
    </div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#evalModal"><i class="bi bi-plus-circle"></i> New Evaluation</button>
</div>

<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="evaluationsTable">
      <thead><tr><th>Employee</th><th>Period</th><th>Quality</th><th>Productivity</th><th>Attendance</th><th>Teamwork</th><th>Overall</th><th>Comments</th></tr></thead>
      <tbody>
      <?php foreach ($evaluations as $ev): ?>
        <tr>
          <td><?= e($ev['first_name'].' '.$ev['last_name']) ?></td>
          <td><?= e($ev['evaluation_period']) ?></td>
          <td><?= $ev['quality_score'] ?></td>
          <td><?= $ev['productivity_score'] ?></td>
          <td><?= $ev['attendance_score'] ?></td>
          <td><?= $ev['teamwork_score'] ?></td>
          <td><span class="badge bg-primary"><?= $ev['overall_score'] ?></span></td>
          <td><?= e($ev['comments']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($evaluations)): ?><tr><td colspan="8" class="text-center text-muted">No evaluations recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#evaluationsTable').DataTable({ order: [] });
});
</script>

<div class="modal fade" id="evalModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="modal-header"><h5 class="modal-title">New Performance Evaluation</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Employee</label>
          <select name="employee_id" class="form-select" required>
            <?php foreach ($employees as $e): ?><option value="<?= $e['employee_id'] ?>"><?= e($e['first_name'].' '.$e['last_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Evaluation Period</label><input type="text" name="evaluation_period" class="form-control" placeholder="e.g. Q3 2026" required></div>
        <div class="row">
          <div class="col-6 mb-3"><label class="form-label">Quality (0-100)</label><input type="number" step="0.01" min="0" max="100" name="quality_score" class="form-control" required></div>
          <div class="col-6 mb-3"><label class="form-label">Productivity (0-100)</label><input type="number" step="0.01" min="0" max="100" name="productivity_score" class="form-control" required></div>
          <div class="col-6 mb-3"><label class="form-label">Attendance (0-100)</label><input type="number" step="0.01" min="0" max="100" name="attendance_score" class="form-control" required></div>
          <div class="col-6 mb-3"><label class="form-label">Teamwork (0-100)</label><input type="number" step="0.01" min="0" max="100" name="teamwork_score" class="form-control" required></div>
        </div>
        <div class="mb-3"><label class="form-label">Comments</label><textarea name="comments" class="form-control" rows="3"></textarea></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Submit Evaluation</button></div>
    </form>
  </div></div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
