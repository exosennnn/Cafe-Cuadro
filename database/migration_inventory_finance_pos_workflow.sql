-- One-time migration for the Inventory -> Finance -> POS purchase workflow.
-- Existing aggregate item stock is assigned to the legacy/default branch 1.
USE `unified_cafe_system`;

-- The live legacy schema has duplicate, identical Finance category rows
-- sharing the same IDs. Preserve each ID so existing transaction references
-- stay valid, but keep only one copy before adding the key required by forms.
CREATE TEMPORARY TABLE finance_categories_migration_backup AS
SELECT fin_category_id, MIN(category_name) AS category_name, MIN(type) AS type,
       MAX(COALESCE(is_system, 0)) AS is_system
FROM finance_categories
GROUP BY fin_category_id;
DELETE FROM finance_categories;
INSERT INTO finance_categories (fin_category_id, category_name, type, is_system)
SELECT fin_category_id, category_name, type, is_system
FROM finance_categories_migration_backup;
DROP TEMPORARY TABLE finance_categories_migration_backup;
ALTER TABLE finance_categories
    MODIFY fin_category_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (fin_category_id);

ALTER TABLE activity_logs
    MODIFY log_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (log_id);

-- Repair identifiers used by lastInsertId() and by delivery/line references.
ALTER TABLE suppliers
    MODIFY supplier_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (supplier_id);

ALTER TABLE purchase_orders
    MODIFY po_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD UNIQUE KEY uq_purchase_orders_number (po_number);

ALTER TABLE purchase_order_items
    MODIFY po_item_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (po_item_id),
    ADD KEY idx_purchase_order_items_po (po_id),
    ADD KEY idx_purchase_order_items_item (item_id),
    ADD CONSTRAINT fk_poi_po FOREIGN KEY (po_id) REFERENCES purchase_orders (po_id),
    ADD CONSTRAINT fk_poi_item FOREIGN KEY (item_id) REFERENCES items (item_id);

ALTER TABLE deliveries
    MODIFY delivery_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (delivery_id),
    ADD UNIQUE KEY uq_deliveries_number (delivery_number),
    ADD KEY idx_deliveries_po (po_id),
    ADD CONSTRAINT fk_delivery_po FOREIGN KEY (po_id) REFERENCES purchase_orders (po_id);

ALTER TABLE delivery_items
    MODIFY delivery_item_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (delivery_item_id),
    ADD KEY idx_delivery_items_delivery (delivery_id),
    ADD KEY idx_delivery_items_po_item (po_item_id),
    ADD KEY idx_delivery_items_item (item_id),
    ADD CONSTRAINT fk_delivery_item_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (delivery_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_delivery_item_po_item FOREIGN KEY (po_item_id) REFERENCES purchase_order_items (po_item_id),
    ADD CONSTRAINT fk_delivery_item_item FOREIGN KEY (item_id) REFERENCES items (item_id);

ALTER TABLE stock_movements
    MODIFY movement_id INT(11) NOT NULL AUTO_INCREMENT,
    ADD PRIMARY KEY (movement_id),
    ADD COLUMN branch_id INT(11) NULL AFTER item_id,
    ADD KEY idx_stock_movements_branch (branch_id),
    ADD CONSTRAINT fk_stock_movement_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id);

UPDATE stock_movements SET branch_id = 1 WHERE branch_id IS NULL;

ALTER TABLE purchase_requests
    ADD COLUMN branch_id INT(11) NULL AFTER item_id,
    ADD KEY idx_purchase_requests_branch (branch_id),
    ADD CONSTRAINT fk_purchase_request_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id);

UPDATE purchase_requests pr
LEFT JOIN employees e ON e.user_id = pr.requested_by
SET pr.branch_id = COALESCE(e.branch_id, 1)
WHERE pr.branch_id IS NULL;

ALTER TABLE purchase_orders
    ADD COLUMN branch_id INT(11) NULL AFTER supplier_id,
    ADD KEY idx_purchase_orders_branch (branch_id),
    ADD CONSTRAINT fk_purchase_order_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id);

UPDATE purchase_orders po
LEFT JOIN employees e ON e.user_id = po.created_by
SET po.branch_id = COALESCE(e.branch_id, 1)
WHERE po.branch_id IS NULL;

CREATE TABLE branch_item_inventory (
    branch_item_inventory_id INT(11) NOT NULL AUTO_INCREMENT,
    branch_id INT(11) NOT NULL,
    item_id INT(11) NOT NULL,
    current_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (branch_item_inventory_id),
    UNIQUE KEY uq_branch_item (branch_id, item_id),
    KEY idx_branch_item_item (item_id),
    CONSTRAINT fk_branch_item_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id) ON DELETE CASCADE,
    CONSTRAINT fk_branch_item_item FOREIGN KEY (item_id) REFERENCES items (item_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO branch_item_inventory (branch_id, item_id, current_stock)
SELECT 1, item_id, COALESCE(current_stock, 0) FROM items;

CREATE TABLE item_pos_mappings (
    item_id INT(11) NOT NULL,
    product_id INT(11) NOT NULL,
    pos_units_per_item DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    created_by INT(11) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id),
    UNIQUE KEY uq_item_pos_product (product_id),
    CONSTRAINT fk_item_pos_item FOREIGN KEY (item_id) REFERENCES items (item_id) ON DELETE CASCADE,
    CONSTRAINT fk_item_pos_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_pos_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE purchase_payables (
    payable_id INT(11) NOT NULL AUTO_INCREMENT,
    delivery_id INT(11) NOT NULL,
    po_id INT(11) NOT NULL,
    supplier_id INT(11) NOT NULL,
    branch_id INT(11) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('UNPAID','PAID') NOT NULL DEFAULT 'UNPAID',
    finance_transaction_id INT(11) DEFAULT NULL,
    received_by INT(11) NOT NULL,
    paid_by INT(11) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME DEFAULT NULL,
    PRIMARY KEY (payable_id),
    UNIQUE KEY uq_payable_delivery (delivery_id),
    UNIQUE KEY uq_payable_finance_transaction (finance_transaction_id),
    KEY idx_payables_branch_status (branch_id, status),
    KEY idx_payables_po (po_id),
    KEY idx_payables_supplier (supplier_id),
    CONSTRAINT fk_payable_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries (delivery_id),
    CONSTRAINT fk_payable_po FOREIGN KEY (po_id) REFERENCES purchase_orders (po_id),
    CONSTRAINT fk_payable_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (supplier_id),
    CONSTRAINT fk_payable_branch FOREIGN KEY (branch_id) REFERENCES branches (branch_id),
    CONSTRAINT fk_payable_transaction FOREIGN KEY (finance_transaction_id) REFERENCES finance_transactions (transaction_id),
    CONSTRAINT fk_payable_received_by FOREIGN KEY (received_by) REFERENCES users (user_id),
    CONSTRAINT fk_payable_paid_by FOREIGN KEY (paid_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE finance_transactions
    MODIFY reference_type ENUM('Manual','Delivery','Sale','Payroll','StockIn','PurchasePayable') DEFAULT 'Manual';
