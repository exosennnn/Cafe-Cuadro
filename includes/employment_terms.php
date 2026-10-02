<?php
/**
 * EMPLOYMENT TERMS - end dates, HR Manager alerts and review actions
 *
 * Employment Type -> end date rules
 *   Regular      (stored as FULL_TIME)  no end date
 *   Probationary                        Probationary End Date   (required)
 *   Contractual                         Contract End Date       (required)
 *   Part-Time                           Employment End Date     (optional)
 *
 * Reaching an end date NEVER changes an employee's record on its own. The
 * employee is only FLAGGED for HR Manager review. The HR Manager then decides:
 *   Probationary -> Regularize / End Employment
 *   Contractual  -> Renew Contract / End Employment
 *
 * Everything degrades safely if database/migration_employment_terms.sql has
 * not been run yet: the helpers report "not ready" and callers skip the
 * feature instead of erroring.
 */

require_once __DIR__ . '/../config/constants.php';

if (!function_exists('employmentTermsReady')) {

    /** True once the end-date columns exist (i.e. the migration was run). */
    function employmentTermsReady(PDO $pdo): bool {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $ready = (bool)$pdo->query("SHOW COLUMNS FROM employees LIKE 'probation_end_date'")->fetch();
        } catch (Exception $e) {
            $ready = false;
        }
        return $ready;
    }

    function employmentTypeLabel(?string $type): string {
        return EMPLOYMENT_TYPE_LABELS[$type ?? ''] ?? ($type ? str_replace('_', ' ', $type) : '-');
    }

    /** Strict Y-m-d check. Returns the string if valid, else null. */
    function employmentTermsValidDate($value): ?string {
        if (!is_string($value)) return null;
        $value = trim($value);
        $d = DateTime::createFromFormat('!Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }

    /**
     * Validate the end-date fields posted from an edit form for a given type.
     *
     * @return array{0: string[], 1: array<string, ?string>} [errors, dates]
     *         dates always has probation_end_date / contract_end_date /
     *         employment_end_date; fields that do not apply to $type are null.
     */
    function employmentTermsFromPost(array $post, string $type, ?string $dateHired): array {
        $errors = [];
        $dates = ['probation_end_date' => null, 'contract_end_date' => null, 'employment_end_date' => null];

        $map = [
            'PROBATIONARY' => ['probation_end_date',  'Probationary End Date', true],
            'CONTRACTUAL'  => ['contract_end_date',   'Contract End Date',     true],
            'PART_TIME'    => ['employment_end_date', 'Employment End Date',   false],
        ];
        if (!isset($map[$type])) return [$errors, $dates];   // Regular: nothing to collect

        [$field, $label, $required] = $map[$type];
        $raw = trim((string)($post[$field] ?? ''));
        if ($raw === '') {
            if ($required) $errors[] = "$label is required for " . employmentTypeLabel($type) . " employees.";
            return [$errors, $dates];
        }
        $valid = employmentTermsValidDate($raw);
        if ($valid === null) {
            $errors[] = "Please enter a valid $label.";
        } elseif ($dateHired !== null && $valid < $dateHired) {
            $errors[] = "$label cannot be earlier than the date hired (" . fdate($dateHired) . ').';
        } else {
            $dates[$field] = $valid;
        }
        return [$errors, $dates];
    }

    /**
     * Persist the validated end dates. Kept as its own UPDATE so the existing
     * employee UPDATE statements stay exactly as they were.
     *
     * Dates that don't apply to the new type are cleared, so a Regular employee
     * never carries a stale probation/contract date. employment_end_date is
     * also the "date employment ended" record, so for someone already
     * RESIGNED/TERMINATED it is preserved unless Part-Time supplies a value.
     */
    function employmentTermsSave(PDO $pdo, int $employeeId, string $type, string $status, array $dates): void {
        if (!employmentTermsReady($pdo)) return;
        $stillEmployed = in_array($status, ['ACTIVE', 'ON_LEAVE'], true);
        $sql = "UPDATE employees SET probation_end_date=?, contract_end_date=?";
        $params = [$dates['probation_end_date'], $dates['contract_end_date']];
        if ($type === 'PART_TIME') {
            $sql .= ", employment_end_date=?";
            $params[] = $dates['employment_end_date'];
        } elseif ($stillEmployed) {
            $sql .= ", employment_end_date=NULL";
        }
        $sql .= " WHERE employee_id=?";
        $params[] = $employeeId;
        $pdo->prepare($sql)->execute($params);
    }

    /** Default probation end date for a fresh hire (used at onboarding). */
    function defaultProbationEndDate(?string $dateHired = null): string {
        $base = $dateHired ? new DateTime($dateHired) : new DateTime('today');
        return $base->modify('+' . (int)DEFAULT_PROBATION_MONTHS . ' months')->format('Y-m-d');
    }

    /** The end date that applies to this employee row (null for Regular / none set). */
    function employmentEndDateOf(array $emp): ?string {
        switch ($emp['employment_type'] ?? '') {
            case 'PROBATIONARY': return $emp['probation_end_date'] ?? null;
            case 'CONTRACTUAL':  return $emp['contract_end_date'] ?? null;
            case 'PART_TIME':    return $emp['employment_end_date'] ?? null;
        }
        return null;
    }

    /**
     * Review state for a Probationary / Contractual employee, or null when no
     * review is needed.
     *   due     - end date is today or already past (needs HR Manager decision)
     *   soon    - end date within EMPLOYMENT_END_ALERT_DAYS
     *   missing - required end date was never set
     *
     * @return array{state:string,kind:string,date:?string,days:?int,title:string,noun:string}|null
     */
    function employmentTermState(array $emp, ?string $today = null): ?array {
        $type = $emp['employment_type'] ?? '';
        if ($type !== 'PROBATIONARY' && $type !== 'CONTRACTUAL') return null;
        if (!in_array($emp['employment_status'] ?? 'ACTIVE', ['ACTIVE', 'ON_LEAVE'], true)) return null;

        $today = $today ?? date('Y-m-d');
        $isProb = $type === 'PROBATIONARY';
        $noun = $isProb ? 'Probationary period' : 'Contract';
        $date = employmentEndDateOf($emp);

        if (!$date || $date === '0000-00-00') {
            return ['state' => 'missing', 'kind' => $type, 'date' => null, 'days' => null,
                    'title' => $isProb ? 'Probationary end date missing' : 'Contract end date missing', 'noun' => $noun];
        }
        $days = (int)((strtotime($date) - strtotime($today)) / 86400);
        if ($days <= 0) {
            return ['state' => 'due', 'kind' => $type, 'date' => $date, 'days' => $days,
                    'title' => $noun . ' ended - review needed', 'noun' => $noun];
        }
        if ($days <= (int)EMPLOYMENT_END_ALERT_DAYS) {
            return ['state' => 'soon', 'kind' => $type, 'date' => $date, 'days' => $days,
                    'title' => $noun . ' ending soon', 'noun' => $noun];
        }
        return null;
    }

    /**
     * Every employee currently needing HR Manager attention, most urgent first
    * (due, then soonest, then missing-date). HR Manager / Owner accounts are
    * excluded by default; Owner may include HR Manager records on the Owner
    * employee-management page.
     *
     * @return array<int, array> employee row + user name fields + 'term' state
     */
    function getEmploymentTermReviews(PDO $pdo, bool $includeHrManagers = false): array {
        if (!employmentTermsReady($pdo)) return [];
      $roleFilter = $includeHrManagers ? 'u.role_id <> ?' : 'u.role_id NOT IN (?, ?)';
        $stmt = $pdo->prepare("SELECT e.employee_id, e.user_id, e.employee_code, e.position, e.employment_type, e.employment_status,
                e.date_hired, e.probation_end_date, e.contract_end_date, e.employment_end_date,
                u.first_name, u.last_name, d.department_name
            FROM employees e
            JOIN users u ON e.user_id = u.user_id
            LEFT JOIN departments d ON e.department_id = d.department_id
            WHERE e.employment_type IN ('PROBATIONARY','CONTRACTUAL')
              AND e.employment_status IN ('ACTIVE','ON_LEAVE')
              AND $roleFilter");
            $stmt->execute($includeHrManagers ? [ROLE_OWNER] : [ROLE_HR_MANAGER, ROLE_OWNER]);

        $today = date('Y-m-d');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $term = employmentTermState($row, $today);
            if ($term) { $row['term'] = $term; $out[] = $row; }
        }
        $rank = ['due' => 0, 'soon' => 1, 'missing' => 2];
        usort($out, function ($a, $b) use ($rank) {
            $ra = $rank[$a['term']['state']]; $rb = $rank[$b['term']['state']];
            if ($ra !== $rb) return $ra <=> $rb;
            return strcmp((string)$a['term']['date'], (string)$b['term']['date']);
        });
        return $out;
    }

    /**
     * Drop an in-app notification on every active HR Manager for each flagged
     * employee. Deduplicated on (recipient, employee, title, message) - the
     * message carries the end date, so each phase (soon / ended) is sent once
     * and a renewed contract with a new date is announced again.
     * Called when HR Manager pages load; there is no cron dependency.
     */
    function syncEmploymentTermNotifications(PDO $pdo, array $reviews): void {
        if (!$reviews) return;
        try {
            $mgrs = $pdo->prepare("SELECT user_id FROM users WHERE role_id = ? AND status = 'ACTIVE'");
            $mgrs->execute([ROLE_HR_MANAGER]);
            $mgrIds = array_column($mgrs->fetchAll(), 'user_id');
            if (!$mgrIds) return;
            $exists = $pdo->prepare("SELECT 1 FROM notifications WHERE user_id=? AND related_type='EMPLOYMENT_TERM' AND related_id=? AND title=? AND message=? LIMIT 1");

            foreach ($reviews as $r) {
                $t = $r['term'];
                if ($t['state'] === 'missing') continue;   // no date to announce; stays on the review list
                $name = trim($r['first_name'] . ' ' . $r['last_name']);
                $when = fdate($t['date']);
                $title = $t['title'];
                if ($t['state'] === 'soon') {
                    $msg = "$name ({$r['employee_code']}): " . strtolower($t['noun']) . " ends on $when.";
                } else {
                    $msg = "$name ({$r['employee_code']}): " . strtolower($t['noun']) . " ended on $when. "
                         . ($t['kind'] === 'PROBATIONARY' ? 'Regularize or end employment.' : 'Renew the contract or end employment.');
                }
                $msg = mb_substr($msg, 0, 255);
                foreach ($mgrIds as $uid) {
                    $exists->execute([$uid, $r['employee_id'], $title, $msg]);
                    if (!$exists->fetchColumn()) {
                        createNotification($pdo, (int)$uid, $title, $msg, 'EMPLOYMENT_TERM', (int)$r['employee_id']);
                    }
                }
            }
        } catch (Exception $e) {
            // notifications must never break the page
        }
    }

    /**
    * Apply an HR Manager or Owner review decision. Caller must already have
    * verified the role and CSRF.
     *
     * Actions: regularize | renew_contract | end_employment
     * @return array{0: bool, 1: string} [success, message]
     */
    function applyEmploymentTermAction(PDO $pdo, int $actorUserId, string $action, int $employeeId, ?string $newEndDate = null): array {
        if (!employmentTermsReady($pdo)) return [false, 'Run database/migration_employment_terms.sql first.'];
        if (!in_array($action, ['regularize', 'renew_contract', 'end_employment'], true)) return [false, 'Unknown action.'];

        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT e.*, u.first_name, u.last_name, u.role_id
                FROM employees e JOIN users u ON e.user_id=u.user_id WHERE e.employee_id=? FOR UPDATE");
            $q->execute([$employeeId]);
            $emp = $q->fetch();

            $actorStmt = $pdo->prepare('SELECT role_id FROM users WHERE user_id=?');
            $actorStmt->execute([$actorUserId]);
            $actorRoleId = (int)$actorStmt->fetchColumn();
            $protectedRoles = [ROLE_OWNER];
            if ($actorRoleId !== ROLE_OWNER) {
              $protectedRoles[] = ROLE_HR_MANAGER;
            }
            if (!$emp || (int)$emp['user_id'] === $actorUserId || in_array((int)$emp['role_id'], $protectedRoles, true)) {
                $pdo->rollBack(); return [false, 'You are not allowed to change this employee record.'];
            }
            if (!in_array($emp['employment_status'], ['ACTIVE', 'ON_LEAVE'], true)) {
                $pdo->rollBack(); return [false, 'This employee is no longer employed, so there is nothing to review.'];
            }
            $name = trim($emp['first_name'] . ' ' . $emp['last_name']);
            $type = $emp['employment_type'];

            if ($action === 'regularize') {
                if ($type !== 'PROBATIONARY') { $pdo->rollBack(); return [false, 'Only probationary employees can be regularized.']; }
                $pdo->prepare("UPDATE employees SET employment_type='FULL_TIME', probation_end_date=NULL, contract_end_date=NULL, employment_end_date=NULL WHERE employee_id=?")
                    ->execute([$employeeId]);
                logAudit($pdo, $actorUserId, 'REGULARIZE_EMPLOYEE', 'Employees',
                    "employee_id=$employeeId type=PROBATIONARY->FULL_TIME probation_end_date=" . ($emp['probation_end_date'] ?? 'none'));
                $pdo->commit();
                createNotification($pdo, (int)$emp['user_id'], 'You have been regularized',
                    'Congratulations! Your employment status is now Regular.', 'EMPLOYMENT_TERM', $employeeId);
                return [true, "$name has been regularized."];
            }

            if ($action === 'renew_contract') {
                if ($type !== 'CONTRACTUAL') { $pdo->rollBack(); return [false, 'Only contractual employees can have a contract renewed.']; }
                $valid = employmentTermsValidDate($newEndDate ?? '');
                $current = $emp['contract_end_date'];
                if ($valid === null) { $pdo->rollBack(); return [false, 'Please enter a valid new contract end date.']; }
                if ($valid <= date('Y-m-d') || ($current && $valid <= $current)) {
                    $pdo->rollBack(); return [false, 'The new contract end date must be in the future and later than the current end date.'];
                }
                $pdo->prepare("UPDATE employees SET contract_end_date=? WHERE employee_id=?")->execute([$valid, $employeeId]);
                logAudit($pdo, $actorUserId, 'RENEW_CONTRACT', 'Employees',
                    "employee_id=$employeeId contract_end_date=" . ($current ?? 'none') . "->$valid");
                $pdo->commit();
                createNotification($pdo, (int)$emp['user_id'], 'Your contract has been renewed',
                    'Your contract now runs until ' . fdate($valid) . '.', 'EMPLOYMENT_TERM', $employeeId);
                return [true, "$name's contract was renewed until " . fdate($valid) . '.'];
            }

            // end_employment - same effect as the existing Deactivate action,
            // plus the date employment ended.
            if (!in_array($type, ['PROBATIONARY', 'CONTRACTUAL', 'PART_TIME'], true)) {
                $pdo->rollBack(); return [false, 'Use Deactivate for this employee.'];
            }
            $pdo->prepare("UPDATE employees SET employment_status='TERMINATED', employment_end_date=CURDATE() WHERE employee_id=?")->execute([$employeeId]);
            $pdo->prepare("UPDATE users SET status='SUSPENDED' WHERE user_id=?")->execute([$emp['user_id']]);
            logAudit($pdo, $actorUserId, 'END_EMPLOYMENT', 'Employees',
                "employee_id=$employeeId type=$type employment_status=TERMINATED user_status=SUSPENDED");
            $pdo->commit();
            return [true, "Employment ended for $name. Their account has been suspended."];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return [false, 'Could not save the change. Please try again.'];
        }
    }

    /** Small badge for tables: "Ends Oct 12, 2026" + Ending soon / Review needed. */
    function renderEmploymentTermBadge(array $emp): string {
        $type = $emp['employment_type'] ?? '';
        $html = '<div>' . e(employmentTypeLabel($type)) . '</div>';
        $date = employmentEndDateOf($emp);
        if ($date && $date !== '0000-00-00') {
            $html .= '<small class="text-muted">Ends ' . e(fdate($date)) . '</small>';
        }
        $t = employmentTermState($emp);
        if ($t) {
            $map = ['due' => ['bg-danger', 'Review needed'], 'soon' => ['bg-warning text-dark', 'Ending soon'], 'missing' => ['bg-secondary', 'End date missing']];
            [$cls, $txt] = $map[$t['state']];
            $html .= '<br><span class="badge ' . $cls . '">' . $txt . '</span>';
        }
        return $html;
    }

    /**
     * The HR Manager review panel. $actionUrl is the page that handles the
     * POSTed actions (modules/hr_manager/employees.php). $compact limits the
     * list (dashboard) and links through to the full list.
     */
    function renderEmploymentTermReviewPanel(array $reviews, string $actionUrl, bool $compact = false): string {
        if (!$reviews) return '';
        $total = count($reviews);
        $shown = $compact ? array_slice($reviews, 0, 5) : $reviews;
        $due = count(array_filter($reviews, fn($r) => $r['term']['state'] === 'due'));
        $csrf = csrfToken();

        ob_start(); ?>
<div class="card border-0 shadow-sm rounded-3 mb-4" id="term-review" style="border-left:4px solid <?= $due ? '#dc3545' : '#ffc107' ?> !important;">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
      <div>
        <h6 class="fw-semibold mb-1"><i class="bi bi-hourglass-split text-warning me-2"></i>Employment Term Alerts
          <span class="badge bg-<?= $due ? 'danger' : 'warning text-dark' ?> ms-1"><?= $total ?></span></h6>
        <p class="small text-muted mb-0">Nobody is ended automatically. Review each employee and choose an action.</p>
      </div>
      <?php if ($compact && $total > count($shown)): ?>
        <a href="<?= e($actionUrl) ?>#term-review" class="btn btn-sm btn-outline-primary">View all <?= $total ?></a>
      <?php endif; ?>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Employee</th><th>Type</th><th>End Date</th><th>Alert</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        <?php foreach ($shown as $r):
          $t = $r['term']; $isProb = $t['kind'] === 'PROBATIONARY';
          $cls = ['due' => 'bg-danger', 'soon' => 'bg-warning text-dark', 'missing' => 'bg-secondary'][$t['state']];
          if ($t['state'] === 'soon')      $when = $t['days'] === 1 ? 'in 1 day' : 'in ' . $t['days'] . ' days';
          elseif ($t['state'] === 'due')   $when = $t['days'] === 0 ? 'today' : abs($t['days']) . ' day' . (abs($t['days']) === 1 ? '' : 's') . ' ago';
          else                             $when = '';
          $empName = trim($r['first_name'] . ' ' . $r['last_name']);
        ?>
          <tr>
            <td><strong><?= e($empName) ?></strong><br><small class="text-muted"><?= e($r['employee_code']) ?><?= $r['department_name'] ? ' &middot; ' . e($r['department_name']) : '' ?></small></td>
            <td><?= e(employmentTypeLabel($r['employment_type'])) ?></td>
            <td class="text-nowrap"><?= $t['date'] ? e(fdate($t['date'])) . '<br><small class="text-muted">' . e($when) . '</small>' : '<span class="text-muted">Not set</span>' ?></td>
            <td><span class="badge <?= $cls ?>"><?= e($t['title']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if ($t['state'] === 'missing'): ?>
                <a href="<?= e($actionUrl) ?>?search=<?= urlencode($r['employee_code']) ?>" class="btn btn-sm btn-outline-primary">Set date</a>
              <?php else: ?>
                <?php if ($isProb): ?>
                  <form method="POST" action="<?= e($actionUrl) ?>" class="d-inline" onsubmit="return confirm('Regularize <?= e(addslashes($empName)) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="regularize">
                    <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                    <button class="btn btn-sm btn-success"><i class="bi bi-patch-check"></i> Regularize</button>
                  </form>
                <?php else: ?>
                  <button type="button" class="btn btn-sm btn-primary" onclick="termRenew(<?= (int)$r['employee_id'] ?>, <?= e(json_encode($empName)) ?>, <?= e(json_encode($t['date'])) ?>)"><i class="bi bi-arrow-repeat"></i> Renew Contract</button>
                <?php endif; ?>
                <form method="POST" action="<?= e($actionUrl) ?>" class="d-inline" onsubmit="return confirm('End employment for <?= e(addslashes($empName)) ?>? Their account will be suspended.')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="end_employment">
                  <input type="hidden" name="employee_id" value="<?= (int)$r['employee_id'] ?>">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-person-x"></i> End Employment</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="renewModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form method="POST" action="<?= e($actionUrl) ?>">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="renew_contract">
    <input type="hidden" name="employee_id" id="renew_employee_id">
    <div class="modal-header"><h5 class="modal-title">Renew Contract</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <p class="mb-2">Renew the contract for <strong id="renew_name"></strong>.</p>
      <p class="small text-muted" id="renew_current"></p>
      <label class="form-label">New Contract End Date</label>
      <input type="date" name="new_end_date" id="renew_date" class="form-control" required>
    </div>
    <div class="modal-footer"><button class="btn btn-primary">Renew Contract</button></div>
  </form>
</div></div></div>
<script>
function termRenew(id, name, current) {
  document.getElementById('renew_employee_id').value = id;
  document.getElementById('renew_name').textContent = name;
  document.getElementById('renew_current').textContent = current ? 'Current end date: ' + current : '';
  var d = document.getElementById('renew_date');
  var tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
  var min = tomorrow.toISOString().slice(0, 10);
  if (current && current >= min) { var c = new Date(current); c.setDate(c.getDate() + 1); min = c.toISOString().slice(0, 10); }
  d.min = min; d.value = '';
  new bootstrap.Modal(document.getElementById('renewModal')).show();
}
</script>
<?php
        return ob_get_clean();
    }

    /**
     * Shared markup + toggle script for the three end-date inputs in the Edit
     * Employee modals. Only the field matching the chosen type is shown.
     */
    function renderEmploymentEndDateFields(): string {
        ob_start(); ?>
      <div class="mb-3 d-none" id="wrap_probation_end_date"><label class="form-label">Probationary End Date</label>
        <input type="date" name="probation_end_date" id="probation_end_date" class="form-control"></div>
      <div class="mb-3 d-none" id="wrap_contract_end_date"><label class="form-label">Contract End Date</label>
        <input type="date" name="contract_end_date" id="contract_end_date" class="form-control"></div>
      <div class="mb-3 d-none" id="wrap_employment_end_date"><label class="form-label">Employment End Date <span class="text-muted small">(optional)</span></label>
        <input type="date" name="employment_end_date" id="employment_end_date" class="form-control"></div>
<?php   return ob_get_clean();
    }

    function renderEmploymentEndDateScript(): string {
        ob_start(); ?>
<script>
(function () {
  var map = { PROBATIONARY: 'probation_end_date', CONTRACTUAL: 'contract_end_date', PART_TIME: 'employment_end_date' };
  var required = { PROBATIONARY: true, CONTRACTUAL: true, PART_TIME: false };
  window.syncEndDateFields = function () {
    var type = document.getElementById('employment_type').value;
    Object.keys(map).forEach(function (t) {
      var wrap = document.getElementById('wrap_' + map[t]);
      var input = document.getElementById(map[t]);
      if (!wrap || !input) return;
      var on = (t === type);
      wrap.classList.toggle('d-none', !on);
      input.disabled = !on;            // hidden fields are not submitted
      input.required = on && required[t];
    });
  };
  document.getElementById('employment_type').addEventListener('change', window.syncEndDateFields);
})();
function loadEndDates(e) {
  ['probation_end_date', 'contract_end_date', 'employment_end_date'].forEach(function (f) {
    var el = document.getElementById(f); if (el) el.value = e[f] || '';
  });
  window.syncEndDateFields();
}
</script>
<?php   return ob_get_clean();
    }
}
