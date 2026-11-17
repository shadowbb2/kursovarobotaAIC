USE crm_agro;

-- This migration upgrades the inquiries table to a more structured workflow.
-- It adds a human-readable inquiry number, contact data, and a due date,
-- then normalizes old statuses and indexes for faster reporting.

-- 1) Add extra fields for tracking who sent the inquiry and when it should be handled.
ALTER TABLE inquiries
    ADD COLUMN inquiry_number VARCHAR(40) DEFAULT NULL AFTER id,
    ADD COLUMN short_description VARCHAR(500) NOT NULL DEFAULT '' AFTER subject,
    ADD COLUMN requester_name VARCHAR(150) DEFAULT NULL AFTER message,
    ADD COLUMN requester_email VARCHAR(190) DEFAULT NULL AFTER requester_name,
    ADD COLUMN requester_phone VARCHAR(40) DEFAULT NULL AFTER requester_email,
    ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT 'Інше' AFTER requester_phone,
    ADD COLUMN response_due_date DATE DEFAULT NULL AFTER source;

-- 2) Fill missing values for existing records so old inquiries remain valid.
UPDATE inquiries
SET inquiry_number = CONCAT('INQ-', LPAD(id, 6, '0'))
WHERE inquiry_number IS NULL;

UPDATE inquiries
SET short_description = LEFT(message, 500)
WHERE short_description = '';

UPDATE inquiries
SET response_due_date = DATE(created_at)
WHERE response_due_date IS NULL;

-- 3) Expand the status list to include historical values before standardizing them.
ALTER TABLE inquiries
    MODIFY inquiry_number VARCHAR(40) NOT NULL,
    MODIFY response_due_date DATE NOT NULL,
    MODIFY status ENUM(
        'Нове',
        'В роботі',
        'Оброблено',
        'В обробці',
        'Закрито'
    ) NOT NULL DEFAULT 'Нове',
    ADD UNIQUE INDEX uq_inquiries_number (inquiry_number),
    ADD INDEX idx_inquiries_created_at (created_at);

-- 4) Convert legacy statuses into the final list used by the application.
UPDATE inquiries
SET status = CASE status
    WHEN 'В обробці' THEN 'В роботі'
    WHEN 'Закрито' THEN 'Оброблено'
    ELSE status
END;

-- 5) Finalize the status enum to the current simplified workflow.
ALTER TABLE inquiries
    MODIFY status ENUM('Нове', 'В роботі', 'Оброблено') NOT NULL DEFAULT 'Нове';
