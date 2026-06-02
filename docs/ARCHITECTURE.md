# E-Invoice Middleware — System Architecture

**Version:** 2.0 (FIRS integration)
**Last updated:** 2026-06-02

## 1. Purpose

The application is a **middleware** between a customer's billing system and the
Nigerian FIRS / NRS e-invoicing portal ("MBS"). Customers (or internal
accountants) create invoices; the middleware builds the IRN, validates, signs,
generates the FIRS QR, transmits to the portal, records every response, tracks
status, and automatically retries anything that fails for a transient reason.

```
 ┌──────────────┐     REST/JSON      ┌─────────────────────────────┐    HTTPS     ┌──────────────────┐
 │  Customer     │  x-client-key/     │   E-Invoice Middleware       │  x-api-key/  │   FIRS / NRS      │
 │  system       │ ───────────────▶  │   (this PHP app)             │ ───────────▶ │   MBS portal      │
 │  / Accountant │   secret           │                              │  secret      │  (DigitalOcean)   │
 │   UI          │ ◀───────────────  │  validate→sign→QR→transmit   │ ◀─────────── │                   │
 └──────────────┘   status / IRN     └──────────────┬──────────────┘   responses   └──────────────────┘
                                                     │
                                              ┌──────▼──────┐
                                              │   MySQL      │  invoices, firs_transmissions,
                                              │  (invoice_app)│  api_clients, api_inbound_invoices
                                              └─────────────┘
```

## 2. Components

| Layer | Files | Responsibility |
|-------|-------|----------------|
| Config | `config/env.php`, `.env` | Load secrets/config; `.env` blocked from web by `.htaccess`. |
| DB access | `config/database.php` | PDO connection. |
| FIRS client | `includes/FirsClient.php` | Auth headers, entity lookup, IRN from template, validate/sign/transmit, RSA QR. |
| Payload mapping | `includes/InvoicePayload.php` | DB rows → verified FIRS BIS 3.0 JSON. Single source of truth for the wire format. |
| Orchestrator | `includes/FirsService.php` | Pipeline (validate→sign→QR→transmit), logging, status, retry policy. |
| Retry runner | `retry_transmissions.php` | Cron entry point; re-runs due retries. |
| Customer API | `api/index.php`, `includes/ApiAuth.php` | Accept invoices, return status; API-key auth (bcrypt secrets). |
| Webhooks | `api/index.php` (inbound route), `includes/WebhookDispatcher.php` | Receive FIRS push events; send HMAC-signed status callbacks to customers. |
| Provisioning | `provision_api_client.php` | Create API clients (CLI). |
| UI | `send_to_api.php`, `view_invoice.php`, `api_docs.php`, `sla.php` | Operator screens + docs. |

## 3. Data model (additions)

- **invoices** — added `irn`, `business_id`, `qr_data` (RSA QR payload),
  `firs_status` (`not_sent → validated → signed → transmitted | failed |
  queued_retry`), `validated_at`, `signed_at`, `transmitted_at`,
  `transmit_attempts`, `last_attempt_at`, `next_retry_at`, `last_error`.
- **firs_transmissions** — append-only audit log: one row per portal call
  (`stage`, `attempt`, `http_code`, `status`, full request + response).
- **api_clients** — customer API credentials (`api_key`, bcrypt `api_secret_hash`,
  optional `webhook_url`).
- **api_inbound_invoices** — invoices received via the API, with the caller's
  `external_reference` for idempotency.

## 4. Submission pipeline

1. **Build IRN** from the entity's `irn_template` (e.g.
   `INVxxxx-5AF9E02D-20260602`); persisted and reused on every retry.
2. **Validate** — `POST /api/v1/invoice/validate`. Stored, status → `validated`.
3. **Sign** — `POST /api/v1/invoice/sign`. Status → `signed`.
4. **QR** — JSON `{irn, certificate}` → RSA/PKCS#1 encrypt with FIRS public key →
   base64. Stored in `qr_data`, rendered on the invoice.
5. **Transmit** — `POST /api/v1/invoice/transmit/{IRN}`. On success → `transmitted`.

Stage gating uses the persisted timestamps, so a retry resumes at the failed
stage and never repeats a non-idempotent step (re-signing returns HTTP 400).

## 4a. Status reporting & webhooks

FIRS transmission is asynchronous (4-corner exchange model), so final status
arrives after the transmit call returns. The app captures it two ways:

- **Inbound push** — `POST /api/v1/webhook/firs` receives FIRS events (logged in
  `firs_webhook_events`, authenticated by `FIRS_WEBHOOK_SECRET` HMAC/token). A push
  never changes state on its own; it triggers a re-poll of `confirm`.
- **Confirm poll** — `GET /api/v1/invoice/confirm/{IRN}` returns the authoritative
  `entry_status` / `transmitted` / `delivered`. Polled on transmit and swept by the
  cron for transmitted-but-undelivered invoices. Works even where FIRS cannot reach
  our inbound URL (e.g. local testing).

On any change we call the customer's `webhook_url` (HMAC-SHA256 signed via their
`webhook_secret`, header `x-webhook-signature`), with retry/backoff logged in
`webhook_deliveries`.

## 5. Retry policy

A failure is **transient** (→ queued, exponential backoff 2/5/15/60/180 min,
max 6 attempts) when it is a network error, HTTP ≥ 500, or the message contains
`offline / timeout / unavailable / temporarily / try again`. Everything else is
a **permanent** business rejection — surfaced immediately, not retried. The cron
job `retry_transmissions.php` drains the due queue.

## 6. Security

- HTTPS/TLS for all portal traffic.
- FIRS credentials in `.env`, denied web access via `.htaccess`; internal
  `includes/` and `config/` PHP blocked from direct access.
- Customer API secrets and user passwords stored as **bcrypt** hashes only.
- QR payloads RSA-encrypted with the FIRS public key per the QR-code spec.
- Idempotency keys prevent duplicate submissions.

## 7. Deployment (cPanel)

1. Upload to `public_html/einvoice`.
2. Import `database/schema.sql` then `database/migration_firs.sql`.
3. Set real values in `.env` (`FIRS_API_KEY`, `FIRS_API_SECRET`,
   `FIRS_BUSINESS_ID`, `FIRS_ENTITY_ID`).
4. Add cron: `*/5 * * * * php /home/USER/public_html/einvoice/retry_transmissions.php`.
5. Provision customer API clients with `provision_api_client.php`.

## 8. Verification status (2026-06-02, sandbox)

| Endpoint | Result |
|----------|--------|
| `GET /api` health | ✅ 200 `{healthy:true}` |
| `GET /api/v1/entity/{id}` | ✅ 200 (business id + irn_template resolved) |
| `POST /api/v1/invoice/validate` | ✅ 200 `{ok:true}` |
| `POST /api/v1/invoice/sign` | ✅ 201 `{ok:true}` |
| `POST /api/v1/invoice/transmit/{IRN}` | ⚠️ portal access points offline in sandbox → exercised retry path |
| Customer API (accept/status/idempotency/auth) | ✅ verified locally |

Transmit depends on the FIRS test access point, which is currently offline;
the middleware correctly logs and re-queues those attempts.
