<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/requests.php';
requireRole([ROLE_EMPLOYEE, ROLE_CASHIER, ROLE_INVENTORY_STAFF, ROLE_FINANCE_STAFF]);

/**
 * MY REQUESTS - employees file requests and track where they are in the route.
 *
 * Every request enters the same approval flow the system already uses:
 * Employee -> Employee Manager -> HR Staff -> HR Manager (final approval).
 */

$userId     = (int)$_SESSION['user_id'];
$employeeId = employeeIdForUser($pdo, $userId);
$actorName  = trim((string)($_SESSION['full_name'] ?? ''));
$actorRole  = currentRoleName();
$today      = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();

    if ($employeeId < 1) {
        setFlash('error', 'Your employee record could not be found, so requests cannot be filed.');
        redirect('modules/employee/requests.php');
    }

    $action = $_POST['action'] ?? '';

    // -----------------------------------------------------------------------
    // Withdraw a still-pending request
    // -----------------------------------------------------------------------
    if ($action === 'cancel') {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        if ($requestId === false || $requestId < 1) {
            setFlash('error', 'Invalid request.');
        } else {
            $reasonIn = $_POST['cancel_reason'] ?? '';
            $result = cancelRequestForm($pdo, $requestId, $employeeId, $userId, $actorName, $actorRole,
                is_string($reasonIn) ? trim($reasonIn) : '');
            setFlash($result['ok'] ? 'success' : 'error', $result['message']);
            if ($result['ok']) {
                logAudit($pdo, $userId, 'CANCEL_REQUEST_FORM', 'Request Forms', "request_id=$requestId");
            }
        }
        redirect('modules/employee/requests.php');
    }

    // -----------------------------------------------------------------------
    // File a new request
    // -----------------------------------------------------------------------
    if ($action === 'file') {
        $type = $_POST['request_type'] ?? '';
        $subjectIn = $_POST['subject'] ?? '';
        $detailsIn = $_POST['details'] ?? '';
        $subject = is_string($subjectIn) ? trim($subjectIn) : '';
        $details = is_string($detailsIn) ? trim($detailsIn) : '';

        $errors = [];
        $fields = ['subject' => $subject, 'details' => $details ?: null];

        if (!isset(requestTypes()[$type])) {
            $errors[] = 'Please choose a valid request type.';
        }
        if ($subject === '' || strlen($subject) > 200) {
            $errors[] = 'Subject is required and must be 200 characters or fewer.';
        }
        if (strlen($details) > 5000) {
            $errors[] = 'Details must be 5000 characters or fewer.';
        }

        // --- Type-specific validation ---
        if ($type === 'LEAVE') {
            $leaveTypeId = filter_var($_POST['leave_type_id'] ?? null, FILTER_VALIDATE_INT);
            $from = is_string($_POST['date_from'] ?? null) ? trim($_POST['date_from']) : '';
            $to   = is_string($_POST['date_to'] ?? null) ? trim($_POST['date_to']) : '';
            $fromDate = DateTime::createFromFormat('!Y-m-d', $from);
            $toDate   = DateTime::createFromFormat('!Y-m-d', $to);
            $validFrom = $fromDate && $fromDate->format('Y-m-d') === $from;
            $validTo   = $toDate && $toDate->format('Y-m-d') === $to;

            if ($leaveTypeId === false || $leaveTypeId < 1) {
                $errors[] = 'Please select a leave type.';
            } else {
                $check = $pdo->prepare('SELECT leave_type_id FROM leave_types WHERE leave_type_id = ?');
                $check->execute([$leaveTypeId]);
                if (!$check->fetchColumn()) {
                    $errors[] = 'The selected leave type does not exist.';
                }
            }
            if (!$validFrom || !$validTo) {
                $errors[] = 'Leave dates must be valid calendar dates.';
            } elseif ($from < $today) {
                $errors[] = 'Leave dates must be today or later.';
            } elseif ($toDate < $fromDate) {
                $errors[] = 'The end date cannot be earlier than the start date.';
            } else {
                // Don't let the same days be booked twice. `leave_requests` is
                // the canonical leave record - every leave filed here has a row
                // there - so checking it alone covers leave filed through this
                // module and leave filed before the modules were merged.
                $clash = $pdo->prepare("SELECT lr.leave_id, rf.request_no
                    FROM leave_requests lr
                    LEFT JOIN request_forms rf ON rf.leave_id = lr.leave_id
                    WHERE lr.employee_id = ?
                      AND lr.status IN ('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL','APPROVED')
                      AND lr.date_from <= ? AND lr.date_to >= ? LIMIT 1");
                $clash->execute([$employeeId, $to, $from]);
                if ($clashing = $clash->fetch()) {
                    $errors[] = 'Those dates overlap leave you have already filed'
                        . (!empty($clashing['request_no']) ? ' (' . $clashing['request_no'] . ')' : '') . '.';
                }

                $fields['date_from']  = $from;
                $fields['date_to']    = $to;
                $fields['total_days'] = $fromDate->diff($toDate)->days + 1;
            }
            $fields['leave_type_id'] = $leaveTypeId ?: null;

        } elseif ($type === 'OVERTIME') {
            $otDate = is_string($_POST['ot_date'] ?? null) ? trim($_POST['ot_date']) : '';
            $timeFrom = is_string($_POST['ot_time_from'] ?? null) ? trim($_POST['ot_time_from']) : '';
            $timeTo   = is_string($_POST['ot_time_to'] ?? null) ? trim($_POST['ot_time_to']) : '';
            $dateObj = DateTime::createFromFormat('!Y-m-d', $otDate);

            if (!$dateObj || $dateObj->format('Y-m-d') !== $otDate) {
                $errors[] = 'Please give a valid overtime date.';
            }
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeFrom) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeTo)) {
                $errors[] = 'Overtime start and end times must be valid times.';
            } else {
                // An overtime window may legitimately cross midnight (the
                // graveyard shift does), so wrap instead of rejecting.
                $start = new DateTime('2000-01-01 ' . $timeFrom);
                $end   = new DateTime('2000-01-01 ' . $timeTo);
                if ($end <= $start) {
                    $end->modify('+1 day');
                }
                $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
                if ($hours <= 0 || $hours > 12) {
                    $errors[] = 'Overtime must be more than 0 and no more than 12 hours.';
                } else {
                    $fields['ot_time_from'] = $timeFrom;
                    $fields['ot_time_to']   = $timeTo;
                    $fields['ot_hours']     = round($hours, 2);
                }
            }
            $fields['ot_date'] = $otDate ?: null;

        } elseif ($type === 'DOCUMENT') {
            $docType = is_string($_POST['document_type'] ?? null) ? trim($_POST['document_type']) : '';
            $copies  = filter_var($_POST['copies'] ?? null, FILTER_VALIDATE_INT);
            $neededBy = is_string($_POST['needed_by'] ?? null) ? trim($_POST['needed_by']) : '';
            $purposeIn = $_POST['purpose'] ?? '';
            $purpose = is_string($purposeIn) ? trim($purposeIn) : '';

            if ($docType === '' || !in_array($docType, requestDocumentTypes($pdo), true)) {
                $errors[] = 'Please choose a document or certificate from the list.';
            }
            if ($copies === false || $copies < 1 || $copies > 20) {
                $errors[] = 'Number of copies must be between 1 and 20.';
            }
            if ($neededBy !== '') {
                $neededDate = DateTime::createFromFormat('!Y-m-d', $neededBy);
                if (!$neededDate || $neededDate->format('Y-m-d') !== $neededBy) {
                    $errors[] = 'Needed-by must be a valid date.';
                } elseif ($neededBy < $today) {
                    $errors[] = 'Needed-by cannot be in the past.';
                }
            }
            if (strlen($purpose) > 255) {
                $errors[] = 'Purpose must be 255 characters or fewer.';
            }

            $fields['document_type'] = $docType;
            $fields['copies']        = $copies ?: null;
            $fields['needed_by']     = $neededBy ?: null;
            $fields['purpose']       = $purpose ?: null;

        } elseif ($type === 'GENERAL') {
            if ($details === '') {
                $errors[] = 'Please describe your request in the details box.';
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        } else {
            try {
                $created = createRequestForm($pdo, $employeeId, $type, $fields, $userId, $actorName, $actorRole);
                setFlash('success', 'Request ' . $created['request_no'] . ' submitted for approval.');
                logAudit($pdo, $userId, 'FILE_REQUEST_FORM', 'Request Forms',
                    'request_no=' . $created['request_no'] . ' type=' . $type);
            } catch (Throwable $exception) {
                error_log('employee request filing error: ' . $exception->getMessage());
                setFlash('error', 'Your request could not be submitted. Please try again.');
            }
        }
        redirect('modules/employee/requests.php');
    }

    redirect('modules/employee/requests.php');
}

