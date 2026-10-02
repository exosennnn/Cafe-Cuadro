<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole([ROLE_EMPLOYEE_MANAGER]);

// Employee Manager's role in the interview workflow: once the HR Manager
// advances an applicant past the HR Initial Interview, the Final Department
// Interview is auto-created for this department (status PENDING_SCHEDULE,
// no date yet). The Employee Manager sets their own date/time/mode/location
// here, then conducts the interview and gives the final recommendation:
// HIRE or REJECT. HR Staff is not involved in this stage.

$userId = $_SESSION['user_id'];

// Departments this Employee Manager is responsible for
$myEmployee = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id=?");
$myEmployee->execute([$userId]);
$myEmployeeId = $myEmployee->fetchColumn();

$myOwnedDeptIds = [];
if ($myEmployeeId) {
    $deptStmt = $pdo->prepare("SELECT department_id FROM departments WHERE manager_id=?");
    $deptStmt->execute([$myEmployeeId]);
    $myOwnedDeptIds = array_column($deptStmt->fetchAll(), 'department_id');
}

// Departments with no manager assigned yet. When the HR Manager advances an
// applicant for one of these, notifyRole() alerts every Employee Manager
// (see hr_manager/interviews.php) because there's no single owner to notify
// - so every Employee Manager must also be able to see and act on those
// interviews here, or the applicant would be scheduled/notified but never
// actually show up in anyone's queue.
$unassignedDeptStmt = $pdo->query("SELECT department_id FROM departments WHERE manager_id IS NULL");
$unassignedDeptIds = array_column($unassignedDeptStmt->fetchAll(), 'department_id');

