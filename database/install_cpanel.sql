-- Combined cPanel install: run AFTER creating the DB in cPanel and selecting it in phpMyAdmin.
-- Order: schema -> firs migration -> webhooks migration.

-- ===== database/schema.sql =====

-- Companies table with 30 fields
CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    tin_number VARCHAR(100), -- Added TIN number as requested
    address VARCHAR(500),
    email VARCHAR(255),
    phone VARCHAR(50),
    city VARCHAR(100),
    state VARCHAR(100),
    country VARCHAR(100),
    postal_code VARCHAR(20),
    website VARCHAR(255),
    tax_id VARCHAR(100),
    registration_number VARCHAR(100),
    industry VARCHAR(100),
    established_date DATE,
    employee_count INT,
    annual_revenue DECIMAL(15,2),
    contact_person VARCHAR(255),
    contact_designation VARCHAR(100),
    contact_phone VARCHAR(50),
    contact_email VARCHAR(255),
    bank_name VARCHAR(255),
    bank_account VARCHAR(100),
    bank_routing VARCHAR(50),
    currency VARCHAR(10) DEFAULT 'USD',
    timezone VARCHAR(50),
    language VARCHAR(10) DEFAULT 'en',
    logo VARCHAR(255),
    description TEXT,
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role ENUM('admin', 'accountant') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Customers table with 30 fields
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    tax_id VARCHAR(100), -- Added GSTIN as requested
    address VARCHAR(500), -- Moved address up as requested
    email VARCHAR(255),
    phone VARCHAR(50),
    mobile VARCHAR(50),
    fax VARCHAR(50),
    website VARCHAR(255),
    registration_number VARCHAR(100),
    billing_address VARCHAR(500),
    billing_city VARCHAR(100),
    billing_state VARCHAR(100),
    billing_country VARCHAR(100),
    billing_postal_code VARCHAR(20),
    shipping_address VARCHAR(500),
    shipping_city VARCHAR(100),
    shipping_state VARCHAR(100),
    shipping_country VARCHAR(100),
    shipping_postal_code VARCHAR(20),
    contact_person VARCHAR(255),
    contact_designation VARCHAR(100),
    contact_phone VARCHAR(50),
    contact_email VARCHAR(255),
    credit_limit DECIMAL(15,2),
    payment_terms VARCHAR(100),
    discount_percentage DECIMAL(5,2),
    currency VARCHAR(10) DEFAULT 'USD',
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Items table with 30 fields
CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(100), -- Added item code as requested
    company_id INT NOT NULL,
    name VARCHAR(255) NOT NULL, -- Item name
    hsn_code VARCHAR(50), -- Added HSN code for GST compliance
    description TEXT,
    sku VARCHAR(100),
    barcode VARCHAR(100),
    category VARCHAR(100),
    subcategory VARCHAR(100),
    brand VARCHAR(100),
    model VARCHAR(100),
    color VARCHAR(50),
    size VARCHAR(50),
    weight DECIMAL(10,3),
    dimensions VARCHAR(100),
    unit VARCHAR(50),
    cost_price DECIMAL(15,2),
    selling_price DECIMAL(15,2),
    mrp DECIMAL(15,2),
    currency VARCHAR(10) DEFAULT 'USD',
    tax_rate DECIMAL(5,2),
    discount_percentage DECIMAL(5,2),
    minimum_stock INT,
    current_stock INT,
    reorder_level INT,
    supplier VARCHAR(255),
    supplier_code VARCHAR(100),
    warranty_period VARCHAR(100),
    expiry_date DATE,
    manufacturing_date DATE,
    location VARCHAR(100),
    notes TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- Invoices table
