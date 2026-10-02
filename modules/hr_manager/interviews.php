<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

requireRole([ROLE_HR_STAFF, ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';

    /*
     * HR Manager evaluation of the HR Initial Interview.
     *
    * HR Initial Interviews are scheduled by HR Staff from the
    * shortlisted applicant list.
     *
     * HR Manager cannot evaluate the interview before its scheduled
     * date and time.
     *
     * ADVANCE -> creates the Final Department Interview as PENDING_SCHEDULE.
     * REJECT -> rejects the application.
     */
    if ($action === 'evaluate') {
        $interviewId = filter_var(
            $_POST['interview_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $recommendation = $_POST['recommendation'] ?? '';
        $notesInput = $_POST['evaluation_notes'] ?? '';
        $notes = is_string($notesInput) ? trim($notesInput) : '';

        if (
            $interviewId === false ||
            $interviewId < 1
        ) {
            setFlash('error', 'Invalid interview.');
        } elseif (currentRoleId() !== ROLE_HR_MANAGER) {
            setFlash(
                'error',
                'Only the HR Manager can evaluate the HR Initial Interview.'
            );
        } elseif (!in_array($recommendation, ['ADVANCE', 'REJECT'], true)) {
            setFlash('error', 'Please select a valid recommendation.');
        } elseif (strlen($notes) > 2000) {
            setFlash(
                'error',
                'Evaluation notes must be 2000 characters or fewer.'
            );
        } else {
            $iv = $pdo->prepare("
                SELECT
                    i.*,
                    ja.application_id,
                    ja.status AS application_status,
                    jv.department_id,
                    jv.title AS job_title,
                    COALESCE(a.first_name, u.first_name) AS first_name,
                    COALESCE(a.last_name, u.last_name) AS last_name,
                    COALESCE(u.email, a.email) AS applicant_email,
                    u.user_id AS applicant_user_id
                FROM interviews i
                JOIN job_applications ja
                    ON i.application_id = ja.application_id
                JOIN applicants a
                    ON ja.applicant_id = a.applicant_id
                LEFT JOIN users u
                    ON a.user_id = u.user_id
                JOIN job_vacancies jv
                    ON ja.job_id = jv.job_id
                WHERE i.interview_id = ?
                  AND i.stage = 'HR_INITIAL'
            ");

            $iv->execute([$interviewId]);
            $iv = $iv->fetch();

            if (!$iv) {
                setFlash('error', 'Interview not found.');
            } elseif (
                $iv['status'] !== 'SCHEDULED' ||
                empty($iv['schedule_date']) ||
                strtotime($iv['schedule_date']) > time()
            ) {
                setFlash(
                    'error',
                    'The HR Initial Interview cannot be evaluated before its scheduled date and time.'
                );
            } elseif (!empty($iv['recommendation'])) {
                setFlash(
                    'error',
                    'This interview has already been evaluated.'
                );
            } else {
                /*
                 * Conditional update prevents duplicate evaluation if
                 * another request tries to evaluate the same interview.
                 */
                $evaluationUpdate = $pdo->prepare("
                    UPDATE interviews
                    SET
                        evaluation_notes = ?,
                        recommendation = ?,
                        evaluated_by = ?,
                        evaluated_at = NOW(),
                        status = 'COMPLETED'
                    WHERE interview_id = ?
                      AND stage = 'HR_INITIAL'
                      AND status = 'SCHEDULED'
                      AND recommendation IS NULL
                ");

                $evaluationUpdate->execute([
                    $notes,
                    $recommendation,
                    $userId,
                    $interviewId
                ]);

                if ($evaluationUpdate->rowCount() !== 1) {
                    setFlash(
                        'error',
                        'This interview has already been evaluated or is no longer available for evaluation.'
                    );
                } elseif ($recommendation === 'ADVANCE') {

                    /*
                     * Move application to the next recruitment stage.
                     */
                    $applicationUpdate = $pdo->prepare("
                        UPDATE job_applications
                        SET status = 'HR_INTERVIEW_PASSED'
                        WHERE application_id = ?
                          AND status = 'INTERVIEW_SCHEDULED'
                    ");

                    $applicationUpdate->execute([
                        $iv['application_id']
                    ]);

                    /*
                     * Create the Final Department Interview only once.
                     * Employee Manager will schedule it.
                     */
                    $existingFinal = $pdo->prepare("
                        SELECT interview_id
                        FROM interviews
                        WHERE application_id = ?
                          AND stage = 'FINAL_DEPARTMENT'
                        LIMIT 1
                    ");

                    $existingFinal->execute([
                        $iv['application_id']
                    ]);

                    if (!$existingFinal->fetch()) {
                        $createFinal = $pdo->prepare("
                            INSERT INTO interviews
                                (
                                    application_id,
                                    stage,
                                    status,
                                    created_by
                                )
                            VALUES
                                (
                                    ?,
                                    'FINAL_DEPARTMENT',
                                    'PENDING_SCHEDULE',
                                    ?
                                )
                        ");

                        $createFinal->execute([
                            $iv['application_id'],
                            $userId
                        ]);
                    }

                    setFlash(
                        'success',
                        'Applicant advanced to the Final Department Interview.'
                    );

                    logAudit(
                        $pdo,
                        $userId,
                        'HR_INTERVIEW_EVALUATION',
                        'Recruitment',
                        "application_id={$iv['application_id']} recommendation=ADVANCE"
                    );

                    /*
                     * Notify applicant.
                     */
                    if (!empty($iv['applicant_user_id'])) {
                        createNotification(
                            $pdo,
                            (int)$iv['applicant_user_id'],
                            'HR Interview Passed',
                            "Congratulations! You passed the HR Initial Interview for \"{$iv['job_title']}\". The hiring department will reach out to schedule your Final Interview.",
                            'INTERVIEW',
                            $iv['application_id']
                        );
                    }

                    /*
                     * Notify the Employee Manager assigned to the
                     * hiring department.
                     */
                    $deptManager = $pdo->prepare("
                        SELECT eu.user_id
                        FROM departments d
                        JOIN employees e
                            ON d.manager_id = e.employee_id
                        JOIN users eu
                            ON e.user_id = eu.user_id
                        WHERE d.department_id = ?
                          AND eu.status = 'ACTIVE'
                        LIMIT 1
                    ");

                    $deptManager->execute([
                        $iv['department_id']
                    ]);

                    $deptManagerUserId = $deptManager->fetchColumn();

                    if ($deptManagerUserId) {
                        createNotification(
                            $pdo,
                            (int)$deptManagerUserId,
                            'Final Interview Awaiting Schedule',
                            "{$iv['first_name']} {$iv['last_name']} ({$iv['job_title']}) passed the HR Initial Interview and is ready for the Final Department Interview. Please set a date.",
                            'INTERVIEW',
                            $iv['application_id']
                        );
                    } else {
                        /*
                         * Fallback if the department has no assigned manager.
                         */
                        notifyRole(
                            $pdo,
                            ROLE_EMPLOYEE_MANAGER,
                            'Final Interview Awaiting Schedule',
                            "{$iv['first_name']} {$iv['last_name']} ({$iv['job_title']}) passed the HR Initial Interview and is ready for the Final Department Interview, but no manager is assigned to that department yet.",
                            'INTERVIEW',
                            $iv['application_id']
                        );
                    }

                } else {
                    /*
                     * REJECT
                     */
                    $applicationReject = $pdo->prepare("
                        UPDATE job_applications
                        SET status = 'REJECTED'
                        WHERE application_id = ?
                    ");

                    $applicationReject->execute([
                        $iv['application_id']
                    ]);

                    setFlash(
                        'success',
                        'Applicant rejected after HR Initial Interview.'
                    );

                    logAudit(
                        $pdo,
                        $userId,
                        'HR_INTERVIEW_EVALUATION',
                        'Recruitment',
                        "application_id={$iv['application_id']} recommendation=REJECT"
                    );

                    /*
                     * Notify applicant.
                     */
                    if (!empty($iv['applicant_user_id'])) {
                        createNotification(
                            $pdo,
                            (int)$iv['applicant_user_id'],
                            'Application Update',
                            "Thank you for interviewing for \"{$iv['job_title']}\". We will not be moving forward with your application at this time.",
                            'INTERVIEW',
                            $iv['application_id']
                        );
                    }

                    if (!empty($iv['applicant_email'])) {
                        $bodyHtml = "
                            <p>Hi " . e($iv['first_name']) . ",</p>

                            <p>
                                Thank you for taking the time to interview
                                for the <strong>" . e($iv['job_title']) . "</strong>
                                position.
                            </p>

                            <p>
                                After careful consideration, we will not be
                                moving forward with your application at this time.
                            </p>

                            <p>
                                We appreciate your interest and wish you the
                                best in your job search.
                            </p>
                        ";

                        sendMail(
                            $iv['applicant_email'],
                            $iv['first_name'] . ' ' . $iv['last_name'],
                            'Application Update - ' . $iv['job_title'],
                            mailTemplate(
                                'Application Update',
                                $bodyHtml
                            )
                        );
                    }
                }
            }
        }

        redirect('modules/hr_manager/interviews.php');
    }
}

/*
 * Display all interviews.
 * No scheduling or editing is performed on this page.
 */
$interviews = $pdo->query("
    SELECT
        i.*,
        ja.application_id,
        ja.status AS app_status,
        COALESCE(a.first_name, u.first_name) AS first_name,
        COALESCE(a.last_name, u.last_name) AS last_name,
        u.profile_photo,
        jv.title AS job_title
    FROM interviews i
    JOIN job_applications ja
        ON i.application_id = ja.application_id
    JOIN applicants a
        ON ja.applicant_id = a.applicant_id
    LEFT JOIN users u
        ON a.user_id = u.user_id
    JOIN job_vacancies jv
        ON ja.job_id = jv.job_id
    ORDER BY
        CASE
            WHEN i.status = 'PENDING_SCHEDULE' THEN 0
            ELSE 1
        END,
        i.schedule_date DESC
")->fetchAll();

$pageTitle = 'Interviews';

require_once __DIR__ . '/../../includes/header.php';
?>

<link
    rel="stylesheet"
    href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css"
>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4>Interviews</h4>
</div>


<div class="card">
    <div class="card-body table-responsive">

        <table
            class="table table-hover align-middle"
            id="interviewsTable"
        >
            <thead>
                <tr>
                    <th>Photo</th>
                    <th>Applicant</th>
                    <th>Job</th>
                    <th>Stage</th>
                    <th>Schedule</th>
                    <th>Mode</th>
                    <th>Location</th>
                    <th>Interviewer</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($interviews as $i): ?>

                    <tr>
                        <td>
                            <?= renderAvatar(
                                $i['profile_photo'] ?? null,
                                $i['first_name'],
                                $i['last_name'],
                                36
                            ) ?>
                        </td>

                        <td>
                            <?= e(
                                $i['first_name'] . ' ' . $i['last_name']
                            ) ?>
                        </td>

                        <td>
                            <?= e($i['job_title']) ?>
                        </td>

                        <td>
                            <span
                                class="badge"
                                style="background-color:<?= $i['stage'] === 'FINAL_DEPARTMENT'
                                    ? '#8d5b4c'
                                    : '#7a6558' ?>; border-radius: 50px; font-weight: 700; padding: 5px 12px;"
                            >
                                <?= e(
                                    interviewStageLabel($i['stage'])
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($i['schedule_date'])): ?>
                                <?= date(
                                    'M d, Y g:i A',
                                    strtotime($i['schedule_date'])
                                ) ?>
                            <?php else: ?>
                                <span class="text-muted">
                                    Not yet set
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= e($i['mode'] ?? '') ?>
                        </td>

                        <td>
                            <?= e($i['location'] ?? '') ?>
                        </td>

                        <td>
                            <?= e($i['interviewer'] ?? '') ?>
                        </td>

                        <td>
                            <?php if ($i['status'] === 'PENDING_SCHEDULE'): ?>

                                <span class="badge bg-warning text-dark">
                                    Awaiting Dept. Manager
                                </span>

                            <?php else: ?>

                                <span class="badge bg-secondary">
                                    <?= e($i['status']) ?>
                                </span>

                            <?php endif; ?>

                            <?php if (!empty($i['recommendation'])): ?>

                                <br>

                                <span class="badge bg-info mt-1">
                                    <?= e($i['recommendation']) ?>
                                </span>

                            <?php endif; ?>
                        </td>

                        <td>

                            <?php if ($i['status'] === 'PENDING_SCHEDULE'): ?>

                                <span class="text-muted small">
                                    Handled by Employee Manager
                                </span>

                            <?php elseif (
                                $i['stage'] === 'HR_INITIAL' &&
                                empty($i['recommendation']) &&
                                currentRoleId() === ROLE_HR_MANAGER
                            ): ?>

                                <button
                                    class="btn btn-sm btn-success"
                                    data-bs-toggle="modal"
                                    data-bs-target="#evalModal<?= $i['interview_id'] ?>"
                                >
                                    <i class="bi bi-clipboard-check"></i>
                                    Evaluate
                                </button>

                            <?php else: ?>

                                <span class="text-muted small">
                                    No action
                                </span>

                            <?php endif; ?>

                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>

    </div>
</div>


<!-- Evaluation Modals -->
<?php foreach ($interviews as $i): ?>

    <?php
    $canEvaluate =
        $i['stage'] === 'HR_INITIAL' &&
        $i['status'] === 'SCHEDULED' &&
        empty($i['recommendation']) &&
        currentRoleId() === ROLE_HR_MANAGER;
    ?>

    <?php if ($canEvaluate): ?>

        <div
            class="modal fade"
            id="evalModal<?= $i['interview_id'] ?>"
            tabindex="-1"
        >
            <div class="modal-dialog">
                <div class="modal-content">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= csrfToken() ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="evaluate"
                        >

                        <input
                            type="hidden"
                            name="interview_id"
                            value="<?= (int)$i['interview_id'] ?>"
                        >

                        <div class="modal-header">

                            <h6 class="modal-title">
                                Evaluate HR Initial Interview —
                                <?= e(
                                    $i['first_name'] . ' ' . $i['last_name']
                                ) ?>
                            </h6>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                            ></button>

                        </div>

                        <div class="modal-body text-start">

                            <div class="mb-3">

                                <label class="form-label">
                                    Evaluation Notes
                                </label>

                                <textarea
                                    name="evaluation_notes"
                                    class="form-control"
                                    rows="4"
                                    maxlength="2000"
                                    placeholder="Overall impression, communication, fit, etc."
                                ></textarea>

                            </div>

                            <div class="mb-2">

                                <label class="form-label">
                                    Recommendation
                                </label>

                                <select
                                    name="recommendation"
                                    class="form-select"
                                    required
                                >
                                    <option value="">
                                        -- Select --
                                    </option>

                                    <option value="ADVANCE">
                                        Advance to Final Department Interview
                                    </option>

                                    <option value="REJECT">
                                        Reject
                                    </option>

                                </select>

                            </div>

                        </div>

                        <div class="modal-footer">

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Save Recommendation
                            </button>

                        </div>

                    </form>

                </div>
            </div>
        </div>

    <?php endif; ?>

<?php endforeach; ?>


<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<script>
$(function () {

    $.fn.dataTable.ext.errMode = 'none';

    if ($.fn.DataTable.isDataTable('#interviewsTable')) {
        $('#interviewsTable').DataTable().destroy();
    }

    $('#interviewsTable').DataTable({
        order: [],
        columnDefs: [
            {
                orderable: false,
                targets: [0, -1]
            }
        ]
    });

});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>