# Database Architecture

**Engine:** MySQL / MariaDB (InnoDB), accessed via PHP PDO.
**Last updated:** 2026-06-09
**Source of truth:** [`database/install_cpanel.sql`](../database/install_cpanel.sql)
(= `schema.sql` + `migration_firs.sql` + `migration_webhooks.sql` + `migration_invoice_fields.sql`).

## 1. Overview

The schema has three logical groups:

1. **Master data** — `companies`, `users`, `customers`, `items`.
2. **Invoicing** — `invoices`, `invoice_items` (plus all FIRS lifecycle columns on
   `invoices`).
3. **FIRS / API integration** — `firs_transmissions`, `api_clients`,
   `api_inbound_invoices`, `firs_webhook_events`, `webhook_deliveries`.

```
                         ┌───────────────┐
                         │   companies   │ (tenant / supplier)
                         └───────┬───────┘
        ┌────────────────┬───────┼────────────────┬───────────────┐
        │                │       │                │               │
   ┌────▼────┐     ┌─────▼────┐  │           ┌────▼─────┐    ┌─────▼──────┐
   │  users  │     │customers │  │           │  items   │    │api_clients │
   └────┬────┘     └────┬─────┘  │           └────┬─────┘    └─────┬──────┘
        │               │        │                │                │
        │  user_id      │customer_id      item_id │                │ api_client_id
        └───────────┐   │   ┌────────────┐        │        ┌───────┴───────────┐
                    ▼   ▼   ▼            │         │        ▼                   ▼
                 ┌──────────────┐        │  ┌──────▼──────┐ ┌──────────────────┐
                 │   invoices   │◀───────┴──│invoice_items│ │api_inbound_invoices│
                 └──────┬───────┘ invoice_id└─────────────┘ └──────────────────┘
                        │ invoice_id / irn
        ┌───────────────┼─────────────────────┬──────────────────────┐
        ▼               ▼                      ▼                      ▼
 ┌────────────────┐ ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
 │firs_transmissions│ │firs_webhook_events│ │webhook_deliveries│ │  (status cols on │
 │  (portal log)  │ │ (inbound from FIRS)│ │(outbound to cust.)│ │   invoices)      │
 └────────────────┘ └──────────────────┘ └──────────────────┘ └──────────────────┘
```

Hard foreign keys (enforced, `ON DELETE CASCADE`) exist within the master-data /
invoicing core. The integration/audit tables reference `invoice_id` / `api_client_id`
**logically** (no FK constraint) so that an audit row is never lost if its parent
is removed and so high-volume log writes stay cheap.

## 2. Master data

### `companies` — tenant / supplier (the business issuing invoices)
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| name | VARCHAR(255) NOT NULL | |
| tin_number | VARCHAR(100) | FIRS Taxpayer ID (used for the supplier party) |
| address, city, state, country, postal_code | VARCHAR | Postal address |
| email, phone, website | VARCHAR | Contact |
| tax_id, registration_number, industry | VARCHAR | |
| established_date | DATE | |
| employee_count | INT | |
| annual_revenue | DECIMAL(15,2) | |
| contact_person / _designation / _phone / _email | VARCHAR | Primary contact |
| bank_name, bank_account, bank_routing | VARCHAR | |
| currency | VARCHAR(10) DEFAULT 'USD' | |
| timezone, language, logo | VARCHAR | |
| description, notes | TEXT | |
| status | ENUM('active','inactive') DEFAULT 'active' | |
| created_at, updated_at | TIMESTAMP | Auto-managed |

### `users` — application logins (operators)
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| company_id | INT NOT NULL | **FK → companies(id)** CASCADE |
| username | VARCHAR(100) UNIQUE NOT NULL | |
| email | VARCHAR(255) UNIQUE NOT NULL | |
| password | VARCHAR(255) NOT NULL | **bcrypt hash** (never plain) |
| first_name, last_name | VARCHAR(100) | |
| role | ENUM('admin','accountant') NOT NULL | Authorization |
| status | ENUM('active','inactive') DEFAULT 'active' | |
| created_at, updated_at | TIMESTAMP | |

### `customers` — buyers (the invoice recipient)
Key columns: `id` PK; `company_id` **FK → companies(id)** CASCADE; `name` NOT NULL;
`tax_id` (buyer TIN); contact (`email`, `phone`, `mobile`, `fax`, `website`);
**billing** address block (`billing_address`, `billing_city`, `billing_state`,
`billing_country`, `billing_postal_code`) — used to build the FIRS customer party;
**shipping** address block; `credit_limit`, `payment_terms`,
`discount_percentage`, `currency`; `notes`; `status`; timestamps.

