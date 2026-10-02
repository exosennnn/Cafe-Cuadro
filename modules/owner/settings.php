<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_company_info') {
        $fields = ['company_name', 'company_address', 'company_contact', 'working_hours'];
        foreach ($fields as $key) {
            $value = trim($_POST[$key] ?? '');
            $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->execute([$key, $value, $value]);
        }
        setFlash('success', 'Company information updated.');
        logAudit($pdo, $userId, 'OWNER_UPDATE_SETTINGS', 'Settings', 'company info');
    } elseif ($action === 'save_leave_type') {
        $typeId = (int)($_POST['leave_type_id'] ?? 0);
        $days = (int)$_POST['default_days'];
        $desc = trim($_POST['description'] ?? '');
        if ($typeId > 0) {
            $pdo->prepare("UPDATE leave_types SET default_days=?, description=? WHERE leave_type_id=?")->execute([$days, $desc, $typeId]);
            setFlash('success', 'Leave type updated.');
            logAudit($pdo, $userId, 'OWNER_UPDATE_LEAVE_TYPE', 'Settings', "leave_type_id=$typeId");
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'];
        $new = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!password_verify($current, $user['password_hash'])) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 8) {
            setFlash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?")->execute([$hash, $userId]);
            setFlash('success', 'Your password has been changed.');
            logAudit($pdo, $userId, 'CHANGE_OWN_PASSWORD', 'Settings', '');
        }
    }
    redirect('modules/owner/settings.php');
}

$settings = $pdo->query("SELECT * FROM system_settings")->fetchAll();
$settingsMap = [];
foreach ($settings as $s) { $settingsMap[$s['setting_key']] = $s['setting_value']; }

$leaveTypes = $pdo->query("SELECT * FROM leave_types ORDER BY type_name")->fetchAll();

$pageTitle = 'System Settings';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">System Settings</h4>

<div class="row g-4">
  <div class="col-md-7">
    <div class="card mb-4"><div class="card-body">
      <h6 class="card-title mb-3">Company Information</h6>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_company_info">
        <div class="mb-3"><label class="form-label">Business Name</label><input type="text" name="company_name" class="form-control" value="<?= e($settingsMap['company_name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Company Address</label><input type="text" name="company_address" class="form-control" value="<?= e($settingsMap['company_address'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Contact Information</label><input type="text" name="company_contact" class="form-control" placeholder="Phone / Email" value="<?= e($settingsMap['company_contact'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Working Hours</label><input type="text" name="working_hours" class="form-control" placeholder="e.g. Mon-Fri, 8:00 AM - 5:00 PM" value="<?= e($settingsMap['working_hours'] ?? '') ?>"></div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Company Info</button>
      </form>
    </div></div>

    <div class="card"><div class="card-body">
      <h6 class="card-title mb-3">Leave Types</h6>
      <div class="table-responsive mb-3">
        <table id="leaveTypesTable" class="table table-sm align-middle">
          <thead><tr><th>Type</th><th>Default Days</th><th>Description</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($leaveTypes as $lt): ?>
            <tr>
              <td><?= e($lt['type_name']) ?></td>
              <td><?= (int)$lt['default_days'] ?></td>
              <td><?= e($lt['description']) ?></td>
              <td><button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#leaveTypeModal<?= $lt['leave_type_id'] ?>"><i class="bi bi-pencil"></i></button></td>
            </tr>
            <div class="modal fade" id="leaveTypeModal<?= $lt['leave_type_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
              <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="save_leave_type">
                <input type="hidden" name="leave_type_id" value="<?= $lt['leave_type_id'] ?>">
                <div class="modal-header"><h6 class="modal-title">Edit "<?= e($lt['type_name']) ?>"</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                  <div class="mb-3"><label class="form-label">Default Days</label><input type="number" name="default_days" min="0" class="form-control" value="<?= (int)$lt['default_days'] ?>"></div>
                  <div class="mb-3"><label class="form-label">Description</label><input type="text" name="description" class="form-control" value="<?= e($lt['description']) ?>"></div>
                </div>
                <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
              </form>
            </div></div></div>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div></div>
  </div>

  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
  <script>
  $(function () {
    $('#leaveTypesTable').DataTable({
      paging: false,
      order: [[0, 'asc']],
      columnDefs: [{ orderable: false, targets: -1 }],
      language: { search: '_INPUT_', searchPlaceholder: 'Search leave types...' }
    });
  });
  </script>

  <div class="col-md-5">
    <div class="card"><div class="card-body">
      <h6 class="card-title mb-3">Change My Password</h6>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="change_password">
        <div class="mb-3"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" minlength="8" required></div>
        <div class="mb-3"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" minlength="8" required></div>
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-key"></i> Change Password</button>
      </form>
    </div></div>

    
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>