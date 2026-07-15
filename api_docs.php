<?php
// API reference. Public (no login) -> standalone byteinvoice-style page.
// Logged in -> rendered inside the normal operator layout, like any other page.
require_once 'includes/auth.php';
$auth = new Auth();
$loggedIn = $auth->isLoggedIn();
$host = $_SERVER['HTTP_HOST'] ?? 'test.virdi.biz';
$base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host;

/** Lightweight JSON syntax highlighter for the dark code blocks. */
function hljson(string $json): string {
    $e = htmlspecialchars($json, ENT_QUOTES);
    $e = preg_replace('/(&quot;[^&]*?&quot;)(\s*:)/', '<span class="k">$1</span>$2', $e);
    $e = preg_replace('/(:\s*)(&quot;[^&]*?&quot;)/', '$1<span class="s">$2</span>', $e);
    $e = preg_replace('/(:\s*)(-?\d+\.?\d*)/', '$1<span class="n">$2</span>', $e);
    $e = preg_replace('/\b(true|false|null)\b/', '<span class="b">$1</span>', $e);
    return $e;
}
function hlbash(string $cmd): string {
    $e = htmlspecialchars($cmd, ENT_QUOTES);
    $e = preg_replace('/(&quot;[^&]*?&quot;)/', '<span class="s">$1</span>', $e);
    $e = preg_replace('/\b(curl|-X|-H|-d)\b/', '<span class="kw">$1</span>', $e);
    $e = preg_replace('/\b(GET|POST)\b/', '<span class="mkw">$1</span>', $e);
    return $e;
}

// Scoped styles (everything under .apiref so the operator layout is untouched).
$CSS = <<<CSS
.apiref{--card:#ffffff;--ink:#1b2230;--muted:#69707e;--faint:#9aa2b1;--line:#e6e9ef;
  --line2:#eef1f6;--accent:#0d7a3f;--link:#1a56db;--req:#e5484d;--code-bg:#0d1117;
  --mono:'SF Mono','SFMono-Regular',ui-monospace,Menlo,Consolas,monospace;color:var(--ink);}