CREATE TABLE invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_number VARCHAR(100) UNIQUE NOT NULL,
    date DATE NOT NULL, -- Invoice date
    time TIME NOT NULL, -- Invoice time as requested
    customer_id INT NOT NULL, -- Customer reference
    company_id INT NOT NULL,
    user_id INT NOT NULL,
    due_date DATE,
    subtotal DECIMAL(15,2) NOT NULL,
    tax_rate DECIMAL(5,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL,
    qr_url VARCHAR(255),
    status ENUM('draft', 'sent', 'paid', 'cancelled', 'verified') DEFAULT 'draft',
    api_status ENUM('pending', 'sent', 'success', 'failed') DEFAULT 'pending',
    api_response TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Invoice items table
CREATE TABLE invoice_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_code VARCHAR(100), -- Item code as requested
    quantity DECIMAL(10,3) NOT NULL,
    rate DECIMAL(15,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    item_id INT NOT NULL,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

-- Insert sample data
INSERT INTO companies (name, email, phone, address, city, state, country, postal_code) VALUES
('Sample Company Ltd', 'info@samplecompany.com', '+1-555-0123', '123 Business St', 'Business City', 'Business State', 'USA', '12345');

INSERT INTO users (company_id, username, email, password, first_name, last_name, role) VALUES
(1, 'admin', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin', 'User', 'admin'),
(1, 'accountant', 'accountant@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Account', 'User', 'accountant');

-- ===== database/migration_firs.sql =====
-- ─────────────────────────────────────────────────────────────────────────────
-- FIRS e-invoice middleware migration
-- Adds IRN/QR storage, granular pipeline status, retry tracking, and a full
-- transmission audit log. Idempotent (MariaDB IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────────

-- Per-invoice FIRS fields -----------------------------------------------------
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS irn VARCHAR(120) NULL AFTER invoice_number,
    ADD COLUMN IF NOT EXISTS business_id VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS qr_data MEDIUMTEXT NULL,            -- RSA-encrypted QR payload (base64)
    ADD COLUMN IF NOT EXISTS firs_status VARCHAR(32) NOT NULL DEFAULT 'not_sent',
        -- not_sent | validated | signed | transmitted | failed | queued_retry
    ADD COLUMN IF NOT EXISTS validated_at  DATETIME NULL,
    ADD COLUMN IF NOT EXISTS signed_at     DATETIME NULL,
    ADD COLUMN IF NOT EXISTS transmitted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS transmit_attempts INT NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS last_attempt_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS next_retry_at DATETIME NULL,        -- set when queued for retry
    ADD COLUMN IF NOT EXISTS last_error VARCHAR(500) NULL;

CREATE INDEX IF NOT EXISTS idx_invoices_next_retry ON invoices (next_retry_at);
CREATE INDEX IF NOT EXISTS idx_invoices_firs_status ON invoices (firs_status);

-- Full audit log of every call to the portal -----------------------------------
CREATE TABLE IF NOT EXISTS firs_transmissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NULL,
    irn VARCHAR(120) NULL,
    stage VARCHAR(20) NOT NULL,            -- validate | sign | transmit | confirm
    attempt INT NOT NULL DEFAULT 1,
    http_code INT NULL,
    status VARCHAR(20) NOT NULL,           -- success | failed | network_error
    request_payload MEDIUMTEXT NULL,
    response_body MEDIUMTEXT NULL,
    error_message VARCHAR(1000) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tx_invoice (invoice_id),
    INDEX idx_tx_stage (stage)
);

-- API keys for the customer-facing middleware APIs -----------------------------
CREATE TABLE IF NOT EXISTS api_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    client_name VARCHAR(255) NOT NULL,
    api_key VARCHAR(64) UNIQUE NOT NULL,
    api_secret_hash VARCHAR(255) NOT NULL,  -- bcrypt hash of the secret (never stored plain)
    webhook_url VARCHAR(500) NULL,          -- where we POST status callbacks
    status ENUM('active','revoked') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_clients_company (company_id)
);

-- Inbound invoices accepted from external customers via the API ----------------
CREATE TABLE IF NOT EXISTS api_inbound_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_client_id INT NULL,
    external_reference VARCHAR(120) NULL,   -- caller's own id (idempotency)
    invoice_id INT NULL,                    -- our created invoice, once mapped
    irn VARCHAR(120) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'received',
    payload MEDIUMTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_inbound_client (api_client_id),
    INDEX idx_inbound_ref (external_reference)
);

-- Make sure the sample company carries the FIRS-registered TIN so sandbox
-- validation/sign succeed out of the box.
UPDATE companies SET tin_number = '23385763-7539', country = 'Nigeria'
    WHERE id = 1 AND (tin_number IS NULL OR tin_number = '');

-- ===== database/migration_webhooks.sql =====
-- ─────────────────────────────────────────────────────────────────────────────
-- Webhook layer migration
--   * inbound:  log of events FIRS pushes to us (POST /api/v1/webhook/firs)
--   * status:   confirm-poll results stored on the invoice (transmitted/delivered)
--   * outbound: HMAC-signed status callbacks we send to the customer + delivery log
-- Idempotent (MariaDB IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────────

-- Confirm/poll status fields on the invoice -----------------------------------
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS delivered TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS entry_status VARCHAR(40) NULL,   -- e.g. NEW_ENTRY, TRANSMITTED, DELIVERED, REJECTED
    ADD COLUMN IF NOT EXISTS confirmed_at DATETIME NULL;      -- last time we polled /confirm

-- Secret used to HMAC-sign outbound webhooks to the customer (shared with them).
ALTER TABLE api_clients
    ADD COLUMN IF NOT EXISTS webhook_secret VARCHAR(80) NULL;

-- Inbound: every event FIRS posts to our receiver, raw, for audit + replay.
CREATE TABLE IF NOT EXISTS firs_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    irn VARCHAR(120) NULL,
    event_type VARCHAR(60) NULL,
    invoice_id INT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    remote_ip VARCHAR(64) NULL,
    payload MEDIUMTEXT NULL,
    processed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fwe_irn (irn)
);

-- Outbound: each attempt to notify a customer of a status change.
CREATE TABLE IF NOT EXISTS webhook_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_client_id INT NULL,
    invoice_id INT NULL,
    event VARCHAR(60) NOT NULL,
    target_url VARCHAR(500) NULL,
    attempt INT NOT NULL DEFAULT 1,
    http_code INT NULL,
    status VARCHAR(20) NOT NULL,            -- success | failed | network_error | skipped
    payload MEDIUMTEXT NULL,
    response_body MEDIUMTEXT NULL,
    next_retry_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wd_client (api_client_id),
    INDEX idx_wd_retry (status, next_retry_at)
);

-- Backfill a webhook secret for any client that predates this column.
UPDATE api_clients
   SET webhook_secret = CONCAT('whsec_', SHA2(CONCAT(api_key, RAND()), 256))
 WHERE webhook_secret IS NULL OR webhook_secret = '';

