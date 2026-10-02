<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contracts.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER, ROLE_OWNER, ROLE_APPLICANT, ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

/**
 * EMPLOYMENT CONTRACT PAGE
 *
 * This is the same contract that has always been generated from the job offer
 * (job_applications + job_offers) - not a new or second contract. What changed
 * is how it is presented:
 *
 *   contract.php?application_id=N             -> formal document on screen
 *   contract.php?application_id=N&format=pdf  -> the original PDF download
 *
 * Existing links elsewhere in the system point at the first form and now open
 * the document instead of downloading immediately; the PDF is one click away.
 *
 * The document shows the contract's unique Contract No. and, at the bottom,
 * the e-signatures of the employee and the authorised company representative,
 * each with name, role, and date signed.
 */

$appId  = (int)($_GET['application_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$roleId = (int)currentRoleId();

// HR and the Owner may open any contract. Everyone else may only open their
// own, matched through the applicant record the contract was generated from.
$isHrView = in_array($roleId, [ROLE_HR_STAFF, ROLE_HR_MANAGER, ROLE_OWNER], true);
$fallbackPage = $isHrView ? 'modules/hr_staff/applications.php'
    : ($roleId == ROLE_APPLICANT ? 'modules/applicant/my_applications.php' : 'modules/employee/profile.php');

$data = employmentContractData($pdo, $appId);

if (!$data) {
    setFlash('error', 'No job offer has been sent for this application yet, so there is no contract to show.');
    redirect($fallbackPage);
}

$isOwnContract = !empty($data['applicant_user_id']) && (int)$data['applicant_user_id'] === $userId;
if (!$isHrView && !$isOwnContract) {
    setFlash('error', 'Contract not found.');
    redirect($fallbackPage);
}

// ---------------------------------------------------------------------------
// PDF download - unchanged behaviour, now behind &format=pdf
// ---------------------------------------------------------------------------
if (($_GET['format'] ?? '') === 'pdf') {
    $built = buildEmploymentContractPdf($pdo, $appId);
    if (!$built) {
        setFlash('error', 'No job offer has been sent for this application yet.');
        redirect($fallbackPage);
    }
    $built['pdf']->output($built['filename']);
    exit;
}

// Issue the Contract No. on first view if the contract predates this feature.
$contract = getContractByApplication($pdo, $appId, true);

// ---------------------------------------------------------------------------
// E-signatures
// ---------------------------------------------------------------------------
$canSignAsEmployee = $contract && $isOwnContract && empty($contract['employee_signed_at']);
$canSignAsEmployer = $contract && canSignAsEmployer($roleId) && empty($contract['employer_signed_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $party = $_POST['party'] ?? '';
    $allowed = ($party === 'employee' && $canSignAsEmployee) || ($party === 'employer' && $canSignAsEmployer);

    if (!$allowed) {
        setFlash('error', 'You are not able to sign this contract, or that signature has already been recorded.');
    } elseif (empty($_POST['confirm_signature'])) {
        setFlash('error', 'Please tick the confirmation box before signing.');
    } else {
        $signerName = trim((string)($_SESSION['full_name'] ?? ''));
        // The employee signs in their role under the contract (the position
        // being contracted), not their system role.
        $signerRole = $party === 'employee'
            ? ($data['job_title'] ?: 'Employee')
            : currentRoleName() . ' (Authorized Company Representative)';

        if (signContract($pdo, (int)$contract['contract_id'], $party, $userId, $signerName, $signerRole)) {
            setFlash('success', 'Your signature has been recorded on Contract No. ' . $contract['contract_no'] . '.');
            logAudit($pdo, $userId, 'SIGN_CONTRACT', 'Employment Contract',
                'contract_no=' . $contract['contract_no'] . ' party=' . $party);
        } else {
            setFlash('error', 'That signature could not be recorded. It may already have been signed.');
        }
    }
    redirect('modules/hr_staff/contract.php?application_id=' . $appId);
}

// ---------------------------------------------------------------------------
// Render values - same source as the PDF, minus the PDF text transliteration
// ---------------------------------------------------------------------------
$company = companyIdentity($pdo);
$employeeName = trim($data['first_name'] . ' ' . $data['last_name']);
$jobTitle = (string)$data['job_title'];
$employmentTypeLabel = EMPLOYMENT_TYPE_LABELS[$data['employment_type']] ?? str_replace('_', ' ', (string)$data['employment_type']);
$shiftLabel = SHIFT_SCHEDULES[$data['shift']]['label'] ?? str_replace('_', ' ', (string)$data['shift']);

$clauses = employmentContractClauses([
    'job_title'             => $jobTitle,
    'department_name'       => (string)($data['department_name'] ?? ''),
    'branch_name'           => (string)($data['branch_name'] ?? ''),
    'branch_address'        => (string)($data['branch_address'] ?? ''),
    'employment_type_label' => $employmentTypeLabel,
    'is_probationary'       => $data['employment_type'] === 'PROBATIONARY',
    'start_date'            => fdate($data['offer_date']),
    'salary'                => fmoney($data['offered_salary']),
    'shift_label'           => $shiftLabel,
]);

$pageTitle = 'Employment Contract';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/doc_styles.php';
?>

<div class="doc-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <div>
    <h4 class="mb-0">Employment Contract</h4>
    <p class="text-muted small mb-0">
      <?= e($employeeName) ?> &middot; <?= e($jobTitle) ?>
      <?php if ($contract): ?> &middot; Contract No. <strong><?= e($contract['contract_no']) ?></strong><?php endif; ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>hr-staff/contract?application_id=<?= $appId ?>&amp;format=pdf" class="btn btn-outline-dark btn-sm">
      <i class="bi bi-download"></i> Download PDF
    </a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
      <i class="bi bi-printer"></i> Print
    </button>
    <a href="<?= BASE_URL . $fallbackPage ?>" class="btn btn-light btn-sm border"><i class="bi bi-arrow-left"></i> Back</a>
  </div>
</div>

<?php if ($canSignAsEmployee || $canSignAsEmployer): ?>
  <div class="alert alert-warning doc-toolbar d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <i class="bi bi-pen"></i>
      <?php if ($canSignAsEmployee): ?>
        You have not signed this contract yet.
      <?php else: ?>
        This contract is awaiting the authorized company representative's signature.
      <?php endif; ?>
    </div>
    <button class="btn btn-sm btn-brand" data-bs-toggle="modal" data-bs-target="#signModal">
      <i class="bi bi-vector-pen"></i> Sign Contract
    </button>
  </div>
<?php endif; ?>

<div class="doc-sheet">

  <div class="doc-letterhead">
    <div class="company"><?= e($company['name']) ?></div>
    <?php if ($company['address']): ?><div class="meta"><?= e($company['address']) ?></div><?php endif; ?>
    <?php if ($company['contact']): ?><div class="meta"><?= e($company['contact']) ?></div><?php endif; ?>
  </div>

  <div class="doc-title">Employment Contract</div>

  <div class="doc-refbar">
    <span class="doc-refbar-key">Contract No.: <?= $contract ? e($contract['contract_no']) : 'Pending' ?></span>
    <span>Date Issued: <?= $contract ? e(fdate($contract['generated_at'])) : e(fdate($data['offer_date'])) ?></span>
    <span>Employment Type: <?= e($employmentTypeLabel) ?></span>
  </div>

  <p>This Employment Contract is entered into by and between:</p>

  <p class="doc-justify">
    <strong><?= e(strtoupper($company['name'])) ?></strong>, a business duly organized and operating under the laws of the
    Republic of the Philippines, with office address at <?= e($company['address'] ?: '_______________________________') ?>,
    represented herein by
    <?= !empty($contract['employer_signed_name']) ? '<strong>' . e($contract['employer_signed_name']) . '</strong>' : '_______________________________' ?>,
    hereinafter referred to as the &ldquo;EMPLOYER&rdquo;;
  </p>

  <p class="text-center fst-italic">- and -</p>

  <p class="doc-justify">
    <strong><?= e($employeeName) ?></strong>, of legal age, Filipino, residing at
    <?= e($data['address'] ?: '_______________________________') ?>, hereinafter referred to as the &ldquo;EMPLOYEE.&rdquo;
  </p>

  <p>The Employer and Employee agree to the following terms and conditions:</p>

  <?php foreach ($clauses as $clause): ?>
    <div class="doc-clause">
      <h3><?= e($clause['heading']) ?></h3>
      <?php foreach ($clause['paragraphs'] as $paragraph): ?>
        <p class="<?= strncmp($paragraph, '- ', 2) === 0 ? 'doc-list' : 'doc-justify' ?>"><?= nl2br(e($paragraph)) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($data['offer_details'])): ?>
    <div class="doc-clause">
      <h3>Additional Terms Included in the Written Offer</h3>
      <p class="doc-justify"><?= nl2br(e($data['offer_details'])) ?></p>
    </div>
  <?php endif; ?>

  <!-- E-signatures -->
  <div class="doc-clause">
    <h3>Signatures</h3>
    <p class="doc-justify">
      The parties confirm their acknowledgment and acceptance of this Contract. Signatures recorded electronically
      through the company&rsquo;s HR system are shown below together with the signatory&rsquo;s name, role, and the date
      and time the signature was applied.
    </p>

    <div class="doc-sign-grid">
      <div class="doc-sign-block">
        <div class="party">Employer</div>
        <?php if (!empty($contract['employer_signed_at'])): ?>
          <div class="doc-sign-name"><?= e($contract['employer_signed_name']) ?></div>
          <div class="doc-sign-meta">
            <div><strong>Role:</strong> <?= e($contract['employer_signed_role']) ?></div>
            <div><strong>For:</strong> <?= e(strtoupper($company['name'])) ?></div>
            <div><strong>Date Signed:</strong> <?= e(fdate($contract['employer_signed_at'], 'F d, Y \a\t g:i A')) ?></div>
          </div>
          <div class="doc-sign-ref">E-signature ref: <?= e($contract['employer_signature_hash']) ?></div>
        <?php else: ?>
          <div class="doc-sign-name doc-unsigned">&nbsp;</div>
          <div class="doc-sign-meta doc-unsigned">
            <div>Authorized Company Representative</div>
            <div>For: <?= e(strtoupper($company['name'])) ?></div>
            <div>Date Signed: not yet signed</div>
          </div>
        <?php endif; ?>
      </div>

      <div class="doc-sign-block">
        <div class="party">Employee</div>
        <?php if (!empty($contract['employee_signed_at'])): ?>
          <div class="doc-sign-name"><?= e($contract['employee_signed_name']) ?></div>
          <div class="doc-sign-meta">
            <div><strong>Role:</strong> <?= e($contract['employee_signed_role'] ?: $jobTitle) ?></div>
            <div><strong>Date Signed:</strong> <?= e(fdate($contract['employee_signed_at'], 'F d, Y \a\t g:i A')) ?></div>
          </div>
          <div class="doc-sign-ref">E-signature ref: <?= e($contract['employee_signature_hash']) ?></div>
        <?php else: ?>
          <div class="doc-sign-name doc-unsigned">&nbsp;</div>
          <div class="doc-sign-meta doc-unsigned">
            <div><?= e($employeeName) ?></div>
            <div>Position: <?= e($jobTitle) ?></div>
            <div>Date Signed: not yet signed</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="doc-footnote">
    <?php if ($contract): ?>
      This document is Contract No. <strong><?= e($contract['contract_no']) ?></strong> on record with the
      <?= e($company['name']) ?> HR system, generated <?= e(fdate($contract['generated_at'], 'F d, Y')) ?>.
      <?= contractIsFullySigned($contract)
            ? 'Both parties have signed electronically.'
            : 'One or more signatures are still outstanding.' ?>
    <?php else: ?>
      This contract has not yet been assigned a Contract No.
    <?php endif; ?>
  </div>

</div>

<?php if ($canSignAsEmployee || $canSignAsEmployer): ?>
<div class="modal fade" id="signModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="party" value="<?= $canSignAsEmployee ? 'employee' : 'employer' ?>">
      <div class="modal-header">
        <h5 class="modal-title">Sign Contract No. <?= e($contract['contract_no']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">You are signing as:</p>
        <ul class="list-unstyled small mb-3">
          <li><strong>Name:</strong> <?= e($_SESSION['full_name'] ?? '') ?></li>
          <li><strong>Role:</strong>
            <?= $canSignAsEmployee ? e($jobTitle ?: 'Employee') : e(currentRoleName() . ' (Authorized Company Representative)') ?>
          </li>
          <li><strong>Date:</strong> <?= e(date('F d, Y \a\t g:i A')) ?></li>
        </ul>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="confirm_signature" id="confirmSignature" value="1" required>
          <label class="form-check-label small" for="confirmSignature">
            I have read and understood this Contract, and I apply my electronic signature to it. I understand this is
            permanent and will be recorded with my name, role, and the date and time above.
          </label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand"><i class="bi bi-vector-pen"></i> Apply Signature</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
