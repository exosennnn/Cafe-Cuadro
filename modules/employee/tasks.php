<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_EMPLOYEE]);

$userId = $_SESSION['user_id'];
$emp = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id=?");
$emp->execute([(int)$userId]);
$emp = $emp->fetch();
$employeeId = $emp ? (int)$emp['employee_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $employeeId > 0) {
    csrfVerify();
  $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
  $status = $_POST['status'] ?? '';
  $allowedStatuses = ['PENDING', 'IN_PROGRESS', 'COMPLETED'];
  if ($taskId === false || $taskId < 1) {
    setFlash('error', 'Invalid task.');
    redirect('modules/employee/tasks.php');
  }
  if (!in_array($status, $allowedStatuses, true)) {
    setFlash('error', 'Invalid task status.');
    redirect('modules/employee/tasks.php');
  }
    // Only allow updating own tasks, and only if not already completed
    $check = $pdo->prepare("SELECT task_id, status FROM tasks WHERE task_id=? AND employee_id=?");
  $check->execute([$taskId, (int)$employeeId]);
    $check = $check->fetch();
    if ($check && $check['status'] !== 'COMPLETED') {
    $update = $pdo->prepare("UPDATE tasks SET status=? WHERE task_id=? AND employee_id=? AND status <> 'COMPLETED'");
    $update->execute([$status, $taskId, (int)$employeeId]);
    if ($update->rowCount() === 1) {
      setFlash('success', 'Task status updated.');
    } else {
      setFlash('error', 'That task could not be updated.');
    }
    } elseif ($check) {
        setFlash('error', 'This task is already completed and can no longer be edited.');
  } else {
    setFlash('error', 'Task not found or not assigned to you.');
    }
    redirect('modules/employee/tasks.php');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrfVerify();
  setFlash('error', 'Your employee record could not be found.');
  redirect('modules/employee/tasks.php');
}

$tasks = $pdo->prepare("SELECT t.task_id, t.employee_id, t.assigned_by, t.title, t.description,
  t.due_date, t.priority, t.status, t.created_at,
  u.first_name AS assigner_first, u.last_name AS assigner_last
  FROM tasks t
  LEFT JOIN users u ON t.assigned_by = u.user_id
  WHERE t.employee_id = ?
    ORDER BY FIELD(t.status,'PENDING','IN_PROGRESS','OVERDUE','COMPLETED'), t.due_date ASC");
$stmt = $tasks;
$stmt->execute([(int)$employeeId]);
$tasks = $stmt->fetchAll();

$pageTitle = 'My Tasks';
require_once __DIR__ . '/../../includes/header.php';

$priorityColors = ['LOW' => 'secondary', 'MEDIUM' => 'warning', 'HIGH' => 'danger'];
$statusColors = ['PENDING' => 'secondary', 'IN_PROGRESS' => 'info', 'COMPLETED' => 'success', 'OVERDUE' => 'danger'];
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-list-task"></i></div>
  <div>
    <h4>My Tasks</h4>
  </div>
</div>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="myTasksTable">
      <thead><tr><th>Task</th><th>Assigned By</th><th>Due Date</th><th>Priority</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): ?>
        <tr>
          <td><strong><?= e((string)($t['title'] ?? '')) ?></strong><br><small class="text-muted"><?= e((string)($t['description'] ?? '')) ?></small></td>
          <?php $assignerName = trim((string)($t['assigner_first'] ?? '') . ' ' . (string)($t['assigner_last'] ?? '')); ?>
          <td><?= e($assignerName !== '' ? $assignerName : 'Unknown') ?></td>
          <td><?= e($t['due_date'] ? fdate($t['due_date']) : '-') ?></td>
          <td><span class="badge bg-<?= $priorityColors[$t['priority'] ?? ''] ?? 'secondary' ?>"><?= e((string)($t['priority'] ?? '')) ?></span></td>
          <td>
            <?php $taskStatus = (string)($t['status'] ?? ''); ?>
            <span class="badge bg-<?= $statusColors[$taskStatus] ?? 'secondary' ?> mb-1"><?= e(str_replace('_',' ', $taskStatus)) ?></span>
            <?php if ($taskStatus === 'COMPLETED'): ?>
              <div class="small text-muted"><i class="bi bi-check-circle"></i> Done</div>
            <?php else: ?>
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="task_id" value="<?= $t['task_id'] ?>">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php foreach (['PENDING','IN_PROGRESS','COMPLETED'] as $s): ?>
                    <option value="<?= $s ?>" <?= $taskStatus===$s?'selected':'' ?>><?= e(str_replace('_',' ', $s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($tasks)): ?><tr><td colspan="5" class="text-center text-muted">No tasks assigned to you yet.</td></tr><?php endif; ?>
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
  $('#myTasksTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
