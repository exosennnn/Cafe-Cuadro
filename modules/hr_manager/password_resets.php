<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole([ROLE_HR_MANAGER]);

$managerId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $requestId = (int)($_POST['request_id'] ?? 0);
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : '';

    if (!in_array($action, ['reset', 'reject'], true)) {
      setFlash('error', 'Please select a valid password reset action.');
      redirect('modules/hr_manager/password_resets.php');
    }
    if ($requestId <= 0) {
      setFlash('error', 'Invalid password reset request.');
      redirect('modules/hr_manager/password_resets.php');
    }
    if (strlen($remarks) > 2000) {
      setFlash('error', 'Remarks must be 2000 characters or fewer.');
      redirect('modules/hr_manager/password_resets.php');
    }

    $stmt = $pdo->prepare("SELECT r.*, u.email, u.first_name, u.last_name, u.role_id
        FROM password_reset_requests r JOIN users u ON r.user_id = u.user_id
        WHERE r.request_id = ? LIMIT 1");
    $stmt->execute([$requestId]);
    $reqRow = $stmt->fetch();

    if (!$reqRow || $reqRow['status'] !== 'PENDING') {
        setFlash('error', 'That request no longer needs action.');
    } elseif ((int)$reqRow['role_id'] === ROLE_OWNER) {
      setFlash('error', 'HR Managers cannot reset or modify the Owner account.');
    } elseif ($action === 'reject') {
      $update = $pdo->prepare("UPDATE password_reset_requests SET status='REJECTED', reviewed_by=?, reviewed_at=NOW(), review_remarks=? WHERE request_id=? AND status='PENDING'");
      $update->execute([$managerId, $remarks, $requestId]);
      if ($update->rowCount() !== 1) {
        setFlash('error', 'That request was already processed.');
      } else {
        setFlash('success', 'Reset request rejected.');
        logAudit($pdo, $managerId, 'PASSWORD_RESET_REJECTED', 'User Management', "request_id=$requestId");
      }
    } elseif ($action === 'reset') {
        // Hard server-side enforcement of the cooldown — never trust the
        // disabled-button state in the HTML alone.
        if (strtotime($reqRow['unlock_at']) > time()) {
            setFlash('error', 'This request is still in its ' . PASSWORD_RESET_LOCK_MINUTES . '-minute security waiting period and cannot be processed yet.');
        } else {
            $newPassInput = $_POST['new_password'] ?? '';
            $newPass = is_string($newPassInput) ? $newPassInput : '';
            if (strlen($newPass) < 8 || strlen($newPass) > 72) {
              setFlash('error', 'New password must be between 8 and 72 characters.');
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $newPass)) {
              setFlash('error', 'New password cannot contain control characters.');
            } else {
              try {
                $pdo->beginTransaction();
                $locked = $pdo->prepare("SELECT r.*, u.email, u.first_name, u.last_name, u.role_id
                  FROM password_reset_requests r JOIN users u ON r.user_id = u.user_id
                  WHERE r.request_id=? AND r.status='PENDING' FOR UPDATE");
                $locked->execute([$requestId]);
                $lockedRow = $locked->fetch();
                if (!$lockedRow || (int)$lockedRow['role_id'] === ROLE_OWNER || strtotime($lockedRow['unlock_at']) > time()) {
                  throw new RuntimeException('This password reset request is no longer available for processing.');
                }

                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $userUpdate = $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=? AND role_id<>?");
                $userUpdate->execute([$hash, $lockedRow['user_id'], ROLE_OWNER]);
                $requestUpdate = $pdo->prepare("UPDATE password_reset_requests SET status='APPROVED', reviewed_by=?, reviewed_at=NOW(), review_remarks=? WHERE request_id=? AND status='PENDING'");
                $requestUpdate->execute([$managerId, $remarks, $requestId]);
                if ($userUpdate->rowCount() !== 1 || $requestUpdate->rowCount() !== 1) {
                  throw new RuntimeException('That request was already processed or the target account is protected.');
                }
                $pdo->commit();

                createNotification($pdo, (int)$lockedRow['user_id'], 'Password Reset Completed',
                  'Your password was reset by HR. Please coordinate with your HR Manager for your new password.',
                  'PASSWORD_RESET', $requestId);

                // Best-effort email too, since this may matter even if they
                // aren't actively logged in to see the in-app notification.
                sendMail($lockedRow['email'], $lockedRow['first_name'] . ' ' . $lockedRow['last_name'],
                  'Your Password Was Reset',
                  mailTemplate('Password Reset Completed', '<p>Hi ' . e($lockedRow['first_name']) . ',</p>
                    <p>Your ' . e(APP_NAME) . ' account password was just reset by HR, as you requested.</p>
                    <p>For security, we don\'t send the new password by email &mdash; please coordinate with your HR Manager to get it.</p>'));

                setFlash('success', 'Password reset for ' . $lockedRow['first_name'] . ' ' . $lockedRow['last_name'] . '.');
                logAudit($pdo, $managerId, 'PASSWORD_RESET_APPROVED', 'User Management', "request_id=$requestId user_id={$lockedRow['user_id']}");
              } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('password_resets.php reset error: ' . $exception->getMessage());
                setFlash('error', 'Password reset could not be completed. No changes were made.');
              }
            }
        }
    }
    redirect('modules/hr_manager/password_resets.php');
}

$statusFilter = $_GET['status'] ?? 'PENDING';
$allowedStatuses = ['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', ''];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'PENDING';

