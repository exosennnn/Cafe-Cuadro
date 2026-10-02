<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/requests.php';
require_once __DIR__ . '/../../includes/contracts.php';
require_once __DIR__ . '/../../includes/signature_pad.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF, ROLE_EMPLOYEE_MANAGER, ROLE_HR_STAFF, ROLE_HR_MANAGER, ROLE_OWNER]);

/**
 * REQUEST FORM - DOCUMENT VIEW
 *
 * The formal, printable version of a single request: the filed details, then
 * the complete approval / e-signature history showing every step of the route
 * with the acting user's name, role, date and time, action, and remarks.
 *
 * One page serves every role. Employees see their own requests; the approval
 * roles and the Owner can open any request. Approvers whose step is the one
 * currently waiting can act directly from here, so they don't have to go back
 * to their queue to sign off on a request they're already reading.
 */

$requestId = (int)($_GET['request_id'] ?? 0);
$userId    = (int)$_SESSION['user_id'];
$roleId    = (int)currentRoleId();
$actorName = trim((string)($_SESSION['full_name'] ?? ''));
$actorRole = currentRoleName();

// Where "Back" goes, and where a POST redirects to, per role.
$backPage = [
    ROLE_EMPLOYEE_MANAGER => 'employee-manager/requests',
    ROLE_HR_STAFF         => 'hr-staff/requests',
    ROLE_HR_MANAGER       => 'hr-manager/requests',
][$roleId] ?? 'employee/requests';

$request = getRequestForm($pdo, $requestId);

if (!$request) {
    setFlash('error', 'That request could not be found.');
    redirect($backPage);
}
if (!userCanViewRequest($request, $userId, $roleId)) {
    setFlash('error', 'You are not authorized to view that request.');
    redirect($backPage);
}

// ---------------------------------------------------------------------------
// PDF download - same document, same data, just a downloadable file. The
// on-screen "Print" button (window.print()) is unchanged and still there;
// this is an additional, explicit "Download PDF" option next to it.
// ---------------------------------------------------------------------------
if (($_GET['format'] ?? '') === 'pdf') {
    $built = buildRequestFormPdf($pdo, $requestId);
    if (!$built) {
        setFlash('error', 'This request could not be turned into a PDF.');
        redirect($backPage);
    }
    $built['pdf']->output($built['filename']);
    exit;
}

$workflow    = requestWorkflow();
$pendingStep = requestStepForStatus($request['status']);
$isFiler     = ((int)$request['employee_user_id'] === $userId);

// Can this viewer act on this request right now? Only if their role owns the
// step the request is currently sitting on.
$myStep = null;
foreach ($workflow as $stepNo => $step) {
    if ($step['pending_status'] !== null && (int)$step['role_id'] === $roleId) {
        $myStep = $stepNo;
    }
}
$canAct = ($myStep !== null && $myStep === $pendingStep);

// ---------------------------------------------------------------------------
// Act on the request from the document itself
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    $decision  = $_POST['decision'] ?? '';
    $remarksIn = $_POST['remarks'] ?? '';
    $remarks   = is_string($remarksIn) ? trim($remarksIn) : '';
    $sigIn     = $_POST['signature_data'] ?? '';
    $signature = is_string($sigIn) && $sigIn !== '' ? $sigIn : null;

    if (!$canAct) {
        setFlash('error', 'This request is not awaiting your action.');
    } elseif ($decision === 'reject' && $remarks === '') {
        setFlash('error', 'Please give a reason when rejecting a request.');
    } elseif ($signature === null) {
        setFlash('error', 'Please draw your signature before submitting your decision.');
    } else {
        $result = actOnRequest($pdo, $requestId, $myStep, $decision, $userId, $actorName, $actorRole, $remarks, $signature);
        setFlash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok']) {
            logAudit($pdo, $userId, 'REVIEW_REQUEST_FORM', 'Request Forms',
                "request_id=$requestId step=$myStep decision=$decision status=" . ($result['status'] ?? ''));
        }
    }
    redirect('modules/shared/request_view.php?request_id=' . $requestId);
}

$approvals = getRequestApprovals($pdo, $requestId);
$company   = companyIdentity($pdo);
$summary   = requestTypeSummary($request);
$isFinalStep = ($myStep === array_key_last($workflow));

// Index the history by step so the route can be rendered in order, with steps
// not yet reached shown as still outstanding rather than simply missing.
$actionsByStep = [];
foreach ($approvals as $approval) {
    $actionsByStep[(int)$approval['step_no']][] = $approval;
}

