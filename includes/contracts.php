<?php
/**
 * EMPLOYMENT CONTRACT No. + E-SIGNATURES
 *
 * This does NOT introduce a second contract system. The contract itself is
 * still the one that already existed: its content is assembled from
 * job_applications / job_offers by buildEmploymentContractPdf() in
 * includes/functions.php, and it is still reached from the same places.
 *
 * What is added here is the record layer around that existing contract:
 *
 *   - a unique Contract No. issued when hiring is finalised, and
 *   - the two e-signatures (employee + authorised company representative)
 *     that appear at the bottom of the contract document.
 *
 * Both live in `employment_contracts`, keyed to the application the contract
 * was generated from and to the employee record created from it.
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/doc_numbers.php';

if (!function_exists('issueContractNumber')) {
    /**
     * Create (or return the existing) contract record for an application.
     *
     * Called at the point hiring is finalised - i.e. from the "create employee
     * record" action, inside that action's transaction. Idempotent: calling it
     * twice for the same application returns the same Contract No. rather than
     * issuing a second one.
     *
     * @return string|null The Contract No., or null if no job offer exists yet.
     */
    function issueContractNumber(PDO $pdo, int $applicationId, ?int $employeeId = null, ?int $generatedBy = null): ?string
    {
        $existing = $pdo->prepare("SELECT contract_id, contract_no, employee_id FROM employment_contracts WHERE application_id = ?");
        $existing->execute([$applicationId]);
        if ($row = $existing->fetch()) {
            // Backfill the employee link if the contract was numbered before
            // the employee record existed.
            if ($employeeId && empty($row['employee_id'])) {
                $pdo->prepare("UPDATE employment_contracts SET employee_id = ? WHERE contract_id = ?")
                    ->execute([$employeeId, (int)$row['contract_id']]);
            }
            return $row['contract_no'];
        }

        // A contract only exists once an offer has been made - the same
        // precondition buildEmploymentContractPdf() already enforces.
        $offer = $pdo->prepare("SELECT offer_id FROM job_offers WHERE application_id = ? ORDER BY offer_id DESC LIMIT 1");
        $offer->execute([$applicationId]);
        $offerId = $offer->fetchColumn();
        if (!$offerId) {
            return null;
        }

        $contractNo = nextDocumentNumber($pdo, 'EC', 'EC');
        $pdo->prepare("INSERT INTO employment_contracts (contract_no, application_id, employee_id, offer_id, generated_by, generated_at)
                       VALUES (?, ?, ?, ?, ?, NOW())")
            ->execute([$contractNo, $applicationId, $employeeId, (int)$offerId, $generatedBy]);

        return $contractNo;
    }
}

if (!function_exists('getContractByApplication')) {
    /**
     * Fetch the contract record for an application.
     *
     * If $autoIssue is true and the application has an offer but no contract
     * record yet, one is created on the spot. That is what gives a Contract No.
     * to employees who were hired before this feature existed, without anyone
     * having to run a backfill script.
     */
    function getContractByApplication(PDO $pdo, int $applicationId, bool $autoIssue = false): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM employment_contracts WHERE application_id = ?");
        $stmt->execute([$applicationId]);
        $contract = $stmt->fetch();

        if (!$contract && $autoIssue) {
            // Link it to the employee record if the applicant has already been
            // converted, so Employee Profile can find it by employee_id too.
            $emp = $pdo->prepare("SELECT e.employee_id FROM job_applications ja
                JOIN applicants a ON ja.applicant_id = a.applicant_id
                JOIN employees e ON e.user_id = a.user_id
                WHERE ja.application_id = ?");
            $emp->execute([$applicationId]);
            $employeeId = $emp->fetchColumn();

            try {
                if (issueContractNumber($pdo, $applicationId, $employeeId ? (int)$employeeId : null, $_SESSION['user_id'] ?? null)) {
                    $stmt->execute([$applicationId]);
                    $contract = $stmt->fetch();
                }
            } catch (Throwable $exception) {
                error_log('contract auto-issue failed for application ' . $applicationId . ': ' . $exception->getMessage());
            }
        }

        return $contract ?: null;
    }
}