// ---------------------------------------------------------------------------
// My requests
// ---------------------------------------------------------------------------
$myRequests = [];
if ($employeeId > 0) {
    $stmt = $pdo->prepare("SELECT rf.*, lt.type_name AS leave_type_name
        FROM request_forms rf
        LEFT JOIN leave_types lt ON rf.leave_type_id = lt.leave_type_id
        WHERE rf.employee_id = ?
        ORDER BY rf.filed_at DESC");
    $stmt->execute([$employeeId]);
    $myRequests = $stmt->fetchAll();
}

$leaveTypes    = $pdo->query("SELECT * FROM leave_types ORDER BY type_name")->fetchAll();
$documentTypes = requestDocumentTypes($pdo);
$workflow      = requestWorkflow();

$pageTitle = 'My Requests';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-journal-text"></i></div>
    <div>
      <h4>My Requests</h4>
    </div>
  </div>
  <?php if ($employeeId > 0): ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal">
      <i class="bi bi-plus-circle"></i> File a Request
    </button>
  <?php endif; ?>
</div>

<?php if ($employeeId < 1): ?>
  <div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i>
    Your employee record could not be found, so requests cannot be filed. Please contact HR.
  </div>
<?php endif; ?>


<div class="card">
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle" id="myRequestsTable">
      <thead>
        <tr>
          <th>Request No.</th>
          <th>Type</th>
          <th>Subject</th>
          <th>Details</th>
          <th>Filed</th>
          <th>Status</th>
          <th>Now With</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($myRequests as $r): ?>
        <?php
          $summary = requestTypeSummary($r);
          $pendingStep = requestStepForStatus($r['status']);
        ?>
        <tr>
          <td class="text-nowrap"><strong><?= e($r['request_no']) ?></strong></td>
          <td class="text-nowrap"><i class="bi <?= e(requestTypeIcon($r['request_type'])) ?>"></i> <?= e(requestTypeLabel($r['request_type'])) ?></td>
          <td><?= e($r['subject']) ?></td>
          <td class="small">
            <?php foreach ($summary as $label => $value): ?>
              <div><span class="text-muted"><?= e($label) ?>:</span> <?= e($value) ?></div>
            <?php endforeach; ?>
          </td>
          <td class="text-nowrap small"><?= e(fdate($r['filed_at'])) ?></td>
          <td><span class="badge bg-<?= e(requestStatusColor($r['status'])) ?>"><?= e(requestStatusLabel($r['status'])) ?></span></td>
          <td class="small">
            <?= $pendingStep ? e($workflow[$pendingStep]['role_label']) : '<span class="text-muted">&mdash;</span>' ?>
          </td>
          <td class="text-nowrap">
            <a href="<?= BASE_URL ?>shared/request-view?request_id=<?= (int)$r['request_id'] ?>"
               class="btn btn-sm btn-outline-dark" title="Open request document"><i class="bi bi-file-earmark-text"></i></a>
            <?php if (requestIsOpen($r['status'])): ?>
              <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal<?= (int)$r['request_id'] ?>">
                <i class="bi bi-x-circle"></i>
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($myRequests)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">
          <i class="bi bi-journal d-block fs-3 mb-2 opacity-50"></i>You haven&rsquo;t filed any requests yet.
        </td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($myRequests as $r): ?>
  <?php if (!requestIsOpen($r['status'])) continue; ?>
  <div class="modal fade" id="cancelModal<?= (int)$r['request_id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="request_id" value="<?= (int)$r['request_id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Withdraw <?= e($r['request_no']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p>This ends the request. It stays on record, along with the reason you give here.</p>
          <label class="form-label">Reason (optional)</label>
          <textarea name="cancel_reason" class="form-control" rows="2" maxlength="2000"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Keep Request</button>
          <button type="submit" class="btn btn-danger">Withdraw Request</button>
        </div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>

<!-- ---------------------------------------------------------------------- -->
<!-- File a request                                                          -->
<!-- The type selector shows only that type's fields; each panel's inputs    -->
<!-- are disabled while hidden so the browser never validates or submits     -->
<!-- fields belonging to a type the employee didn't pick.                    -->
<!-- ---------------------------------------------------------------------- -->
<div class="modal fade" id="requestModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <form method="POST" id="requestForm">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="action" value="file">
      <div class="modal-header">
        <h5 class="modal-title">File a Request</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">

        <div class="mb-3">
          <label class="form-label">Request Type</label>
          <select name="request_type" id="requestType" class="form-select" required>
            <option value="">-- Select a request type --</option>
            <?php foreach (requestTypes() as $code => $meta): ?>
              <option value="<?= e($code) ?>"><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Subject</label>
          <input type="text" name="subject" class="form-control" maxlength="200" required
                 placeholder="A short title for this request">
        </div>

        <!-- LEAVE -->
        <div class="type-panel" data-type="LEAVE" hidden>
          <div class="mb-3">
            <label class="form-label">Leave Type</label>
            <select name="leave_type_id" class="form-select" disabled>
              <?php foreach ($leaveTypes as $t): ?>
                <option value="<?= (int)$t['leave_type_id'] ?>"><?= e($t['type_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">From</label>
              <input type="date" name="date_from" class="form-control" min="<?= e($today) ?>" disabled>
            </div>
            <div class="col-md-6 mb-3">
              <label class="form-label">To</label>
              <input type="date" name="date_to" class="form-control" min="<?= e($today) ?>" disabled>
            </div>
          </div>
        </div>

        <!-- OVERTIME -->
        <div class="type-panel" data-type="OVERTIME" hidden>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Date of Overtime</label>
              <input type="date" name="ot_date" class="form-control" disabled>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Start Time</label>
              <input type="time" name="ot_time_from" class="form-control" disabled>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">End Time</label>
              <input type="time" name="ot_time_to" class="form-control" disabled>
            </div>
          </div>
          <p class="form-text">
            Overtime crossing midnight is fine &mdash; enter the actual clock times and the hours are worked out for you.
          </p>
        </div>

        <!-- DOCUMENT -->
        <div class="type-panel" data-type="DOCUMENT" hidden>
          <div class="mb-3">
            <label class="form-label">Document / Certificate</label>
            <select name="document_type" class="form-select" disabled>
              <?php foreach ($documentTypes as $docType): ?>
                <option value="<?= e($docType) ?>"><?= e($docType) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <div class="col-md-4 mb-3">
              <label class="form-label">Copies</label>
              <input type="number" name="copies" class="form-control" min="1" max="20" value="1" disabled>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Needed By</label>
              <input type="date" name="needed_by" class="form-control" min="<?= e($today) ?>" disabled>
            </div>
            <div class="col-md-4 mb-3">
              <label class="form-label">Purpose</label>
              <input type="text" name="purpose" class="form-control" maxlength="255" disabled
                     placeholder="e.g. loan application">
            </div>
          </div>
        </div>

        <div class="mb-1">
          <label class="form-label">Details / Reason</label>
          <textarea name="details" id="requestDetails" class="form-control" rows="4" maxlength="5000"
                    placeholder="Explain your request. This is shown to every approver."></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Submit Request</button>
      </div>
    </form>
  </div></div>
</div>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $.fn.dataTable.ext.errMode = 'none';
  $('#myRequestsTable').DataTable({ order: [], columnDefs: [{ orderable: false, targets: -1 }] });
});

(function () {
  var typeSelect = document.getElementById('requestType');
  var panels = document.querySelectorAll('#requestForm .type-panel');
  var details = document.getElementById('requestDetails');
  if (!typeSelect) { return; }

  // Required fields per type. Server-side validation is the real gate; this
  // just stops the employee submitting an obviously incomplete form.
  var requiredByType = {
    LEAVE:    ['leave_type_id', 'date_from', 'date_to'],
    OVERTIME: ['ot_date', 'ot_time_from', 'ot_time_to'],
    DOCUMENT: ['document_type', 'copies'],
    GENERAL:  []
  };

  function applyType() {
    var chosen = typeSelect.value;
    panels.forEach(function (panel) {
      var active = panel.dataset.type === chosen;
      panel.hidden = !active;
      panel.querySelectorAll('input, select, textarea').forEach(function (field) {
        // Disabled fields are not submitted, so a hidden panel can never
        // send stale values for a type the employee didn't choose.
        field.disabled = !active;
        field.required = active && (requiredByType[chosen] || []).indexOf(field.name) !== -1;
      });
    });
    details.required = (chosen === 'GENERAL');
  }

  typeSelect.addEventListener('change', applyType);
  applyType();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