$actionVerb = [
    'SUBMITTED' => 'Filed',
    'FORWARDED' => 'Reviewed and forwarded',
    'APPROVED'  => 'Approved',
    'REJECTED'  => 'Rejected',
    'CANCELLED' => 'Withdrawn',
];

$pageTitle = 'Request ' . $request['request_no'];
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/doc_styles.php';
?>

<div class="doc-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0"><?= e(requestTypeLabel($request['request_type'])) ?></h4>
    <p class="text-muted small mb-0">
      <?= e($request['request_no']) ?> &middot;
      <?= e($request['first_name'] . ' ' . $request['last_name']) ?> &middot;
      <span class="badge bg-<?= e(requestStatusColor($request['status'])) ?>"><?= e(requestStatusLabel($request['status'])) ?></span>
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canAct): ?>
      <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#actModal">
        <i class="bi bi-check2-square"></i> <?= $isFinalStep ? 'Approve or Reject' : 'Review' ?>
      </button>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>shared/request-view?request_id=<?= (int)$requestId ?>&format=pdf"
       class="btn btn-outline-dark btn-sm">
      <i class="bi bi-file-earmark-arrow-down"></i> Download PDF
    </a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
      <i class="bi bi-printer"></i> Print
    </button>
    <a href="<?= BASE_URL . $backPage ?>" class="btn btn-light btn-sm border"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<?php if ($canAct): ?>
  <div class="alert alert-warning doc-toolbar">
    <i class="bi bi-hourglass-split"></i>
    This request is waiting on you at <strong><?= e($workflow[$myStep]['label']) ?></strong>.
  </div>
<?php endif; ?>