.apiref *{box-sizing:border-box;}
.apiref .wrap{max-width:960px;margin:0 auto;padding:8px 4px 40px;}
.apiref code,.apiref pre{font-family:var(--mono);}
.apiref a{color:var(--link);text-decoration:none;}.apiref a:hover{text-decoration:underline;}
.apiref h1{font-size:2rem;font-weight:800;letter-spacing:-.01em;margin:0 0 12px;color:var(--ink);}
.apiref .lead{color:var(--muted);font-size:1.02rem;max-width:720px;margin:0 0 26px;}
.apiref .lead .hl{color:var(--accent);font-weight:600;}
.apiref .grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
.apiref .card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px 22px;}
.apiref .lbl{font-size:.68rem;font-weight:700;letter-spacing:.09em;color:var(--faint);text-transform:uppercase;margin-bottom:9px;}
.apiref .mono{font-family:var(--mono);font-size:.9rem;}
.apiref .codes{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 18px;margin-top:4px;}
.apiref .codes div{font-size:.92rem;}.apiref .codes b{color:#4f46e5;font-family:var(--mono);margin-right:8px;}
.apiref h2.sec{font-size:1.25rem;font-weight:700;margin:38px 0 16px;padding-bottom:10px;border-bottom:1px solid var(--line);color:var(--ink);}
.apiref .ep{background:var(--card);border:1px solid var(--line);border-radius:12px;margin-bottom:22px;overflow:hidden;}
.apiref .ep-h{display:flex;align-items:center;gap:12px;padding:15px 20px;}
.apiref .m{font-size:.72rem;font-weight:800;letter-spacing:.03em;padding:4px 9px;border-radius:6px;text-transform:uppercase;}
.apiref .m.get{background:#e8f0fe;color:#1a56db;}.apiref .m.post{background:#e6f6ec;color:#0d7a3f;}
.apiref .m.patch{background:#fff3e0;color:#b06c08;}
.apiref .path{font-family:var(--mono);font-weight:700;font-size:1rem;}
.apiref .ep-b{padding:0 20px 20px;border-top:1px solid var(--line2);}
.apiref .ep-b .desc{color:var(--muted);margin:16px 0 8px;}
.apiref table{width:100%;border-collapse:collapse;margin:6px 0 14px;font-size:.88rem;}
.apiref thead th{background:#f6f8fb;color:var(--muted);font-weight:600;text-align:left;padding:8px 12px;border-top:1px solid var(--line);border-bottom:1px solid var(--line);}
.apiref tbody td{padding:8px 12px;border-bottom:1px solid var(--line2);vertical-align:top;}
.apiref td.p{font-family:var(--mono);font-size:.83rem;color:var(--ink);}
.apiref td.t{font-style:italic;color:var(--faint);white-space:nowrap;}
.apiref .rq{color:var(--req);font-weight:700;}
.apiref pre{background:var(--code-bg);color:#e6edf3;border-radius:9px;padding:14px 16px;overflow-x:auto;font-size:.82rem;line-height:1.55;margin:6px 0 16px;}
.apiref pre .k{color:#79c0ff;}.apiref pre .s{color:#a5d6ff;}.apiref pre .n{color:#7ee787;}.apiref pre .b{color:#ff7b72;}
.apiref pre .kw{color:#ff7b72;}.apiref pre .mkw{color:#d2a8ff;font-weight:700;}
@media(max-width:720px){.apiref .grid2,.apiref .codes{grid-template-columns:1fr;}}
CSS;

// ── Build the shared content once ────────────────────────────────────────────
ob_start(); ?>
<div class="apiref"><div class="wrap">
  <h1>API Reference</h1>
  <p class="lead">The <span class="hl">Virdi E-Invoice</span> API lets you submit, sign, and manage
    <span class="hl">FIRS</span>-compliant e-invoices programmatically. All fields follow the
    <span class="hl">FIRS / NRS BIS 3.0</span> (Business Invoice Standard).</p>

  <div class="grid2">
    <div class="card">
      <div class="lbl">Base URL</div>
      <div class="mono"><?php echo htmlspecialchars($base); ?></div>
      <div style="color:var(--faint);font-size:.85rem;margin-top:8px;">All v1 endpoints are prefixed with <span class="mono">/api/v1</span></div>
    </div>
    <div class="card">
      <div class="lbl">Authentication</div>
      <div class="mono">x-client-key: ak_&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;<br>x-client-secret: sk_&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</div>
      <div style="color:var(--faint);font-size:.85rem;margin-top:8px;">Provisioned per customer (bcrypt-hashed). The <span class="mono">health</span> endpoint needs no auth.</div>
    </div>
  </div>

  <div class="card" style="margin-bottom:8px;">
    <div class="lbl">FIRS Invoice Type Codes</div>
    <div class="codes">
      <div><b>380</b>Standard Invoice</div>
      <div><b>381</b>Credit Note</div>
      <div><b>383</b>Debit Note</div>
      <div><b>386</b>Prepayment Invoice</div>
      <div><b>389</b>Self-billed Invoice</div>
      <div><b>396</b>Invoice Request</div>
      <div><b>751</b>Invoice Information</div>
    </div>
  </div>

  <h2 class="sec">Invoices</h2>

  <div class="ep">
    <div class="ep-h"><span class="m post">POST</span><span class="path">/api/v1/invoices</span></div>
    <div class="ep-b">
      <p class="desc">Create and submit a new FIRS/NRS-compliant invoice. The middleware builds the IRN,
        validates and signs the payload with FIRS, generates the QR code, and transmits it to the NRS gateway.
        Returns the IRN and status immediately; final FIRS delivery is confirmed asynchronously. Send the full
        FIRS BIS 3.0 body below.</p>
      <div class="lbl" style="margin-top:6px;">Request Body Fields (<span class="rq">*</span> = required)</div>
      <table>
        <thead><tr><th style="width:46%">Parameter</th><th style="width:14%">Type</th><th>Description</th></tr></thead>
        <tbody>
        <?php
        $fields = [
          ['business_id','string',1,'FIRS business UUID. Set by the server from your entity profile.'],
          ['irn','string',1,'Invoice Reference Number - generated by the server (ALL UPPERCASE, letters/digits/hyphens).'],
          ['issue_date','string',1,'Issue date (YYYY-MM-DD).'],
          ['invoice_type_code','string',1,'FIRS invoice type code - see table above (e.g. 381).'],
          ['document_currency_code','string',1,'ISO 4217 currency code (e.g. NGN).'],
          ['tax_currency_code','string',1,'Currency used for tax amounts (e.g. NGN).'],
          ['accounting_supplier_party.party_name','string',1,'Supplier legal name.'],
          ['accounting_supplier_party.tin','string',1,'Supplier TIN (e.g. 22047671-0001).'],
          ['accounting_supplier_party.email','string',1,'Supplier contact email.'],
          ['accounting_supplier_party.postal_address','object',1,'street_name, city_name, postal_zone, country (all required).'],
          ['accounting_supplier_party.telephone','string',0,'Supplier phone (country code, e.g. +234) - optional.'],
          ['accounting_supplier_party.business_description','string',0,'Brief description of the supplier business - optional.'],
          ['accounting_customer_party.party_name','string',1,'Buyer legal name.'],
          ['accounting_customer_party.tin','string',1,'Buyer TIN (e.g. 12345678-0001).'],
          ['accounting_customer_party.email','string',1,'Buyer contact email.'],
          ['accounting_customer_party.postal_address','object',1,'street_name, city_name, postal_zone, country (all required).'],
          ['accounting_customer_party.telephone','string',0,'Buyer phone (country code, e.g. +234) - optional.'],
          ['legal_monetary_total.line_extension_amount','number',1,'Sum of all line amounts before tax.'],
          ['legal_monetary_total.tax_exclusive_amount','number',1,'Total amount excluding tax.'],
          ['legal_monetary_total.tax_inclusive_amount','number',1,'Total amount including tax.'],
          ['legal_monetary_total.payable_amount','number',1,'Final amount payable.'],
          ['legal_monetary_total.allowance_total_amount','number',0,'Total discounts - optional, default 0.'],
          ['legal_monetary_total.charge_total_amount','number',0,'Total charges - optional, default 0.'],
          ['invoice_line[].hsn_code','string',1,'FIRS HSN / service code (e.g. 8517.12).'],
          ['invoice_line[].product_category','string',1,'Product or service category.'],
          ['invoice_line[].invoiced_quantity','number',1,'Quantity.'],
          ['invoice_line[].line_extension_amount','number',1,'quantity x unit price.'],
          ['invoice_line[].item.name','string',1,'Item name.'],
          ['invoice_line[].item.description','string',1,'Item description.'],
          ['invoice_line[].price.price_amount','number',1,'Unit price in Naira.'],
          ['invoice_line[].price.base_quantity','number',1,'Base quantity (usually 1).'],
          ['invoice_line[].price.price_unit','string',1,'Price unit label (e.g. NGN per 1).'],
          ['tax_total[].tax_amount','number',1,'Total tax amount.'],
          ['tax_total[].tax_subtotal[].taxable_amount','number',1,'Taxable base amount.'],
          ['tax_total[].tax_subtotal[].tax_amount','number',1,'Tax on this subtotal.'],
          ['tax_total[].tax_subtotal[].tax_category.id','string',1,'FIRS tax category: STANDARD_VAT, ZERO_RATED, EXEMPTED.'],
          ['tax_total[].tax_subtotal[].tax_category.percent','number',1,'Tax rate (e.g. 7.5).'],
          ['reference','string',0,'Your idempotency key - optional (else derived from the payload).'],
          ['due_date','string',0,'Payment due date (YYYY-MM-DD) - optional.'],
          ['issue_time','string',0,'Issue time (HH:MM:SS) - optional.'],
          ['payment_status','string',0,'PENDING | PAID | PARTIAL - optional, defaults to PENDING.'],
          ['allowance_charge[]','array',0,'Document-level discount/charge (charge_indicator, allowance_charge_reason, amount, base_amount) - optional.'],
          ['tax_point_date','string',0,'Tax point date (YYYY-MM-DD) - optional.'],
          ['note','string',0,'Free-text note - optional.'],
        ];
        foreach ($fields as [$p,$t,$req,$d]) {
          echo '<tr><td class="p">'.htmlspecialchars($p).($req?' <span class="rq">*</span>':'').'</td>'
             . '<td class="t">'.$t.'</td><td>'.htmlspecialchars($d).'</td></tr>';
        }
        ?>
        </tbody>
      </table>

      <div class="lbl">Example Request</div>
<pre><?php echo hlbash('curl -X POST "'.$base.'/api/v1/invoices" \\
  -H "x-client-key: ak_••••••••••••••••" \\
  -H "x-client-secret: sk_••••••••••••••••••••••••••••••••" \\
  -H "Content-Type: application/json" \\
  -d \'{…}\''); ?></pre>

      <div class="lbl">Request Body</div>
<pre><?php echo hljson('{
  "reference": "ACME-2001",
  "issue_date": "2026-07-06",
  "due_date": "2026-08-05",
  "issue_time": "09:00:00",
  "invoice_type_code": "381",
  "payment_status": "PENDING",
  "document_currency_code": "NGN",
  "tax_currency_code": "NGN",
  "accounting_supplier_party": {
    "party_name": "Virdi Nigeria Limited",
    "tin": "22047671-0001",
    "email": "info@virdi.com.ng",
    "telephone": "+2348012345678",
    "business_description": "General trade",
    "postal_address": { "street_name": "12 Marina Road", "city_name": "Lagos", "postal_zone": "100001", "country": "NG" }
  },
  "accounting_customer_party": {
    "party_name": "Acme Trading Limited",
    "tin": "12345678-0001",
    "email": "accounts@acme.ng",
    "telephone": "+2348098765432",
    "postal_address": { "street_name": "5 Broad Street", "city_name": "Lagos", "postal_zone": "100001", "country": "NG" }
  },
  "legal_monetary_total": {
    "line_extension_amount": 100000,
    "tax_exclusive_amount": 100000,
    "tax_inclusive_amount": 107500,
    "payable_amount": 107500
  },
  "invoice_line": [
    {
      "hsn_code": "8517.12",
      "product_category": "Electronics",
      "invoiced_quantity": 1,
      "line_extension_amount": 100000,
      "item": { "name": "Smartphone", "description": "Android smartphone" },
      "price": { "price_amount": 100000, "base_quantity": 1, "price_unit": "NGN per 1" }
    }
  ],
  "tax_total": [
    {
      "tax_amount": 7500,
      "tax_subtotal": [
        { "taxable_amount": 100000, "tax_amount": 7500, "tax_category": { "id": "STANDARD_VAT", "percent": 7.5 } }
      ]
    }
  ]
}'); ?></pre>

      <div class="lbl">Response</div>
<pre><?php echo hljson('{
  "reference": "ACME-2001",
  "invoice_id": 42,
  "irn": "ACME2001-4BB2353A-20260706",
  "firs_status": "transmitted",
  "qr_present": true,
  "status_url": "/api/v1/invoices/ACME-2001/status"
}'); ?></pre>
      <p style="color:var(--faint);font-size:.85rem;">Returns <span class="mono">201</span> when transmitted,
        <span class="mono">202</span> when accepted &amp; queued for retry. Errors: <span class="mono">401</span>
        bad credentials, <span class="mono">422</span> validation.</p>
    </div>
  </div>

  <div class="ep">
    <div class="ep-h"><span class="m get">GET</span><span class="path">/api/v1/invoices/:reference/status</span></div>
    <div class="ep-b">
      <p class="desc">Check the live FIRS status for a previously submitted invoice: pipeline status, retry
        attempts, transmission time and any last error.</p>
      <div class="lbl">Example Request</div>
<pre><?php echo hlbash('curl -X GET "'.$base.'/api/v1/invoices/ACME-2001/status" \\
  -H "x-client-key: ak_••••••••••••••••" \\
  -H "x-client-secret: sk_••••••••••••••••••••••••••••••••"'); ?></pre>
      <div class="lbl">Response</div>
<pre><?php echo hljson('{
  "reference": "ACME-2001",
  "invoice_id": 42,
  "irn": "ACME2001-4BB2353A-20260706",
  "firs_status": "transmitted",
  "attempts": 1,
  "transmitted_at": "2026-07-06 09:31:00",
  "next_retry_at": null,
  "last_error": null
}'); ?></pre>
    </div>
  </div>

  <div class="ep">
    <div class="ep-h"><span class="m get">GET</span><span class="path">/api/v1/health</span></div>
    <div class="ep-b">
      <p class="desc">Liveness probe. No authentication required.</p>
      <div class="lbl">Example Request</div>
<pre><?php echo hlbash('curl -X GET "'.$base.'/api/v1/health"'); ?></pre>
      <div class="lbl">Response</div>
<pre><?php echo hljson('{ "healthy": true, "service": "einvoice-middleware", "time": "2026-07-06T09:30:00+01:00" }'); ?></pre>
    </div>
  </div>

  <h2 class="sec">Payments &amp; Reporting</h2>

  <div class="ep">
    <div class="ep-h"><span class="m patch">PATCH</span><span class="path">/api/v1/invoices/:reference/payment-status</span></div>
    <div class="ep-b">
      <p class="desc">Update the invoice payment status. Call this when the buyer pays.</p>
      <div class="lbl">Request Body Fields (<span class="rq">*</span> = required)</div>
      <table>
        <thead><tr><th style="width:46%">Parameter</th><th style="width:14%">Type</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td class="p">payment_status <span class="rq">*</span></td><td class="t">string</td><td>PAID | PARTIAL | PENDING</td></tr>
          <tr><td class="p">reference</td><td class="t">string</td><td>Payment transaction reference - optional.</td></tr>
        </tbody>
      </table>
      <div class="lbl">Example Request</div>
<pre><?php echo hlbash('curl -X PATCH "'.$base.'/api/v1/invoices/ACME-2001/payment-status" \\
  -H "x-client-key: ak_••••••••••••••••" \\
  -H "x-client-secret: sk_••••••••••••••••••••••••••••••••" \\
  -H "Content-Type: application/json" \\
  -d \'{…}\''); ?></pre>
      <div class="lbl">Request Body</div>
<pre><?php echo hljson('{
  "payment_status": "PAID",
  "reference": "TRX-9988"
}'); ?></pre>
      <div class="lbl">Response</div>
<pre><?php echo hljson('{
  "ok": true,
  "reference": "ACME-2001",
  "irn": "ACME2001-4BB2353A-20260706",
  "payment_status": "PAID",
  "transaction_reference": "TRX-9988"
}'); ?></pre>
    </div>
  </div>

  <div class="ep">
    <div class="ep-h"><span class="m post">POST</span><span class="path">/api/v1/invoices/:reference/report</span></div>
    <div class="ep-b">
      <p class="desc">Report the invoice VAT basis to FIRS/NRS (post-payment reporting).</p>
      <div class="lbl">Request Body Fields (<span class="rq">*</span> = required)</div>
      <table>
        <thead><tr><th style="width:46%">Parameter</th><th style="width:14%">Type</th><th>Description</th></tr></thead>
        <tbody>
          <tr><td class="p">vat_status</td><td class="t">string</td><td>STANDARD_VAT | ZERO_RATED | EXEMPT (default: STANDARD_VAT).</td></tr>
          <tr><td class="p">vat_rate</td><td class="t">string</td><td>VAT rate as string (default: '7.5').</td></tr>
          <tr><td class="p">other_taxes</td><td class="t">string</td><td>Other applicable taxes (default: '0').</td></tr>
        </tbody>
      </table>
      <div class="lbl">Example Request</div>
<pre><?php echo hlbash('curl -X POST "'.$base.'/api/v1/invoices/ACME-2001/report" \\
  -H "x-client-key: ak_••••••••••••••••" \\
  -H "x-client-secret: sk_••••••••••••••••••••••••••••••••" \\
  -H "Content-Type: application/json" \\
  -d \'{…}\''); ?></pre>
      <div class="lbl">Request Body</div>
<pre><?php echo hljson('{
  "vat_status": "STANDARD_VAT",
  "vat_rate": "7.5",
  "other_taxes": "0"
}'); ?></pre>
      <div class="lbl">Response</div>
<pre><?php echo hljson('{
  "ok": true,
  "reference": "ACME-2001",
  "irn": "ACME2001-4BB2353A-20260706",
  "vat_status": "STANDARD_VAT",
  "vat_rate": "7.5",
  "other_taxes": "0"
}'); ?></pre>
    </div>
  </div>

  <h2 class="sec">Webhooks</h2>

  <div class="ep">
    <div class="ep-h"><span class="m post">POST</span><span class="path">/api/v1/webhook/firs</span></div>
    <div class="ep-b">
      <p class="desc">Inbound endpoint that receives FIRS status push events. Register this URL with FIRS.
        Authenticated by a shared secret - an <span class="mono">x-firs-signature</span> HMAC header or
        <span class="mono">?token=</span>. Every event is logged; the authoritative status is then re-pulled
        from the FIRS confirm endpoint, so a forged push cannot change state on its own.</p>
      <div class="lbl">Response</div>
<pre><?php echo hljson('{ "received": true, "irn": "ACME2001-4BB2353A-20260706", "matched_invoice": 42 }'); ?></pre>
    </div>
  </div>

  <div class="ep">
    <div class="ep-h"><span class="m post">POST</span><span class="path">Outbound &rarr; your webhook_url</span></div>
    <div class="ep-b">
      <p class="desc">On every status change the middleware POSTs to your configured <span class="mono">webhook_url</span>.
        The body is signed with HMAC-SHA256 so you can verify it came from us - recompute
        <span class="mono">HMAC-SHA256(rawBody, webhook_secret)</span> and compare to the
        <span class="mono">x-webhook-signature</span> header.</p>
      <div class="lbl">Headers &amp; Body</div>
<pre><?php echo htmlspecialchars('x-webhook-event: invoice.delivered
x-webhook-signature: sha256=<hmac>'); ?>

<?php echo hljson('{
  "event": "invoice.transmitted",
  "reference": "ACME-2001",
  "irn": "ACME2001-4BB2353A-20260706",
  "firs_status": "transmitted",
  "transmitted": true,
  "delivered": false,
  "entry_status": "NEW_ENTRY"
}'); ?></pre>
    </div>
  </div>

  <h2 class="sec">FIRS / NRS (MBS) Portal Endpoints</h2>
  <div class="card">
    <p style="color:var(--muted);margin-top:0;">Each submitted invoice flows through these government portal
      endpoints. FIRS issues two credential sets: the <b>APP</b> key and the <b>SI</b> key.</p>
    <table>
      <thead><tr><th>Stage</th><th style="width:14%">Key</th><th>Endpoint</th></tr></thead>
      <tbody>
        <tr><td>Health</td><td class="t">-</td><td class="p">GET /api</td></tr>
        <tr><td>Entity lookup</td><td class="t">SI</td><td class="p">GET /api/v1/entity/{id}</td></tr>
        <tr><td>Reference data</td><td class="t">SI</td><td class="p">GET /api/v1/invoice/resources/{name}</td></tr>
        <tr><td>Validate</td><td class="t">APP</td><td class="p">POST /api/v1/invoice/validate</td></tr>
        <tr><td>Sign</td><td class="t">APP</td><td class="p">POST /api/v1/invoice/sign</td></tr>
        <tr><td>Transmit</td><td class="t">SI</td><td class="p">POST /api/v1/invoice/transmit/{IRN}</td></tr>
        <tr><td>Confirm status</td><td class="t">APP</td><td class="p">GET /api/v1/invoice/confirm/{IRN}</td></tr>
      </tbody>
    </table>
    <p style="color:var(--faint);font-size:.85rem;margin-bottom:0;">The QR payload is
      <span class="mono">{ irn: "&lt;IRN&gt;.&lt;unixTimestamp&gt;", certificate }</span>, RSA/PKCS#1 v1.5
      encrypted with the FIRS public key and base64-encoded, per the FIRS QR-code spec.</p>
  </div>

</div></div>
<?php
$content = ob_get_clean();

if ($loggedIn) {
    // Rendered inside the normal operator layout (sidebar), like any other page.
    $page_title = 'API Documentation';
    include 'includes/header.php';
    echo "<style>\n$CSS\n</style>";
    echo $content;
    include 'includes/footer.php';
} else {
    // Standalone public docs page with its own top bar.
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API Reference - Virdi E-Invoice</title>
<style>
  body{margin:0;background:#f6f8fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1b2230;}
  .apitop{position:sticky;top:0;z-index:10;background:#fff;border-bottom:1px solid #e6e9ef;}
  .apitop .in{max-width:960px;margin:0 auto;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;}
  .apitop .brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:1.05rem;}
  .apitop .logo{width:30px;height:30px;border-radius:8px;background:#0d7a3f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;}
  .apitop .pill{font-size:.68rem;font-weight:600;color:#69707e;background:#eef1f6;border-radius:20px;padding:2px 9px;}
  .apitop .btn{border:1px solid #e6e9ef;border-radius:8px;padding:7px 15px;font-weight:600;font-size:.85rem;color:#1b2230;background:#fff;text-decoration:none;}
  .apitop .btn:hover{background:#f6f8fb;}
  .apiref .wrap{padding:38px 24px 80px;}
<?php echo $CSS; ?>
</style>
</head>
<body>
<div class="apitop"><div class="in">
  <div class="brand"><span class="logo">V</span> Virdi E-Invoice <span class="pill">API v1</span></div>
  <a class="btn" href="login.php">Sign in &rarr;</a>
</div></div>
<?php echo $content; ?>
</body>
</html>
<?php
}
