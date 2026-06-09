# Architecture Overview
This document serves as a critical, living template designed to equip agents with a rapid and comprehensive understanding of the codebase's architecture, enabling efficient navigation and effective contribution from day one. Update this document as the codebase evolves.

> A more detailed, narrative version of this architecture (data flows, pipeline
> stages, retry policy, verification status) lives in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## 1. Project Structure
This section provides a high-level overview of the project's directory and file structure, categorised by architectural layer or major functional area. It is essential for quickly navigating the codebase, locating relevant files, and understanding the overall organization and separation of concerns.

```
[Project Root]/
├── api/                       # Customer-facing REST API
│   ├── index.php              # API front controller (health, invoices, status, inbound webhook)
│   └── .htaccess              # Routes /api/* to index.php
├── includes/                  # Core PHP classes & shared view partials
│   ├── FirsClient.php         # HTTP client for the FIRS/NRS MBS portal
│   ├── InvoicePayload.php     # DB rows -> FIRS BIS 3.0 JSON (single source of truth)
│   ├── FirsService.php        # Pipeline orchestrator (validate→sign→QR→transmit), retry
│   ├── WebhookDispatcher.php  # HMAC-signed outbound status callbacks
│   ├── ApiAuth.php            # Customer API key/secret auth (bcrypt)
│   ├── Crypto.php             # AES-256-CBC field encryption at rest
│   ├── auth.php               # App user session auth / role gating
│   └── header.php, footer.php # Shared UI layout
├── config/                    # Configuration
│   ├── env.php                # .env loader + env() helper
│   └── database.php           # PDO connection (reads DB_* from .env)
├── database/                  # SQL schema & migrations
│   ├── schema.sql             # Base tables
│   ├── migration_firs.sql     # FIRS lifecycle columns
│   ├── migration_webhooks.sql # Webhook/delivery columns
│   ├── migration_invoice_fields.sql # Payload columns
│   └── install_cpanel.sql     # Combined one-shot install (schema + all migrations)
├── docs/                      # Documentation (detailed architecture)
├── *.php (root)               # Operator UI & entry points (see below)
├── .env                       # Secrets/config (gitignored, blocked from web)
├── .htaccess                  # Blocks .env/.sql/includes/config from web
└── architecture.md            # This document
```

Root PHP pages (operator UI & entry points): `login.php` / `logout.php`,
`dashboard.php`, `invoices.php` / `my_invoices.php`, `create_invoice.php`,
`view_invoice.php`, `customers.php`, `items.php`, `send_to_api.php` (trigger the
FIRS pipeline), `api_docs.php`, `sla.php`, `endpoint_tests.php` (live endpoint
coverage), `provision_api_client.php` (CLI — create API clients),
`retry_transmissions.php` (cron — retries + confirm polls + webhook resends).

## 2. High-Level System Diagram
Provide a simple block diagram (e.g., a C4 Model Level 1: System Context diagram, or a basic component diagram) or a clear text-based description of the major components and their interactions. Focus on how data flows, services communicate, and key architectural boundaries.

```
[Customer system / Accountant UI]
        |  x-client-key / x-client-secret (REST/JSON)
        v
[E-Invoice Middleware (this PHP app)]  --validate→sign→QR→transmit-->  [FIRS / NRS MBS portal]
        |                                  x-api-key / x-api-secret          (DigitalOcean)
        v
   [MySQL / MariaDB]   (invoices, firs_transmissions, api_clients, webhook_deliveries, ...)
```

The middleware sits between a customer's billing system (or internal
accountants) and the Nigerian FIRS/NRS e-invoicing portal. It builds the IRN,
validates, signs, generates the QR, transmits, logs every response, tracks
status (via webhooks + confirm polling), and auto-retries transient failures.

## 3. Core Components
(List and briefly describe the main components of the system. For each, include its primary responsibility and key technologies used.)

### 3.1. Frontend

Name: Operator / Accountant Web UI

Description: Server-rendered web interface where operators manage companies,
customers and items, create invoices, trigger submission to FIRS, and view IRN /
signed QR / transmission status. Includes API documentation and SLA pages and an
admin-only live endpoint-coverage page.

Technologies: PHP 8.3 (server-rendered views), Bootstrap 5, vanilla JS. No SPA framework.

Deployment: Apache (dev / XAMPP), LiteSpeed (production / cPanel).

### 3.2. Backend Services

#### 3.2.1. FIRS Submission Service

Name: FIRS Submission / Orchestration (`FirsService`, `FirsClient`, `InvoicePayload`)

