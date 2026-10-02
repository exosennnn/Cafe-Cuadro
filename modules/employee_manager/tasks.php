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
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $empId = (int)$_POST['employee_id'];
        $title = trim($_POST['title']);
        $desc = trim($_POST['description']);
        $due = $_POST['due_date'] ?: null;
        $priority = $_POST['priority'];

        $belongsCheck = $pdo->prepare("SELECT e.employee_id FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=? AND u.role_id=? AND e.employment_status='ACTIVE'");
        $belongsCheck->execute([$empId, ROLE_EMPLOYEE]);
        if ($belongsCheck->fetch()) {
            $pdo->prepare("INSERT INTO tasks (employee_id, assigned_by, title, description, due_date, priority) VALUES (?,?,?,?,?,?)")
                ->execute([$empId, $userId, $title, $desc, $due, $priority]);
            setFlash('success', 'Task assigned successfully.');
            logAudit($pdo, $userId, 'ASSIGN_TASK', 'Tasks', "employee_id=$empId title=$title");
        } else {
            setFlash('error', 'You can only assign tasks to active employees.');
        }
    } elseif ($action === 'update_status') {
        $taskId = (int)$_POST['task_id'];
        $status = $_POST['status'];
        $existing = $pdo->prepare("SELECT t.status FROM tasks t JOIN employees e ON t.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id WHERE t.task_id=? AND u.role_id=?");
        $existing->execute([$taskId, ROLE_EMPLOYEE]);
        $existing = $existing->fetch();
        if ($existing && $existing['status'] !== 'COMPLETED') {
            $pdo->prepare("UPDATE tasks SET status=? WHERE task_id=?")->execute([$status, $taskId]);
            setFlash('success', 'Task status updated.');
        } else {
            setFlash('error', 'This task is already completed or not found.');
        }
    }
    redirect('modules/employee_manager/tasks.php');
}

$employees = $pdo->prepare("SELECT e.employee_id, u.first_name, u.last_name FROM employees e JOIN users u ON e.user_id=u.user_id WHERE u.role_id=? AND e.employment_status='ACTIVE' ORDER BY u.first_name");
$employees->execute([ROLE_EMPLOYEE]);
$employees = $employees->fetchAll();

$tasks = $pdo->prepare("SELECT t.*, u.first_name, u.last_name FROM tasks t JOIN employees e ON t.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE u.role_id=? ORDER BY t.created_at DESC");
$tasks->execute([ROLE_EMPLOYEE]);
$tasks = $tasks->fetchAll();

$pageTitle = 'Assign Tasks';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-list-task"></i></div>
    <div>
      <h4>Assign Tasks</h4>
    </div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#taskModal"><i class="bi bi-plus-circle"></i> Assign New Task</button>
</div>

<div class="card border-0 shadow-sm rounded-3">
  <div class="card-body table-responsive">
    <?php $statusColors = ['PENDING'=>'secondary','IN_PROGRESS'=>'info','COMPLETED'=>'success','OVERDUE'=>'danger']; ?>
    <table class="table table-hover align-middle" id="tasksTable">
      <thead><tr><th>Employee</th><th>Task</th><th>Due Date</th><th>Priority</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $t): ?>
        <tr>
          <td><?= e($t['first_name'].' '.$t['last_name']) ?></td>
          <td><?= e($t['title']) ?><br><small class="text-muted"><?= e($t['description']) ?></small></td>
          <td><?= fdate($t['due_date']) ?></td>
          <td><span class="badge bg-<?= $t['priority']=='HIGH'?'danger':($t['priority']=='MEDIUM'?'warning':'secondary') ?>"><?= e($t['priority']) ?></span></td>
          <td>
            <?php $sColor = $statusColors[$t['status']] ?? 'secondary'; ?>
            <span class="badge bg-<?= $sColor ?> mb-1"><?= e(str_replace('_',' ', $t['status'])) ?></span>
            <?php if ($t['status'] === 'COMPLETED'): ?>
              <div class="small text-muted"><i class="bi bi-check-circle"></i> Done</div>
            <?php else: ?>
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="task_id" value="<?= $t['task_id'] ?>">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php foreach (['PENDING','IN_PROGRESS','COMPLETED','OVERDUE'] as $s): ?>
                    <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($tasks)): ?><tr><td colspan="5" class="text-center text-muted">No tasks assigned yet.</td></tr><?php endif; ?>
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
  $('#tasksTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>

<div class="modal fade" id="taskModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">Assign New Task</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Employee</label>
          <select name="employee_id" class="form-select" required>
            <?php foreach ($employees as $e): ?><option value="<?= $e['employee_id'] ?>"><?= e($e['first_name'].' '.$e['last_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Task Title</label><input type="text" name="title" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"></textarea></div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">Due Date</label><input type="date" name="due_date" class="form-control" min="<?= APP_LAUNCH_DATE ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Priority</label>
            <select name="priority" class="form-select"><option value="LOW">Low</option><option value="MEDIUM" selected>Medium</option><option value="HIGH">High</option></select>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Assign Task</button></div>
    </form>
  </div></div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
