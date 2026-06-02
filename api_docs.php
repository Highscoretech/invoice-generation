<?php
require_once 'includes/auth.php';
$auth = new Auth();
$auth->requireLogin();
$page_title = 'API Documentation';
include 'includes/header.php';

$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain');
?>
<div class="pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">API Documentation</h1>
    <p class="text-muted">Endpoints exposed by the e-invoice middleware. Customers integrate against these to submit
        invoices and track their FIRS status without touching the government portal directly.</p>
</div>

<style>
    .endpoint { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px; }
    .method { font-weight:700; padding:3px 10px; border-radius:6px; color:#fff; font-size:.8rem; }
    .m-get{background:#10b981;} .m-post{background:#3b82f6;}
    .endpoint pre { background:#0f172a; color:#e2e8f0; padding:14px; border-radius:8px; overflow:auto; font-size:.8rem; }
    .endpoint code.path { font-size:1rem; }
</style>

<div class="endpoint">
    <h5>Authentication</h5>
    <p>Every customer endpoint (except health) requires two headers issued when your API client is provisioned:</p>
    <pre>x-client-key: ak_xxxxxxxxxxxxxxxx
x-client-secret: sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx</pre>
    <p class="mb-0 text-muted">Secrets are stored only as bcrypt hashes; the plaintext secret is shown once at
        provisioning time. Provision a client with
        <code>php provision_api_client.php "Customer Name"</code>.</p>
</div>

<div class="endpoint">
    <span class="method m-get">GET</span> <code class="path">/api/v1/health</code>
    <p class="mt-2">Liveness probe. No authentication.</p>
    <strong>200 OK</strong>
    <pre>{ "healthy": true, "service": "einvoice-middleware", "time": "2026-06-02T15:40:12+01:00" }</pre>
</div>

<div class="endpoint">
    <span class="method m-post">POST</span> <code class="path">/api/v1/invoices</code>
    <p class="mt-2">Accept an invoice from a customer system. The app creates the invoice, builds the IRN,
        validates and signs it with FIRS, generates the signed QR, and transmits it. The call is
        <strong>idempotent</strong> on <code>reference</code> — replaying it never creates a duplicate.</p>
    <strong>Request body</strong>
    <pre>{
  "reference": "ACME-2001",              // your unique id (idempotency key) — required
  "invoice": { "number": "INV-ACME-2001", "date": "2026-06-02", "due_date": "2026-07-02" },
  "customer": {
    "name": "Acme Buyer Ltd",            // required
    "tin": "12345678-0001",
    "email": "buyer@acme.test",
    "address": "5 Marina", "city": "Lagos", "country": "Nigeria", "postal_zone": "100001"
  },
  "items": [                              // at least one required
    { "name": "Consulting", "quantity": 2, "rate": 50000, "hsn_code": "CC-001", "category": "Services" }
  ],
  "tax_rate": 7.5
}</pre>
    <strong>201 / 202</strong> (201 transmitted, 202 accepted &amp; queued for retry)
    <pre>{
  "reference": "ACME-2001",
  "invoice_id": 3,
  "irn": "INVACME2001-5AF9E02D-20260602",
  "firs_status": "transmitted",          // or "queued_retry"
  "qr_present": true,
  "status_url": "/api/v1/invoices/ACME-2001/status"
}</pre>
    <p class="text-muted mb-0">Errors: <code>401</code> bad credentials, <code>422</code> validation, <code>500</code> server.</p>
</div>

<div class="endpoint">
    <span class="method m-get">GET</span> <code class="path">/api/v1/invoices/{reference}/status</code>
    <p class="mt-2">Return the current FIRS status for a previously submitted invoice.</p>
    <strong>200 OK</strong>
    <pre>{
  "reference": "ACME-2001",
  "invoice_id": 3,
  "irn": "INVACME2001-5AF9E02D-20260602",
  "firs_status": "queued_retry",
  "attempts": 1,
  "transmitted_at": null,
  "next_retry_at": "2026-06-02 15:42:31",
  "last_error": "unable to transmit ... access points are offline"
}</pre>
</div>

<div class="endpoint">
    <h5>FIRS pipeline (internal, tested against the portal)</h5>
    <p>Each submitted invoice flows through these portal endpoints. All are exercised by the automated
        verification and logged in <code>firs_transmissions</code>.</p>
    <table class="table table-sm">
        <thead><tr><th>Stage</th><th>Portal endpoint</th><th>Verified</th></tr></thead>
        <tbody>
            <tr><td>Health</td><td><code>GET /api</code></td><td><span class="badge bg-success">200</span></td></tr>
            <tr><td>Entity / business lookup</td><td><code>GET /api/v1/entity/{id}</code></td><td><span class="badge bg-success">200</span></td></tr>
            <tr><td>Validate</td><td><code>POST /api/v1/invoice/validate</code></td><td><span class="badge bg-success">200</span></td></tr>
            <tr><td>Sign</td><td><code>POST /api/v1/invoice/sign</code></td><td><span class="badge bg-success">201</span></td></tr>
            <tr><td>Transmit</td><td><code>POST /api/v1/invoice/transmit/{IRN}</code></td><td><span class="badge bg-warning text-dark">AP-dependent</span></td></tr>
        </tbody>
    </table>
    <p class="mb-0 text-muted">IRN is built from the entity's <code>irn_template</code>. The QR payload is the
        invoice IRN + certificate, RSA-encrypted (PKCS#1) with the FIRS public key and base64-encoded, exactly per
        the FIRS QR-code spec.</p>
</div>

<div class="endpoint">
    <h5>Webhooks</h5>
    <p>Invoice status is reported two ways, so a result is never lost: FIRS pushes events to us, and we also
        actively poll FIRS's <code>confirm</code> endpoint. Whenever an invoice changes state we call the
        customer back.</p>

    <h6 class="mt-3">Inbound — FIRS → this app</h6>
    <p><span class="method m-post">POST</span> <code class="path">/api/v1/webhook/firs</code> —
        register this URL with FIRS. Authenticated by a shared secret: an <code>x-firs-signature</code>
        HMAC header or <code>?token=</code>, both checked against <code>FIRS_WEBHOOK_SECRET</code>. Every event is
        logged to <code>firs_webhook_events</code>; the authoritative status is then re-pulled from
        <code>GET /api/v1/invoice/confirm/{IRN}</code> (fields <code>entry_status</code>,
        <code>transmitted</code>, <code>delivered</code>) so a forged push can't change state on its own.</p>

    <h6 class="mt-3">Outbound — this app → your system</h6>
    <p>We <span class="method m-post">POST</span> to your <code>webhook_url</code> on every status change. The body
        is signed so you can verify it came from us:</p>
    <pre>x-webhook-event: invoice.delivered
x-webhook-signature: sha256=&lt;hmac&gt;          // HMAC-SHA256(body, your webhook_secret)

{
  "event": "invoice.transmitted",     // or invoice.delivered / invoice.failed
  "reference": "ACME-2001",
  "irn": "INVACME2001-5AF9E02D-20260602",
  "firs_status": "transmitted",
  "transmitted": true,
  "delivered": false,
  "entry_status": "NEW_ENTRY"
}</pre>
    <p class="mb-0 text-muted">Verify: recompute <code>HMAC-SHA256(rawBody, webhook_secret)</code> and compare to the
        header. Failed deliveries are retried with backoff (1/5/15/60 min) and logged in
        <code>webhook_deliveries</code>. Re-send is handled by the same cron as transmit retries.</p>
</div>

<?php include 'includes/footer.php'; ?>
