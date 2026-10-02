<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];
$canScheduleInterview = currentRoleId() === ROLE_HR_STAFF;
$interviewers = $pdo->prepare("SELECT user_id, first_name, last_name FROM users WHERE role_id=? AND status='ACTIVE' ORDER BY last_name, first_name");
$interviewers->execute([ROLE_HR_STAFF]);
$interviewers = $interviewers->fetchAll();
$statuses = ['SUBMITTED','UNDER_REVIEW','SHORTLISTED','INTERVIEW_SCHEDULED','RECOMMENDED_FOR_HIRE','HIRE_APPROVED','OFFERED','ACCEPTED','DECLINED','REJECTED','HIRED'];
$manualStatusTransitions = [
  'SUBMITTED' => ['SUBMITTED', 'UNDER_REVIEW', 'REJECTED'],
  'UNDER_REVIEW' => ['UNDER_REVIEW', 'SHORTLISTED', 'REJECTED'],
  'SHORTLISTED' => ['SHORTLISTED', 'REJECTED'],
  'INTERVIEW_SCHEDULED' => ['INTERVIEW_SCHEDULED', 'REJECTED'],
  'OFFERED' => ['OFFERED', 'ACCEPTED', 'DECLINED'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    $appId = filter_var($_POST['application_id'] ?? null, FILTER_VALIDATE_INT);
    if ($appId === false || $appId < 1) {
      setFlash('error', 'Invalid application.');
      redirect('modules/hr_staff/applications.php');
    }

    if ($action === 'schedule_interview') {
      $interviewerId = filter_var($_POST['interviewer_id'] ?? null, FILTER_VALIDATE_INT);
      $scheduleDate = $_POST['interview_date'] ?? '';
      $scheduleTime = $_POST['interview_time'] ?? '';
      $parsedDate = is_string($scheduleDate) ? DateTime::createFromFormat('!Y-m-d', $scheduleDate) : false;
      $dateValid = $parsedDate && $parsedDate->format('Y-m-d') === $scheduleDate;
      $timeValid = is_string($scheduleTime) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $scheduleTime);

      if (!$canScheduleInterview) {
        setFlash('error', 'Only HR Staff can schedule HR Initial Interviews.');
      } elseif ($interviewerId === false || $interviewerId < 1 || !$dateValid || !$timeValid) {
        setFlash('error', 'Select a valid HR Staff interviewer, interview date, and interview time.');
      } elseif (strtotime($scheduleDate . ' ' . $scheduleTime) <= time()) {
        setFlash('error', 'The interview date and time must be in the future.');
      } else {
        $scheduleDateTime = $scheduleDate . ' ' . $scheduleTime . ':00';
        $scheduledInterview = null;
        try {
          $pdo->beginTransaction();
          $mutex = $pdo->prepare("SELECT user_id FROM users WHERE role_id=? AND status='ACTIVE' ORDER BY user_id LIMIT 1 FOR UPDATE");
          $mutex->execute([ROLE_HR_STAFF]);
          if (!$mutex->fetch()) {
            throw new RuntimeException('No active HR Staff account is available.');
          }

          $interviewerStmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id=? AND role_id=? AND status='ACTIVE' FOR UPDATE");
          $interviewerStmt->execute([$interviewerId, ROLE_HR_STAFF]);
          $interviewerRow = $interviewerStmt->fetch();
          if (!$interviewerRow) {
            throw new RuntimeException('The selected interviewer is not an active HR Staff member.');
          }
          $interviewer = trim($interviewerRow['first_name'] . ' ' . $interviewerRow['last_name']);

          $applicationStmt = $pdo->prepare("SELECT ja.status, ja.applicant_id,
              COALESCE(a.first_name, u.first_name) AS first_name,
              COALESCE(a.last_name, u.last_name) AS last_name,
              COALESCE(u.email, a.email) AS applicant_email, u.user_id AS applicant_user_id,
              jv.title AS job_title, b.branch_name
            FROM job_applications ja
            JOIN applicants a ON ja.applicant_id=a.applicant_id
            LEFT JOIN users u ON a.user_id=u.user_id
            JOIN job_vacancies jv ON ja.job_id=jv.job_id
            LEFT JOIN branches b ON jv.branch_id=b.branch_id
            WHERE ja.application_id=? FOR UPDATE");
          $applicationStmt->execute([$appId]);
          $application = $applicationStmt->fetch();
          if (!$application || $application['status'] !== 'SHORTLISTED') {
            throw new RuntimeException('Only shortlisted applications can be scheduled for an HR Initial Interview.');
          }
          if (empty($application['branch_name'])) {
            throw new RuntimeException('The job posting has no branch assigned, so an interview location cannot be set.');
          }

          $existingInterview = $pdo->prepare("SELECT interview_id FROM interviews WHERE application_id=? AND stage='HR_INITIAL' LIMIT 1 FOR UPDATE");
          $existingInterview->execute([$appId]);
          if ($existingInterview->fetch()) {
            throw new RuntimeException('An HR Initial Interview already exists for this application.');
          }

          $occupied = $pdo->prepare("SELECT interview_id FROM interviews WHERE stage='HR_INITIAL' AND status <> 'CANCELLED' AND schedule_date=? FOR UPDATE");
          $occupied->execute([$scheduleDateTime]);
          if ($occupied->fetch()) {
            throw new RuntimeException('That interview date and time is already scheduled. Please choose another slot.');
          }

          $pdo->prepare("INSERT INTO interviews (application_id, stage, schedule_date, location, interviewer, mode, status, created_by)
            VALUES (?, 'HR_INITIAL', ?, ?, ?, 'ONSITE', 'SCHEDULED', ?)")
            ->execute([$appId, $scheduleDateTime, $application['branch_name'], $interviewer, $userId]);
          $update = $pdo->prepare("UPDATE job_applications SET status='INTERVIEW_SCHEDULED' WHERE application_id=? AND status='SHORTLISTED'");
          $update->execute([$appId]);
          if ($update->rowCount() !== 1) {
            throw new RuntimeException('The application status changed before scheduling could be completed.');
          }
          $pdo->commit();
          $scheduledInterview = $application;
        } catch (Throwable $exception) {
          if ($pdo->inTransaction()) { $pdo->rollBack(); }
          error_log('HR Initial interview scheduling failed: ' . $exception->getMessage());
          setFlash('error', $exception->getMessage());
        }

        if ($scheduledInterview) {
          $when = date('M d, Y g:i A', strtotime($scheduleDateTime));
          try {
            if ($scheduledInterview['applicant_user_id']) {
              createNotification($pdo, (int)$scheduledInterview['applicant_user_id'], 'Interview Scheduled',
                "Your HR Initial Interview for \"{$scheduledInterview['job_title']}\" is set on $when (ONSITE).", 'INTERVIEW', $appId);
            }
            if (!empty($scheduledInterview['applicant_email'])) {
              sendMail($scheduledInterview['applicant_email'], $scheduledInterview['first_name'] . ' ' . $scheduledInterview['last_name'],
                'Interview Scheduled - ' . $scheduledInterview['job_title'], mailTemplate('Interview Scheduled',
                  '<p>Hi ' . e($scheduledInterview['first_name']) . ',</p><p>Your <strong>HR Initial Interview</strong> for <strong>' . e($scheduledInterview['job_title']) . '</strong> is scheduled for <strong>' . e($when) . '</strong>.</p><p><strong>Location:</strong> ' . e($scheduledInterview['branch_name']) . '</p>'));
            }
            notifyRole($pdo, ROLE_HR_MANAGER, 'HR Interview Scheduled',
              "{$scheduledInterview['first_name']} {$scheduledInterview['last_name']} ({$scheduledInterview['job_title']}) is scheduled for HR Initial Interview on $when.", 'INTERVIEW', $appId);
          } catch (Throwable $notificationException) {
            error_log('HR Initial interview notification failed: ' . $notificationException->getMessage());
          }
          setFlash('success', 'Interview scheduled for ' . $when . '.');
          logAudit($pdo, $userId, 'SCHEDULE_INTERVIEW', 'Recruitment', "application_id=$appId schedule=$scheduleDateTime interviewer_id=$interviewerId");
        }
      }
    } elseif ($action === 'update_status') {
      $status = $_POST['status'] ?? '';
      $remarksInput = $_POST['remarks'] ?? '';
      $remarks = is_string($remarksInput) ? trim($remarksInput) : '';
      $currentStmt = $pdo->prepare('SELECT status FROM job_applications WHERE application_id=?');
      $currentStmt->execute([$appId]);
      $currentStatus = $currentStmt->fetchColumn();
      if ($currentStatus === false) {
        setFlash('error', 'Application not found.');
      } elseif (!in_array($status, $statuses, true) || !isset($manualStatusTransitions[$currentStatus]) || !in_array($status, $manualStatusTransitions[$currentStatus], true)) {
        setFlash('error', 'That status change is not allowed from the current recruitment stage.');
      } elseif (strlen($remarks) > 2000 || preg_match('/[\x00-\x1F\x7F]/', $remarks)) {
        setFlash('error', 'Remarks must be 2000 characters or fewer and cannot contain control characters.');
      } elseif ($status === 'REJECTED' && $remarks === '') {
        setFlash('error', 'Remarks are required when rejecting an application.');
      } else {
        $update = $pdo->prepare("UPDATE job_applications SET status=?, remarks=? WHERE application_id=? AND status=?");
        $update->execute([$status, $remarks, $appId, $currentStatus]);
        if ($update->rowCount() === 1) {
          setFlash('success', 'Application status updated.');
          logAudit($pdo, $userId, 'UPDATE_APP_STATUS', 'Recruitment', "application_id=$appId status=$status");
        } else {
          setFlash('error', 'The application status changed before your update could be saved.');
        }
      }
    } elseif ($action === 'create_offer') {
        // Guard: the HR Manager must have approved hiring first (see
        // modules/hr_manager/hiring_approvals.php). This is the real
        // approval gate between the final interview recommendation and
        // the job offer.
        $salaryInput = $_POST['offered_salary'] ?? '';
        $detailsInput = $_POST['offer_details'] ?? '';
        $offerDate = $_POST['offer_date'] ?? date('Y-m-d');
        $employmentType = $_POST['employment_type'] ?? '';
        $details = is_string($detailsInput) ? trim($detailsInput) : '';
        $offerDateValid = is_string($offerDate) && DateTime::createFromFormat('!Y-m-d', $offerDate) !== false && DateTime::createFromFormat('!Y-m-d', $offerDate)->format('Y-m-d') === $offerDate;
        $salaryValid = is_string($salaryInput) && preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $salaryInput) && (float)$salaryInput > 0;
        if (!$salaryValid) {
          setFlash('error', 'Offered salary must be a positive amount with at most two decimals.');
        } elseif (strlen($details) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $details)) {
          setFlash('error', 'Offer details must be 255 characters or fewer and cannot contain control characters.');
        } elseif (!$offerDateValid) {
          setFlash('error', 'Offer date must be a valid YYYY-MM-DD date.');
        } elseif (!in_array($employmentType, EMPLOYMENT_TYPES, true)) {
          setFlash('error', 'Please select a valid employment type.');
        } else {
          $salary = (float)$salaryInput;
          $offerDate = (string)$offerDate;
            $employmentType = $_POST['employment_type'] ?? 'PROBATIONARY';
            // Note: shift is intentionally NOT set here. Scheduling (Morning/
            // Afternoon/Graveyard) is a manual assignment made by the HR
            // Manager after the employee is hired, not chosen at offer time.
            try {
              $pdo->beginTransaction();
              $statusCheck = $pdo->prepare("SELECT status FROM job_applications WHERE application_id=? FOR UPDATE");
              $statusCheck->execute([$appId]);
              if ($statusCheck->fetchColumn() !== 'HIRE_APPROVED') {
                throw new RuntimeException('This applicant\'s hiring has not been approved by the HR Manager yet.');
              }
              $pdo->prepare("INSERT INTO job_offers (application_id, offered_salary, employment_type, remarks, offer_date, created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$appId, $salary, $employmentType, $details, $offerDate, $userId]);
              $offerUpdate = $pdo->prepare("UPDATE job_applications SET status='OFFERED' WHERE application_id=? AND status='HIRE_APPROVED'");
              $offerUpdate->execute([$appId]);
              if ($offerUpdate->rowCount() !== 1) {
                throw new RuntimeException('The application is no longer ready for an offer.');
              }
              $pdo->commit();
            } catch (Throwable $exception) {
              if ($pdo->inTransaction()) { $pdo->rollBack(); }
              setFlash('error', 'Job offer could not be created. No changes were made.');
              redirect('modules/hr_staff/applications.php');
            }
            setFlash('success', 'Job offer sent to applicant.');
            logAudit($pdo, $userId, 'CREATE_OFFER', 'Recruitment', "application_id=$appId");

            // Notify the applicant in-app (if they have an account) and, most
            // importantly, email them directly at the address on file - the
            // employment contract PDF is generated fresh and attached so they
            // have everything (offer + contract) in one message.
            $info = $pdo->prepare("SELECT u.user_id AS applicant_user_id,
                    COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
                    COALESCE(u.email, a.email) AS applicant_email, jv.title AS job_title
                FROM job_applications ja
                JOIN applicants a ON ja.applicant_id = a.applicant_id
                LEFT JOIN users u ON a.user_id = u.user_id
                JOIN job_vacancies jv ON ja.job_id = jv.job_id
                WHERE ja.application_id=?");
            $info->execute([$appId]);
            $info = $info->fetch();

            if ($info) {
                if ($info['applicant_user_id']) {
                    createNotification($pdo, (int)$info['applicant_user_id'], 'Job Offer Sent',
                        "You have a new job offer for \"{$info['job_title']}\". Check your email and My Applications to review and respond.",
                        'INTERVIEW', $appId);
                }

                if (!empty($info['applicant_email'])) {
                    $employmentTypeLabel = EMPLOYMENT_TYPE_LABELS[$employmentType] ?? str_replace('_', ' ', $employmentType);
                    $detailsLine = $details !== '' ? '<p><strong>Additional Terms &amp; Benefits:</strong><br>' . nl2br(e($details)) . '</p>' : '';
                    $bodyHtml = "<p>Hi " . e($info['first_name']) . ",</p>
                        <p>Congratulations! We're pleased to offer you the <strong>" . e($info['job_title']) . "</strong> position.</p>
                        <p><strong>Offered Salary:</strong> " . e(fmoney($salary)) . " / month<br>
                        <strong>Employment Type:</strong> " . e($employmentTypeLabel) . "<br>
                        <strong>Offer Date:</strong> " . e(fdate($offerDate)) . "</p>
                        $detailsLine
                        <p>Your employment contract is attached to this email for your review. If you already have an applicant account, use it to respond to the offer. Guest applicants should contact HR to record their response and complete contract onboarding.</p>
                        <p>If you have questions, coordinate with HR.</p>";

                    $attachments = [];
                    $tmpContractPath = null;
                    $built = buildEmploymentContractPdf($pdo, $appId);
                    if ($built) {
                        $tmpContractPath = sys_get_temp_dir() . '/' . uniqid('contract_', true) . '.pdf';
                        $built['pdf']->output($built['filename'], 'F', $tmpContractPath);
                        $attachments[] = ['path' => $tmpContractPath, 'name' => $built['filename']];
                    }

                    sendMail($info['applicant_email'], $info['first_name'] . ' ' . $info['last_name'],
                        'Job Offer - ' . $info['job_title'], mailTemplate('Job Offer', $bodyHtml), '', $attachments);

                    if ($tmpContractPath && is_file($tmpContractPath)) {
                        unlink($tmpContractPath);
                    }
                }
            }
        }
    } elseif ($action === 'create_employee') {
        // Explicit HR Staff action: only allowed once the applicant has
        // accepted the job offer (status ACCEPTED). This finalizes hiring
        // alongside the employment contract by creating the employee account.
        $statusCheck = $pdo->prepare("SELECT status FROM job_applications WHERE application_id=?");
        $statusCheck->execute([$appId]);
        $currentStatus = $statusCheck->fetchColumn();

        if ($currentStatus !== 'ACCEPTED') {
            setFlash('error', 'This applicant has not accepted a job offer yet.');
        } else {
            // Let HR supply a missing email before creating the employee
            // account, instead of having to edit the applicant elsewhere.
            $fixEmailInput = $_POST['fix_email'] ?? '';
            $fixEmail = is_string($fixEmailInput) ? trim($fixEmailInput) : '';
            if ($fixEmail !== '') {
                if (!filter_var($fixEmail, FILTER_VALIDATE_EMAIL)) {
                    setFlash('error', 'Please enter a valid email address.');
                    redirect('modules/hr_staff/applications.php');
                }
                $applicantRow = $pdo->prepare("SELECT a.applicant_id, a.user_id FROM job_applications ja
                    JOIN applicants a ON ja.applicant_id = a.applicant_id WHERE ja.application_id=?");
                $applicantRow->execute([$appId]);
                $applicantRow = $applicantRow->fetch();
                if ($applicantRow) {
                    $emailTaken = $pdo->prepare("SELECT user_id FROM users WHERE email=?");
                    $emailTaken->execute([$fixEmail]);
                    if ($emailTaken->fetch()) {
                        setFlash('error', 'That email is already used by another account.');
                        redirect('modules/hr_staff/applications.php');
                    }
                    if (!empty($applicantRow['user_id'])) {
                        $pdo->prepare("UPDATE users SET email=? WHERE user_id=?")->execute([$fixEmail, $applicantRow['user_id']]);
                    } else {
                        $pdo->prepare("UPDATE applicants SET email=? WHERE applicant_id=?")->execute([$fixEmail, $applicantRow['applicant_id']]);
                    }
                    logAudit($pdo, $userId, 'FIX_APPLICANT_EMAIL', 'Recruitment', "application_id=$appId email=$fixEmail");
                }
            }

            try {
              $pdo->beginTransaction();
              $acceptedCheck = $pdo->prepare("SELECT status FROM job_applications WHERE application_id=? FOR UPDATE");
              $acceptedCheck->execute([$appId]);
              if ($acceptedCheck->fetchColumn() !== 'ACCEPTED') {
                throw new RuntimeException('This applicant has not accepted a job offer yet.');
              }
              $employeeCode = convertApplicantToEmployee($pdo, $appId);
              if (!$employeeCode) {
                throw new RuntimeException('Could not create the employee record - please enter a valid email address for this applicant.');
              }
              $statusUpdate = $pdo->prepare("UPDATE job_applications SET status='HIRED' WHERE application_id=? AND status='ACCEPTED'");
              $statusUpdate->execute([$appId]);
              if ($statusUpdate->rowCount() !== 1) {
                throw new RuntimeException('The application status changed before hiring could be completed.');
              }

              // Hiring is now final, so the employment contract becomes a real
              // document: issue its unique Contract No. in the same transaction
              // as the employee record, so the two can never exist without each
              // other. issueContractNumber() is idempotent - re-running this
              // action returns the number already issued rather than a second one.
              $newEmployeeId = $pdo->prepare("SELECT employee_id FROM employees e
                JOIN applicants a ON e.user_id = a.user_id
                JOIN job_applications ja ON ja.applicant_id = a.applicant_id
                WHERE ja.application_id = ?");
              $newEmployeeId->execute([$appId]);
              $newEmployeeId = $newEmployeeId->fetchColumn();
              $contractNo = issueContractNumber($pdo, $appId, $newEmployeeId ? (int)$newEmployeeId : null, $userId);

              $pdo->commit();
              setFlash('success', "Employee record created ($employeeCode)."
                . ($contractNo ? " Employment Contract No. $contractNo issued." : ''));
              logAudit($pdo, $userId, 'CREATE_EMPLOYEE_RECORD', 'Recruitment',
                "application_id=$appId employee_code=$employeeCode contract_no=" . ($contractNo ?: 'none'));
            } catch (Throwable $exception) {
              if ($pdo->inTransaction()) { $pdo->rollBack(); }
              error_log('hr_staff applications employee creation error: ' . $exception->getMessage());
              setFlash('error', 'Could not create the employee record. No changes were made.');
            }
        }
    }
    redirect('modules/hr_staff/applications.php');
}

$statusFilter = $_GET['status'] ?? '';
$where = '1=1'; $params = [];
if ($statusFilter !== '') { $where .= " AND ja.status=?"; $params[] = $statusFilter; }

$total = $pdo->prepare("SELECT COUNT(*) AS c FROM job_applications ja WHERE $where");
$total->execute($params);
$total = $total->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 10);

$stmt = $pdo->prepare("SELECT ja.*,
        COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
        COALESCE(a.email, u.email) AS email, u.profile_photo,
        a.resume_path, a.resume_original_name, jv.title AS job_title, b.branch_name, jo.offer_id
    FROM job_applications ja
    JOIN applicants a ON ja.applicant_id = a.applicant_id
    LEFT JOIN users u ON a.user_id = u.user_id
    JOIN job_vacancies jv ON ja.job_id = jv.job_id
    LEFT JOIN branches b ON jv.branch_id = b.branch_id
    LEFT JOIN job_offers jo ON jo.application_id = ja.application_id
    WHERE $where ORDER BY ja.applied_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$applications = $stmt->fetchAll();

// Other required documents (besides resume) submitted alongside each application
$documentsByApp = [];
if (!empty($applications)) {
    $appIds = array_column($applications, 'application_id');
    $placeholders = implode(',', array_fill(0, count($appIds), '?'));
    $docStmt = $pdo->prepare("SELECT * FROM application_documents WHERE application_id IN ($placeholders) ORDER BY uploaded_at ASC");
    $docStmt->execute($appIds);
    foreach ($docStmt->fetchAll() as $doc) {
        $documentsByApp[$doc['application_id']][] = $doc;
    }
}

$pageTitle = 'Review Applications';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="emp-page-header">
  <div class="emp-page-icon"><i class="bi bi-file-earmark-person-fill"></i></div>
  <div>
    <h4>Review Applications</h4>
  </div>
</div>

<form method="GET" class="row g-2 mb-3">
  <div class="col-md-4">
    <select name="status" class="form-select" onchange="this.form.submit()">
      <option value="">All Statuses</option>
      <?php foreach ($statuses as $s): ?><option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= str_replace('_',' ',$s) ?></option><?php endforeach; ?>
    </select>
  </div>
</form>

<div class="card">
  <div class="card-body table-responsive">
    <table id="applicationsTable" class="table table-hover align-middle">
      <thead><tr><th>Ref. Code</th><th>Photo</th><th>Applicant</th><th>Position Applied For</th><th>Branch</th><th>Documents</th><th>Status</th><th>Applied</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($applications as $a): ?>
        <tr>
          <td><code><?= e($a['reference_code']) ?></code></td>
          <td><?= renderAvatar($a['profile_photo'], $a['first_name'], $a['last_name'], 40) ?></td>
          <td><?= e($a['first_name'].' '.$a['last_name']) ?><br><small class="text-muted"><?= e($a['email']) ?></small></td>
          <td><?= e($a['job_title']) ?></td>
          <td><?= e($a['branch_name'] ?? 'Not assigned') ?></td>
          <td>
            <?php if ($a['resume_path']): ?><a href="<?= BASE_URL ?>uploads/resumes/<?= e($a['resume_path']) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Resume</a><?php else: ?>No resume<?php endif; ?>
            <?php if (!empty($documentsByApp[$a['application_id']])): ?>
              <?php foreach ($documentsByApp[$a['application_id']] as $doc): ?>
                <br><a href="<?= BASE_URL ?>uploads/documents/<?= e($doc['file_path']) ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= e($doc['document_type']) ?></a>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
          <td><span class="badge bg-info"><?= str_replace('_',' ',$a['status']) ?></span></td>
          <td><?= fdate($a['applied_at']) ?></td>
          <td class="text-nowrap">
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#statusModal<?= $a['application_id'] ?>"><i class="bi bi-pencil"></i> Update</button>
            <div class="dropdown d-inline-block">
              <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Recruitment actions"><i class="bi bi-three-dots"></i></button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php if ($canScheduleInterview && $a['status'] === 'SHORTLISTED'): ?>
                  <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#scheduleInterviewModal<?= (int)$a['application_id'] ?>"><i class="bi bi-calendar-plus me-2"></i>Schedule Interview</button></li>
                <?php endif; ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>hr-staff/interviews?application_id=<?= (int)$a['application_id'] ?>"><i class="bi bi-calendar-event me-2"></i>View Interviews</a></li>
              </ul>
            </div>
            <?php if ($a['status'] === 'HIRE_APPROVED'): ?>
              <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#offerModal<?= $a['application_id'] ?>"><i class="bi bi-gift"></i></button>
            <?php else: ?>
              <button class="btn btn-sm btn-outline-success" disabled title="Awaiting HR Manager hiring approval"><i class="bi bi-gift"></i></button>
            <?php endif; ?>
            <?php if (!empty($a['offer_id'])): ?>
              <a href="<?= BASE_URL ?>hr-staff/contract?application_id=<?= $a['application_id'] ?>" class="btn btn-sm btn-outline-dark" title="View Employment Contract"><i class="bi bi-file-earmark-text"></i></a>
            <?php endif; ?>
            <?php if ($a['status'] === 'ACCEPTED'): ?>
              <?php if (empty($a['email'])): ?>
                <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#fixEmailModal<?= $a['application_id'] ?>" title="No email on file - add one to create the account"><i class="bi bi-envelope-exclamation"></i></button>
              <?php else: ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="action" value="create_employee">
                  <input type="hidden" name="application_id" value="<?= $a['application_id'] ?>">
                  <button class="btn btn-sm btn-outline-primary" title="Create Employee Account and Record"><i class="bi bi-person-plus"></i></button>
                </form>
              <?php endif; ?>
            <?php elseif ($a['status'] === 'HIRED'): ?>
              <button class="btn btn-sm btn-outline-primary" disabled title="Employee record already created"><i class="bi bi-person-check"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>

<?php foreach ($applications as $a): ?>
  <?php if ($canScheduleInterview && $a['status'] === 'SHORTLISTED'): ?>
  <div class="modal fade" id="scheduleInterviewModal<?= (int)$a['application_id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="schedule_interview">
        <input type="hidden" name="application_id" value="<?= (int)$a['application_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Schedule HR Interview</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Applicant</label><input type="text" class="form-control" value="<?= e($a['first_name'] . ' ' . $a['last_name']) ?>" readonly></div>
          <div class="mb-3"><label class="form-label">Position</label><input type="text" class="form-control" value="<?= e($a['job_title']) ?>" readonly></div>
          <div class="mb-3"><label class="form-label">Branch</label><input type="text" class="form-control" value="<?= e($a['branch_name'] ?? 'Not assigned') ?>" readonly></div>
          <div class="mb-3"><label class="form-label" for="interviewer<?= (int)$a['application_id'] ?>">Interviewer</label>
            <select id="interviewer<?= (int)$a['application_id'] ?>" name="interviewer_id" class="form-select" required>
              <option value="">Select an HR Staff interviewer</option>
              <?php foreach ($interviewers as $interviewer): ?>
                <option value="<?= (int)$interviewer['user_id'] ?>"><?= e($interviewer['first_name'] . ' ' . $interviewer['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="interviewDate<?= (int)$a['application_id'] ?>">Interview Date</label><input id="interviewDate<?= (int)$a['application_id'] ?>" type="date" name="interview_date" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
            <div class="col-md-6"><label class="form-label" for="interviewTime<?= (int)$a['application_id'] ?>">Interview Time</label><input id="interviewTime<?= (int)$a['application_id'] ?>" type="time" name="interview_time" class="form-control" required></div>
          </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-calendar-check me-1"></i>Save Interview Schedule</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>

  <!-- Status Update Modal -->
  <div class="modal fade" id="statusModal<?= $a['application_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="application_id" value="<?= $a['application_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Update Application Status</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach ($statuses as $s): ?>
                <?php if ($a['status'] === 'SHORTLISTED' && $s === 'INTERVIEW_SCHEDULED') continue; ?>
                <option value="<?= $s ?>" <?= $a['status']===$s?'selected':'' ?>><?= str_replace('_',' ',$s) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($a['status'] === 'SHORTLISTED'): ?><div class="form-text">Use Schedule Interview to set this status.</div><?php endif; ?>
          </div>
          <div class="mb-3"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" maxlength="2000"><?= e($a['remarks']) ?></textarea></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
      </form>
    </div></div>
  </div>

  <!-- Offer Modal -->
  <div class="modal fade" id="offerModal<?= $a['application_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="create_offer">
        <input type="hidden" name="application_id" value="<?= $a['application_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Send Job Offer</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3"><label class="form-label">Offered Salary</label><input type="number" step="0.01" name="offered_salary" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Offer Date</label><input type="date" name="offer_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
          <div class="mb-3"><label class="form-label">Employment Type</label>
            <select name="employment_type" class="form-select" required>
              <?php foreach (EMPLOYMENT_TYPES as $t): ?>
                <option value="<?= e($t) ?>" <?= $t==='PROBATIONARY'?'selected':'' ?>><?= e(EMPLOYMENT_TYPE_LABELS[$t]) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">The contract the applicant will sign if they accept this offer.</div>
          </div>
          <div class="mb-3"><label class="form-label">Offer Details (incl. benefits)</label><textarea name="offer_details" class="form-control" rows="3" maxlength="255" placeholder="Benefits, start date, etc."></textarea></div>
        </div>
        <div class="modal-footer"><button class="btn btn-success">Send Offer</button></div>
      </form>
    </div></div>
  </div>

  <?php if ($a['status'] === 'ACCEPTED' && empty($a['email'])): ?>
  <!-- Missing email modal for completing employee onboarding -->
  <div class="modal fade" id="fixEmailModal<?= $a['application_id'] ?>" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="create_employee">
        <input type="hidden" name="application_id" value="<?= $a['application_id'] ?>">
        <div class="modal-header"><h6 class="modal-title">Add Email &amp; Create Employee Account</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle"></i> This applicant accepted the offer but has no email on file. Enter a valid email below to finish creating the employee account.
          </div>
          <div class="mb-3"><label class="form-label">Email Address</label><input type="email" name="fix_email" class="form-control" maxlength="254" required placeholder="applicant@example.com"></div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary">Create Employee Account</button></div>
      </form>
    </div></div>
  </div>
  <?php endif; ?>
<?php endforeach; ?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(function () {
  $('#applicationsTable').DataTable({ order: [], paging: false, info: false, columnDefs: [{ orderable: false, targets: [1, 5, 8] }] });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>