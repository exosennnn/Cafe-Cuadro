<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

$userId = $_SESSION['user_id'];

// Owner may manage the system's operational and management accounts, but not Owner accounts.
$MANAGEABLE_ROLES = [ROLE_HR_MANAGER, ROLE_HR_STAFF, ROLE_EMPLOYEE_MANAGER, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF];
$CREATABLE_ROLES = [ROLE_HR_MANAGER, ROLE_HR_STAFF, ROLE_EMPLOYEE_MANAGER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $roleId = (int)$_POST['role_id'];
        $email = trim($_POST['email']);
        $first = trim($_POST['first_name']);
        $last = trim($_POST['last_name']);
        $phone = trim($_POST['phone']);
        $password = $_POST['password'];
        $phoneDigits = preg_replace('/\D+/', '', $phone);

        if (!in_array($roleId, $CREATABLE_ROLES, true)) {
            $message = in_array($roleId, [ROLE_CASHIER], true)
                ? 'Cashier accounts must be provisioned through recruitment and hiring.'
                : 'The Owner account may only create HR Manager, HR Staff, Employee Manager, Inventory Staff, or Finance Staff accounts directly.';
            setFlash('error', $message);
            redirect('modules/owner/users.php');
        }

        $exists = $pdo->prepare("SELECT user_id FROM users WHERE email=?");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            setFlash('error', 'A user with that email already exists.');
        } elseif ($phone !== '' && !preg_match('/^09\d{9}$/', $phoneDigits)) {
            setFlash('error', 'Please enter a valid 11-digit Philippine mobile number starting with 09.');
        } else {
            $phone = $phoneDigits;
            $pdo->beginTransaction();
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (role_id, email, password_hash, first_name, last_name, phone, status) VALUES (?,?,?,?,?,?,'ACTIVE')")
                ->execute([$roleId, $email, $hash, $first, $last, $phone]);
            $newUserId = $pdo->lastInsertId();

            $code = generateEmployeeCode($pdo);
            $deptId = $_POST['department_id'] ?: null;
            $branchId = $_POST['branch_id'] ?: null;
            
            if (in_array($roleId, [ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF], true) && !$branchId) {
                setFlash('error', 'Inventory Staff and Finance Staff must be assigned to a branch.');
                redirect('modules/owner/users.php');
            }
            
            $position = trim($_POST['position'] ?? '');
            $pdo->prepare("INSERT INTO employees (user_id, employee_code, department_id, branch_id, position, date_hired, employment_status) VALUES (?,?,?,?,?,CURDATE(),'ACTIVE')")
                ->execute([$newUserId, $code, $deptId, $branchId, $position]);

            $pdo->commit();
            setFlash('success', 'Account created successfully.');
            logAudit($pdo, $userId, 'OWNER_CREATE_USER', 'User Management', "email=$email role_id=$roleId");
        }
    } elseif ($action === 'update') {
        $targetId = (int)$_POST['user_id'];
        $target = $pdo->prepare("SELECT * FROM users WHERE user_id=?");
        $target->execute([$targetId]);
        $target = $target->fetch();

        if (!$target || !in_array((int)$target['role_id'], $MANAGEABLE_ROLES, true)) {
            setFlash('error', 'You may only edit HR Manager, HR Staff, Employee Manager, Cashier, Inventory Staff, or Finance Staff accounts.');
        } else {
            $first = trim($_POST['first_name']);
            $last = trim($_POST['last_name']);
            $phone = preg_replace('/\D+/', '', trim($_POST['phone'] ?? ''));
            $deptId = $_POST['department_id'] ?: null;
            $branchId = $_POST['branch_id'] ?: null;
            $position = trim($_POST['position'] ?? '');

            if (in_array((int)$target['role_id'], [ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF], true) && !$branchId) {
                setFlash('error', 'Inventory Staff and Finance Staff must be assigned to a branch.');
                redirect('modules/owner/users.php');
            }

            if ($phone !== '' && !preg_match('/^09\d{9}$/', $phone)) {
                setFlash('error', 'Please enter a valid 11-digit Philippine mobile number starting with 09.');
            } else {
                $pdo->prepare("UPDATE users SET first_name=?, last_name=?, phone=? WHERE user_id=?")
                    ->execute([$first, $last, $phone, $targetId]);
                $pdo->prepare("UPDATE employees SET department_id=?, branch_id=?, position=? WHERE user_id=?")
                    ->execute([$deptId, $branchId, $position, $targetId]);
                setFlash('success', 'Account updated successfully.');
                logAudit($pdo, $userId, 'OWNER_UPDATE_USER', 'User Management', "user_id=$targetId");
            }
        }
    } elseif ($action === 'update_status') {
        $targetId = (int)$_POST['user_id'];
        $status = $_POST['status'];
        $target = $pdo->prepare("SELECT role_id FROM users WHERE user_id=?");
        $target->execute([$targetId]);
        $targetRole = (int)($target->fetch()['role_id'] ?? 0);

        if ($targetId === (int)$userId || !in_array($targetRole, $MANAGEABLE_ROLES, true)) {
          setFlash('error', 'You may only activate/deactivate HR Manager, HR Staff, Employee Manager, Cashier, Inventory Staff, or Finance Staff accounts.');
        } else {
            $pdo->prepare("UPDATE users SET status=? WHERE user_id=?")->execute([$status, $targetId]);
            setFlash('success', 'Account status updated.');
            logAudit($pdo, $userId, 'OWNER_UPDATE_STATUS', 'User Management', "user_id=$targetId status=$status");
        }
    } elseif ($action === 'reset_password') {
        $targetId = (int)$_POST['user_id'];
        $target = $pdo->prepare("SELECT role_id FROM users WHERE user_id=?");
        $target->execute([$targetId]);
        $targetRole = (int)($target->fetch()['role_id'] ?? 0);

        if (!in_array($targetRole, $MANAGEABLE_ROLES, true)) {
            setFlash('error', 'You may only reset passwords for HR Manager, HR Staff, Employee Manager, Cashier, Inventory Staff, or Finance Staff accounts.');
        } else {
            $newPass = $_POST['new_password'];
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?")->execute([$hash, $targetId]);
            setFlash('success', 'Password reset successfully.');
            logAudit($pdo, $userId, 'OWNER_RESET_PASSWORD', 'User Management', "user_id=$targetId");
        }
    }
    redirect('modules/owner/users.php');
}

