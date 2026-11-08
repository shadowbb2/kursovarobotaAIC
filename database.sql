-- This is the main database schema for crm_agro.

CREATE DATABASE IF NOT EXISTS crm_agro
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE crm_agro;

-- Users are the main authenticated accounts in the system.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'manager') NOT NULL DEFAULT 'manager',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Work sessions track when a user was active in the system.
CREATE TABLE IF NOT EXISTS work_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL,
    ended_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_work_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_work_sessions_user_active (user_id, ended_at),
    INDEX idx_work_sessions_started_at (started_at)
) ENGINE=InnoDB;

-- Clients store company and contact information for all business relationships.
CREATE TABLE IF NOT EXISTS clients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(180) NOT NULL,
    contact_person VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(40) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_clients_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_clients_company (company_name),
    INDEX idx_clients_email (email)
) ENGINE=InnoDB;

-- Machinery represents equipment or products the company sells or produces.
CREATE TABLE IF NOT EXISTS machinery (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    model VARCHAR(100) DEFAULT NULL,
    category VARCHAR(100) DEFAULT NULL,
    price DECIMAL(12,2) DEFAULT NULL,
    stock_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_machinery_name (name)
) ENGINE=InnoDB;

-- Additional custom attributes can be attached to each machinery item.
CREATE TABLE IF NOT EXISTS machinery_attributes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    machinery_id INT UNSIGNED NOT NULL,
    attribute_name VARCHAR(100) NOT NULL,
    attribute_value VARCHAR(500) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_machinery_attributes_product
        FOREIGN KEY (machinery_id) REFERENCES machinery(id) ON DELETE CASCADE,
    INDEX idx_machinery_attributes_product (machinery_id, sort_order)
) ENGINE=InnoDB;

-- Orders are the main sales records. Each order references a client and optional machinery.
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(40) NOT NULL UNIQUE,
    order_name VARCHAR(180) NOT NULL,
    client_id INT UNSIGNED NOT NULL,
    machinery_id INT UNSIGNED DEFAULT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('в роботі', 'Виконано', 'Нове', 'Закрито', 'Відкладено') NOT NULL DEFAULT 'Нове',
    order_date DATE NOT NULL,
    due_date DATE NOT NULL,
    notes TEXT DEFAULT NULL,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_machinery FOREIGN KEY (machinery_id) REFERENCES machinery(id) ON DELETE SET NULL,
    CONSTRAINT fk_orders_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_orders_status (status),
    INDEX idx_orders_date (order_date)
) ENGINE=InnoDB;

-- Order items split a single order into multiple product/line entries.
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

-- Inquiries represent customer requests or questions before they become orders.
CREATE TABLE IF NOT EXISTS inquiries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inquiry_number VARCHAR(40) NOT NULL UNIQUE,
    client_id INT UNSIGNED DEFAULT NULL,
    subject VARCHAR(180) NOT NULL,
    short_description VARCHAR(500) NOT NULL,
    message TEXT NOT NULL,
    requester_name VARCHAR(150) DEFAULT NULL,
    requester_email VARCHAR(190) DEFAULT NULL,
    requester_phone VARCHAR(40) DEFAULT NULL,
    source VARCHAR(40) NOT NULL,
    response_due_date DATE NOT NULL,
    status ENUM('Нове', 'В роботі', 'Оброблено') NOT NULL DEFAULT 'Нове',
    created_by INT UNSIGNED DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inquiries_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL,
    CONSTRAINT fk_inquiries_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_inquiries_status (status),
    INDEX idx_inquiries_created_at (created_at)
) ENGINE=InnoDB;

-- Seed the default admin account used by the CRM.
INSERT INTO users (full_name, email, password_hash, role)
VALUES (
    'Денис Павелко',
    'denyspavelko@aic.local',
    '$2y$12$JvabewJWOG9NOKgxAu5KXOHprK0A/jK5GE9Mb.vaHnsIR8ovWx64u',
    'admin'
)
ON DUPLICATE KEY UPDATE email = VALUES(email);
