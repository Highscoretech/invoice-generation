-- ─────────────────────────────────────────────────────────────────────────────
-- FIRS e-invoice middleware migration
-- Adds IRN/QR storage, granular pipeline status, retry tracking, and a full
-- transmission audit log. Idempotent (MariaDB IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────────
USE invoice_app;

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