if (!function_exists('getContractByEmployee')) {
    /**
     * Fetch an employee's contract record, for display on Employee Profile and
     * the HR employee list. Falls back to matching through the applicant's
     * hired application when employee_id was never filled in.
     */
    function getContractByEmployee(PDO $pdo, int $employeeId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM employment_contracts WHERE employee_id = ? ORDER BY contract_id DESC LIMIT 1");
        $stmt->execute([$employeeId]);
        if ($contract = $stmt->fetch()) {
            return $contract;
        }

        $viaApplication = $pdo->prepare("SELECT ec.* FROM employment_contracts ec
            JOIN job_applications ja ON ec.application_id = ja.application_id
            JOIN applicants a ON ja.applicant_id = a.applicant_id
            JOIN employees e ON e.user_id = a.user_id
            WHERE e.employee_id = ? ORDER BY ec.contract_id DESC LIMIT 1");
        $viaApplication->execute([$employeeId]);
        return $viaApplication->fetch() ?: null;
    }
}

if (!function_exists('employeeContractNumbers')) {
    /**
     * Contract numbers for a batch of employees, keyed by employee_id.
     * Used by list pages so they don't run one query per row.
     *
     * @param int[] $employeeIds
     * @return array<int, string>
     */
    function employeeContractNumbers(PDO $pdo, array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (empty($employeeIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmt = $pdo->prepare("SELECT employee_id, contract_no FROM employment_contracts
            WHERE employee_id IN ($placeholders) AND status = 'ACTIVE'");
        $stmt->execute($employeeIds);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int)$row['employee_id']] = $row['contract_no'];
        }
        return $map;
    }
}

if (!function_exists('signContract')) {
    /**
     * Record one of the two e-signatures on a contract.
     *
     * @param string $party 'employee' or 'employer'
     * @return bool false if that party has already signed (signatures are not
     *              overwritten - a signed contract stays signed).
     */
    function signContract(PDO $pdo, int $contractId, string $party, int $userId, string $name, string $role): bool
    {
        if (!in_array($party, ['employee', 'employer'], true)) {
            return false;
        }

        $current = $pdo->prepare("SELECT contract_no, employee_signed_at, employer_signed_at FROM employment_contracts WHERE contract_id = ?");
        $current->execute([$contractId]);
        $contract = $current->fetch();
        if (!$contract || !empty($contract[$party . '_signed_at'])) {
            return false;
        }

        $signedAt = date('Y-m-d H:i:s');
        $hash = signatureHash($contract['contract_no'], $name, strtoupper($party) . '_SIGNATURE', $signedAt);

        $update = $pdo->prepare("UPDATE employment_contracts
            SET {$party}_signed_by = ?, {$party}_signed_name = ?, {$party}_signed_role = ?,
                {$party}_signed_at = ?, {$party}_signature_hash = ?
            WHERE contract_id = ? AND {$party}_signed_at IS NULL");
        $update->execute([$userId, $name, $role, $signedAt, $hash, $contractId]);

        return $update->rowCount() === 1;
    }
}

if (!function_exists('contractIsFullySigned')) {
    function contractIsFullySigned(?array $contract): bool
    {
        return $contract && !empty($contract['employee_signed_at']) && !empty($contract['employer_signed_at']);
    }
}

if (!function_exists('canSignAsEmployer')) {
    /**
     * Which roles may sign a contract on the company's behalf. HR Manager is
     * the authorised representative; the Owner may also sign.
     */
    function canSignAsEmployer(int $roleId): bool
    {
        return in_array($roleId, [ROLE_HR_MANAGER, ROLE_OWNER], true);
    }
}

// ---------------------------------------------------------------------------
// Contract body
// ---------------------------------------------------------------------------

if (!function_exists('employmentContractClauses')) {
    /**
     * The numbered clauses of the employment contract, as structured data.
     *
     * This is the single source of the contract's wording. Both renderers use
     * it - the PDF in buildEmploymentContractPdf() and the on-screen document
     * on the Employment Contract page - so the two can never drift apart. The
     * text is unchanged from the original contract; only its storage moved.
     *
     * @param array $c Resolved contract values: job_title, department_name,
     *                 branch_name, branch_address, employment_type_label,
     *                 is_probationary, start_date, salary, shift_label.
     * @return array<int, array{heading:string, paragraphs:string[]}>
     */
    function employmentContractClauses(array $c): array
    {
        $jobTitle        = $c['job_title'] ?? '';
        $departmentName  = $c['department_name'] ?? '';
        $branchName      = $c['branch_name'] ?? '';
        $branchAddress   = $c['branch_address'] ?? '';
        $typeLabel       = $c['employment_type_label'] ?? '';
        $isProbationary  = !empty($c['is_probationary']);
        $startDate       = $c['start_date'] ?? '';
        $salary          = $c['salary'] ?? '';
        $shiftLabel      = $c['shift_label'] ?? '';

        $clauses = [];

        $clauses[] = ['heading' => '1. POSITION AND DUTIES', 'paragraphs' => [
            'The Employee is hired as ' . $jobTitle
                . ($departmentName ? ' in the ' . $departmentName . ' department' : '')
                . ($branchName ? ', assigned to ' . $branchName . '.' : '.'),
            'The Employee shall perform the duties and responsibilities associated with the position, as well as other '
                . 'reasonable tasks related to the operations of the cafe that may be assigned by the Employee\'s immediate '
                . 'supervisor or authorized management representative.',
            'The Employee agrees to perform their duties responsibly, honestly, and in accordance with the company\'s '
                . 'policies, procedures, and standards.',
        ]];

        if ($isProbationary) {
            $statusParagraphs = [
                'The Employee shall be employed on a probationary basis beginning ' . $startDate . '.',
                'The probationary period shall not exceed the period allowed by applicable Philippine labor laws. '
                    . 'During this period, the Employee shall be evaluated based on the company\'s reasonable and '
                    . 'communicated standards for regularization, including job performance, attendance, work attitude, '
                    . 'compliance with company policies, and ability to perform the duties of the position.',
                'Upon successful completion of the probationary period and satisfaction of the applicable requirements, '
                    . 'the Employee may be confirmed as a regular employee in accordance with applicable law.',
            ];
        } else {
            $statusParagraphs = [
                'The Employee is engaged on a ' . strtolower($typeLabel) . ' basis beginning ' . $startDate
                    . ', subject to the terms of this Contract and applicable Philippine labor laws.',
            ];
        }
        $clauses[] = ['heading' => '2. EMPLOYMENT STATUS', 'paragraphs' => $statusParagraphs];

        $clauses[] = ['heading' => '3. PLACE OF WORK', 'paragraphs' => [
            'The Employee shall primarily work at ' . ($branchName ?: '_______________________________')
                . ($branchAddress ? ', located at ' . $branchAddress . '.' : '.'),
            'The Employer may reasonably assign the Employee to another branch or work location when operational '
                . 'requirements call for it, subject to applicable laws and company policies.',
        ]];

        $clauses[] = ['heading' => '4. COMPENSATION', 'paragraphs' => [
            'The Employee shall receive a basic salary of ' . $salary . ' per month.',
            'Salary shall be paid according to the Employer\'s established payroll cut-off of every ' . (int) PAYROLL_CUTOFF_DAYS
                . ' days, subject to applicable deductions required by law and authorized deductions.',
            'The Employee shall receive all compensation, overtime pay, holiday pay, night shift differential, and '
                . 'other benefits required by applicable Philippine laws, rules, and regulations, when applicable.',
        ]];

        $clauses[] = ['heading' => '5. WORKING HOURS AND SCHEDULE', 'paragraphs' => [
            'The Employee\'s regular work schedule shall be based on the cafe\'s operational requirements and the '
                . 'schedule assigned by management. The Employee\'s recorded shift assignment is ' . $shiftLabel . '.',
            'Regular working hours shall generally be ' . (int) WORK_HOURS_PER_DAY . ' hours per day across '
                . (int) WORK_DAYS_PER_WEEK . ' days per week, exclusive of meal periods, subject to applicable Philippine labor laws.',
            'The Employee may be required to work on weekends or holidays depending on the cafe\'s operating schedule. '
                . 'Work performed beyond regular working hours shall be compensated in accordance with applicable law and '
                . 'company policy.',
            'The Employee shall properly record their attendance through the company\'s designated attendance or '
                . 'timekeeping system.',
        ]];

        $clauses[] = ['heading' => '6. OVERTIME', 'paragraphs' => [
            'Overtime work must be authorized or approved by the Employee\'s immediate supervisor or authorized '
                . 'management representative.',
            'Approved overtime shall be compensated in accordance with applicable Philippine labor laws and regulations.',
            'Unauthorized overtime may be subject to company rules regarding timekeeping and scheduling, without '
                . 'prejudice to the Employee\'s statutory rights.',
        ]];

        $clauses[] = ['heading' => '7. STATUTORY BENEFITS AND CONTRIBUTIONS', 'paragraphs' => [
            'The Employer shall provide and remit applicable statutory contributions and benefits in accordance with Philippine laws, including, when applicable:',
            "- Social Security System (SSS)\n"
                . "- Philippine Health Insurance Corporation (PhilHealth)\n"
                . "- Home Development Mutual Fund (Pag-IBIG)\n"
                . "- 13th Month Pay\n"
                . "- Service Incentive Leave, when applicable\n"
                . "- Holiday pay and other statutory benefits, when applicable",
            'Employee contributions shall be deducted from the Employee\'s salary as required by law.',
        ]];

        $clauses[] = ['heading' => '8. COMPANY POLICIES AND WORKPLACE CONDUCT', 'paragraphs' => [
            'The Employee agrees to comply with the Employer\'s reasonable workplace policies and procedures, including but not limited to:',
            "- Attendance and punctuality\n"
                . "- Proper uniform and personal hygiene\n"
                . "- Food safety and sanitation procedures\n"
                . "- Proper handling of company equipment and property\n"
                . "- Customer service standards\n"
                . "- Workplace safety rules\n"
                . "- Cash-handling procedures, when applicable\n"
                . "- Data privacy and confidentiality\n"
                . "- Anti-harassment and respectful workplace policies",
            'The Employee shall be given access to applicable company rules and policies and shall be responsible for complying with them.',
        ]];

        $clauses[] = ['heading' => '9. CONFIDENTIALITY', 'paragraphs' => [
            'During and after employment, the Employee shall maintain the confidentiality of non-public company '
                . 'information obtained through their employment, including customer information, employee information, '
                . 'business records, sales information, passwords, recipes, operating procedures, and other confidential '
                . 'business information.',
            'The Employee shall not disclose or use confidential information for purposes unrelated to their '
                . 'employment without proper authorization, except when disclosure is required by law.',
        ]];

        $clauses[] = ['heading' => '10. COMPANY PROPERTY', 'paragraphs' => [
            'All company property entrusted to the Employee, including uniforms, identification cards, keys, '
                . 'equipment, documents, devices, and other materials, shall remain the property of the Employer.',
            'The Employee shall take reasonable care of company property and shall return all company property upon '
                . 'request or upon the end of employment, subject to applicable law.',
        ]];

        $clauses[] = ['heading' => '11. DATA PRIVACY', 'paragraphs' => [
            'The Employee acknowledges that the Employer may collect and process personal information necessary for '
                . 'legitimate employment purposes, including payroll, attendance, benefits administration, government '
                . 'compliance, and other lawful employment-related activities.',
            'The Employer shall process such information in accordance with applicable Philippine data privacy laws and regulations.',
        ]];

        $clauses[] = ['heading' => '12. PERFORMANCE AND DISCIPLINE', 'paragraphs' => [
            'The Employee\'s performance, attendance, conduct, and compliance with company policies may be evaluated during employment.',
            'Violations of company rules may result in appropriate disciplinary action in accordance with the Employer\'s policies and applicable Philippine labor laws.',
            'The Employee shall be given the applicable notice and opportunity to be heard whenever required by law.',
        ]];

        $clauses[] = ['heading' => '13. TERMINATION OF EMPLOYMENT', 'paragraphs' => [
            'Employment may end through resignation, authorized causes, just causes, expiration of a lawful fixed-term '
                . 'arrangement, failure to meet lawful probationary standards, or other grounds recognized under applicable '
                . 'Philippine labor laws.',
            'Any termination shall be carried out in accordance with applicable legal requirements and the Employee\'s statutory rights.',
            'Upon separation from employment, the Employee shall complete the required clearance and return company property in their possession.',
        ]];

        $clauses[] = ['heading' => '14. AMENDMENTS', 'paragraphs' => [
            'Any amendment or modification to this Contract shall be made in writing and acknowledged by both parties, '
                . 'provided that no amendment shall reduce any right or benefit granted to the Employee by applicable law.',
        ]];

        $clauses[] = ['heading' => '15. GOVERNING LAW', 'paragraphs' => [
            'This Contract shall be governed by and interpreted in accordance with the laws of the Republic of the Philippines.',
            'Any provision of this Contract that is found to be inconsistent with mandatory provisions of applicable '
                . 'law shall be interpreted or modified only to the extent necessary to comply with such law, without '
                . 'affecting the validity of the remaining provisions.',
        ]];

        $clauses[] = ['heading' => '16. ENTIRE AGREEMENT', 'paragraphs' => [
            'This Contract, together with applicable company policies and documents properly provided to the Employee, '
                . 'constitutes the understanding between the Employer and Employee regarding the terms of employment.',
            'The Employee acknowledges that they have read and understood the terms of this Contract and have been '
                . 'given an opportunity to ask questions regarding its contents before signing.',
        ]];

        return $clauses;
    }
}

if (!function_exists('employmentContractData')) {
    /**
     * The source record for a contract: applicant, vacancy, department,
     * branch, and the job offer whose terms the contract states.
     *
     * A contract only exists once a job offer has been made, so this returns
     * null when there is no job_offers row - the same precondition the
     * download has always enforced.
     *
     * Shared by the PDF builder and the on-screen contract document.
     */
    function employmentContractData(PDO $pdo, int $appId): ?array
{
    $stmt = $pdo->prepare("SELECT ja.status,
            COALESCE(a.first_name, u.first_name) AS first_name, COALESCE(a.last_name, u.last_name) AS last_name,
            a.address, a.user_id AS applicant_user_id,
            jv.title AS job_title, d.department_name,
            b.branch_name, b.address AS branch_address,
            jo.*, jo.remarks AS offer_details
        FROM job_applications ja
        JOIN applicants a ON ja.applicant_id = a.applicant_id
        LEFT JOIN users u ON a.user_id = u.user_id
        JOIN job_vacancies jv ON ja.job_id = jv.job_id
        LEFT JOIN departments d ON jv.department_id = d.department_id
        LEFT JOIN branches b ON jv.branch_id = b.branch_id
        JOIN job_offers jo ON jo.application_id = ja.application_id
        WHERE ja.application_id = ?");
    $stmt->execute([$appId]);
    return $stmt->fetch() ?: null;
}
}

if (!function_exists('companyIdentity')) {
    /** Company name / address / contact, from system_settings with sane fallbacks. */
    function companyIdentity(PDO $pdo): array
    {
        $map = [];
        try {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings
                WHERE setting_key IN ('company_name','company_address','company_contact')")->fetchAll();
            foreach ($rows as $row) {
                $map[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $exception) {
            // fall through to defaults
        }
        return [
            'name'    => $map['company_name'] ?? APP_NAME,
            'address' => $map['company_address'] ?? '',
            'contact' => $map['company_contact'] ?? '',
        ];
    }
}
