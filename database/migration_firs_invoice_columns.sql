-- ─────────────────────────────────────────────────────────────────────────────
-- NRS technical-capabilities fix: the invoices table must expose EVERY field the
-- FIRS invoice API defines, including the optional ones. The header-level FIRS
-- fields that were previously derived at submit time (the full monetary breakdown,
-- tax currency, tax category, allowance reason) are now first-class columns, so the
-- stored invoice mirrors the API object 1:1.
-- Idempotent (MariaDB IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────────
USE invoice_app;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS tax_currency_code       VARCHAR(10)    NOT NULL DEFAULT 'NGN'         AFTER document_currency_code,
    ADD COLUMN IF NOT EXISTS line_extension_amount   DECIMAL(15,2)  NOT NULL DEFAULT 0             AFTER subtotal,
    ADD COLUMN IF NOT EXISTS allowance_total_amount  DECIMAL(15,2)  NOT NULL DEFAULT 0             AFTER discount_amount,
    ADD COLUMN IF NOT EXISTS charge_total_amount     DECIMAL(15,2)  NOT NULL DEFAULT 0             AFTER allowance_total_amount,
    ADD COLUMN IF NOT EXISTS tax_exclusive_amount    DECIMAL(15,2)  NOT NULL DEFAULT 0             AFTER charge_total_amount,
    ADD COLUMN IF NOT EXISTS tax_inclusive_amount    DECIMAL(15,2)  NOT NULL DEFAULT 0             AFTER tax_exclusive_amount,
    ADD COLUMN IF NOT EXISTS payable_amount          DECIMAL(15,2)  NOT NULL DEFAULT 0             AFTER total_amount,
    ADD COLUMN IF NOT EXISTS tax_category_id         VARCHAR(40)    NOT NULL DEFAULT 'STANDARD_VAT' AFTER tax_rate,
    ADD COLUMN IF NOT EXISTS allowance_charge_reason VARCHAR(255)   NULL                           AFTER allowance_total_amount;

-- Backfill existing rows from the columns we already had, so the new FIRS-named
-- fields are populated and reconcile (tax is charged on the post-discount base).
UPDATE invoices SET
    tax_currency_code      = COALESCE(NULLIF(tax_currency_code, ''), document_currency_code, 'NGN'),
    line_extension_amount  = subtotal,
    allowance_total_amount = discount_amount,
    charge_total_amount    = 0,
    tax_exclusive_amount   = subtotal - discount_amount,
    tax_inclusive_amount   = (subtotal - discount_amount) + tax_amount,
    payable_amount         = total_amount,
    tax_category_id        = 'STANDARD_VAT',
    allowance_charge_reason = CASE WHEN discount_amount > 0 THEN 'Discount' ELSE allowance_charge_reason END;
