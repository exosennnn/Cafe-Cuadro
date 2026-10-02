<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contracts.php';
requireRole([ROLE_EMPLOYEE, ROLE_EMPLOYEE_MANAGER, ROLE_HR_STAFF, ROLE_HR_MANAGER, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

$userId = $_SESSION['user_id'];
$user = $pdo->prepare("SELECT user_id, first_name, last_name, email, phone, profile_photo FROM users WHERE user_id=?");
$user->execute([(int)$userId]);
$user = $user->fetch();
$userFound = (bool)$user;
$user = $user ?: [
  'user_id' => (int)$userId,
  'first_name' => '',
  'last_name' => '',
  'email' => '',
  'phone' => null,
  'profile_photo' => null,
];

$emp = $pdo->prepare("SELECT e.employee_id, e.employee_code, e.department_id, e.position, e.date_hired,
  e.employment_type, e.shift, e.employment_status, e.address, e.emergency_contact_name,
  e.emergency_contact_phone, d.department_name
  FROM employees e LEFT JOIN departments d ON e.department_id=d.department_id WHERE e.user_id=?");
$emp->execute([(int)$userId]);
$emp = $emp->fetch();
// The employment contract on record for this employee - its Contract No. is
// shown below alongside the rest of the employment details.
$contract = (is_array($emp) && !empty($emp['employee_id'])) ? getContractByEmployee($pdo, (int)$emp['employee_id']) : null;

// End date that applies to the employee's type (columns exist after
// database/migration_employment_terms.sql; skipped safely before it).
$empEndDate = null; $empEndLabel = '';
if (is_array($emp) && !empty($emp['employee_id'])) {
    try {
        $endRow = $pdo->prepare("SELECT probation_end_date, contract_end_date, employment_end_date FROM employees WHERE employee_id=?");
        $endRow->execute([(int)$emp['employee_id']]);
        $endRow = $endRow->fetch() ?: [];
        $endMap = ['PROBATIONARY' => ['probation_end_date', 'Probationary End Date'],
                   'CONTRACTUAL'  => ['contract_end_date',  'Contract End Date'],
                   'PART_TIME'    => ['employment_end_date', 'Employment End Date']];
        if (isset($endMap[$emp['employment_type']])) {
            [$col, $empEndLabel] = $endMap[$emp['employment_type']];
            $empEndDate = $endRow[$col] ?? null;
        }
    } catch (Exception $ex) { /* migration not run yet */ }
}

if (!$emp) {
    $emp = [
        'employee_code' => '-',
        'department_name' => null,
        'position' => null,
        'employment_type' => '-',
        'employment_status' => 'N/A',
        'date_hired' => null,
        'address' => null,
        'emergency_contact_name' => null,
        'emergency_contact_phone' => null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    if (($_POST['action'] ?? '') === 'upload_photo') {
      if (isset($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
            $result = handleFileUpload($_FILES['photo'], PROFILE_UPLOAD_DIR, ALLOWED_IMAGE_TYPES, MAX_PHOTO_SIZE, 'photo_' . $userId);
            if ($result['ok']) {
          try {
            $photoUpdate = $pdo->prepare("UPDATE users SET profile_photo=? WHERE user_id=?");
            $photoUpdate->execute([$result['filename'], (int)$userId]);
            if ($photoUpdate->rowCount() < 1) {
              throw new RuntimeException('Profile photo record was not updated.');
            }
            $_SESSION['profile_photo'] = $result['filename'];
            setFlash('success', 'Profile photo updated successfully.');
            logAudit($pdo, $userId, 'UPLOAD_PHOTO', 'Employee', $result['filename']);
          } catch (Throwable $exception) {
            $uploadedPath = rtrim(PROFILE_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $result['filename'];
            if (is_file($uploadedPath)) { @unlink($uploadedPath); }
            error_log('employee profile photo update error: ' . $exception->getMessage());
            setFlash('error', 'Profile photo could not be updated.');
          }
            } else {
                setFlash('error', $result['error']);
            }
        } else {
            setFlash('error', 'Please choose a photo to upload.');
        }
        redirect('modules/employee/profile.php');
    }

    $phoneInput = $_POST['phone'] ?? '';
    $addressInput = $_POST['address'] ?? '';
    $emergencyNameInput = $_POST['emergency_contact_name'] ?? '';
    $emergencyPhoneInput = $_POST['emergency_contact_phone'] ?? '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $address = is_string($addressInput) ? trim($addressInput) : '';
    $emergencyName = is_string($emergencyNameInput) ? trim($emergencyNameInput) : '';
    $emergencyPhone = is_string($emergencyPhoneInput) ? trim($emergencyPhoneInput) : '';

    $phoneDigits = preg_replace('/\D+/', '', $phone);
    $emergencyPhoneDigits = preg_replace('/\D+/', '', $emergencyPhone);
    $errors = [];

    if ($phone !== '' && !preg_match('/^09\d{9}$/', $phoneDigits)) {
        $errors[] = 'Please enter a valid 11-digit Philippine phone number starting with 09 for Phone.';
    }
    if ($emergencyPhone !== '' && !preg_match('/^09\d{9}$/', $emergencyPhoneDigits)) {
        $errors[] = 'Please enter a valid 11-digit Philippine phone number starting with 09 for Emergency Contact Phone.';
    }
    if ($emergencyName !== '' && !preg_match('/^[A-Za-z ]+$/', $emergencyName)) {
        $errors[] = 'Emergency contact name may only contain letters and spaces.';
    }
    if (strlen($address) > 255 || preg_match('/[\x00-\x1F\x7F]/', $address)) {
      $errors[] = 'Address must be 255 characters or fewer and cannot contain control characters.';
    }
    if (strlen($emergencyName) > 150 || preg_match('/[\x00-\x1F\x7F]/', $emergencyName)) {
      $errors[] = 'Emergency contact name must be 150 characters or fewer and cannot contain control characters.';
    }

    if (empty($errors)) {
      try {
        $pdo->beginTransaction();
        $userUpdate = $pdo->prepare("UPDATE users SET phone=? WHERE user_id=?");
        $userUpdate->execute([$phoneDigits ?: null, (int)$userId]);
        if (!$userFound) {
          throw new RuntimeException('User profile was not found.');
        }
        if ($emp) {
          $pdo->prepare("UPDATE employees SET address=?, emergency_contact_name=?, emergency_contact_phone=? WHERE user_id=?")
            ->execute([$address ?: null, $emergencyName ?: null, $emergencyPhoneDigits ?: null, (int)$userId]);
        }
        $pdo->commit();
        setFlash('success', 'Profile updated successfully.');
        logAudit($pdo, $userId, 'UPDATE_PROFILE', 'Employee', '');
      } catch (Throwable $exception) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('employee profile update error: ' . $exception->getMessage());
        setFlash('error', 'Profile could not be updated. No changes were made.');
      }
    } else {
        foreach ($errors as $error) {
            setFlash('error', $error);
        }
    }

    redirect('modules/employee/profile.php');
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-person-fill"></i></div>
  <div>
    <h4>My Profile</h4>
  </div>
</div>

<div class="row g-4">
  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-body text-center">
        <h6 class="card-title mb-3">Profile Photo</h6>
        <div class="mb-3"><?= renderAvatar($user['profile_photo'] ?? null, $user['first_name'], $user['last_name'], 110) ?></div>
        <form method="POST" enctype="multipart/form-data" class="text-start">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="upload_photo">
          <div class="mb-3">
            <label class="form-label">Upload Photo (JPG or PNG, max 2MB)</label>
            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png" required>
          </div>
          <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-upload"></i> Upload Photo</button>
        </form>
      </div>
    </div>

    <div class="card"><div class="card-body">
      <h6 class="card-title">Employment Details (read-only)</h6>
      <p><strong>Employee Code:</strong> <?= e($emp['employee_code']) ?></p>
      <?php if ((int)currentRoleId() !== ROLE_EMPLOYEE_MANAGER): ?>
      <p><strong>Contract No.:</strong>
        <?php if ($contract): ?>
          <?= e($contract['contract_no']) ?>
          <?php if (!empty($contract['application_id'])): ?>
            <a href="<?= BASE_URL ?>hr-staff/contract?application_id=<?= (int)$contract['application_id'] ?>"
               class="ms-1 small"><i class="bi bi-file-earmark-text"></i> View contract</a>
          <?php endif; ?>
          <?php if (!contractIsFullySigned($contract)): ?>
            <br><span class="badge bg-warning text-dark mt-1">Awaiting signature</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="text-muted">Not issued</span>
        <?php endif; ?>
      </p>
      <?php endif; ?>
      <p><strong>Department:</strong> <?= e($emp['department_name'] ?? '-') ?></p>
      <p><strong>Position:</strong> <?= e($emp['position'] ?? '-') ?></p>
      <p><strong>Employment Type:</strong> <?= e(EMPLOYMENT_TYPE_LABELS[$emp['employment_type']] ?? $emp['employment_type']) ?></p>
      <?php if (!empty($empEndDate)): ?>
      <p><strong><?= e($empEndLabel) ?>:</strong> <?= e(fdate($empEndDate)) ?></p>
      <?php endif; ?>
      <p><strong>Shift:</strong> <?= e(shiftLabel($emp['shift'] ?? null)) ?></p>
      <p><strong>Work Schedule:</strong> <?= (int)WORK_DAYS_PER_WEEK ?> days a week, <?= (int)WORK_HOURS_PER_DAY ?> hours a day</p>
      <p><strong>Payroll Cut-off:</strong> Every <?= (int)PAYROLL_CUTOFF_DAYS ?> days</p>
      <p><strong>Status:</strong> <span class="badge bg-success"><?= e($emp['employment_status']) ?></span></p>
      <p><strong>Date Hired:</strong> <?= e(fdate($emp['date_hired'] ?? null)) ?></p>
      <p class="small text-muted">To update employment details, please contact HR.</p>
    </div></div>
  </div>
  <div class="col-md-7">
    <div class="card"><div class="card-body">
      <h6 class="card-title">Personal Information</h6>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="update_profile">
        <div class="mb-3"><label class="form-label">Full Name</label><input type="text" class="form-control" value="<?= e($user['first_name'].' '.$user['last_name']) ?>" disabled></div>
        <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled></div>
        <div class="mb-3"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" value="<?= e($user['phone']) ?>"></div>
        <div class="mb-3"><label class="form-label">Address</label><input type="text" name="address" class="form-control" maxlength="255" value="<?= e($emp['address'] ?? '') ?>"></div>
        <div class="row">
          <div class="col-md-6 mb-3"><label class="form-label">Emergency Contact Name</label><input type="text" name="emergency_contact_name" class="form-control" maxlength="150" pattern="[A-Za-z ]+" title="Letters and spaces only" value="<?= e($emp['emergency_contact_name'] ?? '') ?>"></div>
          <div class="col-md-6 mb-3"><label class="form-label">Emergency Contact Phone</label><input type="tel" name="emergency_contact_phone" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" value="<?= e($emp['emergency_contact_phone'] ?? '') ?>"></div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
      </form>
    </div></div>
  </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
