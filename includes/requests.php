<?php
/**
 * REQUEST FORMS MODULE - shared library
 *
 * Four request types (Leave, Overtime, General, Document/Certificate) sharing
 * one record table and one approval route.
 *
 * The route is the approval flow the system already uses for leave:
 *
 *   Step 1  Employee            submits                -> PENDING_MANAGER
 *   Step 2  Employee Manager    reviews, forwards      -> PENDING_HR
 *   Step 3  HR Staff            processes, forwards    -> PENDING_APPROVAL
 *   Step 4  HR Manager          final approval         -> APPROVED
 *
 * A rejection at any step ends the request immediately (-> REJECTED), and the
 * employee may withdraw their own request while it is still pending
 * (-> CANCELLED). The status values are deliberately the same strings used by
 * `leave_requests`, so leaveStatusLabel() / leaveStatusColor() apply unchanged.
 *
 * Every action, including the original submission, appends a row to
 * `request_approvals`. That table is append-only and is what the document view
 * renders as the approval / e-signature history.
 */

require_once __DIR__ . '/doc_numbers.php';

// ---------------------------------------------------------------------------
// Type definitions
// ---------------------------------------------------------------------------

if (!function_exists('requestTypes')) {
    function requestTypes(): array
    {
        return [
            'LEAVE'    => ['label' => 'Leave Request',                 'seq' => 'REQ-LV', 'prefix' => 'LV', 'icon' => 'bi-calendar-check'],
            'OVERTIME' => ['label' => 'Overtime Request',              'seq' => 'REQ-OT', 'prefix' => 'OT', 'icon' => 'bi-clock-history'],
            'GENERAL'  => ['label' => 'General Request',               'seq' => 'REQ-GR', 'prefix' => 'GR', 'icon' => 'bi-file-earmark-text'],
            'DOCUMENT' => ['label' => 'Document / Certificate Request', 'seq' => 'REQ-DC', 'prefix' => 'DC', 'icon' => 'bi-patch-check'],
        ];
    }
}

if (!function_exists('requestTypeLabel')) {
    function requestTypeLabel(?string $type): string
    {
        return requestTypes()[$type]['label'] ?? 'Request';
    }
}

if (!function_exists('requestTypeIcon')) {
    function requestTypeIcon(?string $type): string
    {
        return requestTypes()[$type]['icon'] ?? 'bi-file-earmark';
    }
}

if (!function_exists('requestDocumentTypes')) {
    /** The certificate/document options offered on the Document Request form. */
    function requestDocumentTypes(PDO $pdo): array
    {
        $default = 'Certificate of Employment,Payslip Copy,Service Record,Clearance Certificate,Other';
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'request_document_types'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
        } catch (Throwable $exception) {
            $value = null;
        }
        $list = array_filter(array_map('trim', explode(',', (string)($value ?: $default))));
        return array_values($list);
    }
}

// ---------------------------------------------------------------------------
// Workflow definition
// ---------------------------------------------------------------------------

if (!function_exists('requestWorkflow')) {
    /**
     * The four steps, in order. `pending_status` is the request status while
     * that step is the one waiting to act; step 1 has none because submission
     * is instantaneous.
     */
    function requestWorkflow(): array
    {
        return [
            1 => ['label' => 'Filed by Employee',        'role_id' => ROLE_EMPLOYEE,          'role_label' => 'Employee',         'pending_status' => null],
            2 => ['label' => 'Manager Review',           'role_id' => ROLE_EMPLOYEE_MANAGER,  'role_label' => 'Employee Manager', 'pending_status' => 'PENDING_MANAGER'],
            3 => ['label' => 'HR Processing',            'role_id' => ROLE_HR_STAFF,          'role_label' => 'HR Staff',         'pending_status' => 'PENDING_HR'],
            4 => ['label' => 'Final Approval',           'role_id' => ROLE_HR_MANAGER,        'role_label' => 'HR Manager',       'pending_status' => 'PENDING_APPROVAL'],
        ];
    }
}

