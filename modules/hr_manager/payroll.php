<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole([ROLE_HR_MANAGER]);

$userId = $_SESSION['user_id'];

$parseMoney = static function ($value): ?int {
  $value = trim((string)$value);
  if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $value)) {
    return null;
  }
  [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
  $cents = (int)str_pad($fraction, 2, '0');
  return ((int)$whole * 100) + $cents;
};
$moneyValue = static fn(int $cents): string => number_format($cents / 100, 2, '.', '');
$maxMoneyCents = 999999999999; // DECIMAL(12,2) maximum
$lockAttendanceDeductions = static function (PDO $pdo, int $employeeId, string $start, string $end, ?int $payrollId = null) use ($parseMoney): array {
  $sql = "SELECT ad.deduction_id, ad.amount
    FROM attendance_deductions ad JOIN attendance a ON a.attendance_id=ad.attendance_id
    WHERE ad.employee_id=? AND a.attendance_date BETWEEN ? AND ? AND (ad.included_in_payroll_id IS NULL";
  $params = [$employeeId, $start, $end];
  if ($payrollId !== null) {
    $sql .= " OR ad.included_in_payroll_id=?";
    $params[] = $payrollId;
  }
  $sql .= ") FOR UPDATE";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $ids = [];
  $totalCents = 0;
  foreach ($stmt->fetchAll() as $deduction) {
    $amountCents = $parseMoney($deduction['amount']);
    if ($amountCents === null) {
      return [null, []];
    }
    $ids[] = (int)$deduction['deduction_id'];
    $totalCents += $amountCents;
  }
  return [$totalCents, $ids];
};

$calculateHours = static function (array $rows): array {
  $regularHours = 0.0;
  $overtimeHours = 0.0;
  $absentDays = 0;
  foreach ($rows as $row) {
    if ($row['status'] === 'ABSENT') { $absentDays++; continue; }
    if ($row['status'] === 'ON_LEAVE') continue;
    if (empty($row['time_in']) || empty($row['time_out'])) {
      if ($row['status'] === 'HALF_DAY') $regularHours += WORK_HOURS_PER_DAY / 2;
      continue;
    }
    $clockIn = strtotime($row['attendance_date'] . ' ' . $row['time_in']);
    $clockOut = strtotime($row['attendance_date'] . ' ' . $row['time_out']);
    if ($clockIn === false || $clockOut === false) continue;
    if ($clockOut <= $clockIn) $clockOut += 86400;
    $workedHours = ($clockOut - $clockIn) / 3600;
    if ($workedHours < 0 || $workedHours > 24) continue;
    $regularHours += min($workedHours, WORK_HOURS_PER_DAY);
    $overtimeHours += max(0, $workedHours - WORK_HOURS_PER_DAY);
  }
  return [round($regularHours, 2), round($overtimeHours, 2), $absentDays];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['prepare_batch', 'submit_payroll'], true)) {
  csrfVerify();
  $action = $_POST['action'];
  if ($action === 'prepare_batch') {
    $branchId = filter_var($_POST['branch_id'] ?? null, FILTER_VALIDATE_INT);
    $month = $_POST['payroll_month'] ?? date('Y-m');
    $cutoff = $_POST['payroll_cutoff'] ?? '1st';
    $start = '';
    $end = '';
    if (preg_match('/^\d{4}-\d{2}$/', $month)) {
        if ($cutoff === '1st') {
            $start = $month . '-01';
            $end = $month . '-15';
        } else {
            $start = $month . '-16';
            $end = date('Y-m-t', strtotime($month . '-01'));
        }
    }
    try {
      if ($branchId === false || $branchId < 1) throw new InvalidArgumentException('Select a valid branch.');
      $batch = preparePayrollBatch($pdo, $branchId, $start, $end, (int)$userId);
      setFlash('success', $batch['count'] . ' payroll record(s) prepared for HR review. Adjust and submit each record to the Owner.');
      logAudit($pdo, $userId, 'PREPARE_PAYROLL_BATCH', 'Payroll', "branch_id={$batch['branch_id']} period={$batch['period_start']}:{$batch['period_end']} count={$batch['count']}");
    } catch (Throwable $exception) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      setFlash('error', $exception instanceof InvalidArgumentException || $exception instanceof RuntimeException ? $exception->getMessage() : 'Payroll batch preparation failed. No records were changed.');
    }
    redirect('modules/hr_manager/payroll.php');
  }

  $payrollId = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT);
  $overtimeCents = $parseMoney($_POST['overtime_pay'] ?? '');
  $allowanceCents = $parseMoney($_POST['allowances'] ?? '');
  $otherDeductionCents = $parseMoney($_POST['other_deductions'] ?? '');
  $taxCents = $parseMoney($_POST['tax'] ?? '');
  $remarksInput = $_POST['adjustment_remarks'] ?? '';
  $adjustmentRemarks = is_string($remarksInput) ? trim($remarksInput) : '';
  if ($payrollId === false || $payrollId < 1 || $overtimeCents === null || $allowanceCents === null || $otherDeductionCents === null || $taxCents === null || $adjustmentRemarks === '' || strlen($adjustmentRemarks) > 500) {
    setFlash('error', 'Enter valid payroll adjustments and adjustment remarks (500 characters maximum).');
    redirect('modules/hr_manager/payroll.php');
  }

  try {
    $pdo->beginTransaction();
    $payrollStmt = $pdo->prepare("SELECT p.* FROM payroll p WHERE p.payroll_id=? FOR UPDATE");
    $payrollStmt->execute([$payrollId]);
    $payroll = $payrollStmt->fetch();
    $isRejected = $payroll && $payroll['status'] === 'DRAFT' && !empty($payroll['approval_remarks']);
    if (!$payroll || !($payroll['status'] === 'PREPARED' || $isRejected) || empty($payroll['branch_id'])) {
      throw new RuntimeException('This payroll is no longer available for HR review.');
    }
    $attendanceCents = $parseMoney($payroll['attendance_deductions']);
    $absenceCents = $parseMoney($payroll['absence_deductions']);
    $basicCents = $parseMoney($payroll['basic_pay']);
    if ($attendanceCents === null || $absenceCents === null || $basicCents === null) throw new RuntimeException('Stored payroll amounts are invalid.');
    $deductionCents = $attendanceCents + $absenceCents + $otherDeductionCents;
    $grossCents = $basicCents + $overtimeCents + $allowanceCents;
    $netCents = $grossCents - $deductionCents - $taxCents;
    if ($netCents < 0 || $grossCents > $maxMoneyCents || $netCents > $maxMoneyCents) throw new RuntimeException('Deductions and tax cannot exceed gross pay.');

    $update = $pdo->prepare("UPDATE payroll SET overtime_pay=?, allowances=?, deductions=?, tax=?, net_pay=?, adjustment_remarks=?, status='SUBMITTED', approval_remarks=NULL, approved_by=NULL, approved_at=NULL
      WHERE payroll_id=? AND (status='PREPARED' OR (status='DRAFT' AND approval_remarks IS NOT NULL))");
    $update->execute([$moneyValue($overtimeCents), $moneyValue($allowanceCents), $moneyValue($deductionCents), $moneyValue($taxCents), $moneyValue($netCents), $adjustmentRemarks, $payrollId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Payroll changed before it could be submitted.');
    $pdo->commit();
    setFlash('success', 'Payroll reviewed and submitted to the Owner.');
    logAudit($pdo, $userId, 'SUBMIT_PAYROLL', 'Payroll', "payroll_id=$payrollId gross=" . $moneyValue($grossCents) . ' net=' . $moneyValue($netCents) . " remarks=$adjustmentRemarks");
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    setFlash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Payroll could not be submitted. No records were changed.');
  }
  redirect('modules/hr_manager/payroll.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['legacy_generate_disabled', 'legacy_resubmit_disabled', 'generate', 'resubmit'], true)) {
      setFlash('error', 'This payroll action is no longer supported. Prepare a branch payroll and submit reviewed records instead.');
      redirect('modules/hr_manager/payroll.php');
    }

    if ($action === 'legacy_generate_disabled') {
      $empId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
      $start = trim($_POST['pay_period_start'] ?? '');
      $end = trim($_POST['pay_period_end'] ?? '');
      $otCents = $parseMoney($_POST['overtime_pay'] ?? '');
      $allowCents = $parseMoney($_POST['allowances'] ?? '');
      $otherDedCents = $parseMoney($_POST['deductions'] ?? '');
      $taxCents = $parseMoney($_POST['tax'] ?? '');

        $currentYear = (int)date('Y');

        $errors = [];
      $employee = null;
      if ($empId === false || $empId < 1) {
        $errors[] = 'Please select a valid employee.';
      } else {
        $employeeStmt = $pdo->prepare("SELECT e.employee_id, e.basic_salary FROM employees e JOIN users u ON e.user_id=u.user_id
          WHERE e.employee_id=? AND e.employment_status='ACTIVE' AND u.status='ACTIVE'");
        $employeeStmt->execute([$empId]);
        $employee = $employeeStmt->fetch();
        if (!$employee) { $errors[] = 'That employee is not active or does not exist.'; }
      }
        if ($start === '' || $end === '') {
            $errors[] = 'Please select both Period Start and Period End.';
        } else {
        $startDate = DateTime::createFromFormat('!Y-m-d', $start);
        $endDate = DateTime::createFromFormat('!Y-m-d', $end);
        if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
                $errors[] = 'Please enter valid dates for the payroll period.';
            } elseif ($endDate < $startDate) {
                $errors[] = 'Period End cannot be earlier than Period Start.';
            } elseif ((int)$startDate->format('Y') !== $currentYear || (int)$endDate->format('Y') !== $currentYear) {
                $errors[] = "Pay period dates must fall within the current year ($currentYear).";
            }
        }

            foreach (['overtime_pay' => $otCents, 'allowances' => $allowCents, 'deductions' => $otherDedCents, 'tax' => $taxCents] as $field => $value) {
              if ($value === null) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid non-negative amount with at most two decimals.';
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        } else {
              try {
                    $pdo->beginTransaction();
                    $employeeLock = $pdo->prepare("SELECT e.employee_id, e.basic_salary FROM employees e JOIN users u ON e.user_id=u.user_id
                      WHERE e.employee_id=? AND e.employment_status='ACTIVE' AND u.status='ACTIVE' FOR UPDATE");
                    $employeeLock->execute([$empId]);
                    $lockedEmployee = $employeeLock->fetch();
                    if (!$lockedEmployee) {
                      throw new RuntimeException('That employee is no longer active.');
                    }
                    $basicCents = $parseMoney($lockedEmployee['basic_salary']);
                    if ($basicCents === null) {
                      throw new RuntimeException('The employee has an invalid basic salary.');
                    }
                    $duplicate = $pdo->prepare('SELECT payroll_id FROM payroll WHERE employee_id=? AND pay_period_start=? AND pay_period_end=? LIMIT 1 FOR UPDATE');
                    $duplicate->execute([$empId, $start, $end]);
                    if ($duplicate->fetch()) {
                      throw new RuntimeException('Payroll already exists for this employee and pay period.');
                    }
                    [$attendanceDedCents, $deductionIds] = $lockAttendanceDeductions($pdo, $empId, $start, $end);
                    $totalDedCents = $attendanceDedCents === null || $otherDedCents === null ? null : $attendanceDedCents + $otherDedCents;
                    $grossCents = $basicCents + $otCents + $allowCents;
                    $netCents = $totalDedCents === null ? null : $grossCents - $totalDedCents - $taxCents;
                    if ($totalDedCents === null || $grossCents > $maxMoneyCents || $netCents < 0 || $netCents > $maxMoneyCents) {
                      throw new RuntimeException('Deductions and tax cannot exceed Basic Pay + Overtime + Allowances (Net Pay cannot be negative).');
                    }
                    $pdo->prepare("INSERT INTO payroll (employee_id, pay_period_start, pay_period_end, basic_pay, overtime_pay, allowances, deductions, tax, net_pay, status, generated_by) VALUES (?,?,?,?,?,?,?,?,?, 'DRAFT', ?)")
                       ->execute([$empId, $start, $end, $moneyValue($basicCents), $moneyValue($otCents), $moneyValue($allowCents), $moneyValue($totalDedCents), $moneyValue($taxCents), $moneyValue($netCents), $userId]);
                    $newPayrollId = (int)$pdo->lastInsertId();
                    if ($deductionIds) {
                      $placeholders = implode(',', array_fill(0, count($deductionIds), '?'));
                      $claim = $pdo->prepare("UPDATE attendance_deductions SET included_in_payroll_id=?
                        WHERE deduction_id IN ($placeholders) AND included_in_payroll_id IS NULL");
                      $claim->execute(array_merge([$newPayrollId], $deductionIds));
                      if ($claim->rowCount() !== count($deductionIds)) {
                        throw new RuntimeException('Attendance deductions were already assigned to another payroll.');
                      }
                    }
                    $pdo->commit();
                    setFlash('success', 'Payroll generated and sent to the Owner for approval.');
                    logAudit($pdo, $userId, 'GENERATE_PAYROLL', 'Payroll', "employee_id=$empId period=$start:$end basic=" . $moneyValue($basicCents) . " net=" . $moneyValue($netCents));
                  } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    setFlash('error', 'Payroll could not be generated. No records were changed.');
                  }
        }
    } elseif ($action === 'legacy_resubmit_disabled') {
        // Correct a payroll the Owner rejected (approval_remarks is set) and resubmit it
      $payrollId = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT);
      $stmt = $pdo->prepare("SELECT p.*, e.basic_salary FROM payroll p JOIN employees e ON p.employee_id=e.employee_id
        JOIN users u ON e.user_id=u.user_id
        WHERE p.payroll_id=? AND p.status='DRAFT' AND p.approval_remarks IS NOT NULL AND p.approved_by IS NOT NULL
        AND e.employment_status='ACTIVE' AND u.status='ACTIVE'");
        $stmt->execute([$payrollId]);
        $row = $stmt->fetch();
        if (!$row) {
        setFlash('error', 'Only payroll returned by the Owner for correction can be resubmitted.');
            redirect('modules/hr_manager/payroll.php');
        }
      $basicCents = $parseMoney($row['basic_salary']);
      $otCents = $parseMoney($_POST['overtime_pay'] ?? '');
      $allowCents = $parseMoney($_POST['allowances'] ?? '');
      $otherDedCents = $parseMoney($_POST['deductions'] ?? '');
      $taxCents = $parseMoney($_POST['tax'] ?? '');

        $errors = [];
      foreach (['overtime_pay' => $otCents, 'allowances' => $allowCents, 'deductions' => $otherDedCents, 'tax' => $taxCents] as $field => $value) {
        if ($value === null) {
          $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a valid non-negative amount with at most two decimals.';
            }
        }
      if ($basicCents === null) { $errors[] = 'The employee has an invalid basic salary.'; }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                setFlash('error', $error);
            }
        } else {
          try {
            $pdo->beginTransaction();
            $employeeLock = $pdo->prepare("SELECT e.employee_id, e.basic_salary FROM employees e JOIN users u ON e.user_id=u.user_id
              WHERE e.employee_id=? AND e.employment_status='ACTIVE' AND u.status='ACTIVE' FOR UPDATE");
            $employeeLock->execute([$row['employee_id']]);
            $lockedEmployee = $employeeLock->fetch();
            if (!$lockedEmployee) {
              throw new RuntimeException('That employee is no longer active.');
            }
            $payrollLock = $pdo->prepare("SELECT payroll_id FROM payroll
              WHERE payroll_id=? AND status='DRAFT' AND approval_remarks IS NOT NULL AND approved_by IS NOT NULL FOR UPDATE");
            $payrollLock->execute([$payrollId]);
            if (!$payrollLock->fetch()) {
              throw new RuntimeException('Payroll is no longer available for resubmission.');
            }
            $basicCents = $parseMoney($lockedEmployee['basic_salary']);
            [$attendanceDedCents, $deductionIds] = $lockAttendanceDeductions($pdo, (int)$row['employee_id'], $row['pay_period_start'], $row['pay_period_end'], $payrollId);
            $totalDedCents = $attendanceDedCents === null || $otherDedCents === null ? null : $attendanceDedCents + $otherDedCents;
            $grossCents = $basicCents + $otCents + $allowCents;
            $netCents = $totalDedCents === null ? null : $grossCents - $totalDedCents - $taxCents;
            if ($totalDedCents === null || $grossCents > $maxMoneyCents || $netCents < 0 || $netCents > $maxMoneyCents) {
              throw new RuntimeException('Deductions and tax cannot exceed Basic Pay + Overtime + Allowances (Net Pay cannot be negative).');
            }
            $before = "basic={$row['basic_pay']},overtime={$row['overtime_pay']},allowances={$row['allowances']},deductions={$row['deductions']},tax={$row['tax']},net={$row['net_pay']},remarks=" . $row['approval_remarks'];
            $resubmit = $pdo->prepare("UPDATE payroll SET basic_pay=?, overtime_pay=?, allowances=?, deductions=?, tax=?, net_pay=?, approval_remarks=NULL, approved_by=NULL, approved_at=NULL WHERE payroll_id=? AND status='DRAFT' AND approval_remarks IS NOT NULL");
            $resubmit->execute([$moneyValue($basicCents), $moneyValue($otCents), $moneyValue($allowCents), $moneyValue($totalDedCents), $moneyValue($taxCents), $moneyValue($netCents), $payrollId]);
            if ($resubmit->rowCount() !== 1) { throw new RuntimeException('Payroll is no longer available for resubmission.'); }
            if ($deductionIds) {
              $placeholders = implode(',', array_fill(0, count($deductionIds), '?'));
              $claim = $pdo->prepare("UPDATE attendance_deductions SET included_in_payroll_id=?
                WHERE deduction_id IN ($placeholders) AND (included_in_payroll_id IS NULL OR included_in_payroll_id=?)");
              $claim->execute(array_merge([$payrollId], $deductionIds, [$payrollId]));
            }
            $pdo->commit();
            setFlash('success', 'Payroll updated and resubmitted to the Owner for approval.');
            logAudit($pdo, $userId, 'RESUBMIT_PAYROLL', 'Payroll', "payroll_id=$payrollId before=$before after=basic=" . $moneyValue($basicCents) . ",overtime=" . $moneyValue($otCents) . ",allowances=" . $moneyValue($allowCents) . ",deductions=" . $moneyValue($totalDedCents) . ",tax=" . $moneyValue($taxCents) . ",net=" . $moneyValue($netCents));
          } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            setFlash('error', 'Payroll could not be resubmitted. No records were changed.');
          }
        }
    } elseif ($action === 'delete_draft') {
        // HR Manager may only pull back / delete payroll that has not been approved yet
        $payrollId = filter_var($_POST['payroll_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("SELECT * FROM payroll WHERE payroll_id=? AND (status='PREPARED' OR (status='DRAFT' AND approval_remarks IS NOT NULL)) AND released_by IS NULL AND released_at IS NULL");
        $stmt->execute([$payrollId]);
        $row = $stmt->fetch();
        if ($row) {
          try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE attendance_deductions SET included_in_payroll_id=NULL WHERE included_in_payroll_id=?")->execute([$payrollId]);
            $pdo->prepare("DELETE FROM payroll WHERE payroll_id=? AND (status='PREPARED' OR (status='DRAFT' AND approval_remarks IS NOT NULL))")->execute([$payrollId]);
            $pdo->commit();
            setFlash('success', 'Payroll deleted.');
            logAudit($pdo, $userId, 'DELETE_PAYROLL_DRAFT', 'Payroll', "payroll_id=$payrollId employee_id={$row['employee_id']} period={$row['pay_period_start']}:{$row['pay_period_end']}");
          } catch (Throwable $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            setFlash('error', 'That payroll could not be deleted. No records were changed.');
          }
        } else {
          setFlash('error', 'That payroll can no longer be deleted (it may already be approved or paid).');
        }
    }
    redirect('modules/hr_manager/payroll.php');
}

$branches = $pdo->query("SELECT branch_id, branch_name FROM branches WHERE status='ACTIVE' ORDER BY branch_name")->fetchAll();

// Pending (not yet applied to a payslip) attendance-based deductions, e.g. late arrivals
$pendingDeductions = $pdo->query("SELECT ad.employee_id, u.first_name, u.last_name, COUNT(*) AS deduction_count, SUM(ad.amount) AS total_amount
    FROM attendance_deductions ad JOIN employees e ON ad.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id
    WHERE ad.included_in_payroll_id IS NULL GROUP BY ad.employee_id ORDER BY total_amount DESC")->fetchAll();

$rejectedCount = $pdo->query("SELECT COUNT(*) AS c FROM payroll WHERE status='DRAFT' AND approval_remarks IS NOT NULL")->fetch()['c'];

$total = $pdo->query("SELECT COUNT(*) AS c FROM payroll")->fetch()['c'];
[$offset, $limit, $page, $totalPages] = paginate($total, 10);
$payrolls = $pdo->query("SELECT p.*, e.employee_code, u.first_name, u.last_name, b.branch_name,
  (SELECT COALESCE(SUM(ad.amount), 0) FROM attendance_deductions ad WHERE ad.included_in_payroll_id=p.payroll_id) AS attendance_deductions
  FROM payroll p
    JOIN employees e ON p.employee_id=e.employee_id JOIN users u ON e.user_id=u.user_id LEFT JOIN branches b ON b.branch_id=p.branch_id
    ORDER BY (p.status='DRAFT' AND p.approval_remarks IS NOT NULL) DESC, p.generated_at DESC LIMIT $limit OFFSET $offset")->fetchAll();

$pageTitle = 'Prepare Payroll';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="emp-page-header mb-0">
    <div class="emp-page-icon"><i class="bi bi-cash-coin"></i></div>
    <div>
      <h4>Prepare Payroll</h4>
    </div>
  </div>
  <div>
    <?php if ($rejectedCount > 0): ?>
      <span class="badge bg-danger me-2"><?= (int)$rejectedCount ?> sent back for correction</span>
    <?php endif; ?>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payModal"><i class="bi bi-plus-circle"></i> Prepare Payroll</button>
  </div>
</div>

<!-- Description removed for cleaner UI -->

<?php if (!empty($pendingDeductions)): ?>
<div class="card mb-3 border-warning">
  <div class="card-body">
    <h6 class="card-title"><i class="bi bi-exclamation-triangle text-warning"></i> Pending Attendance Deductions (not yet applied to a payslip)</h6>
    <table id="pendingDeductionsTable" class="table table-sm mb-0">
      <thead><tr><th>Employee</th><th># of Deductions</th><th>Total</th></tr></thead>
      <tbody>
      <?php foreach ($pendingDeductions as $pd): ?>
        <tr>
          <td><?= e($pd['first_name'].' '.$pd['last_name']) ?></td>
          <td><?= (int)$pd['deduction_count'] ?></td>
          <td><strong><?= fmoney($pd['total_amount']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="small text-muted mb-0 mt-2">These are auto-logged late-arrival penalties. The selected employee's deductions are calculated from the pay period when the payslip is generated.</p>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-body table-responsive">
    <table id="payrollTable" class="table table-hover align-middle">
      <thead><tr><th>Employee</th><th>Branch</th><th>Period</th><th>Regular / OT Hours</th><th>Basic</th><th>Overtime</th><th>Allowances</th><th>Deductions</th><th>Tax</th><th>Net Pay</th><th>Adjustment Remarks</th><th>Status</th><th style="width:160px;"></th></tr></thead>
      <tbody>
      <?php foreach ($payrolls as $p): ?>
        <tr>
          <td><?= e($p['first_name'].' '.$p['last_name']) ?> <small class="text-muted">(<?= e($p['employee_code']) ?>)</small></td>
          <td><?= e($p['branch_name'] ?? '-') ?></td>
          <td><?= fdate($p['pay_period_start']) ?> - <?= fdate($p['pay_period_end']) ?></td>
          <td><?= number_format((float)$p['regular_hours'], 2) ?> / <?= number_format((float)$p['overtime_hours'], 2) ?></td>
          <td><?= fmoney($p['basic_pay']) ?></td>
          <td><?= fmoney($p['overtime_pay']) ?></td>
          <td><?= fmoney($p['allowances']) ?></td>
          <td><?= fmoney($p['deductions']) ?></td>
          <td><?= fmoney($p['tax']) ?></td>
          <td><strong><?= fmoney($p['net_pay']) ?></strong></td>
          <td><?= e($p['adjustment_remarks'] ?? '') ?></td>
          <td>
            <span class="badge bg-<?= payrollStatusColor($p['status']) ?>"><?= e(payrollStatusLabel($p['status'])) ?></span>
            <?php if ($p['status'] === 'DRAFT' && !empty($p['approval_remarks'])): ?>
              <div class="small text-danger mt-1"><i class="bi bi-arrow-return-left"></i> <?= e($p['approval_remarks']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($p['status'] === 'PREPARED' || ($p['status'] === 'DRAFT' && !empty($p['approval_remarks']))): ?>
              <div class="d-flex gap-1">
                <button class="btn btn-sm btn-outline-primary" title="Edit" data-bs-toggle="modal" data-bs-target="#editModal<?= $p['payroll_id'] ?>"><i class="bi bi-pencil"></i></button>
                <form method="POST" class="d-inline delete-payroll-form">
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                  <input type="hidden" name="action" value="delete_draft">
                  <input type="hidden" name="payroll_id" value="<?= $p['payroll_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($payrolls)): ?><tr><td colspan="13" class="text-center text-muted">No payroll records yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?= renderPagination($page, $totalPages) ?>
  </div>
</div>

<?php foreach ($payrolls as $p): ?>
  <?php if ($p['status'] === 'PREPARED' || ($p['status'] === 'DRAFT' && !empty($p['approval_remarks']))): ?>
    <div class="modal fade" id="editModal<?= $p['payroll_id'] ?>" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="submit_payroll">
        <input type="hidden" name="payroll_id" value="<?= $p['payroll_id'] ?>">
        <div class="modal-header"><h5 class="modal-title">Review Payroll - <?= e($p['first_name'].' '.$p['last_name']) ?></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <?php if (!empty($p['approval_remarks'])): ?>
            <div class="alert alert-warning py-2"><strong>Owner's note:</strong> <?= e($p['approval_remarks']) ?></div>
          <?php endif; ?>
          <p class="small text-muted">Period <?= fdate($p['pay_period_start']) ?> - <?= fdate($p['pay_period_end']) ?> · <?= e($p['branch_name'] ?? '-') ?></p>
          <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Basic Pay (from employee record)</label><input type="number" step="0.01" min="0" class="form-control" value="<?= e((string)$p['basic_pay']) ?>" readonly></div>
            <div class="col-md-6 mb-3"><label class="form-label">Regular / Overtime Hours</label><input type="text" class="form-control" value="<?= number_format((float)$p['regular_hours'], 2) ?> / <?= number_format((float)$p['overtime_hours'], 2) ?>" readonly></div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Calculated Attendance Deductions</label><input type="text" class="form-control" value="<?= e((string)$p['attendance_deductions']) ?>" readonly></div>
            <div class="col-md-6 mb-3"><label class="form-label">Calculated Absence Deductions</label><input type="text" class="form-control" value="<?= e((string)$p['absence_deductions']) ?>" readonly></div>
          </div>
          <div class="row">
            <div class="col-md-6 mb-3"><label class="form-label">Overtime Pay</label><input type="number" step="0.01" min="0" max="9999999999.99" name="overtime_pay" class="form-control" value="<?= e((string)$p['overtime_pay']) ?>" required></div>
            <div class="col-md-6 mb-3"><label class="form-label">Allowances</label><input type="number" step="0.01" min="0" max="9999999999.99" name="allowances" class="form-control" value="<?= e((string)$p['allowances']) ?>" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Other Deductions</label><input type="number" step="0.01" min="0" max="9999999999.99" name="other_deductions" class="form-control" value="<?= e(number_format(max(0, (float)$p['deductions'] - (float)$p['attendance_deductions'] - (float)$p['absence_deductions']), 2, '.', '')) ?>" required></div>
            <div class="col-md-4 mb-3"><label class="form-label">Tax</label><input type="number" step="0.01" min="0" max="9999999999.99" name="tax" class="form-control" value="<?= e((string)$p['tax']) ?>" required></div>
            <div class="col-12 mb-3"><label class="form-label">Adjustment Remarks</label><textarea name="adjustment_remarks" class="form-control" rows="2" maxlength="500" required><?= e($p['adjustment_remarks'] ?? '') ?></textarea></div>
          </div>
        </div>
        <div class="modal-footer"><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit to Owner</button></div>
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
  if ($('#pendingDeductionsTable').length) {
    $('#pendingDeductionsTable').DataTable({ order: [], paging: false, info: false });
  }
  $('#payrollTable').DataTable({ order: [], paging: false, info: false, columnDefs: [{ orderable: false, targets: -1 }] });
});

document.querySelectorAll('.delete-payroll-form').forEach(form => {
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    Swal.fire({
      title: 'Delete this payslip?',
      text: "You can generate it again later if needed.",
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#dc3545',
      cancelButtonColor: '#7a6558',
      confirmButtonText: 'Yes, delete it',
      cancelButtonText: 'Cancel'
    }).then((result) => {
      if (result.isConfirmed) {
        form.submit();
      }
    });
  });
});
</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="modal fade" id="payModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="action" value="prepare_batch">
    <div class="modal-header"><h5 class="modal-title">Prepare Branch Payroll</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="mb-3"><label class="form-label">Branch</label>
        <select name="branch_id" class="form-select" required>
          <option value="">Select branch</option>
          <?php foreach ($branches as $branch): ?><option value="<?= (int)$branch['branch_id'] ?>"><?= e($branch['branch_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label">Payroll Month</label>
          <input type="month" name="payroll_month" class="form-control" value="<?= date('Y-m') ?>" required>
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label">Cutoff Period</label>
          <select name="payroll_cutoff" class="form-select" required>
            <option value="1st">1st Half (1st to 15th)</option>
            <option value="2nd">2nd Half (16th to End)</option>
          </select>
        </div>
      </div>
      <!-- Description removed for cleaner UI -->
    </div>
    <div class="modal-footer"><button class="btn btn-primary"><i class="bi bi-calculator me-1"></i>Calculate Payroll</button></div>
  </form>
</div></div></div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