<div class="doc-sheet">

  <div class="doc-letterhead">
    <div class="company"><?= e($company['name']) ?></div>
    <?php if ($company['address']): ?><div class="meta"><?= e($company['address']) ?></div><?php endif; ?>
    <?php if ($company['contact']): ?><div class="meta"><?= e($company['contact']) ?></div><?php endif; ?>
  </div>

  <div class="doc-title"><?= e(requestTypeLabel($request['request_type'])) ?></div>
  <div class="doc-subtitle">Human Resources &mdash; Employee Request Form</div>

  <div class="doc-refbar">
    <span class="doc-refbar-key">Request No.: <?= e($request['request_no']) ?></span>
    <span>Date Filed: <?= e(fdate($request['filed_at'], 'F d, Y')) ?></span>
    <span>Status: <?= e(requestStatusLabel($request['status'])) ?></span>
  </div>

  <!-- ------------------------------------------------------------------ -->
  <!-- Part I - who is asking                                              -->
  <!-- ------------------------------------------------------------------ -->
  <div class="doc-clause">
    <h3>I. Employee Details</h3>
    <table class="doc-fields">
      <tr><th>Name</th><td><?= e($request['first_name'] . ' ' . $request['last_name']) ?></td></tr>
      <tr><th>Employee Code</th><td><?= e($request['employee_code']) ?></td></tr>
      <tr><th>Position</th><td><?= e($request['position'] ?: '-') ?></td></tr>
      <tr><th>Department</th><td><?= e($request['department_name'] ?: '-') ?></td></tr>
      <?php if (!empty($request['branch_name'])): ?>
        <tr><th>Branch</th><td><?= e($request['branch_name']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- ------------------------------------------------------------------ -->
  <!-- Part II - what is being asked                                       -->
  <!-- ------------------------------------------------------------------ -->
  <div class="doc-clause">
    <h3>II. Request Details</h3>
    <table class="doc-fields">
      <tr><th>Request Type</th><td><?= e(requestTypeLabel($request['request_type'])) ?></td></tr>
      <tr><th>Subject</th><td><?= e($request['subject']) ?></td></tr>
      <?php foreach ($summary as $label => $value): ?>
        <tr><th><?= e($label) ?></th><td><?= e($value) ?></td></tr>
      <?php endforeach; ?>
      <tr>
        <th>Details / Reason</th>
        <td><?= !empty($request['details']) ? nl2br(e($request['details'])) : '<span class="doc-unsigned">None provided</span>' ?></td>
      </tr>
    </table>
  </div>

  <!-- ------------------------------------------------------------------ -->
  <!-- Part III - the approval route and its e-signatures                  -->
  <!-- ------------------------------------------------------------------ -->
  <div class="doc-clause">
    <h3>III. Approval and E-Signature History</h3>
    <p class="doc-justify">
      Each entry below is a permanent record of an action taken on this request, showing the acting user&rsquo;s name and
      role, the date and time, the action taken, and any remarks given. Entries are never edited or removed.
    </p>

    <ul class="doc-trail">
      <?php foreach ($workflow as $stepNo => $step): ?>
        <?php $entries = $actionsByStep[$stepNo] ?? []; ?>

        <?php if (!empty($entries)): ?>
          <?php foreach ($entries as $entry): ?>
            <?php
              $action = $entry['action'];
              $stateClass = in_array($action, ['APPROVED', 'FORWARDED', 'SUBMITTED'], true) ? 'is-approved'
                          : (in_array($action, ['REJECTED', 'CANCELLED'], true) ? 'is-rejected' : '');
            ?>
            <li class="<?= e($stateClass) ?>">
              <div class="step-head">Step <?= (int)$entry['step_no'] ?> &middot; <?= e($entry['step_label']) ?></div>
              <div class="step-actor"><?= e($entry['actor_name']) ?></div>
              <div class="step-meta">
                <div><strong>Role:</strong> <?= e($entry['actor_role']) ?></div>
                <div><strong>Action:</strong> <?= e($actionVerb[$action] ?? $action) ?></div>
                <div><strong>Date &amp; Time:</strong> <?= e(fdate($entry['acted_at'], 'F d, Y \a\t g:i A')) ?></div>
              </div>
              <?php if (!empty($entry['remarks'])): ?>
                <div class="step-remarks"><strong>Remarks:</strong> <?= nl2br(e($entry['remarks'])) ?></div>
              <?php endif; ?>
              <?php $sigSvg = renderSignatureSvg($entry['signature_data'] ?? null); ?>
              <?php if ($sigSvg !== ''): ?>
                <div class="step-signature"><?= $sigSvg ?></div>
              <?php endif; ?>
              <div class="step-ref">E-signature ref: <?= e($entry['signature_hash']) ?></div>
            </li>
          <?php endforeach; ?>

        <?php elseif ($stepNo === $pendingStep): ?>
          <li class="is-pending">
            <div class="step-head">Step <?= (int)$stepNo ?> &middot; <?= e($step['label']) ?></div>
            <div class="step-actor doc-unsigned">Awaiting <?= e($step['role_label']) ?></div>
            <div class="step-meta doc-unsigned">This step has not been acted on yet.</div>
          </li>

        <?php elseif (requestIsOpen($request['status']) && $pendingStep !== null && $stepNo > $pendingStep): ?>
          <li class="is-pending">
            <div class="step-head">Step <?= (int)$stepNo ?> &middot; <?= e($step['label']) ?></div>
            <div class="step-actor doc-unsigned">Not yet reached</div>
            <div class="step-meta doc-unsigned">Pending <?= e($step['role_label']) ?>.</div>
          </li>

        <?php else: ?>
          <?php // Closed request that never reached this step (rejected or withdrawn earlier). ?>
          <li class="is-pending">
            <div class="step-head">Step <?= (int)$stepNo ?> &middot; <?= e($step['label']) ?></div>
            <div class="step-actor doc-unsigned">Not reached</div>
            <div class="step-meta doc-unsigned">
              The request was <?= e(strtolower(requestStatusLabel($request['status']))) ?> before this step.
            </div>
          </li>
        <?php endif; ?>

      <?php endforeach; ?>
    </ul>
  </div>

  <div class="doc-footnote">
    Request No. <strong><?= e($request['request_no']) ?></strong>, filed
    <?= e(fdate($request['filed_at'], 'F d, Y \a\t g:i A')) ?> and currently
    <strong><?= e(requestStatusLabel($request['status'])) ?></strong>.
    Approval route: <?= e(implode(' → ', array_column($workflow, 'role_label'))) ?>.
    This document is generated from the <?= e($company['name']) ?> HR system record and reflects its state as of
    <?= e(date('F d, Y \a\t g:i A')) ?>.
  </div>

</div>

<?php if ($canAct): ?>
<div class="modal fade" id="actModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <div class="modal-header">
        <h5 class="modal-title"><?= e($workflow[$myStep]['label']) ?> &mdash; <?= e($request['request_no']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Remarks <span class="text-muted small">(required when rejecting)</span></label>
          <textarea name="remarks" class="form-control" rows="3" maxlength="2000"
                    placeholder="Recorded with your e-signature and shown on this document."></textarea>
        </div>
        <?php signaturePadHtml('DocView' . $requestId); ?>
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
          <?= $isFinalStep ? 'Approve' : 'Forward to ' . e($workflow[$myStep + 1]['role_label']) ?>
        </button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
