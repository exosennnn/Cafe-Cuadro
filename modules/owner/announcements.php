<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_OWNER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $dept = $_POST['target_department'] ?: null;
        $expires = $_POST['expires_at'] ?: null;

        if ($expires !== null && $expires < APP_LAUNCH_DATE) {
            setFlash('error', 'Expires On must be ' . APP_LAUNCH_DATE . ' or later.');
            redirect('modules/owner/announcements.php');
        }

        $pdo->prepare("INSERT INTO announcements (title, content, posted_by, target_department, expires_at) VALUES (?,?,?,?,?)")
            ->execute([$title, $content, $userId, $dept, $expires]);
        setFlash('success', 'Announcement posted.');
        logAudit($pdo, $userId, 'CREATE_ANNOUNCEMENT', 'Announcements', $title);
    } elseif ($action === 'update') {
        $id = (int)$_POST['announcement_id'];
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $dept = $_POST['target_department'] ?: null;
        $expires = $_POST['expires_at'] ?: null;

        if ($expires !== null && $expires < APP_LAUNCH_DATE) {
            setFlash('error', 'Expires On must be ' . APP_LAUNCH_DATE . ' or later.');
            redirect('modules/owner/announcements.php');
        }

        $pdo->prepare("UPDATE announcements SET title=?, content=?, target_department=?, expires_at=? WHERE announcement_id=?")
            ->execute([$title, $content, $dept, $expires, $id]);
        setFlash('success', 'Announcement updated.');
        logAudit($pdo, $userId, 'UPDATE_ANNOUNCEMENT', 'Announcements', $title);
    } elseif ($action === 'delete') {
        $id = (int)$_POST['announcement_id'];
        $del = $pdo->prepare("SELECT title FROM announcements WHERE announcement_id=?");
        $del->execute([$id]);
        $delTitle = $del->fetchColumn();
        $pdo->prepare("DELETE FROM announcements WHERE announcement_id=?")->execute([$id]);
        setFlash('success', 'Announcement deleted.');
        logAudit($pdo, $userId, 'DELETE_ANNOUNCEMENT', 'Announcements', $delTitle ?: ('#'.$id));
    }
    redirect('modules/owner/announcements.php');
}

$announcements = $pdo->query("SELECT a.*, u.first_name, u.last_name, d.department_name
    FROM announcements a JOIN users u ON a.posted_by=u.user_id LEFT JOIN departments d ON a.target_department=d.department_id
    ORDER BY a.posted_at DESC")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY department_name")->fetchAll();

$pageTitle = 'Announcements';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4>Announcements</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#annModal"><i class="bi bi-plus-circle"></i> New Announcement</button>
</div>

<?php foreach ($announcements as $a): ?>
  <div class="card mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between">
        <h6 class="fw-bold"><?= e($a['title']) ?></h6>
        <div class="d-flex gap-1">
          <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModal<?= $a['announcement_id'] ?>"><i class="bi bi-pencil"></i></button>
          <form method="POST" onsubmit="return confirm('Delete this announcement?')">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      </div>
      <p class="small text-muted mb-2">Posted by <?= e($a['first_name'].' '.$a['last_name']) ?> on <?= fdate($a['posted_at']) ?>
        &middot; Target: <?= e($a['department_name'] ?? 'All Departments') ?>
        <?php if ($a['expires_at']): ?> &middot; Expires <?= fdate($a['expires_at']) ?><?php endif; ?></p>
      <p><?= nl2br(e($a['content'])) ?></p>
    </div>
  </div>

  <div class="modal fade" id="editModal<?= $a['announcement_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="announcement_id" value="<?= $a['announcement_id'] ?>">
        <div class="modal-header"><h5 class="modal-title">Edit Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" value="<?= e($a['title']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="4" required><?= e($a['content']) ?></textarea></div>
          <div class="mb-3"><label class="form-label">Target Department</label>
            <select name="target_department" class="form-select">
              <option value="">All Departments</option>
              <?php foreach ($departments as $d): ?><option value="<?= $d['department_id'] ?>" <?= $a['target_department'] == $d['department_id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3"><label class="form-label">Expires On (optional)</label><input type="date" name="expires_at" class="form-control" min="<?= APP_LAUNCH_DATE ?>" value="<?= e($a['expires_at']) ?>"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Save Changes</button></div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>
<?php if (empty($announcements)): ?><div class="alert alert-info">No announcements posted yet.</div><?php endif; ?>

<div class="modal fade" id="annModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="create">
      <div class="modal-header"><h5 class="modal-title">New Announcement</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Content</label><textarea name="content" class="form-control" rows="4" required></textarea></div>
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
