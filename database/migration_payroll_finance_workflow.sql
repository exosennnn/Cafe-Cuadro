-- HR Manager preparation -> Owner approval -> Finance payment -> employee payslip.
USE `unified_cafe_system`;
-- The schema in unified_cafe_system.sql already contains these payroll columns,
-- indexes, and constraints. Keep this file as a data backfill for databases
-- created from that schema; rerunning the old ALTER TABLE caused duplicate
-- column errors (for example, branch_id already exists).

UPDATE payroll p
JOIN employees e ON e.employee_id=p.employee_id
SET p.branch_id=COALESCE(e.branch_id,1)
WHERE p.branch_id IS NULL;

UPDATE payroll p
LEFT JOIN (
    SELECT included_in_payroll_id AS payroll_id, SUM(amount) AS total_amount
    FROM attendance_deductions
    WHERE included_in_payroll_id IS NOT NULL
    GROUP BY included_in_payroll_id
) ad ON ad.payroll_id=p.payroll_id
SET p.attendance_deductions=COALESCE(ad.total_amount,0)
WHERE p.attendance_deductions=0;

UPDATE payroll p
JOIN finance_transactions ft
    ON ft.reference_type='Payroll'
   AND ft.reference_id=p.payroll_id
   AND ft.transaction_type='Expense'
SET p.finance_transaction_id=ft.transaction_id
    ,p.status='PAID'
    ,p.released_by=COALESCE(p.released_by,ft.created_by)
    ,p.released_at=COALESCE(p.released_at,ft.created_at,NOW())
WHERE p.finance_transaction_id IS NULL;

UPDATE payroll
SET status='SUBMITTED'
WHERE status='DRAFT' AND approval_remarks IS NULL;
