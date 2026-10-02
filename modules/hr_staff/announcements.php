<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_STAFF]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
      $titleInput = $_POST['title'] ?? '';
      $contentInput = $_POST['content'] ?? '';
      $departmentInput = $_POST['target_department'] ?? '';
      $expiresInput = $_POST['expires_at'] ?? '';
      $title = is_string($titleInput) ? trim($titleInput) : '';
      $content = is_string($contentInput) ? trim($contentInput) : '';
      $departmentValue = is_string($departmentInput) ? trim($departmentInput) : '';
      $expiresValue = is_string($expiresInput) ? trim($expiresInput) : '';
      $errors = [];

      if ($title === '') {
        $errors[] = 'Title is required.';
      } elseif (strlen($title) > 150 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
        $errors[] = 'Title must be 150 characters or fewer and cannot contain control characters.';
      }
      if ($content === '') {
        $errors[] = 'Content is required.';
      } elseif (strlen($content) > 10000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)) {
        $errors[] = 'Content must be 10000 characters or fewer and cannot contain control characters.';
      }

      $dept = null;
      if ($departmentValue !== '') {
        if (!ctype_digit($departmentValue) || (int)$departmentValue < 1) {
          $errors[] = 'Please select a valid department.';
        } else {
          $dept = (int)$departmentValue;
          $departmentCheck = $pdo->prepare('SELECT department_id FROM departments WHERE department_id=?');
          $departmentCheck->execute([$dept]);
          if (!$departmentCheck->fetchColumn()) {
            $errors[] = 'The selected department does not exist.';
          }
        }
      }

      $expires = null;
      if ($expiresValue !== '') {
        $expiresDate = DateTime::createFromFormat('!Y-m-d', $expiresValue);
        $dateErrors = DateTime::getLastErrors();
        if (!$expiresDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $expiresDate->format('Y-m-d') !== $expiresValue) {
          $errors[] = 'Expires On must be a valid date in YYYY-MM-DD format.';
        } elseif ($expiresValue < APP_LAUNCH_DATE) {
          $errors[] = 'Expires On must be ' . APP_LAUNCH_DATE . ' or later.';
        } else {
          $expires = $expiresValue;
        }
      }

      if ($errors) {
        foreach ($errors as $error) {
          setFlash('error', $error);
        }
      } else {
        try {
          $insert = $pdo->prepare("INSERT INTO announcements (title, content, posted_by, target_department, expires_at) VALUES (?,?,?,?,?)");
          $insert->execute([$title, $content, $userId, $dept, $expires]);
          if ($insert->rowCount() !== 1) {
            throw new RuntimeException('Announcement was not saved.');
          }
          setFlash('success', 'Announcement posted.');
          logAudit($pdo, $userId, 'CREATE_ANNOUNCEMENT', 'Announcements', $title);
        } catch (Throwable $exception) {
          error_log('hr_staff announcements create error: ' . $exception->getMessage());
          setFlash('error', 'Announcement could not be posted. No changes were made.');
        }
      }
    }
    // HR Staff can only create and view announcements. Editing and deleting
    // is restricted to HR Manager and Owner.
    redirect('modules/hr_staff/announcements.php');
}

$announcements = $pdo->query("SELECT a.*, u.first_name, u.last_name, d.department_name
    FROM announcements a JOIN users u ON a.posted_by=u.user_id LEFT JOIN departments d ON a.target_department=d.department_id
    ORDER BY a.posted_at DESC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

$pageTitle = 'Announcements';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-megaphone-fill"></i></div>
    <div>
      <h4>Announcements</h4>
    </div>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#annModal"><i class="bi bi-plus-circle"></i> New Announcement</button>
</div>

<?php foreach ($announcements as $a): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="fw-bold"><?= e($a['title']) ?></h6>
      <p class="small text-muted mb-2">Posted by <?= e($a['first_name'].' '.$a['last_name']) ?> on <?= fdate($a['posted_at']) ?>
        &middot; Target: <?= e($a['department_name'] ?? 'All Departments') ?>
        <?php if ($a['expires_at']): ?> &middot; Expires <?= fdate($a['expires_at']) ?><?php endif; ?></p>
      <p><?= nl2br(e($a['content'])) ?></p>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($announcements)): ?><div class="alert alert-info">No announcements posted yet.</div><?php endif; ?>

<div class="modal fade" id="annModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">New Announcement</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" maxlength="150" required></div>
        <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="4" maxlength="10000" required></textarea></div>
        <div class="mb-3"><label class="form-label">Target Department</label>
          <select name="target_department" class="form-select">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>"><?= e($d['department_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Expires On (optional)</label><input type="date" name="expires_at" class="form-control" min="<?= APP_LAUNCH_DATE ?>"></div>
      </div>
      <div class="modal-footer"><button class="btn btn-primary">Post</button></div>
    </form>
  </div></div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
