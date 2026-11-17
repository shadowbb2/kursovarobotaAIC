USE crm_agro;

-- This migration cleans up the orders table structure and normalizes old data.
-- It adds display info (order name / due date), converts legacy status values,
-- and creates the order_items table for storing detailed product lines.

-- 1) Add new fields needed for better order reporting and delivery planning.
ALTER TABLE orders
    ADD COLUMN order_name VARCHAR(180) NOT NULL DEFAULT '' AFTER order_number,
    ADD COLUMN due_date DATE DEFAULT NULL AFTER order_date;

-- 2) Fill generated values for existing records so old orders stay usable.
UPDATE orders
SET order_name = CONCAT('Замовлення ', order_number)
WHERE order_name = '';

UPDATE orders
SET due_date = order_date
WHERE due_date IS NULL;

-- 3) Expand the status field to include legacy values that were used before cleanup.
ALTER TABLE orders
    MODIFY due_date DATE NOT NULL,
    MODIFY status ENUM(
        'в роботі',
        'Виконано',
        'Нове',
        'Закрито',
        'Відкладено',
        'Підтверджено',
        'У виробництві',
        'Скасовано'
    ) NOT NULL DEFAULT 'Нове';

-- 4) Normalize old statuses into the final simplified workflow used in the app.
UPDATE orders
SET status = CASE status
    WHEN 'Підтверджено' THEN 'в роботі'
    WHEN 'У виробництві' THEN 'в роботі'
    WHEN 'Скасовано' THEN 'Закрито'
    ELSE status
END;

-- 5) Keep only the final status list used by the current UI and business logic.
ALTER TABLE orders
    MODIFY status ENUM('в роботі', 'Виконано', 'Нове', 'Закрито', 'Відкладено')
    NOT NULL DEFAULT 'Нове';

-- 6) Create a separate table for order line items. Each order can have multiple products,
-- while the parent order tracks totals and overall status.
CREATE TABLE IF NOT EXISTS order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    machinery_id INT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(180) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_machinery FOREIGN KEY (machinery_id) REFERENCES machinery(id) ON DELETE SET NULL,
    INDEX idx_order_items_order (order_id)
) ENGINE=InnoDB;
