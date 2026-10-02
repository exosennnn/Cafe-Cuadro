<?php
/**
 * SHARED REQUEST REVIEW PAGE
 *
 * The Employee Manager, HR Staff, and HR Manager pages are the same screen
 * differing only in which workflow step they occupy, so all three include this
 * file rather than carrying three near-identical copies of the queue, the
 * decision handler, and the modals.
 *
 * The including page must set, before requiring this file:
 *   $reviewStep     int    2 = Manager Review, 3 = HR Processing, 4 = Final Approval
 *   $reviewRedirect string path used by redirect() after a POST
 *
 * It must already have required db.php, auth.php, functions.php, requests.php
 * and called requireRole() for its own role.
 */

if (!isset($reviewStep, $reviewRedirect)) {
    http_response_code(500);
    die('Request review page misconfigured.');
}

require_once __DIR__ . '/signature_pad.php';

$workflow = requestWorkflow();
if (!isset($workflow[$reviewStep]) || $workflow[$reviewStep]['pending_status'] === null) {
    http_response_code(500);
    die('Invalid approval step.');
}

$step          = $workflow[$reviewStep];
$pendingStatus = $step['pending_status'];
$isFinalStep   = ($reviewStep === array_key_last($workflow));
$userId        = (int)$_SESSION['user_id'];
$actorName     = trim((string)($_SESSION['full_name'] ?? ''));
$actorRole     = currentRoleName();

// ---------------------------------------------------------------------------
// Decision handler
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $decision  = $_POST['decision'] ?? '';
    $remarksIn = $_POST['remarks'] ?? '';
    $remarks   = is_string($remarksIn) ? trim($remarksIn) : '';
    $sigIn     = $_POST['signature_data'] ?? '';
    $signature = is_string($sigIn) && $sigIn !== '' ? $sigIn : null;

    if ($requestId === false || $requestId < 1) {
        setFlash('error', 'Invalid request.');
    } elseif ($decision === 'reject' && $remarks === '') {
        // A rejection ends the request, so the filer is entitled to a reason.
        setFlash('error', 'Please give a reason when rejecting a request.');
    } elseif ($signature === null) {
        setFlash('error', 'Please draw your signature before submitting your decision.');
    } else {
        $result = actOnRequest($pdo, $requestId, $reviewStep, $decision, $userId, $actorName, $actorRole, $remarks, $signature);
        setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok']) {
            logAudit($pdo, $userId, 'REVIEW_REQUEST_FORM', 'Request Forms',
                "request_id=$requestId step=$reviewStep decision=$decision status=" . ($result['status'] ?? ''));
        }
    }
    redirect($reviewRedirect);
}