$myDeptIds = array_values(array_unique(array_merge($myOwnedDeptIds, $unassignedDeptIds)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? 'evaluate';
    $interviewId = (int)($_POST['interview_id'] ?? 0);

    if ($action === 'schedule') {
        $date = $_POST['schedule_date'] ?? '';
        $location = trim($_POST['location'] ?? '');
        $interviewer = trim($_POST['interviewer'] ?? '');
        $mode = $_POST['mode'] ?? 'ONSITE';
        $errors = [];

        if ($date === '' || strtotime($date) === false) {
            $errors[] = 'Please pick a valid date and time for the interview.';
        } elseif (new DateTime($date) < new DateTime()) {
            $errors[] = 'Interview date must be today or in the future.';
        }

        // Only allow acting on interviews for a job vacancy in a department this manager owns
        $iv = $pdo->prepare("SELECT i.*, ja.application_id, jv.department_id, b.branch_name FROM interviews i
            JOIN job_applications ja ON i.application_id = ja.application_id
            JOIN job_vacancies jv ON ja.job_id = jv.job_id
            LEFT JOIN branches b ON jv.branch_id = b.branch_id
            WHERE i.interview_id=? AND i.stage='FINAL_DEPARTMENT' AND i.status='PENDING_SCHEDULE'");
        $iv->execute([$interviewId]);
        $iv = $iv->fetch();

        if (!$iv || !in_array($iv['department_id'], $myDeptIds, true)) {
            $errors[] = 'Interview not found or not in your department.';
        }

        // The physical venue for an ONSITE interview is fixed to the branch the
        // job vacancy was posted under - it cannot be typed differently here.
        // ONLINE/PHONE interviews use "location" as a meeting link / phone
        // number instead, so it stays freely editable.
        if ($mode === 'ONSITE' && $iv) {
            if (empty($iv['branch_name'])) {
                $errors[] = 'This job vacancy has no branch set, so an onsite venue cannot be fixed. Ask HR to set a branch on the job posting first.';
            } else {
                $location = $iv['branch_name'];
            }
        }

        if (empty($errors)) {
            $pdo->prepare("UPDATE interviews SET schedule_date=?, location=?, interviewer=?, mode=?, status='SCHEDULED' WHERE interview_id=?")
                ->execute([$date, $location, $interviewer, $mode, $interviewId]);
            $pdo->prepare("UPDATE job_applications SET status='FINAL_INTERVIEW_SCHEDULED' WHERE application_id=?")
                ->execute([$iv['application_id']]);
            setFlash('success', 'Final Department Interview scheduled.');
            logAudit($pdo, $userId, 'SCHEDULE_INTERVIEW', 'Recruitment', "application_id={$iv['application_id']} stage=FINAL_DEPARTMENT");

            $info = $pdo->prepare("SELECT u.user_id AS applicant_user_id,
                    COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
                    COALESCE(u.email, a.email) AS applicant_email, jv.title AS job_title
                FROM job_applications ja
                JOIN applicants a ON ja.applicant_id = a.applicant_id
                LEFT JOIN users u ON a.user_id = u.user_id
                JOIN job_vacancies jv ON ja.job_id = jv.job_id
                WHERE ja.application_id=?");
            $info->execute([$iv['application_id']]);
            $info = $info->fetch();

            if ($info) {
                $when = date('M d, Y g:i A', strtotime($date));
                if ($info['applicant_user_id']) {
                    createNotification($pdo, (int)$info['applicant_user_id'], 'Interview Scheduled',
                        "Your Final Department Interview for \"{$info['job_title']}\" is set on $when ($mode).", 'INTERVIEW', $iv['application_id']);
                }
                if (!empty($info['applicant_email'])) {
                    $locationLine = $location !== '' ? "<p><strong>Location:</strong> " . e($location) . "</p>" : '';
                    $bodyHtml = "<p>Hi " . e($info['first_name']) . ",</p>
                        <p>Good news! Your <strong>Final Department Interview</strong> for the <strong>" . e($info['job_title']) . "</strong> position has been scheduled.</p>
                        <p><strong>Date &amp; Time:</strong> $when<br>
                        <strong>Mode:</strong> " . e($mode) . "</p>
                        $locationLine
                        <p>Please be ready ahead of time. If you have questions, coordinate with HR.</p>";
                    sendMail($info['applicant_email'], $info['first_name'] . ' ' . $info['last_name'],
                        'Interview Scheduled - ' . $info['job_title'], mailTemplate('Interview Scheduled', $bodyHtml));
                }
            }
        } else {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        }
        redirect('modules/employee_manager/interviews.php');
    }

    $recommendation = $_POST['recommendation'] ?? '';
    $notes = trim($_POST['evaluation_notes'] ?? '');

    if (!in_array($recommendation, ['HIRE', 'REJECT'], true)) {
        setFlash('error', 'Please select a valid recommendation.');
    } else {
        // Only allow acting on interviews for a job vacancy in a department this manager owns
        $iv = $pdo->prepare("SELECT i.*, jv.department_id FROM interviews i
            JOIN job_applications ja ON i.application_id = ja.application_id
            JOIN job_vacancies jv ON ja.job_id = jv.job_id
            WHERE i.interview_id=? AND i.stage='FINAL_DEPARTMENT'");
        $iv->execute([$interviewId]);
        $iv = $iv->fetch();

        if (!$iv || !in_array($iv['department_id'], $myDeptIds, true)) {
            setFlash('error', 'Interview not found or not in your department.');
        } elseif ($iv['status'] !== 'SCHEDULED' || empty($iv['schedule_date']) || strtotime($iv['schedule_date']) > time()) {
          setFlash('error', 'The Final Department Interview cannot be evaluated before its scheduled date and time.');
        } else {
          $evaluation = $pdo->prepare("UPDATE interviews SET evaluation_notes=?, recommendation=?, evaluated_by=?, evaluated_at=NOW(), status='COMPLETED' WHERE interview_id=? AND recommendation IS NULL AND status='SCHEDULED'");
          $evaluation->execute([$notes, $recommendation, $userId, $interviewId]);
          if ($evaluation->rowCount() !== 1) {
            setFlash('error', 'This interview was already evaluated or is no longer scheduled.');
          } else {
            $newStatus = $recommendation === 'HIRE' ? 'RECOMMENDED_FOR_HIRE' : 'REJECTED';
            $pdo->prepare("UPDATE job_applications SET status=? WHERE application_id=?")->execute([$newStatus, $iv['application_id']]);
            setFlash('success', 'Final recommendation recorded.');
            logAudit($pdo, $userId, 'FINAL_INTERVIEW_EVALUATION', 'Recruitment', "application_id={$iv['application_id']} recommendation=$recommendation");

            $info = $pdo->prepare("SELECT u.user_id AS applicant_user_id,
                    COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
                    jv.title AS job_title
                FROM job_applications ja
                JOIN applicants a ON ja.applicant_id = a.applicant_id
                LEFT JOIN users u ON a.user_id = u.user_id
                JOIN job_vacancies jv ON ja.job_id = jv.job_id
                WHERE ja.application_id=?");
            $info->execute([$iv['application_id']]);
            $info = $info->fetch();

            if ($info) {
                if ($recommendation === 'HIRE') {
                    if ($info['applicant_user_id']) {
                        createNotification($pdo, (int)$info['applicant_user_id'], 'Final Interview Passed',
                            "Great news! You passed the Final Department Interview for \"{$info['job_title']}\". HR will follow up with next steps.",
                            'INTERVIEW', $iv['application_id']);
                    }
                    notifyRole($pdo, ROLE_HR_MANAGER, 'Final Recommendation: HIRE',
                        "{$info['first_name']} {$info['last_name']} ({$info['job_title']}) was recommended for HIRE after the Final Department Interview. Awaiting your approval before a job offer can be sent.",
                        'INTERVIEW', $iv['application_id']);
                } elseif ($info['applicant_user_id']) {
                    createNotification($pdo, (int)$info['applicant_user_id'], 'Application Update',
                        "Thank you for interviewing for \"{$info['job_title']}\". We will not be moving forward with your application at this time.",
                        'INTERVIEW', $iv['application_id']);
                }
            }
              }
        }
    }
    redirect('modules/employee_manager/interviews.php');
}

$toSchedule = [];
$toConduct = [];
$evaluated = [];
if (!empty($myDeptIds)) {
    $placeholders = implode(',', array_fill(0, count($myDeptIds), '?'));

    // Auto-created by the HR Manager's ADVANCE decision - awaiting a date from this manager
    $stmt0 = $pdo->prepare("SELECT i.*, ja.application_id,
        COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
        COALESCE(a.email, u.email) AS email, u.profile_photo, jv.title AS job_title, jv.department_id, b.branch_name
        FROM interviews i
        JOIN job_applications ja ON i.application_id = ja.application_id
        JOIN applicants a ON ja.applicant_id = a.applicant_id
        LEFT JOIN users u ON a.user_id = u.user_id
        JOIN job_vacancies jv ON ja.job_id = jv.job_id
        LEFT JOIN branches b ON jv.branch_id = b.branch_id
        WHERE i.stage = 'FINAL_DEPARTMENT' AND i.status = 'PENDING_SCHEDULE' AND jv.department_id IN ($placeholders)
        ORDER BY i.created_at ASC");
    $stmt0->execute($myDeptIds);
    $toSchedule = $stmt0->fetchAll();

    $stmt = $pdo->prepare("SELECT i.*, ja.application_id,
        COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
        COALESCE(a.email, u.email) AS email, u.profile_photo, jv.title AS job_title, jv.department_id
        FROM interviews i
        JOIN job_applications ja ON i.application_id = ja.application_id
        JOIN applicants a ON ja.applicant_id = a.applicant_id
        LEFT JOIN users u ON a.user_id = u.user_id
        JOIN job_vacancies jv ON ja.job_id = jv.job_id
        WHERE i.stage = 'FINAL_DEPARTMENT' AND i.recommendation IS NULL AND i.status != 'PENDING_SCHEDULE' AND jv.department_id IN ($placeholders)
        ORDER BY i.schedule_date ASC");
    $stmt->execute($myDeptIds);
    $toConduct = $stmt->fetchAll();

    $stmt2 = $pdo->prepare("SELECT i.*, ja.application_id,
        COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
        jv.title AS job_title
        FROM interviews i
        JOIN job_applications ja ON i.application_id = ja.application_id
        JOIN applicants a ON ja.applicant_id = a.applicant_id
        LEFT JOIN users u ON a.user_id = u.user_id
        JOIN job_vacancies jv ON ja.job_id = jv.job_id
        WHERE i.stage = 'FINAL_DEPARTMENT' AND i.recommendation IS NOT NULL AND jv.department_id IN ($placeholders)
        ORDER BY i.evaluated_at DESC LIMIT 20");
    $stmt2->execute($myDeptIds);
    $evaluated = $stmt2->fetchAll();
}

$pageTitle = 'Final Department Interviews';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-person-video3"></i></div>
  <div>
    <h4>Final Department Interviews</h4>
  </div>
</div>

<?php if (empty($myOwnedDeptIds)): ?>
  <div class="alert alert-info">
    You are not currently assigned as the manager of a department, so anything below is for a <strong>department with no manager set</strong> — visible to every Employee Manager until HR assigns an owner. Contact HR to be linked to your department.
  </div>
<?php endif; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4">
  <div class="card-header bg-white"><strong>Awaiting Your Scheduling</strong></div>
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Photo</th><th>Applicant</th><th>Job</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($toSchedule as $i): ?>
        <tr>
          <td><?= renderAvatar($i['profile_photo'], $i['first_name'], $i['last_name'], 36) ?></td>
          <td>
            <?= e($i['first_name'].' '.$i['last_name']) ?><br><small class="text-muted"><?= e($i['email']) ?></small>
            <?php if (!in_array($i['department_id'], $myOwnedDeptIds, true)): ?>
              <br><span class="badge bg-warning text-dark mt-1" title="This department has no manager assigned yet, so it's shared with every Employee Manager.">Unassigned Dept.</span>
            <?php endif; ?>
          </td>
          <td><?= e($i['job_title']) ?></td>
          <td><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#scheduleModal<?= $i['interview_id'] ?>"><i class="bi bi-calendar-plus"></i> Set Schedule</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($toSchedule)): ?><tr><td colspan="4" class="text-center text-muted">Nothing waiting on your scheduling right now.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modals live outside the <table> - a <div> placed inside <tbody> isn't
     valid HTML and browsers silently relocate it, which can leave the modal
     detached from Bootstrap's controls (opens, but won't accept input or
     close without a refresh). -->
<?php foreach ($toSchedule as $i): ?>
  <div class="modal fade" id="scheduleModal<?= $i['interview_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="schedule">
        <input type="hidden" name="interview_id" value="<?= $i['interview_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Schedule Final Interview — <?= e($i['first_name'].' '.$i['last_name']) ?></h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Date & Time</label><input type="datetime-local" name="schedule_date" class="form-control" required min="<?= date('Y-m-d\TH:i') ?>"></div>
          <div class="row">
            <div class="col-md-6 mb-3">
              <label class="form-label">Mode</label>
              <select name="mode" class="form-select emi-mode-select" onchange="emiSyncLocation(this)">
                <option value="ONSITE">Onsite</option><option value="ONLINE">Online</option><option value="PHONE">Phone</option>
              </select>
            </div>
            <div class="col-md-6 mb-3"><label class="form-label">Interviewer</label><input type="text" name="interviewer" class="form-control" placeholder="Name of interviewer"></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Location / Meeting Link</label>
            <input type="text" name="location" class="form-control emi-location-field" data-branch="<?= e($i['branch_name'] ?? '') ?>">
            <div class="form-text emi-location-hint"></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Schedule</button></div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>

<div class="card border-0 shadow-sm rounded-3 mb-4">
  <div class="card-header bg-white"><strong>Awaiting Final Interview</strong></div>
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Photo</th><th>Applicant</th><th>Job</th><th>Schedule</th><th>Mode / Location</th><th>Interviewer</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($toConduct as $i): ?>
        <tr>
          <td><?= renderAvatar($i['profile_photo'], $i['first_name'], $i['last_name'], 36) ?></td>
          <td>
            <?= e($i['first_name'].' '.$i['last_name']) ?><br><small class="text-muted"><?= e($i['email']) ?></small>
            <?php if (!in_array($i['department_id'], $myOwnedDeptIds, true)): ?>
              <br><span class="badge bg-warning text-dark mt-1" title="This department has no manager assigned yet, so it's shared with every Employee Manager.">Unassigned Dept.</span>
            <?php endif; ?>
          </td>
          <td><?= e($i['job_title']) ?></td>
          <td><?= date('M d, Y g:i A', strtotime($i['schedule_date'])) ?></td>
          <td><?= e($i['mode']) ?> <?= $i['location'] ? '(' . e($i['location']) . ')' : '' ?></td>
          <td><?= e($i['interviewer']) ?></td>
          <td><button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#evalModal<?= $i['interview_id'] ?>"><i class="bi bi-clipboard-check"></i> Evaluate</button></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($toConduct)): ?><tr><td colspan="7" class="text-center text-muted">No final interviews awaiting evaluation.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php foreach ($toConduct as $i): ?>
  <div class="modal fade" id="evalModal<?= $i['interview_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="evaluate">
        <input type="hidden" name="interview_id" value="<?= $i['interview_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Final Evaluation — <?= e($i['first_name'].' '.$i['last_name']) ?></h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Final Evaluation Notes</label>
            <textarea name="evaluation_notes" class="form-control" rows="4" placeholder="Technical fit, department needs, team fit, etc."></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Final Recommendation</label>
            <select name="recommendation" class="form-select" required>
              <option value="">-- Select --</option>
              <option value="HIRE">Hire</option>
              <option value="REJECT">Reject</option>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Save Recommendation</button></div>
      </form>
    </div></div>
  </div>
<?php endforeach; ?>


<div class="card border-0 shadow-sm rounded-3">
  <div class="card-header bg-white"><strong>Recommendation History</strong></div>
  <div class="card-body table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>Applicant</th><th>Job</th><th>Recommendation</th><th>Notes</th><th>Evaluated</th></tr></thead>
      <tbody>
      <?php foreach ($evaluated as $i): ?>
        <tr>
          <td><?= e($i['first_name'].' '.$i['last_name']) ?></td>
          <td><?= e($i['job_title']) ?></td>
          <td><span class="badge <?= $i['recommendation']==='HIRE'?'bg-success':'bg-danger' ?>"><?= e($i['recommendation']) ?></span></td>
          <td class="small"><?= e($i['evaluation_notes']) ?></td>
          <td><?= $i['evaluated_at'] ? date('M d, Y g:i A', strtotime($i['evaluated_at'])) : '-' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($evaluated)): ?><tr><td colspan="5" class="text-center text-muted">No recommendations recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function emiSyncLocation(modeSel) {
  const body = modeSel.closest('.modal-body');
  const locField = body.querySelector('.emi-location-field');
  const hint = body.querySelector('.emi-location-hint');
  const branch = locField.dataset.branch || '';

  if (modeSel.value === 'ONSITE') {
    locField.value = branch;
    locField.readOnly = true;
    hint.textContent = branch
      ? 'Fixed to the branch this job was posted under - the venue cannot be changed here.'
      : 'This job has no branch set - ask HR to assign one before scheduling onsite.';
  } else {
    locField.readOnly = false;
    if (locField.dataset.wasAuto === '1') { locField.value = ''; }
    hint.textContent = 'Enter a meeting link or phone number for this ' + modeSel.value.toLowerCase() + ' interview.';
  }
  locField.dataset.wasAuto = (modeSel.value === 'ONSITE') ? '1' : '0';
}
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.emi-mode-select').forEach(emiSyncLocation);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>