if (!function_exists('requestStepForStatus')) {
    /** Which step number is currently waiting to act, or null if the request is closed. */
    function requestStepForStatus(string $status): ?int
    {
        foreach (requestWorkflow() as $stepNo => $step) {
            if ($step['pending_status'] !== null && $step['pending_status'] === $status) {
                return $stepNo;
            }
        }
        return null;
    }
}

if (!function_exists('requestIsOpen')) {
    function requestIsOpen(string $status): bool
    {
        return in_array($status, ['PENDING_MANAGER', 'PENDING_HR', 'PENDING_APPROVAL'], true);
    }
}

if (!function_exists('requestStatusLabel')) {
    /**
     * Wraps the existing leaveStatusLabel()/leaveStatusColor() helpers, which
     * already understand this exact set of status strings.
     */
    function requestStatusLabel(string $status): string
    {
        return function_exists('leaveStatusLabel') ? leaveStatusLabel($status) : ucwords(strtolower(str_replace('_', ' ', $status)));
    }
}

if (!function_exists('requestStatusColor')) {
    function requestStatusColor(string $status): string
    {
        return function_exists('leaveStatusColor') ? leaveStatusColor($status) : 'secondary';
    }
}

// ---------------------------------------------------------------------------
// Approval / e-signature trail
// ---------------------------------------------------------------------------

if (!function_exists('clampRemark')) {
    /**
     * `leave_requests` remark columns are varchar(255). Trim to fit rather
     * than let an over-long remark fail the whole decision. Falls back to
     * substr() where mbstring isn't available.
     */
    function clampRemark(string $remarks, int $limit = 255): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($remarks, 0, $limit);
        }
        return substr($remarks, 0, $limit);
    }
}

if (!function_exists('recordRequestAction')) {
    /**
     * Append one e-signature row to a request's approval history.
     *
     * The actor's name and role are stored as literal text rather than only as
     * a user_id, so the signed record still reads correctly years later if that
     * person is renamed, changes role, or leaves the company.
     */
    function recordRequestAction(PDO $pdo, int $requestId, string $requestNo, int $stepNo, string $stepLabel,
                                 ?int $userId, string $actorName, string $actorRole, string $action, string $remarks = '',
                                 ?string $signatureData = null): void
    {
        $actedAt = date('Y-m-d H:i:s');
        $hash = signatureHash($requestNo, $actorName, $action, $actedAt);

        $pdo->prepare("INSERT INTO request_approvals
                (request_id, step_no, step_label, actor_user_id, actor_name, actor_role, action, remarks, signature_hash, signature_data, acted_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$requestId, $stepNo, $stepLabel, $userId, $actorName, $actorRole, $action,
                       ($remarks !== '' ? $remarks : null), $hash,
                       ($signatureData !== null && $signatureData !== '' ? $signatureData : null), $actedAt]);
    }
}

if (!function_exists('getRequestApprovals')) {
    function getRequestApprovals(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM request_approvals WHERE request_id = ? ORDER BY approval_id ASC");
        $stmt->execute([$requestId]);
        return $stmt->fetchAll();
    }
}

// ---------------------------------------------------------------------------
// Reading requests
// ---------------------------------------------------------------------------

