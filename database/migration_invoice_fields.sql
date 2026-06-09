-- ─────────────────────────────────────────────────────────────────────────────
-- Persist FIRS payload fields on the invoice (instead of hardcoding them in the
-- payload builder). Idempotent (MariaDB IF NOT EXISTS).
-- The `notes` column already exists and is now stored encrypted at rest.
-- ─────────────────────────────────────────────────────────────────────────────
USE invoice_app;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS invoice_type_code VARCHAR(10) NOT NULL DEFAULT '381' AFTER total_amount,
    ADD COLUMN IF NOT EXISTS payment_status VARCHAR(20) NOT NULL DEFAULT 'PENDING' AFTER invoice_type_code,
    ADD COLUMN IF NOT EXISTS document_currency_code VARCHAR(10) NOT NULL DEFAULT 'NGN' AFTER payment_status,
    ADD COLUMN IF NOT EXISTS tax_point_date DATE NULL AFTER document_currency_code,
    ADD COLUMN IF NOT EXISTS discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_amount;