// ---------------------------------------------------------------------------
// Queue
// ---------------------------------------------------------------------------
$allowedStatuses = ['PENDING_MANAGER', 'PENDING_HR', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED', ''];
$statusFilter = $_GET['status'] ?? $pendingStatus;
if (!is_string($statusFilter) || !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = $pendingStatus;
}

$typeFilter = $_GET['type'] ?? '';
if (!is_string($typeFilter) || ($typeFilter !== '' && !isset(requestTypes()[$typeFilter]))) {
    $typeFilter = '';
}

$where = '1=1';
$params = [];
if ($statusFilter !== '') { $where .= ' AND rf.status = ?';       $params[] = $statusFilter; }
if ($typeFilter !== '')   { $where .= ' AND rf.request_type = ?'; $params[] = $typeFilter; }

$stmt = $pdo->prepare("SELECT rf.*, e.employee_code, e.position,
        u.first_name, u.last_name, u.profile_photo,
        d.department_name, lt.type_name AS leave_type_name
    FROM request_forms rf
    JOIN employees e ON rf.employee_id = e.employee_id
    JOIN users u ON e.user_id = u.user_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN leave_types lt ON rf.leave_type_id = lt.leave_type_id
    WHERE $where
    ORDER BY rf.filed_at DESC");
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Leave balances for the leave rows on this page, so reviewers can see what a
// request would consume before approving it - the check HR Staff had on the
// old Leave Requests page.
$leaveBalances = [];
foreach ($requests as $r) {
    if ($r['request_type'] === 'LEAVE') {
        $leaveBalances[(int)$r['request_id']] = leaveBalanceFor($pdo, (int)$r['employee_id'], $r['leave_type_id'] ? (int)$r['leave_type_id'] : null);
    }
}

// Count of everything still sitting on this step, for the header badge.
$awaiting = $pdo->prepare("SELECT COUNT(*) FROM request_forms WHERE status = ?");
$awaiting->execute([$pendingStatus]);
$awaitingCount = (int)$awaiting->fetchColumn();

$pageTitle = $isFinalStep ? 'Request Final Approval' : 'Request Forms';
require_once __DIR__ . '/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-inboxes-fill"></i></div>
  <div>
    <?php if (!empty($reviewCompactHeader)): ?>
    <h4>Request Forms</h4>
    <?php else: ?>
    <h4><?= e($step['label']) ?> &mdash; Request Forms</h4>
    <p>
      <?php if ($isFinalStep): ?>
        Final approval. Approving here completes the request; rejecting ends it.
      <?php else: ?>
        Review requests at this step. Forwarding sends them to
        <?= e($workflow[$reviewStep + 1]['role_label']) ?>; rejecting ends the request immediately.
      <?php endif; ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<p class="text-muted small">
  <?php if (empty($reviewCompactHeader)): ?>
  You are step <?= (int)$reviewStep ?> of <?= count($workflow) ?> in the approval route
  (<?= e(implode(' → ', array_column($workflow, 'role_label'))) ?>).
  Every decision is recorded with your name, role, and the date and time, and appears on the request document.
  <?php endif; ?>
  <?php if ($awaitingCount > 0): ?>
    <strong><?= $awaitingCount ?></strong> request<?= $awaitingCount === 1 ? '' : 's' ?> awaiting your action.
  <?php endif; ?>
</p>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="<?= e($pendingStatus) ?>" <?= $statusFilter === $pendingStatus ? 'selected' : '' ?>>Awaiting My Action</option>
      <?php foreach (['PENDING_MANAGER', 'PENDING_HR', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED'] as $s): ?>
        <?php if ($s === $pendingStatus) continue; ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(requestStatusLabel($s)) ?></option>
      <?php endforeach; ?>
      <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All Statuses</option>
    </select>
  </div>
  <div class="col-md-3">
    <select name="type" class="form-select" onchange="this.form.submit()">
      <option value="">All Request Types</option>
      <?php foreach (requestTypes() as $code => $meta): ?>
        <option value="<?= e($code) ?>" <?= $typeFilter === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="requestsTable">
      <thead>
        <tr>
          <th>Request No.</th>
          <th>Type</th>
          <th>Employee</th>
          <th>Subject</th>
          <th>Details</th>
          <th>Filed</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <?php
          $summary = requestTypeSummary($r);
          $actionable = ($r['status'] === $pendingStatus);
        ?>
        <tr>
          <td class="text-nowrap"><strong><?= e($r['request_no']) ?></strong></td>
          <td class="text-nowrap"><i class="bi <?= e(requestTypeIcon($r['request_type'])) ?>"></i> <?= e(requestTypeLabel($r['request_type'])) ?></td>
          <td>
            <?= e($r['first_name'] . ' ' . $r['last_name']) ?>
            <br><small class="text-muted"><?= e($r['employee_code']) ?><?= $r['department_name'] ? ' &middot; ' . e($r['department_name']) : '' ?></small>
          </td>
          <td><?= e($r['subject']) ?></td>
          <td class="small">
            <?php foreach ($summary as $label => $value): ?>
              <div><span class="text-muted"><?= e($label) ?>:</span> <?= e($value) ?></div>
            <?php endforeach; ?>
            <?php if (empty($summary) && !empty($r['details'])): ?>
              <div class="text-muted"><?= e(function_exists('mb_strimwidth') ? mb_strimwidth($r['details'], 0, 90, '...') : substr($r['details'], 0, 90)) ?></div>
            <?php endif; ?>
            <?php $bal = $leaveBalances[(int)$r['request_id']] ?? null; ?>
            <?php if ($bal): ?>
              <div class="mt-1">
                <span class="badge bg-<?= $bal['remaining'] - (int)$r['total_days'] < 0 ? 'danger' : 'light text-dark border' ?>">
                  Balance: <?= (int)$bal['remaining'] ?>/<?= (int)$bal['entitlement'] ?> days left
                </span>
              </div>
            <?php endif; ?>
          </td>
          <td class="text-nowrap small"><?= e(fdate($r['filed_at'])) ?></td>
          <td><span class="badge bg-<?= e(requestStatusColor($r['status'])) ?>"><?= e(requestStatusLabel($r['status'])) ?></span></td>
          <td class="text-nowrap">
            <a href="<?= BASE_URL ?>modules/shared/request_view.php?request_id=<?= (int)$r['request_id'] ?>"
               class="btn btn-sm btn-outline-dark" title="Open request document"><i class="bi bi-file-earmark-text"></i></a>
            <?php if ($actionable): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#actModal<?= (int)$r['request_id'] ?>">
                <i class="bi bi-check2-square"></i> <?= $isFinalStep ? 'Decide' : 'Review' ?>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($requests)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">
          <i class="bi bi-inbox d-block fs-3 mb-2 opacity-50"></i>No requests match this filter.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($requests as $r): ?>
  <?php if ($r['status'] !== $pendingStatus) continue; ?>
  <div class="modal fade" id="actModal<?= (int)$r['request_id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title"><?= e($r['request_no']) ?> &mdash; <?= e(requestTypeLabel($r['request_type'])) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="mb-2">
            <strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong>
            <span class="text-muted">(<?= e($r['employee_code']) ?><?= $r['position'] ? ', ' . e($r['position']) : '' ?>)</span>
          </p>
          <p class="mb-2"><strong>Subject:</strong> <?= e($r['subject']) ?></p>
          <?php foreach (requestTypeSummary($r) as $label => $value): ?>
            <p class="mb-1 small"><strong><?= e($label) ?>:</strong> <?= e($value) ?></p>
          <?php endforeach; ?>
          <?php if (!empty($r['details'])): ?>
            <p class="mb-3 small"><strong>Details:</strong><br><?= nl2br(e($r['details'])) ?></p>
          <?php endif; ?>

          <?php $bal = $leaveBalances[(int)$r['request_id']] ?? null; ?>
          <?php if ($bal): ?>
            <?php $after = $bal['remaining'] - (int)$r['total_days']; ?>
            <div class="alert alert-<?= $after < 0 ? 'danger' : 'light border' ?> py-2 small">
              <strong>Leave balance this year:</strong>
              <?= (int)$bal['used'] ?> of <?= (int)$bal['entitlement'] ?> days used,
              <?= (int)$bal['remaining'] ?> remaining.
              Approving this request would leave <strong><?= (int)$after ?></strong>.
              <?php if ($after < 0): ?>
                <br>This exceeds the employee&rsquo;s entitlement for this leave type.
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Remarks <span class="text-muted small">(required when rejecting)</span></label>
            <textarea name="remarks" class="form-control" rows="3" maxlength="2000"
                      placeholder="Your remarks are recorded with your e-signature and shown on the request document."></textarea>
          </div>
          <?php signaturePadHtml((string)$r['request_id']); ?>
          <p class="small text-muted mb-0">
            Signing as <strong><?= e($actorName) ?></strong> &middot; <?= e($actorRole) ?> &middot; <?= e(date('M d, Y g:i A')) ?>
          </p>
        </div>
        <div class="modal-footer">
          <button type="submit" name="decision" value="reject" class="btn btn-outline-danger">
            <i class="bi bi-x-circle"></i> Reject
          </button>
          <button type="submit" name="decision" value="<?= $isFinalStep ? 'approve' : 'forward' ?>" class="btn btn-success">
            <i class="bi bi-check-circle"></i>
            <?= $isFinalStep ? 'Approve' : 'Forward to ' . e($workflow[$reviewStep + 1]['role_label']) ?>
          </button>
        </div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#requestsTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: [-1, -2] }] });
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>