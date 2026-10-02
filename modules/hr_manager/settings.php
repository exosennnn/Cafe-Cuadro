<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];
$SETTING_KEYS = ['company_name', 'company_address'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
      $companyName = trim($_POST['company_name'] ?? '');
      $companyAddress = trim($_POST['company_address'] ?? '');
      $errors = [];

      if ($companyName === '' || strlen($companyName) > 150 || preg_match('/[\x00-\x1F\x7F]/', $companyName)) {
        $errors[] = 'Company name is required and must be 150 characters or fewer.';
      }
      if ($companyAddress === '' || strlen($companyAddress) > 255 || preg_match('/[\x00-\x1F\x7F]/', $companyAddress)) {
        $errors[] = 'Company address is required and must be 255 characters or fewer.';
      }
      if ($errors) {
        foreach ($errors as $error) { setFlash('error', $error); }
      } else {
        $settingsToSave = [
          'company_name' => $companyName,
          'company_address' => $companyAddress,
        ];
        try {
          $pdo->beginTransaction();
          $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
          foreach ($SETTING_KEYS as $key) {
            $stmt->execute([$key, $settingsToSave[$key], $settingsToSave[$key]]);
          }
          $pdo->commit();
          setFlash('success', 'System settings updated.');
          logAudit($pdo, $userId, 'UPDATE_SETTINGS', 'Settings', 'company settings');
        } catch (Throwable $exception) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          setFlash('error', 'Settings could not be saved. No changes were made.');
        }
        }
    } elseif ($action === 'change_password') {
      $current = $_POST['current_password'] ?? '';
      $new = $_POST['new_password'] ?? '';
      $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || !is_string($current) || $current === '' || !password_verify($current, $user['password_hash'])) {
            setFlash('error', 'Current password is incorrect.');
        } elseif (!is_string($new) || strlen($new) < 8 || strlen($new) > 255) {
          setFlash('error', 'New password must be between 8 and 255 characters.');
        } elseif (!is_string($confirm) || $new !== $confirm) {
            setFlash('error', 'New passwords do not match.');
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?")->execute([$hash, $userId]);
            setFlash('success', 'Your password has been changed.');
            logAudit($pdo, $userId, 'CHANGE_OWN_PASSWORD', 'Settings', '');
        }
    }
    redirect('modules/hr_manager/settings.php');
}

$settingPlaceholders = implode(',', array_fill(0, count($SETTING_KEYS), '?'));
$settingsStmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($settingPlaceholders)");
$settingsStmt->execute($SETTING_KEYS);
$settings = $settingsStmt->fetchAll();
$settingsMap = [];
foreach ($settings as $s) { $settingsMap[$s['setting_key']] = $s['setting_value']; }

$pageTitle = 'System Settings';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">System Settings</h4>

<div class="row g-4">
  <div class="col-md-7">
    <div class="card"><div class="card-body">
      <h6 class="card-title mb-3">Company / Application Settings</h6>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="save_settings">
        <div class="mb-3"><label class="form-label">Company Name</label><input type="text" name="company_name" class="form-control" value="<?= e($settingsMap['company_name'] ?? '') ?>"></div>
        <div class="mb-3"><label class="form-label">Company Address</label><input type="text" name="company_address" class="form-control" value="<?= e($settingsMap['company_address'] ?? '') ?>"></div>
        <hr>
        <p class="small text-muted mb-3"><i class="bi bi-info-circle"></i> Every employee is assigned one of three straight 8-hour shifts (6 days/week):</p>
        <ul class="small text-muted mb-3">
          <?php foreach (SHIFT_SCHEDULES as $key => $s): ?>
            <li><strong><?= e($s['label']) ?></strong>: <?= date('g:i A', strtotime($s['start'])) ?> - <?= date('g:i A', strtotime($s['end'])) ?></li>
          <?php endforeach; ?>
        </ul>
        <p class="small text-muted mb-1"><i class="bi bi-info-circle"></i> Clocking in even 1 minute past the employee's own shift start time is automatically marked <strong>LATE</strong> with a tiered deduction:</p>
        <ul class="small text-muted mb-3">
          <?php foreach (LATE_DEDUCTION_TIERS as $i => $tier): $prevMax = $i === 0 ? 0 : LATE_DEDUCTION_TIERS[$i-1]['max_minutes']; ?>
            <li><?= $tier['max_minutes'] === PHP_INT_MAX ? ($prevMax + 1) . '+ minutes late' : ($prevMax + 1) . '–' . $tier['max_minutes'] . ' minutes late' ?>: <?= fmoney($tier['amount']) ?></li>
          <?php endforeach; ?>
        </ul>
        <p class="small text-muted mb-3">Payroll is cut off and released every <?= (int)PAYROLL_CUTOFF_DAYS ?> days.</p>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Settings</button>
      </form>
    </div></div>
  </div>

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

    <div class="card mt-3"><div class="card-body">
      <h6 class="card-title">System Information</h6>
      <p class="small mb-0"><strong>Application:</strong> <?= APP_NAME ?></p>
    </div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