if (!function_exists('getRequestForm')) {
    /** One request, joined to the filer's name/code and (for leave) the leave type. */
    function getRequestForm(PDO $pdo, int $requestId): ?array
    {
        $stmt = $pdo->prepare("SELECT rf.*, e.employee_code, e.position, e.user_id AS employee_user_id,
                d.department_name, b.branch_name,
                u.first_name, u.last_name, u.email,
                lt.type_name AS leave_type_name
            FROM request_forms rf
            JOIN employees e ON rf.employee_id = e.employee_id
            JOIN users u ON e.user_id = u.user_id
            LEFT JOIN departments d ON e.department_id = d.department_id
            LEFT JOIN branches b ON e.branch_id = b.branch_id
            LEFT JOIN leave_types lt ON rf.leave_type_id = lt.leave_type_id
            WHERE rf.request_id = ?");
        $stmt->execute([$requestId]);
        return $stmt->fetch() ?: null;
    }
}

if (!function_exists('userCanViewRequest')) {
    /**
     * Who may open a request's document view:
     *   - the employee who filed it
     *   - anyone in the approval route (Employee Manager, HR Staff, HR Manager)
     *   - the Owner, for oversight
     */
    function userCanViewRequest(array $request, int $userId, int $roleId): bool
    {
        if ((int)$request['employee_user_id'] === $userId) {
            return true;
        }
        return in_array($roleId, [ROLE_EMPLOYEE_MANAGER, ROLE_HR_STAFF, ROLE_HR_MANAGER, ROLE_OWNER], true);
    }
}

if (!function_exists('employeeIdForUser')) {
    function employeeIdForUser(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}

// ---------------------------------------------------------------------------
// Creating a request
// ---------------------------------------------------------------------------

if (!function_exists('createRequestForm')) {
    /**
     * File a new request and write its "SUBMITTED" e-signature row.
     *
     * @param array $fields Only the columns relevant to $type are read.
     * @return array{request_id:int, request_no:string}
     * @throws Throwable on failure (caller should catch and show a flash).
     */
    function createRequestForm(PDO $pdo, int $employeeId, string $type, array $fields,
                               int $actorUserId, string $actorName, string $actorRole): array
    {
        $types = requestTypes();
        if (!isset($types[$type])) {
            throw new InvalidArgumentException('Unknown request type.');
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $requestNo = nextDocumentNumber($pdo, $types[$type]['seq'], $types[$type]['prefix']);

            // Leave is filed here but STORED in the existing `leave_requests`
            // table as well, which stays the canonical leave record. Every
            // dashboard, report, leave balance and attendance check in the
            // system already reads from there, so writing both keeps all of
            // them correct without touching a single one of those pages.
            $leaveId = null;
            if ($type === 'LEAVE') {
                $pdo->prepare("INSERT INTO leave_requests
                        (employee_id, leave_type_id, date_from, date_to, total_days, reason, status)
                        VALUES (?,?,?,?,?,?, 'PENDING_MANAGER')")
                    ->execute([
                        $employeeId, $fields['leave_type_id'], $fields['date_from'],
                        $fields['date_to'], $fields['total_days'], $fields['details'] ?? null,
                    ]);
                $leaveId = (int)$pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO request_forms
                    (request_no, request_type, employee_id, subject, details, status,
                     leave_type_id, leave_id, date_from, date_to, total_days,
                     ot_date, ot_time_from, ot_time_to, ot_hours,
                     document_type, copies, needed_by, purpose)
                    VALUES (?,?,?,?,?, 'PENDING_MANAGER', ?,?,?,?,?, ?,?,?,?, ?,?,?,?)")
                ->execute([
                    $requestNo, $type, $employeeId, $fields['subject'], $fields['details'] ?? null,
                    $fields['leave_type_id'] ?? null, $leaveId, $fields['date_from'] ?? null, $fields['date_to'] ?? null, $fields['total_days'] ?? null,
                    $fields['ot_date'] ?? null, $fields['ot_time_from'] ?? null, $fields['ot_time_to'] ?? null, $fields['ot_hours'] ?? null,
                    $fields['document_type'] ?? null, $fields['copies'] ?? null, $fields['needed_by'] ?? null, $fields['purpose'] ?? null,
                ]);

            $requestId = (int)$pdo->lastInsertId();

            recordRequestAction($pdo, $requestId, $requestNo, 1, 'Filed by Employee',
                $actorUserId, $actorName, $actorRole, 'SUBMITTED', $fields['details'] ?? '');

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        // Notifications must not be able to roll back a filed request.
        if (function_exists('notifyRole')) {
            notifyRole($pdo, ROLE_EMPLOYEE_MANAGER, 'New ' . $types[$type]['label'],
                $actorName . ' filed ' . $requestNo . ' for your review.', 'request_form', $requestId);
        }

        return ['request_id' => $requestId, 'request_no' => $requestNo];
    }
}

// ---------------------------------------------------------------------------
// Acting on a request
// ---------------------------------------------------------------------------

if (!function_exists('actOnRequest')) {
    /**
     * Apply an approval-route decision to a request.
     *
     * The status is re-checked under a row lock and the UPDATE is guarded by
     * the expected status, so two approvers clicking at the same moment cannot
     * both move the same request - the second one is told it already moved.
     *
     * @param int    $stepNo   Which workflow step the actor occupies (2, 3 or 4).
     * @param string $decision 'forward' (or 'approve' at step 4) or 'reject'.
     * @return array{ok:bool, message:string, status?:string}
     */
    function actOnRequest(PDO $pdo, int $requestId, int $stepNo, string $decision,
                          int $actorUserId, string $actorName, string $actorRole, string $remarks = '',
                          ?string $signatureData = null): array
    {
        $workflow = requestWorkflow();
        if (!isset($workflow[$stepNo]) || $workflow[$stepNo]['pending_status'] === null) {
            return ['ok' => false, 'message' => 'That approval step is not valid.'];
        }
        if (!in_array($decision, ['forward', 'approve', 'reject'], true)) {
            return ['ok' => false, 'message' => 'Please select a valid decision.'];
        }
        if (strlen($remarks) > 2000) {
            return ['ok' => false, 'message' => 'Remarks must be 2000 characters or fewer.'];
        }

        $expectedStatus = $workflow[$stepNo]['pending_status'];
        $isFinalStep = ($stepNo === array_key_last($workflow));

        if ($decision === 'reject') {
            $newStatus = 'REJECTED';
            $action = 'REJECTED';
        } elseif ($isFinalStep) {
            $newStatus = 'APPROVED';
            $action = 'APPROVED';
        } else {
            // Move to whatever status the NEXT step waits on.
            $newStatus = $workflow[$stepNo + 1]['pending_status'];
            $action = 'FORWARDED';
        }

        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $locked = $pdo->prepare("SELECT request_id, request_no, request_type, employee_id, status, leave_id
                                     FROM request_forms WHERE request_id = ? FOR UPDATE");
            $locked->execute([$requestId]);
            $request = $locked->fetch();

            if (!$request || $request['status'] !== $expectedStatus) {
                if ($ownTransaction) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'This request is no longer awaiting your action.'];
            }

            $update = $pdo->prepare("UPDATE request_forms SET status = ? WHERE request_id = ? AND status = ?");
            $update->execute([$newStatus, $requestId, $expectedStatus]);
            if ($update->rowCount() !== 1) {
                if ($ownTransaction) { $pdo->rollBack(); }
                return ['ok' => false, 'message' => 'This request changed before your decision could be saved.'];
            }

            recordRequestAction($pdo, $requestId, $request['request_no'], $stepNo, $workflow[$stepNo]['label'],
                $actorUserId, $actorName, $actorRole, $action, $remarks, $signatureData);

            // Keep the canonical leave record in step, in this same
            // transaction, so `leave_requests` can never disagree with the
            // request form it belongs to. Each workflow step writes the
            // reviewer columns that step has always owned, so leave approved
            // through Request Forms looks identical to leave approved the old
            // way to every page that reads those columns.
            if ($request['request_type'] === 'LEAVE' && !empty($request['leave_id'])) {
                $leaveColumns = [
                    2 => ['manager_reviewed_by', 'manager_reviewed_at', 'manager_remarks'],
                    3 => ['hr_processed_by',     'hr_processed_at',     'hr_remarks'],
                    4 => ['reviewed_by',         'reviewed_at',         'review_remarks'],
                ];
                if (isset($leaveColumns[$stepNo])) {
                    [$byCol, $atCol, $remarkCol] = $leaveColumns[$stepNo];
                    // Those remark columns are varchar(255); trim rather than
                    // let a long remark fail the whole decision.
                    $pdo->prepare("UPDATE leave_requests
                            SET status = ?, $byCol = ?, $atCol = NOW(), $remarkCol = ?
                            WHERE leave_id = ?")
                        ->execute([$newStatus, $actorUserId, clampRemark($remarks) ?: null, (int)$request['leave_id']]);
                }
            }

            // An approved leave request blocks out the employee's attendance for
            // those dates, exactly as the existing leave approval flow does. Days
            // the employee already clocked in for are left untouched.
            if ($newStatus === 'APPROVED' && $request['request_type'] === 'LEAVE') {
                $dates = $pdo->prepare("SELECT date_from, date_to FROM request_forms WHERE request_id = ?");
                $dates->execute([$requestId]);
                $range = $dates->fetch();
                if ($range && !empty($range['date_from']) && !empty($range['date_to'])) {
                    $period = new DatePeriod(
                        new DateTime($range['date_from']),
                        new DateInterval('P1D'),
                        (new DateTime($range['date_to']))->modify('+1 day')
                    );
                    $upsert = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status, remarks)
                        VALUES (?,?,'ON_LEAVE','Auto-marked: approved leave request')
                        ON DUPLICATE KEY UPDATE
                            status  = IF(time_in IS NULL, 'ON_LEAVE', status),
                            remarks = IF(time_in IS NULL, 'Auto-marked: approved leave request', remarks)");
                    foreach ($period as $day) {
                        $upsert->execute([(int)$request['employee_id'], $day->format('Y-m-d')]);
                    }
                }
            }

            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('actOnRequest error: ' . $exception->getMessage());
            return ['ok' => false, 'message' => 'The request could not be processed. No changes were made.'];
        }

        notifyRequestParties($pdo, $requestId, $request, $newStatus, $stepNo, $actorName, $workflow);

        $verb = $action === 'FORWARDED'
            ? 'forwarded to ' . $workflow[$stepNo + 1]['role_label']
            : strtolower($action);

        return ['ok' => true, 'status' => $newStatus, 'message' => $request['request_no'] . ' ' . $verb . '.'];
    }
}

if (!function_exists('notifyRequestParties')) {
    /** Tell the filer what happened, and the next approver that they're up. Never throws. */
    function notifyRequestParties(PDO $pdo, int $requestId, array $request, string $newStatus, int $stepNo, string $actorName, array $workflow): void
    {
        try {
            $filer = $pdo->prepare("SELECT u.user_id FROM employees e JOIN users u ON e.user_id = u.user_id WHERE e.employee_id = ?");
            $filer->execute([(int)$request['employee_id']]);
            if ($filerUserId = $filer->fetchColumn()) {
                createNotification($pdo, (int)$filerUserId, 'Request ' . $request['request_no'] . ' updated',
                    $actorName . ' set it to ' . requestStatusLabel($newStatus) . '.', 'request_form', $requestId);
            }

            if (requestIsOpen($newStatus) && isset($workflow[$stepNo + 1])) {
                notifyRole($pdo, $workflow[$stepNo + 1]['role_id'], 'Request awaiting your action',
                    $request['request_no'] . ' has reached ' . $workflow[$stepNo + 1]['label'] . '.', 'request_form', $requestId);
            }
        } catch (Throwable $exception) {
            // Notifications are best-effort and must never break the workflow.
        }
    }
}

if (!function_exists('cancelRequestForm')) {
    /** The filer withdraws their own still-pending request. */
    function cancelRequestForm(PDO $pdo, int $requestId, int $employeeId, int $actorUserId, string $actorName, string $actorRole, string $reason = ''): array
    {
        $stmt = $pdo->prepare("SELECT request_no, status, request_type, leave_id FROM request_forms WHERE request_id = ? AND employee_id = ?");
        $stmt->execute([$requestId, $employeeId]);
        $request = $stmt->fetch();

        if (!$request || !requestIsOpen($request['status'])) {
            return ['ok' => false, 'message' => 'That request is no longer pending, or does not belong to you.'];
        }

        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare("UPDATE request_forms SET status = 'CANCELLED'
                WHERE request_id = ? AND employee_id = ? AND status IN ('PENDING_MANAGER','PENDING_HR','PENDING_APPROVAL')");
            $update->execute([$requestId, $employeeId]);
            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => 'That request is no longer pending.'];
            }
            if ($request['request_type'] === 'LEAVE' && !empty($request['leave_id'])) {
                $pdo->prepare("UPDATE leave_requests SET status = 'CANCELLED' WHERE leave_id = ?")
                    ->execute([(int)$request['leave_id']]);
            }
            recordRequestAction($pdo, $requestId, $request['request_no'], 1, 'Withdrawn by Employee',
                $actorUserId, $actorName, $actorRole, 'CANCELLED', $reason);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('cancelRequestForm error: ' . $exception->getMessage());
            return ['ok' => false, 'message' => 'The request could not be withdrawn.'];
        }

        return ['ok' => true, 'message' => $request['request_no'] . ' withdrawn.'];
    }
}

// ---------------------------------------------------------------------------
// Shared rendering
// ---------------------------------------------------------------------------

if (!function_exists('leaveBalanceFor')) {
    /**
     * Days of a given leave type an employee has already used this year, and
     * the entitlement for that type.
     *
     * This is the balance check HR Staff had on the old Leave Requests page,
     * carried over so nothing was lost when leave moved into Request Forms.
     * It reads `leave_requests`, which remains the canonical leave record.
     *
     * @return array{used:int, entitlement:int, remaining:int}|null
     */
    function leaveBalanceFor(PDO $pdo, int $employeeId, ?int $leaveTypeId): ?array
    {
        if (!$leaveTypeId) {
            return null;
        }
        $entitlement = $pdo->prepare("SELECT default_days FROM leave_types WHERE leave_type_id = ?");
        $entitlement->execute([$leaveTypeId]);
        $days = $entitlement->fetchColumn();
        if ($days === false) {
            return null;
        }

        $used = $pdo->prepare("SELECT COALESCE(SUM(total_days), 0) FROM leave_requests
            WHERE employee_id = ? AND leave_type_id = ?
              AND status = 'APPROVED' AND YEAR(date_from) = YEAR(CURDATE())");
        $used->execute([$employeeId, $leaveTypeId]);
        $usedDays = (int)$used->fetchColumn();

        return [
            'used'        => $usedDays,
            'entitlement' => (int)$days,
            'remaining'   => (int)$days - $usedDays,
        ];
    }
}

if (!function_exists('requestTypeSummary')) {
    /**
     * The type-specific field/value pairs shown in the document view and in the
     * reviewer tables, so all four pages describe a request identically.
     *
     * @return array<string, string> label => display value
     */
    function requestTypeSummary(array $request): array
    {
        switch ($request['request_type']) {
            case 'LEAVE':
                return [
                    'Leave Type'    => $request['leave_type_name'] ?? '-',
                    'Inclusive Dates' => fdate($request['date_from']) . ' to ' . fdate($request['date_to']),
                    'Total Days'    => (string)(int)$request['total_days'],
                ];
            case 'OVERTIME':
                return [
                    'Date of Overtime' => fdate($request['ot_date']),
                    'Time'             => substr((string)$request['ot_time_from'], 0, 5) . ' to ' . substr((string)$request['ot_time_to'], 0, 5),
                    'Estimated Hours'  => number_format((float)$request['ot_hours'], 2),
                ];
            case 'DOCUMENT':
                return [
                    'Document Requested' => $request['document_type'] ?? '-',
                    'Number of Copies'   => (string)(int)$request['copies'],
                    'Needed By'          => $request['needed_by'] ? fdate($request['needed_by']) : 'Not specified',
                    'Purpose'            => $request['purpose'] ?: '-',
                ];
            default:
                return [];
        }
    }
}

// ---------------------------------------------------------------------------
// PDF
// ---------------------------------------------------------------------------

if (!function_exists('buildRequestFormPdf')) {
    /**
     * Same document as request_view.php, as a downloadable PDF: letterhead,
     * Part I (employee), Part II (request details), Part III (approval /
     * e-signature history). Where a step has a drawn signature on file, the
     * actual strokes are reproduced (SimplePDF::signatureBlock()) instead of
     * a blank line.
     */
    function buildRequestFormPdf(PDO $pdo, int $requestId): ?array
    {
        require_once __DIR__ . '/../libs/simplepdf.php';
        require_once __DIR__ . '/contracts.php'; // companyIdentity()

        $request = getRequestForm($pdo, $requestId);
        if (!$request) {
            return null;
        }

        $company   = companyIdentity($pdo);
        $workflow  = requestWorkflow();
        $approvals = getRequestApprovals($pdo, $requestId);
        $summary   = requestTypeSummary($request);

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

        $pdf = new SimplePDF('Request Form');

        // ---- Letterhead ----
        $pdf->paragraph(pdfText($company['name']));
        if ($company['address']) { $pdf->paragraph(pdfText($company['address'])); }
        if ($company['contact']) { $pdf->paragraph(pdfText($company['contact'])); }
        $pdf->spacer(10);
        $pdf->h2(pdfText(requestTypeLabel($request['request_type'])));
        $pdf->paragraph('Request No.: ' . $request['request_no'] . '   |   Status: ' . requestStatusLabel($request['status']));
        $pdf->paragraph('Date Filed: ' . fdate($request['filed_at'], 'F d, Y'));
        $pdf->spacer(10);

        // ---- Part I ----
        $pdf->h2('I. Employee Details');
        $pdf->table(['Field', 'Value'], [
            ['Name', pdfText($request['first_name'] . ' ' . $request['last_name'])],
            ['Employee Code', (string)$request['employee_code']],
            ['Position', pdfText($request['position'] ?: '-')],
            ['Department', pdfText($request['department_name'] ?: '-')],
        ], [1, 2]);
        $pdf->spacer(6);

        // ---- Part II ----
        $pdf->h2('II. Request Details');
        $fieldRows = [
            ['Request Type', pdfText(requestTypeLabel($request['request_type']))],
            ['Subject', pdfText($request['subject'])],
        ];
        foreach ($summary as $label => $value) {
            $fieldRows[] = [pdfText($label), pdfText((string)$value)];
        }
        $pdf->table(['Field', 'Value'], $fieldRows, [1, 2]);
        if (!empty($request['details'])) {
            $pdf->spacer(4);
            $pdf->paragraph('Details / Reason:');
            $pdf->wrappedParagraph(pdfText($request['details']));
        }
        $pdf->spacer(10);

        // ---- Part III ----
        $pdf->h2('III. Approval and E-Signature History');
        $pdf->wrappedParagraph(
            'Each entry below is a permanent record of an action taken on this request, showing the acting '
            . 'user\'s name and role, the date and time, the action taken, and any remarks given.'
        );
        $pdf->spacer(6);

        foreach ($workflow as $stepNo => $step) {
            $entries = $actionsByStep[$stepNo] ?? [];
            if (empty($entries)) {
                $pdf->paragraph('Step ' . $stepNo . ' - ' . pdfText($step['label']) . ': not reached.');
                $pdf->spacer(4);
                continue;
            }
            foreach ($entries as $entry) {
                $pdf->paragraph('Step ' . (int)$entry['step_no'] . ' - ' . pdfText($entry['step_label']));
                if (!empty($entry['remarks'])) {
                    $pdf->wrappedParagraph('Remarks: ' . pdfText($entry['remarks']));
                }
                $strokes = [];
                if (!empty($entry['signature_data'])) {
                    $decoded = json_decode($entry['signature_data'], true);
                    if (is_array($decoded)) { $strokes = $decoded; }
                }
                $pdf->signatureBlock($strokes, [
                    pdfText($entry['actor_name']),
                    pdfText($entry['actor_role']),
                    ($actionVerb[$entry['action']] ?? $entry['action']) . ' - ' . fdate($entry['acted_at'], 'M d, Y g:i A'),
                    'Ref: ' . $entry['signature_hash'],
                ]);
            }
        }

        $pdf->spacer(6);
        $pdf->wrappedParagraph(
            'Request No. ' . $request['request_no'] . ', filed ' . fdate($request['filed_at'], 'F d, Y g:i A')
            . ' and currently ' . requestStatusLabel($request['status']) . '. Approval route: '
            . implode(' -> ', array_map('pdfText', array_column($workflow, 'role_label'))) . '.'
        );

        return ['pdf' => $pdf, 'filename' => $request['request_no'] . '.pdf'];
    }
}