Description: Builds the IRN from the entity's template, maps invoice rows to the
FIRS BIS 3.0 payload, and runs the pipeline validate → sign → QR → transmit →
confirm. Logs every portal call, gates stages on persisted timestamps (never
re-signs), and queues transient failures for exponential-backoff retry.

Technologies: PHP 8.3, cURL, OpenSSL (RSA/PKCS#1 QR signing).

Deployment: Same app process; retries driven by `retry_transmissions.php` cron.

#### 3.2.2. Customer API & Webhooks

Name: Customer API (`api/index.php`, `ApiAuth`, `WebhookDispatcher`)

Description: REST API for customer systems to submit invoices and poll status,
plus an inbound endpoint that receives FIRS push events and outbound HMAC-signed
status callbacks to customer webhook URLs.

Technologies: PHP 8.3, REST/JSON, bcrypt (API secrets), HMAC-SHA256 (webhooks).

Deployment: Same app, routed via `api/.htaccess`.

## 4. Data Stores

### 4.1. Primary Database

Name: Invoice application database

Type: MySQL / MariaDB (accessed via PDO)

Purpose: Stores invoices and their FIRS lifecycle state, the append-only portal
transmission audit log, customer API credentials, and webhook delivery records.

Key Schemas/Collections: `invoices`, `firs_transmissions`, `api_clients`,
`api_inbound_invoices`, `firs_webhook_events`, `webhook_deliveries`, `customers`,
`items`, `companies`, `users`.

### 4.2. Cache / Message Queue

Name: Retry queue (database-backed)

Type: No external broker — retry/confirm work is queued in DB columns
(`next_retry_at`, `transmit_attempts`) and drained by a cron job. No Redis/Kafka/RabbitMQ.

Purpose: Re-attempts transient transmit failures with exponential backoff and
polls FIRS for delivery confirmation.

## 5. External Integrations / APIs

Service Name 1: FIRS / NRS MBS e-invoicing portal

Purpose: Government e-invoice validation, signing, transmission and status confirmation.

Integration Method: REST API, `x-api-key` / `x-api-secret` header auth.

## 6. Deployment & Infrastructure

Cloud Provider: Shared cPanel hosting (LiteSpeed). FIRS portal runs on DigitalOcean (external).

Key Services Used: LiteSpeed/Apache, MySQL/MariaDB, PHP 8.3, cron.

CI/CD Pipeline: Manual deployment via FTP; combined `database/install_cpanel.sql` for DB setup.

Monitoring & Logging: Append-only `firs_transmissions` audit log; `webhook_deliveries`
log; admin `endpoint_tests.php` live coverage page.

## 7. Security Considerations

Authentication: App users — PHP sessions + bcrypt. Customer API — `x-client-key` /
`x-client-secret` (bcrypt-hashed). FIRS — `x-api-key` / `x-api-secret`.

Authorization: Role-gated UI (admin/accountant) via `includes/auth.php`.

Data Encryption: TLS in transit; AES-256-CBC for sensitive invoice fields (note)
at rest; RSA/PKCS#1 for the QR payload; HMAC-SHA256 for webhook integrity.

Key Security Tools/Practices: `.env` secrets blocked from web via `.htaccess`;
`includes/`/`config/` and `.sql` files denied direct access; idempotency keys
prevent duplicate submissions.

## 8. Development & Testing Environment

Local Setup Instructions: XAMPP (Apache + MariaDB + PHP 8.3); import
`database/install_cpanel.sql`; set `.env` (FIRS_* and DB_*).

Testing Frameworks: Live endpoint coverage via `endpoint_tests.php` (admin-only)
exercising every FIRS endpoint and the app's own APIs.

Code Quality Tools: PSR-style PHP; no external linter configured.

## 9. Future Considerations / Roadmap

- Transmit is currently exercised against the FIRS sandbox; production go-live
  depends on FIRS enabling the corresponding access point.
- Optional: move the DB-backed retry queue to a dedicated worker if volume grows.

## 10. Project Identification

Project Name: FIRS / NRS E-Invoice Middleware

Repository URL: (private)

Primary Contact/Team: Highscoretech

Date of Last Update: 2026-06-09

## 11. Glossary / Acronyms

FIRS: Federal Inland Revenue Service (Nigeria).

NRS / MBS: Nigeria Revenue Service / Merchant Buyer Solution — the e-invoicing portal.

IRN: Invoice Reference Number — unique ID built from the entity's IRN template.

BIS 3.0: The invoice document format/specification accepted by the FIRS portal.

QR: Signed QR payload — RSA-encrypted `{irn, certificate}`, base64-encoded.