### `items` — product / service catalogue
Key columns: `id` PK; `item_code`; `company_id` **FK → companies(id)** CASCADE;
`name` NOT NULL; `hsn_code` (HS/HSN code for FIRS line items); `description`
(required at entry — FIRS needs a line description); `sku`, `barcode`,
`category`/`subcategory`, `brand`, `model`; physical attrs (`color`, `size`,
`weight`, `dimensions`, `unit`); pricing (`cost_price`, `selling_price`, `mrp`,
`currency`, `tax_rate`, `discount_percentage`); stock (`minimum_stock`,
`current_stock`, `reorder_level`); supplier, warranty, dates; `status`; timestamps.

## 3. Invoicing

### `invoices` — invoice header + full FIRS lifecycle
**Base columns**
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| invoice_number | VARCHAR(100) UNIQUE NOT NULL | |
| date | DATE NOT NULL | Issue date |
| time | TIME NOT NULL | Issue time |
| customer_id | INT NOT NULL | **FK → customers(id)** CASCADE |
| company_id | INT NOT NULL | **FK → companies(id)** CASCADE |
| user_id | INT NOT NULL | **FK → users(id)** CASCADE |
| due_date | DATE | |
| subtotal | DECIMAL(15,2) NOT NULL | Sum of lines (pre-discount) |
| tax_rate | DECIMAL(5,2) DEFAULT 0 | VAT % (defaults to 7.5 in payload) |
| tax_amount | DECIMAL(15,2) DEFAULT 0 | |
| discount_amount | DECIMAL(15,2) DEFAULT 0 | Document-level allowance |
| total_amount | DECIMAL(15,2) NOT NULL | |
| qr_url | VARCHAR(255) | |
| status | ENUM('draft','sent','paid','cancelled','verified') DEFAULT 'draft' | Business status |
| api_status | ENUM('pending','sent','success','failed') DEFAULT 'pending' | Legacy send status |
| api_response | TEXT | |
| notes | TEXT | **Encrypted at rest** (AES-256-CBC via `Crypto`) |
| created_at, updated_at | TIMESTAMP | |

**FIRS lifecycle columns** (added by `migration_firs.sql`)
| Column | Type | Notes |
|--------|------|-------|
| irn | VARCHAR(120) | Invoice Reference Number (built from entity template) |
| business_id | VARCHAR(64) | FIRS business UUID |
| qr_data | MEDIUMTEXT | RSA-encrypted QR payload (base64) |
| firs_payload | MEDIUMTEXT | Exact FIRS BIS 3.0 payload supplied via the API; transmitted to NRS verbatim (NULL when the payload is built from DB rows) |
| firs_status | VARCHAR(32) DEFAULT 'not_sent' | `not_sent → validated → signed → transmitted` / `failed` / `queued_retry` |
| validated_at, signed_at, transmitted_at | DATETIME | Stage timestamps (used for retry stage-gating) |
| transmit_attempts | INT DEFAULT 0 | |
| last_attempt_at | DATETIME | |
| next_retry_at | DATETIME | Set when queued for retry |
| last_error | VARCHAR(500) | |

**Confirm/poll columns** (added by `migration_webhooks.sql`)
| Column | Type | Notes |
|--------|------|-------|
| delivered | TINYINT(1) DEFAULT 0 | |
| entry_status | VARCHAR(40) | e.g. NEW_ENTRY, TRANSMITTED, DELIVERED, REJECTED |
| confirmed_at | DATETIME | Last `/confirm` poll |

**FIRS payload columns** (added by `migration_invoice_fields.sql`)
| Column | Type | Notes |
|--------|------|-------|
| invoice_type_code | VARCHAR(10) DEFAULT '381' | 381 = Commercial Invoice |
| payment_status | VARCHAR(20) DEFAULT 'PENDING' | |
| document_currency_code | VARCHAR(10) DEFAULT 'NGN' | |
| tax_point_date | DATE | Defaults to issue date in payload |
| discount_rate | DECIMAL(5,2) DEFAULT 0 | Multiplier for the allowance_charge |

**Indexes:** `idx_invoices_next_retry (next_retry_at)`,
`idx_invoices_firs_status (firs_status)` — drive the retry-queue sweep.

