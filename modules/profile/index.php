<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'My Profile';

$userId = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT u.*,
           CONCAT(u.first_name, ' ', u.last_name) AS full_name,
           u.phone AS contact_number,
           u.profile_photo AS photo,
           r.role_name AS role
    FROM users u
    LEFT JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    // Account no longer exists - force logout
    session_destroy();
    redirect('auth/login.php');
}

$allowedPhotoTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$maxPhotoSize = 2 * 1024 * 1024; // 2MB

// ==========================================================
// Update profile info (+ optional photo)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contactNumber = trim($_POST['contact_number'] ?? '');

    if ($fullName === '' || $email === '') {
        setFlash('danger', 'Full name and email are required.');
        redirect('modules/profile/index.php');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlash('danger', 'Please enter a valid email address.');
        redirect('modules/profile/index.php');
    }

    // Email must stay unique across accounts
    $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
    $check->execute([$email, $userId]);
    if ($check->fetchColumn() > 0) {
        setFlash('danger', 'That email is already used by another account.');
        redirect('modules/profile/index.php');
    }

    $newPhoto = $user['photo'];

    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['photo'];

        if ($file['size'] > $maxPhotoSize) {
            setFlash('danger', 'Photo must be 2MB or smaller.');
            redirect('modules/profile/index.php');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowedPhotoTypes[$mimeType])) {
            setFlash('danger', 'Only JPG, PNG, or WEBP images are allowed.');
            redirect('modules/profile/index.php');
        }

        if (!is_dir(PROFILE_UPLOAD_DIR)) {
            mkdir(PROFILE_UPLOAD_DIR, 0755, true);
        }

        $filename = 'user_' . $userId . '_' . time() . '.' . $allowedPhotoTypes[$mimeType];
        $destination = PROFILE_UPLOAD_DIR . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Clean up the old photo file once the new one is safely saved
            if (!empty($user['photo']) && file_exists(PROFILE_UPLOAD_DIR . $user['photo'])) {
                @unlink(PROFILE_UPLOAD_DIR . $user['photo']);
            }
            $newPhoto = $filename;
        } else {
            setFlash('danger', 'Photo upload failed. Please try again.');
            redirect('modules/profile/index.php');
        }
    }

    $nameParts = preg_split('/\s+/', $fullName, 2);
    $firstName = $nameParts[0] ?? '';
    $lastName  = $nameParts[1] ?? '';

    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, profile_photo = ? WHERE user_id = ?");
    $stmt->execute([$firstName, $lastName, $email, $contactNumber ?: null, $newPhoto, $userId]);

    $_SESSION['full_name'] = $fullName;
    $_SESSION['profile_photo'] = $newPhoto;

    logActivity($pdo, $userId, 'Profile', 'Updated profile information');
    setFlash('success', 'Profile updated successfully.');
    redirect('modules/profile/index.php');
}

// ==========================================================
// Change password
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        setFlash('danger', 'Please fill in all password fields.');
    } elseif (!password_verify($currentPassword, $user['password_hash'])) {
        setFlash('danger', 'Current password is incorrect.');
    } elseif (strlen($newPassword) < 8) {
        setFlash('danger', 'New password must be at least 8 characters long.');
    } elseif ($newPassword !== $confirmPassword) {
        setFlash('danger', 'New password and confirmation do not match.');
    } else {
        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$hashed, $userId]);
        logActivity($pdo, $userId, 'Profile', 'Changed password');
        setFlash('success', 'Password changed successfully.');
    }
    redirect('modules/profile/index.php');
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card-panel p-4 text-center">
            <div class="profile-avatar-wrap mb-3">
                <?php if (!empty($user['photo']) && file_exists(PROFILE_UPLOAD_DIR . $user['photo'])): ?>
                    <img src="<?= PROFILE_UPLOAD_URL . rawurlencode($user['photo']) ?>" alt="Profile photo" class="profile-avatar">
                <?php else: ?>
                    <i class="bi bi-person-circle profile-avatar-placeholder"></i>
                <?php endif; ?>
            </div>

            <h5 class="mb-0"><?= clean($user['full_name']) ?></h5>
            <p class="text-muted small mb-2">@<?= clean($user['username']) ?></p>
            <span class="badge bg-light text-dark border"><?= clean($user['role']) ?></span>

            <hr>

            <div class="text-start small text-muted">
                <p class="mb-1"><i class="bi bi-envelope me-2"></i><?= clean($user['email']) ?></p>
                <p class="mb-1"><i class="bi bi-telephone me-2"></i><?= $user['contact_number'] ? clean($user['contact_number']) : 'Not set' ?></p>
                <p class="mb-1"><i class="bi bi-clock-history me-2"></i>Last login: <?= $user['last_login'] ? formatDate($user['last_login'], 'M d, Y g:i A') : 'This is your first login' ?></p>
                <p class="mb-0"><i class="bi bi-calendar3 me-2"></i>Member since <?= formatDate($user['created_at']) ?></p>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card-panel p-4 mb-4">
            <h6 class="mb-3">Profile Information</h6>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">

                <div class="mb-3">
                    <label class="form-label">Profile Photo</label>
                    <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <div class="form-text">JPG, PNG, or WEBP. Max 2MB.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control" value="<?= clean($user['full_name']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?= clean($user['email']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Contact Number</label>
                    <input type="text" name="contact_number" class="form-control" value="<?= clean($user['contact_number']) ?>" placeholder="e.g. 0917 123 4567">
                </div>

                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" value="<?= clean($user['username']) ?>" disabled>
                    <div class="form-text">Username cannot be changed.</div>
                </div>

                <button type="submit" class="btn btn-brand">
                    <i class="bi bi-check-lg me-1"></i> Save Changes
                </button>
            </form>
        </div>

        <div class="card-panel p-4">
            <h6 class="mb-3">Change Password</h6>
            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">

                <div class="mb-3">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-control" minlength="8" required>
                    <div class="form-text">At least 8 characters.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-shield-lock me-1"></i> Change Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
