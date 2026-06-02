# E-Invoice Middleware (FIRS / NRS MBS)

A PHP/MariaDB invoice app that acts as **middleware between a customer's billing
system and the Nigerian FIRS e-invoicing portal**. It builds the IRN, validates,
signs, generates the FIRS QR, transmits to the portal, logs every response,
tracks status (push webhook + confirm poll), auto-retries transient failures,
and exposes an API + webhooks for customers.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full design.

## Features

- Real FIRS pipeline: **validate → sign → QR → transmit**, fully logged
- IRN built from the entity's `irn_template`; RSA-PKCS#1 signed QR
- Automatic retry with exponential backoff for transient failures
- Status tracking via FIRS **confirm** polling **and** inbound webhooks
- Customer API: submit invoices + query status (API-key auth, idempotent)
- Outbound **HMAC-signed** status webhooks to customers
- API documentation page + SLA page in-app
- Security: bcrypt for all secrets/passwords, `.env` blocked from web

## Local setup (XAMPP)

1. Put the project in `htdocs` (served at `http://localhost/invoice-generation/`).
2. Create the database and apply migrations **in order**:
   ```
   mysql -u root < database/schema.sql
   mysql -u root invoice_app < database/migration_firs.sql
   mysql -u root invoice_app < database/migration_webhooks.sql
   ```
3. Copy/confirm `.env` (FIRS keys, business id, webhook secret) — see `.env`.
4. DB connection is in `config/database.php` (defaults to root / no password).

## Default logins

- Admin:      `admin`      / `password`
- Accountant: `accountant` / `password`

(The accountant role is the one that sends invoices to the FIRS portal.)

## Deployment (cPanel)

1. Upload all files to the app root (e.g. `public_html/einvoice`) and point the
   domain at it.
2. Create the MySQL DB, then import in order: `schema.sql`,
   `migration_firs.sql`, `migration_webhooks.sql`.
3. Update `config/database.php` with the cPanel DB host/name/user/pass.
4. Put the real FIRS credentials in `.env`
   (`FIRS_API_KEY`, `FIRS_API_SECRET`, `FIRS_BUSINESS_ID`, `FIRS_ENTITY_ID`,
   `FIRS_WEBHOOK_SECRET`). Set `DEBUG=false`.
5. Confirm `.htaccess` is active (it blocks `.env`, `.sql`, `includes/`, `config/`).
6. Add a cron job (every 5 min) for retries / confirm polls / webhook resends:
   ```
   */5 * * * * /usr/bin/php /home/USER/public_html/einvoice/retry_transmissions.php
   ```
7. Provision customer API clients (prints key + secret + webhook_secret once):
   ```
   php provision_api_client.php "Customer Name" 1 https://customer/webhook
   ```
8. Register your inbound webhook URL with FIRS:
   `https://your-domain/api/v1/webhook/firs`

## Key endpoints

- App UI: `login.php`, `create_invoice.php`, `view_invoice.php`, `send_to_api.php`,
  `api_docs.php`, `sla.php`
- Customer API: `GET /api/v1/health`, `POST /api/v1/invoices`,
  `GET /api/v1/invoices/{ref}/status`
- Webhook in: `POST /api/v1/webhook/firs`