### `invoice_items` — invoice line items
| Column | Type | Notes |
|--------|------|-------|
| id | INT PK AI | |
| invoice_id | INT NOT NULL | **FK → invoices(id)** CASCADE |
| item_code | VARCHAR(100) | |
| quantity | DECIMAL(10,3) NOT NULL | |
| rate | DECIMAL(15,2) NOT NULL | Unit price |
| amount | DECIMAL(15,2) NOT NULL | Line extension amount |
| item_id | INT NOT NULL | **FK → items(id)** CASCADE |

## 4. FIRS / API integration

### `firs_transmissions` — append-only portal audit log
One row per call to the FIRS portal. `invoice_id`, `irn`, `stage`
(`validate`/`sign`/`transmit`/`confirm`), `attempt`, `http_code`,
`status` (`success`/`failed`/`network_error`), `request_payload`,
`response_body`, `error_message`, `created_at`.
Indexes: `idx_tx_invoice (invoice_id)`, `idx_tx_stage (stage)`.

### `api_clients` — customer API credentials
`id`; `company_id`; `client_name`; `api_key` VARCHAR(64) UNIQUE; `api_secret_hash`
(**bcrypt**, never plain); `webhook_url`; `webhook_secret` VARCHAR(80) (HMAC-signs
outbound callbacks); `status` ENUM('active','revoked'); `created_at`.
Index: `idx_api_clients_company (company_id)`.

### `api_inbound_invoices` — invoices received via the customer API
`id`; `api_client_id`; `external_reference` (caller's id — **idempotency key**);
`invoice_id` (our mapped invoice); `irn`; `status` (default 'received');
`payload`; timestamps. Indexes: `idx_inbound_client`, `idx_inbound_ref`.

### `firs_webhook_events` — inbound events FIRS pushes to us
`id`; `irn`; `event_type`; `invoice_id`; `signature_valid` TINYINT;
`remote_ip`; `payload`; `processed` TINYINT; `created_at`.
Index: `idx_fwe_irn (irn)`. A push never changes state directly — it triggers a
`/confirm` re-poll.

### `webhook_deliveries` — outbound status callbacks to customers
`id`; `api_client_id`; `invoice_id`; `event`; `target_url`; `attempt`;
`http_code`; `status` (`success`/`failed`/`network_error`/`skipped`); `payload`;
`response_body`; `next_retry_at`; `created_at`.
Indexes: `idx_wd_client (api_client_id)`, `idx_wd_retry (status, next_retry_at)`.

## 5. Relationships summary

| Child | Column | Parent | Enforced? | On delete |
|-------|--------|--------|-----------|-----------|
| users | company_id | companies | FK | CASCADE |
| customers | company_id | companies | FK | CASCADE |
| items | company_id | companies | FK | CASCADE |
| invoices | company_id | companies | FK | CASCADE |
| invoices | customer_id | customers | FK | CASCADE |
| invoices | user_id | users | FK | CASCADE |
| invoice_items | invoice_id | invoices | FK | CASCADE |
| invoice_items | item_id | items | FK | CASCADE |
| firs_transmissions | invoice_id | invoices | logical | — |
| api_clients | company_id | companies | logical | — |
| api_inbound_invoices | api_client_id / invoice_id | api_clients / invoices | logical | — |
| firs_webhook_events | invoice_id | invoices | logical | — |
| webhook_deliveries | api_client_id / invoice_id | api_clients / invoices | logical | — |

## 6. Security & data handling

- **Passwords** (`users.password`) and **API secrets** (`api_clients.api_secret_hash`)
  are stored as **bcrypt** hashes only.
- **`invoices.notes`** is **encrypted at rest** with AES-256-CBC (`includes/Crypto.php`);
  decrypted only when building the FIRS payload or displaying.
- **`qr_data`** holds the RSA/PKCS#1-encrypted QR payload.
- `firs_transmissions`, `firs_webhook_events`, `webhook_deliveries` are
  append-only audit logs — useful for replay, debugging and proving delivery.
- Idempotency on inbound invoices is enforced via
  `api_inbound_invoices.external_reference`.

## 7. Install / migrate

- **Fresh install:** import [`database/install_cpanel.sql`](../database/install_cpanel.sql)
  (complete combined schema).
- **Incremental:** run `schema.sql`, then `migration_firs.sql`,
  `migration_webhooks.sql`, `migration_invoice_fields.sql` in order.
- The migrations are written idempotently (MariaDB `IF NOT EXISTS`); on MySQL the
  combined install file has those guards stripped, so run it once on a clean DB.