$where = '1=1'; $params = [];
if ($statusFilter !== '') { $where .= " AND r.status = ?"; $params[] = $statusFilter; }

$stmt = $pdo->prepare("SELECT r.*, u.email, u.first_name, u.last_name,
        rv.first_name AS reviewer_first, rv.last_name AS reviewer_last
    FROM password_reset_requests r
    JOIN users u ON r.user_id = u.user_id
    LEFT JOIN users rv ON r.reviewed_by = rv.user_id
    WHERE $where
    ORDER BY r.requested_at DESC LIMIT 100");
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pendingCount = $pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status='PENDING'")->fetchColumn();

$pageTitle = 'Password Reset Requests';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h4>Password Reset Requests</h4>
  <span class="badge bg-warning text-dark"><?= (int)$pendingCount ?> pending</span>
</div>



<ul class="nav nav-pills mb-3">
  <?php foreach (['PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected', '' => 'All'] as $val => $label): ?>
    <li class="nav-item">
      <a class="nav-link <?= $statusFilter === $val ? 'active' : '' ?>" href="?status=<?= urlencode($val) ?>"><?= $label ?></a>
    </li>
  <?php endforeach; ?>
</ul>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="passwordResetsTable">
      <thead>
        <tr>
          <th>User</th>
          <th>Email</th>
          <th>Requested</th>
          <th>Unlocks At</th>
          <th>Status</th>
          <th>Reviewed By</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($requests as $r):
        $isUnlocked = strtotime($r['unlock_at']) <= time();
      ?>
        <tr>
          <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
          <td><?= e($r['email']) ?></td>
          <td><?= fdate($r['requested_at'], 'M d, Y g:i A') ?></td>
          <td>
            <?php if ($r['status'] === 'PENDING'): ?>
              <?php if ($isUnlocked): ?>
                <span class="badge bg-success"><i class="bi bi-unlock"></i> Ready</span>
              <?php else: ?>
                <span class="badge bg-secondary"><i class="bi bi-lock"></i> <?= fdate($r['unlock_at'], 'g:i A') ?></span>
              <?php endif; ?>
            <?php else: ?>
              <?= fdate($r['unlock_at'], 'M d, Y g:i A') ?>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $badge = ['PENDING'=>'warning','APPROVED'=>'success','REJECTED'=>'danger','CANCELLED'=>'secondary'][$r['status']] ?? 'secondary';
            ?>
            <span class="badge bg-<?= $badge ?>"><?= e($r['status']) ?></span>
          </td>
          <td><?= $r['reviewer_first'] ? e($r['reviewer_first'] . ' ' . $r['reviewer_last']) : '—' ?></td>
          <td class="text-nowrap">
            <?php if ($r['status'] === 'PENDING'): ?>
              <button class="btn btn-sm btn-primary" <?= $isUnlocked ? '' : 'disabled title="Available at ' . e(fdate($r['unlock_at'], 'g:i A')) . '"' ?>
                data-bs-toggle="modal" data-bs-target="#resetModal<?= $r['request_id'] ?>">
                <i class="bi bi-key"></i> Reset
              </button>
              <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $r['request_id'] ?>">
                <i class="bi bi-x-lg"></i> Reject
              </button>
            <?php else: ?>
              <span class="text-muted small"><?= $r['reviewed_at'] ? fdate($r['reviewed_at'], 'M d, g:i A') : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>

      <?php endforeach; ?>
      <?php if (empty($requests)): ?>
        <tr><td colspan="7" class="text-center text-muted">No requests found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($requests as $r): ?>
  <?php if ($r['status'] === 'PENDING'): ?>
    <?php $isUnlocked = strtotime($r['unlock_at']) <= time(); ?>
    <div class="modal fade" id="resetModal<?= $r['request_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
        <div class="modal-header">
          <h6 class="modal-title">Reset Password &mdash; <?= e($r['first_name'] . ' ' . $r['last_name']) ?></h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if (!$isUnlocked): ?>
            <div class="alert alert-warning small mb-3">
              Still in the <?= PASSWORD_RESET_LOCK_MINUTES ?>-minute waiting period.
              Available at <?= fdate($r['unlock_at'], 'g:i A') ?>.
            </div>
          <?php else: ?>
            
          <?php endif; ?>
          <label class="form-label">New Password</label>
          <input type="password" name="new_password" class="form-control" minlength="8" maxlength="72" required placeholder="Min 8 characters" <?= $isUnlocked ? '' : 'disabled' ?>>
          <label class="form-label mt-2">Remarks (optional)</label>
          <input type="text" name="remarks" class="form-control" maxlength="2000" placeholder="e.g. Verified via phone call">
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary" <?= $isUnlocked ? '' : 'disabled' ?>>Reset Password</button>
        </div>
      </form>
    </div></div></div>

    <div class="modal fade" id="rejectModal<?= $r['request_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="request_id" value="<?= $r['request_id'] ?>">
        <div class="modal-header">
          <h6 class="modal-title">Reject Request &mdash; <?= e($r['first_name'] . ' ' . $r['last_name']) ?></h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Reason (optional)</label>
          <input type="text" name="remarks" class="form-control" maxlength="2000" placeholder="e.g. Could not verify identity">
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-danger">Reject</button>
        </div>
      </form>
    </div></div></div>
  <?php endif; ?>
<?php endforeach; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#passwordResetsTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
