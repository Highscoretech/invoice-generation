-- ─────────────────────────────────────────────────────────────────────────────
-- Store the exact FIRS BIS 3.0 payload supplied by an API caller, so the object
-- we transmit to NRS is byte-for-byte the one the customer sent (the middleware
-- only injects the server-generated irn / business_id / QR). When this column is
-- NULL the pipeline builds the payload from the DB rows as before.
-- Idempotent (MariaDB IF NOT EXISTS).
-- ─────────────────────────────────────────────────────────────────────────────
USE invoice_app;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS firs_payload MEDIUMTEXT NULL AFTER qr_data;