$search = trim($_GET['search'] ?? '');
$roleFilter = $_GET['role'] ?? '';
$roleParamPlaceholders = implode(',', array_fill(0, count($MANAGEABLE_ROLES), '?'));
$where = "u.role_id IN ($roleParamPlaceholders)";
$params = $MANAGEABLE_ROLES;
if ($search !== '') { $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)"; $params[]="%$search%";$params[]="%$search%";$params[]="%$search%"; }
if ($roleFilter !== '' && in_array((int)$roleFilter, $MANAGEABLE_ROLES, true)) { $where .= " AND u.role_id=?"; $params[] = (int)$roleFilter; }

$total = $pdo->prepare("SELECT COUNT(*) AS c FROM users u WHERE $where");
$total->execute($params);
$total = $total->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 10);

$stmt = $pdo->prepare("SELECT u.*, r.role_name, e.department_id, e.branch_id, e.position FROM users u
    JOIN roles r ON u.role_id=r.role_id
    LEFT JOIN employees e ON e.user_id=u.user_id
    WHERE $where ORDER BY u.user_id DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = $pdo->query("SELECT * FROM roles WHERE role_id IN (" . implode(',', $MANAGEABLE_ROLES) . ") ORDER BY role_id")->fetchAll();
$creatableRoles = $pdo->query("SELECT * FROM roles WHERE role_id IN (" . implode(',', $CREATABLE_ROLES) . ") ORDER BY role_id")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();
$branches = $pdo->query("SELECT * FROM branches WHERE status='ACTIVE' ORDER BY branch_name")->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h4 class="mb-1">Manage Users</h4>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal"><i class="bi bi-person-plus"></i> Create Account</button>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4"><input type="text" name="search" class="form-control" placeholder="Search name/email..." value="<?= e($search) ?>"></div>
  <div class="col-md-3">
    <select name="role" class="form-select">
      <option value="">All Manageable Roles</option>
      <?php foreach ($roles as $r): ?><option value="<?= $r['role_id'] ?>" <?= $roleFilter==$r['role_id']?'selected':'' ?>><?= e(str_replace('_',' ',$r['role_name'])) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table id="usersTable" class="table table-hover align-middle">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <?php $deptName = '-'; foreach ($departments as $d) { if ($d['department_id'] == $u['department_id']) { $deptName = $d['department_name']; break; } } ?>
        <tr>
          <td><?= e($u['first_name'].' '.$u['last_name']) ?></td>
          <td><?= e($u['email']) ?></td>
          <td><span class="badge bg-info"><?= e(str_replace('_',' ',$u['role_name'])) ?></span></td>
          <td><?= e($deptName) ?></td>
          <td><span class="badge <?= $u['status']=='ACTIVE'?'bg-success':'bg-secondary' ?>"><?= e($u['status']) ?></span></td>
          <td><?= $u['last_login'] ? fdate($u['last_login'], 'M d, Y g:i A') : 'Never' ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editModal<?= $u['user_id'] ?>"><i class="bi bi-pencil"></i></button>
            <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#pwModal<?= $u['user_id'] ?>"><i class="bi bi-key"></i></button>
            <form method="POST" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
              <input type="hidden" name="action" value="update_status">
              <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
              <input type="hidden" name="status" value="<?= $u['status']=='ACTIVE'?'SUSPENDED':'ACTIVE' ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-<?= $u['status']=='ACTIVE'?'lock':'unlock' ?>"></i></button>
            </form>
          </td>
        </tr>

      <?php endforeach; ?>
      <?php if (empty($users)): ?><tr><td colspan="7" class="text-center text-muted">No accounts found.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>

<?php foreach ($users as $u): ?>
  <div class="modal fade" id="editModal<?= $u['user_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
      <div class="modal-header"><h6 class="modal-title">Edit Account</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" value="<?= e($u['first_name']) ?>" required></div>
          <div class="col-md-6 mb-3"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($u['last_name']) ?>" required></div>
        </div>
        <div class="mb-3"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control" pattern="09\d{9}" maxlength="11" value="<?= e($u['phone']) ?>" placeholder="09XXXXXXXXX"></div>
        <div class="mb-3"><label class="form-label">Department</label>
          <select name="department_id" class="form-select">
            <option value="">-- N/A --</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>" <?= $u['department_id']==$d['department_id']?'selected':'' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Branch</label>
          <select name="branch_id" class="form-select">
            <option value="">-- N/A --</option>
            <?php foreach ($branches as $b): ?><option value="<?= $b['branch_id'] ?>" <?= $u['branch_id']==$b['branch_id']?'selected':'' ?>><?= e($b['branch_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Position</label><input type="text" name="position" class="form-control" value="<?= e($u['position']) ?>"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Save Changes</button></div>
    </form>
  </div></div></div>

  <div class="modal fade" id="pwModal<?= $u['user_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
      <div class="modal-header"><h6 class="modal-title">Reset Password</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><input type="password" name="new_password" class="form-control" minlength="8" required placeholder="New password (min 8 chars)"></div>
      <div class="modal-footer"><button class="btn btn-primary">Reset</button></div>
    </form>
  </div></div></div>
<?php endforeach; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  // Search and pagination are already handled server-side (GET params),
  // so DataTables here only adds client-side column sorting on the current page.
  $('#usersTable').DataTable({
    paging: false,
    searching: false,
    info: false,
    columnDefs: [{ orderable: false, targets: -1 }]
  });
});
</script>

<div class="modal fade" id="createModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="create">
    <div class="modal-header"><h5 class="modal-title">Create Account</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">First Name</label><input type="text" name="first_name" class="form-control" required></div>
        <div class="col-md-6 mb-3"><label class="form-label">Last Name</label><input type="text" name="last_name" class="form-control" required></div>
      </div>
      <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX"></div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Role</label>
          <select name="role_id" class="form-select" required>
            <?php foreach ($creatableRoles as $r): ?><option value="<?= $r['role_id'] ?>"><?= e(str_replace('_',' ',$r['role_name'])) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3"><label class="form-label">Department</label>
          <select name="department_id" class="form-select">
            <option value="">-- N/A --</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3"><label class="form-label">Branch</label>
          <select name="branch_id" class="form-select">
            <option value="">-- N/A --</option>
            <?php foreach ($branches as $b): ?><option value="<?= $b['branch_id'] ?>"><?= e($b['branch_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6 mb-3"><label class="form-label">Position</label><input type="text" name="position" class="form-control"></div>
      </div>
      <div class="mb-3"><label class="form-label">Temporary Password</label><input type="password" name="password" class="form-control" minlength="8" required></div>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Create Account</button></div>
  </form>
</div></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>