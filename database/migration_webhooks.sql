-- ─────────────────────────────────────────────────────────────────────────────
-- Webhook layer migration
--   * inbound:  log of events FIRS pushes to us (POST /api/v1/webhook/firs)
--   * status:   confirm-poll results stored on the invoice (transmitted/delivered)
--   * outbound: HMAC-signed status callbacks we send to the customer + delivery log
-- Idempotent (MariaDB IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────────
USE invoice_app;

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
