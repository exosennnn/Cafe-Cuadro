-- Run after migration_inventory_finance_pos_workflow.sql.
-- Legacy receipt handling already added this stock and posted this expense,
-- so preserve the existing transaction and do not add stock a second time.
INSERT IGNORE INTO purchase_payables
    (delivery_id, po_id, supplier_id, branch_id, amount, status, finance_transaction_id, received_by, paid_by, created_at, paid_at)
SELECT d.delivery_id,
       po.po_id,
       po.supplier_id,
       COALESCE(po.branch_id, 1),
       ft.amount,
       'PAID',
       ft.transaction_id,
       d.received_by,
       ft.created_by,
       COALESCE(d.created_at, NOW()),
       COALESCE(ft.created_at, CONCAT(ft.transaction_date, ' 00:00:00'))
FROM deliveries d
JOIN purchase_orders po ON po.po_id=d.po_id
JOIN finance_transactions ft
    ON ft.reference_type='Delivery'
   AND ft.reference_id=d.delivery_id
   AND ft.transaction_type='Expense';