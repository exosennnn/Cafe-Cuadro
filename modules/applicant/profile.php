<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_APPLICANT]);

$userId = $_SESSION['user_id'];
$user = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$user->execute([$userId]);
$user = $user->fetch();

$applicant = $pdo->prepare("SELECT * FROM applicants WHERE user_id = ?");
$applicant->execute([$userId]);
$applicant = $applicant->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
      $firstName = trim($_POST['first_name'] ?? '');
      $lastName  = trim($_POST['last_name'] ?? '');
      $phone     = trim($_POST['phone'] ?? '');
      $address   = trim($_POST['address'] ?? '');
      $birthdate = $_POST['birthdate'] ?: null;
      $gender    = $_POST['gender'] ?: null;
      $education = trim($_POST['education_summary'] ?? '');
      $experience= trim($_POST['experience_summary'] ?? '');

      $errors = [];

      $phoneDigits = preg_replace('/\D+/', '', $phone);
      if ($phone !== '' && !preg_match('/^09\d{9}$/', $phoneDigits)) {
        $errors[] = 'Please enter a valid 11-digit Philippine phone number starting with 09.';
      }

      if ($birthdate) {
        $dob = DateTime::createFromFormat('Y-m-d', $birthdate);
        if (!$dob) {
          $errors[] = 'Please enter a valid birthdate.';
        } else {
          $today = new DateTime();
          $age = $today->diff($dob)->y;
          if ($age < 18) {
            $errors[] = 'You must be at least 18 years old.';
          } elseif ($age > 60) {
            $errors[] = 'Age must be 60 years or younger.';
          }
        }
      }

      if (empty($errors)) {
        $pdo->prepare("UPDATE users SET first_name=?, last_name=?, phone=? WHERE user_id=?")
          ->execute([$firstName, $lastName, $phoneDigits ?: null, $userId]);

        $pdo->prepare("UPDATE applicants SET address=?, birthdate=?, gender=?, education_summary=?, experience_summary=? WHERE user_id=?")
          ->execute([$address, $birthdate, $gender, $education, $experience, $userId]);

        $_SESSION['full_name'] = $firstName . ' ' . $lastName;
        setFlash('success', 'Profile updated successfully.');
        logAudit($pdo, $userId, 'UPDATE_PROFILE', 'Applicant', '');
      } else {
        foreach ($errors as $err) setFlash('error', $err);
      }

      redirect('modules/applicant/profile.php');
    }

    if ($action === 'upload_photo') {
        if (!empty($_FILES['photo']['name'])) {
            $result = handleFileUpload($_FILES['photo'], PROFILE_UPLOAD_DIR, ALLOWED_IMAGE_TYPES, MAX_PHOTO_SIZE, 'photo_' . $userId);
            if ($result['ok']) {
                $pdo->prepare("UPDATE users SET profile_photo=? WHERE user_id=?")
                    ->execute([$result['filename'], $userId]);
                $_SESSION['profile_photo'] = $result['filename'];
                setFlash('success', 'Profile photo uploaded successfully.');
                logAudit($pdo, $userId, 'UPLOAD_PHOTO', 'Applicant', $result['filename']);
            } else {
                setFlash('error', $result['error']);
            }
        } else {
            setFlash('error', 'Please choose a photo to upload.');
        }
        redirect('modules/applicant/profile.php');
    }

    if ($action === 'upload_resume') {
        if (!empty($_FILES['resume']['name'])) {
            $result = handleFileUpload($_FILES['resume'], RESUME_UPLOAD_DIR, ALLOWED_RESUME_TYPES, MAX_RESUME_SIZE, 'resume_' . $userId);
            if ($result['ok']) {
                $pdo->prepare("UPDATE applicants SET resume_path=?, resume_original_name=? WHERE user_id=?")
                    ->execute([$result['filename'], $_FILES['resume']['name'], $userId]);
                setFlash('success', 'Resume uploaded successfully.');
                logAudit($pdo, $userId, 'UPLOAD_RESUME', 'Applicant', $result['filename']);
            } else {
                setFlash('error', $result['error']);
            }
        } else {
            setFlash('error', 'Please choose a file to upload.');
        }
        redirect('modules/applicant/profile.php');
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../../includes/header.php';
?>
<h4 class="mb-4">My Profile</h4>

<div class="row g-4">
  <div class="col-md-7">
    <div class="card">
      <div class="card-body">
        <h6 class="card-title mb-3">Personal Information</h6>
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="update_profile">
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">First Name</label>
              <input type="text" name="first_name" class="form-control" value="<?= e($user['first_name']) ?>" required>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Last Name</label>
              <input type="text" name="last_name" class="form-control" value="<?= e($user['last_name']) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Email (cannot be changed)</label>
            <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Phone</label>
            <input type="tel" name="phone" class="form-control" pattern="09\d{9}" maxlength="11" placeholder="09XXXXXXXXX" value="<?= e($user['phone']) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Address</label>
            <input type="text" name="address" class="form-control" value="<?= e($applicant['address'] ?? '') ?>">
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Birthdate</label>
              <input type="date" name="birthdate" class="form-control" min="<?= date('Y-m-d', strtotime('-60 years')) ?>" max="<?= date('Y-m-d', strtotime('-18 years')) ?>" value="<?= e($applicant['birthdate'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">Gender</label>
              <select name="gender" class="form-select">
                <option value="">-- Select --</option>
                <?php foreach (['MALE','FEMALE','OTHER'] as $g): ?>
                  <option value="<?= $g ?>" <?= ($applicant['gender'] ?? '') === $g ? 'selected' : '' ?>><?= ucfirst(strtolower($g)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Education Summary</label>
            <textarea name="education_summary" class="form-control" rows="2"><?= e($applicant['education_summary'] ?? '') ?></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label">Experience Summary</label>
            <textarea name="experience_summary" class="form-control" rows="3"><?= e($applicant['experience_summary'] ?? '') ?></textarea>
          </div>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle"></i> Save Changes</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-body text-center">
        <h6 class="card-title mb-3">Profile Photo</h6>
        <div class="mb-3"><?= renderAvatar($user['profile_photo'] ?? null, $user['first_name'], $user['last_name'], 110) ?></div>
        <?php if (empty($user['profile_photo'])): ?>
          <p class="text-muted small">No photo uploaded yet. A photo (2x2-style) is required before you can apply to a job.</p>
        <?php endif; ?>
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

    <div class="card">
      <div class="card-body">
        <h6 class="card-title mb-3">Resume</h6>
        <?php if (!empty($applicant['resume_path'])): ?>
          <p><i class="bi bi-file-earmark-pdf text-danger"></i> Current file: <strong><?= e($applicant['resume_original_name']) ?></strong></p>
          <a href="<?= BASE_URL ?>uploads/resumes/<?= e($applicant['resume_path']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-eye"></i> View Current Resume</a>
        <?php else: ?>
          <p class="text-muted">No resume uploaded yet.</p>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="action" value="upload_resume">
          <div class="mb-3">
            <label class="form-label">Upload New Resume (PDF or DOCX, max 5MB)</label>
            <input type="file" name="resume" class="form-control" accept=".pdf,.docx" required>
          </div>
          <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-upload"></i> Upload Resume</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
