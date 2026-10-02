# Employment Terms, End Dates & HR Manager Review Alerts

## Install
1. Back up `unified_cafe_system`.
2. Import `database/migration_employment_terms.sql` (additive; safe to re-run).
3. Copy the changed files over your `unified/` folder.

Until the migration is run, the new features stay hidden and nothing errors.

## Employment types
| Type | Stored as | End date |
|---|---|---|
| Regular | `FULL_TIME` (label only changed) | none |
| Probationary | `PROBATIONARY` | Probationary End Date (required) |
| Contractual | `CONTRACTUAL` | Contract End Date (required) |
| Part-Time | `PART_TIME` | Employment End Date (optional) |

The edit forms show only the date field for the chosen type. Switching type clears
dates that no longer apply (e.g. Regular carries none).

## Alerts (HR Manager)
- Flagged **30 days before** the end date (`EMPLOYMENT_END_ALERT_DAYS` in `config/constants.php`):
  "Probationary period ending soon" / "Contract ending soon".
- Once the date is reached the employee stays flagged as "... ended - review needed".
  **Nothing is changed automatically.**
- Shown as an *Employment Term Alerts* panel on the HR Manager dashboard and Manage Employees,
  plus a badge per row and a bell notification (sent once per phase per employee).
- Required date missing -> listed as "end date missing" with a *Set date* shortcut.

## Review actions (HR Manager only, audit-logged)
- Probationary: **Regularize** (becomes Regular, probation date cleared) / **End Employment**
- Contractual: **Renew Contract** (asks for a new, later end date) / **End Employment**
- End Employment = status TERMINATED + account suspended (same as existing Deactivate),
  and records the end date. Regularize/Renew also notify the employee.

## Files
New: `includes/employment_terms.php`, `database/migration_employment_terms.sql`
Changed: `config/constants.php`, `includes/functions.php` (default probation on hire),
`modules/hr_manager/employees.php`, `modules/hr_manager/dashboard.php`,
`modules/hr_staff/employees.php`, `modules/employee/profile.php`

## Assumptions to confirm
- Migration sets existing probationary employees' end date to hire date + 6 months
  (`DEFAULT_PROBATION_MONTHS`); new probationary hires get the same default. Existing
  contractual employees are left blank for HR to fill in.
- Because dates are required for Probationary/Contractual, editing such a record with no
  date needs one entered.
- HR Staff can edit end dates on Employee Records; only the HR Manager gets the review actions.
- The label "Regular / Full-Time" is now "Regular" (also appears in contract text).
