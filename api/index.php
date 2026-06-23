<?php
/**
 * Customer-facing middleware API.
 *
 * This is the layer that lets an external customer system hand us an invoice and
 * later ask for its FIRS status, without ever talking to the portal directly —
 * the app sits in the middle, validates, signs, transmits, logs and retries.
 *
 * Routes (all under /api/):
 *     GET  /api/v1/health                       — liveness probe (no auth)
 *     POST /api/v1/invoices                     — accept an invoice, submit to FIRS
 *     GET  /api/v1/invoices/{reference}/status  — get FIRS status for an invoice
 *
 * Auth (the two write/read routes): headers x-client-key + x-client-secret,
 * verified against the api_clients table (secret stored only as a bcrypt hash).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ApiAuth.php';
require_once __DIR__ . '/../includes/FirsService.php';

header('Content-Type: application/json');

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Handle a POST /api/v1/invoices where the body IS a full FIRS BIS 3.0 payload
 * (the same shape the middleware transmits to NRS). We persist tracking rows and
 * store the payload verbatim, then run the normal validated/logged/retried
 * pipeline — which injects only the server-generated irn / business_id / QR and
 * transmits the stored object as-is. Always exits via respond().
 */
function respond_firs_invoice(PDO $conn, array $client, array $body): void
{
    // Idempotency key: an explicit `reference`, else derived deterministically
    // from the payload so identical re-posts collapse to the same invoice.
    $reference = trim((string) ($body['reference'] ?? ''));
    if ($reference === '') {
        $reference = 'firs_' . substr(hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES)), 0, 24);
    }

    $stmt = $conn->prepare("SELECT * FROM api_inbound_invoices WHERE api_client_id = :c AND external_reference = :r");
    $stmt->execute([':c' => $client['id'], ':r' => $reference]);
    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        respond(200, [
            'reference'   => $reference,
            'invoice_id'  => (int) $existing['invoice_id'],
            'irn'         => $existing['irn'],
            'firs_status' => $existing['status'],
            'message'     => 'Already received (idempotent replay)',
        ]);
    }

    $lines = $body['invoice_line'] ?? [];
    if (!is_array($lines) || count($lines) === 0) {
        respond(422, ['error' => 'validation_error', 'message' => 'invoice_line[] is required (at least one line)']);
    }
    $buyer = $body['accounting_customer_party'] ?? [];
    if (empty($buyer['party_name'])) {
        respond(422, ['error' => 'validation_error', 'message' => 'accounting_customer_party.party_name is required']);
    }

    // The exact object we will transmit (drop only our non-FIRS wrapper field).
    $firsPayload = $body;
    unset($firsPayload['reference']);

    try {
        $conn->beginTransaction();
        $companyId = (int) $client['company_id'];

        // Mirror the buyer into customers for listing/status (does not affect the
        // transmitted payload, which keeps the party exactly as supplied).
        $addr = $buyer['postal_address'] ?? [];
        $cstmt = $conn->prepare("SELECT id FROM customers WHERE company_id = :co AND (tax_id = :tin AND :tin <> '' OR name = :name) LIMIT 1");
        $cstmt->execute([':co' => $companyId, ':tin' => (string) ($buyer['tin'] ?? ''), ':name' => $buyer['party_name']]);
        $customerId = $cstmt->fetchColumn();
        if (!$customerId) {
            $ins = $conn->prepare(
                "INSERT INTO customers (company_id, name, tax_id, email, phone, address, billing_address, billing_city, billing_country, billing_postal_code)
                 VALUES (:co, :name, :tin, :email, :phone, :addr, :addr, :city, :country, :zip)"
            );
            $ins->execute([
                ':co' => $companyId, ':name' => $buyer['party_name'], ':tin' => $buyer['tin'] ?? '',
                ':email' => $buyer['email'] ?? '', ':phone' => $buyer['telephone'] ?? '',
                ':addr' => $addr['street_name'] ?? '', ':city' => $addr['city_name'] ?? '',
                ':country' => $addr['country'] ?? 'NG', ':zip' => $addr['postal_zone'] ?? '',
            ]);
            $customerId = (int) $conn->lastInsertId();
        }

        // Totals from the FIRS monetary block (fall back to summing the lines).
        $lmt = $body['legal_monetary_total'] ?? [];
        $subtotal = (float) ($lmt['line_extension_amount'] ?? 0);
        if ($subtotal <= 0) {
            foreach ($lines as $ln) {
                $subtotal += (float) ($ln['line_extension_amount'] ?? 0);
            }
        }
        $taxAmount = 0.0;
        foreach (($body['tax_total'] ?? []) as $tt) {
            $taxAmount += (float) ($tt['tax_amount'] ?? 0);
        }
        $total    = (float) ($lmt['payable_amount'] ?? ($subtotal + $taxAmount));
        $discount = (float) ($lmt['allowance_total_amount'] ?? 0);
        $taxRate  = (float) ($body['tax_total'][0]['tax_subtotal'][0]['tax_category']['percent'] ?? 0);

        $invoiceNumber = (string) ($body['invoice_number'] ?? $reference);
        $date    = !empty($body['issue_date']) ? date('Y-m-d', strtotime($body['issue_date'])) : date('Y-m-d');
        $time    = substr((string) ($body['issue_time'] ?? date('H:i:s')), 0, 8);
        $dueDate = !empty($body['due_date']) ? date('Y-m-d', strtotime($body['due_date'])) : date('Y-m-d', strtotime('+30 days'));
        $uid = (int) $conn->query("SELECT id FROM users WHERE company_id = {$companyId} ORDER BY id LIMIT 1")->fetchColumn();

        // Full FIRS monetary breakdown — prefer the values supplied in the
        // payload's legal_monetary_total, else derive them.
        $taxExcl = (float) ($lmt['tax_exclusive_amount'] ?? round($subtotal - $discount, 2));
        $taxIncl = (float) ($lmt['tax_inclusive_amount'] ?? round($taxExcl + $taxAmount, 2));
        $charge  = (float) ($lmt['charge_total_amount'] ?? 0);
        $taxCatId = $body['tax_total'][0]['tax_subtotal'][0]['tax_category']['id'] ?? 'STANDARD_VAT';
        $ccy = $body['document_currency_code'] ?? 'NGN';
        $allowReason = null;
        foreach (($body['allowance_charge'] ?? []) as $ac) {
            if (($ac['charge_indicator'] ?? true) === false) { $allowReason = $ac['allowance_charge_reason'] ?? 'Discount'; break; }
        }
        if ($allowReason === null && $discount > 0) { $allowReason = 'Discount'; }

        $istmt = $conn->prepare(
            "INSERT INTO invoices
                (invoice_number, date, time, customer_id, company_id, user_id, due_date,
                 subtotal, line_extension_amount, tax_rate, tax_category_id, tax_amount,
                 discount_amount, allowance_total_amount, allowance_charge_reason, charge_total_amount,
                 tax_exclusive_amount, tax_inclusive_amount, total_amount, payable_amount,
                 invoice_type_code, payment_status, document_currency_code, tax_currency_code, status, firs_payload)
             VALUES
                (:num, :date, :time, :cust, :co, :uid, :due,
                 :sub, :lea, :rate, :tcid, :tax,
                 :disc, :allow, :areason, :charge,
                 :texcl, :tincl, :total, :payable,
                 :itc, :pstat, :ccy, :tccy, 'sent', :fp)"
        );
        $istmt->execute([
            ':num' => $invoiceNumber, ':date' => $date, ':time' => $time, ':cust' => $customerId,
            ':co' => $companyId, ':uid' => $uid, ':due' => $dueDate,
            ':sub' => $subtotal, ':lea' => $subtotal, ':rate' => $taxRate, ':tcid' => $taxCatId, ':tax' => $taxAmount,
            ':disc' => $discount, ':allow' => $discount, ':areason' => $allowReason, ':charge' => $charge,
            ':texcl' => $taxExcl, ':tincl' => $taxIncl, ':total' => $total, ':payable' => $total,
            ':itc' => $body['invoice_type_code'] ?? '381', ':pstat' => $body['payment_status'] ?? 'PENDING',
            ':ccy' => $ccy, ':tccy' => $body['tax_currency_code'] ?? $ccy,
            ':fp' => json_encode($firsPayload, JSON_UNESCAPED_SLASHES),
        ]);
        $invoiceId = (int) $conn->lastInsertId();

        foreach ($lines as $ln) {
            $item  = $ln['item'] ?? [];
            $price = $ln['price'] ?? [];
            $qty   = (float) ($ln['invoiced_quantity'] ?? 1);
            $rate  = (float) ($price['price_amount'] ?? 0);
            $itemIns = $conn->prepare(
                "INSERT INTO items (company_id, name, hsn_code, description, category, currency, tax_rate, selling_price)
                 VALUES (:co, :name, :hsn, :desc, :cat, :ccy, :rate, :price)"
            );
            $itemIns->execute([
                ':co' => $companyId, ':name' => $item['name'] ?? 'Item', ':hsn' => $ln['hsn_code'] ?? null,
                ':desc' => $item['description'] ?? ($item['name'] ?? 'Item'), ':cat' => $ln['product_category'] ?? 'General',
                ':ccy' => $body['document_currency_code'] ?? 'NGN', ':rate' => $taxRate, ':price' => $rate,
            ]);
            $itemId = (int) $conn->lastInsertId();
            $liIns = $conn->prepare(
                "INSERT INTO invoice_items (invoice_id, quantity, rate, amount, item_id)
                 VALUES (:inv, :qty, :rate, :amount, :item)"
            );
            $liIns->execute([
                ':inv' => $invoiceId, ':qty' => $qty, ':rate' => $rate,
                ':amount' => (float) ($ln['line_extension_amount'] ?? round($qty * $rate, 2)), ':item' => $itemId,
            ]);
        }

        $inbound = $conn->prepare(
            "INSERT INTO api_inbound_invoices (api_client_id, external_reference, invoice_id, status, payload)
             VALUES (:c, :r, :inv, 'received', :p)"
        );
        $inbound->execute([':c' => $client['id'], ':r' => $reference, ':inv' => $invoiceId, ':p' => json_encode($body, JSON_UNESCAPED_SLASHES)]);
        $inboundId = (int) $conn->lastInsertId();

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        respond(500, ['error' => 'persist_failed', 'message' => $e->getMessage()]);
    }

    $result = (new FirsService($conn))->submit($invoiceId);
    $conn->prepare("UPDATE api_inbound_invoices SET irn = :irn, status = :st WHERE id = :id")
         ->execute([':irn' => $result['irn'], ':st' => $result['status'], ':id' => $inboundId]);

    $httpCode = $result['ok'] ? 201 : ($result['status'] === 'queued_retry' ? 202 : 422);
    respond($httpCode, [
        'reference'   => $reference,
        'invoice_id'  => $invoiceId,
        'irn'         => $result['irn'],
        'firs_status' => $result['status'],
        'qr_present'  => $result['qr'] !== null,
        'message'     => $result['message'],
        'status_url'  => '/api/v1/invoices/' . rawurlencode($reference) . '/status',
    ]);
}

$route  = trim($_GET['_route'] ?? '', '/');
$method = $_SERVER['REQUEST_METHOD'];

// ── Public liveness probe ────────────────────────────────────────────────────
if ($route === 'v1/health' && $method === 'GET') {
    respond(200, ['healthy' => true, 'service' => 'einvoice-middleware', 'time' => date('c')]);
}

$conn = (new Database())->getConnection();

// ── POST /api/v1/webhook/firs — inbound status callbacks FROM the FIRS portal ─
// FIRS does not hold our customer credentials, so this route is authenticated by
// a shared secret instead: an optional HMAC signature (x-firs-signature) and/or
// a ?token= matching FIRS_WEBHOOK_SECRET. Everything is logged raw for audit; the
// authoritative status is then re-pulled via /confirm so we never trust the push
// blindly.
if ($route === 'v1/webhook/firs' && $method === 'POST') {
    require_once __DIR__ . '/../config/env.php';
    require_once __DIR__ . '/../includes/FirsService.php';

    $raw     = file_get_contents('php://input');
    $secret  = (string) env('FIRS_WEBHOOK_SECRET', '');
    $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
    $sigHdr  = $headers['x-firs-signature'] ?? '';
    $token   = (string) ($_GET['token'] ?? '');

    $sigValid = false;
    if ($secret !== '') {
        $expected = hash_hmac('sha256', $raw, $secret);
        $sigValid = ($sigHdr !== '' && hash_equals($expected, preg_replace('/^sha256=/', '', $sigHdr)))
                 || ($token !== '' && hash_equals($secret, $token));
        if (!$sigValid) {
            respond(401, ['error' => 'invalid_signature']);
        }
    }

    $body  = json_decode($raw, true) ?: [];
    // Tolerant extraction — FIRS field naming may vary by event.
    $irn   = $body['irn'] ?? $body['IRN'] ?? ($body['data']['irn'] ?? null);
    $event = $body['event'] ?? $body['event_type'] ?? ($body['type'] ?? 'firs.event');

    $invoiceId = null;
    if ($irn) {
        $s = $conn->prepare("SELECT id FROM invoices WHERE irn = :irn LIMIT 1");
        $s->execute([':irn' => $irn]);
        $invoiceId = $s->fetchColumn() ?: null;
    }

    $conn->prepare(
        "INSERT INTO firs_webhook_events (irn, event_type, invoice_id, signature_valid, remote_ip, payload, processed)
         VALUES (:irn, :ev, :inv, :sv, :ip, :p, :pr)"
    )->execute([
        ':irn' => $irn, ':ev' => $event, ':inv' => $invoiceId, ':sv' => $sigValid ? 1 : 0,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':p' => substr($raw, 0, 60000),
        ':pr' => $invoiceId ? 1 : 0,
    ]);

    // Re-pull authoritative status (this also fires the outbound customer webhook
    // on any delivered/failed transition).
    if ($invoiceId) {
        (new FirsService($conn))->confirmStatus((int) $invoiceId);
    }
    respond(200, ['received' => true, 'irn' => $irn, 'matched_invoice' => (int) $invoiceId]);
}

// ── Authenticate everything else ─────────────────────────────────────────────
$client = (new ApiAuth($conn))->authenticate();
if (!$client) {
    respond(401, ['error' => 'unauthorized', 'message' => 'Provide valid x-client-key and x-client-secret headers']);
}

// ── POST /api/v1/invoices — accept an invoice and submit it to FIRS ──────────
if ($route === 'v1/invoices' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(400, ['error' => 'invalid_json']);
    }

    // ── FIRS BIS 3.0 payload (identical to what we transmit to NRS) ───────────
    // Detected by the presence of FIRS-native structures. The customer sends the
    // full invoice object; we record tracking rows, store the payload verbatim,
    // and run the same pipeline — injecting only the server-generated
    // irn / business_id / QR. So the object we send NRS == the object we received.
    if (isset($body['invoice_line']) || isset($body['accounting_customer_party'])) {
        respond_firs_invoice($conn, $client, $body);
    }

    $reference = (string) ($body['reference'] ?? '');
    if ($reference === '') {
        respond(422, ['error' => 'validation_error', 'message' => 'reference is required (your unique invoice id)']);
    }

    // Idempotency — never create the same external invoice twice.
    $stmt = $conn->prepare("SELECT * FROM api_inbound_invoices WHERE api_client_id = :c AND external_reference = :r");
    $stmt->execute([':c' => $client['id'], ':r' => $reference]);
    if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
        respond(200, [
            'reference'   => $reference,
            'invoice_id'  => (int) $existing['invoice_id'],
            'irn'         => $existing['irn'],
            'firs_status' => $existing['status'],
            'message'     => 'Already received (idempotent replay)',
        ]);
    }

    $customer = $body['customer'] ?? [];
    $items    = $body['items'] ?? [];
    if (empty($customer['name']) || !is_array($items) || count($items) === 0) {
        respond(422, ['error' => 'validation_error', 'message' => 'customer.name and at least one item are required']);
    }

    try {
        $conn->beginTransaction();

        // Upsert customer by TIN (or name) within the client's company.
        $companyId = (int) $client['company_id'];
        $cstmt = $conn->prepare("SELECT id FROM customers WHERE company_id = :co AND (tax_id = :tin AND :tin <> '' OR name = :name) LIMIT 1");
        $cstmt->execute([':co' => $companyId, ':tin' => (string) ($customer['tin'] ?? ''), ':name' => $customer['name']]);
        $customerId = $cstmt->fetchColumn();
        if (!$customerId) {
            $ins = $conn->prepare(
                "INSERT INTO customers (company_id, name, tax_id, email, phone, address, billing_address, billing_city, billing_country, billing_postal_code)
                 VALUES (:co, :name, :tin, :email, :phone, :addr, :addr, :city, :country, :zip)"
            );
            $ins->execute([
                ':co' => $companyId, ':name' => $customer['name'], ':tin' => $customer['tin'] ?? '',
                ':email' => $customer['email'] ?? '', ':phone' => $customer['phone'] ?? '',
                ':addr' => $customer['address'] ?? '', ':city' => $customer['city'] ?? '',
                ':country' => $customer['country'] ?? 'Nigeria', ':zip' => $customer['postal_zone'] ?? '',
            ]);
            $customerId = (int) $conn->lastInsertId();
        }

        // Build invoice totals from the line items.
        $taxRate  = (float) ($body['tax_rate'] ?? 7.5);
        $subtotal = 0.0;
        foreach ($items as $it) {
            $subtotal += (float) ($it['quantity'] ?? 1) * (float) ($it['rate'] ?? 0);
        }
        $taxAmount = round($subtotal * $taxRate / 100, 2);
        $total     = round($subtotal + $taxAmount, 2);

        $invoiceNumber = (string) ($body['invoice']['number'] ?? ('INV' . date('YmdHis')));
        $date    = $body['invoice']['date']     ?? date('Y-m-d');
        $dueDate = $body['invoice']['due_date'] ?? date('Y-m-d', strtotime('+30 days'));

        $istmt = $conn->prepare(
            "INSERT INTO invoices
                (invoice_number, date, time, customer_id, company_id, user_id, due_date,
                 subtotal, line_extension_amount, tax_rate, tax_category_id, tax_amount,
                 discount_amount, allowance_total_amount, charge_total_amount,
                 tax_exclusive_amount, tax_inclusive_amount, total_amount, payable_amount,
                 document_currency_code, tax_currency_code, status)
             VALUES
                (:num, :date, :time, :cust, :co, :uid, :due,
                 :sub, :lea, :rate, :tcid, :tax,
                 0, 0, 0,
                 :texcl, :tincl, :total, :payable,
                 'NGN', 'NGN', 'sent')"
        );
        // user_id: first user of the company (API submissions are system-originated).
        $uid = (int) $conn->query("SELECT id FROM users WHERE company_id = {$companyId} ORDER BY id LIMIT 1")->fetchColumn();
        $istmt->execute([
            ':num' => $invoiceNumber, ':date' => $date, ':time' => date('H:i:s'), ':cust' => $customerId,
            ':co' => $companyId, ':uid' => $uid, ':due' => $dueDate, ':sub' => $subtotal,
            ':lea' => $subtotal, ':rate' => $taxRate, ':tcid' => 'STANDARD_VAT', ':tax' => $taxAmount,
            ':texcl' => $subtotal, ':tincl' => $total, ':total' => $total, ':payable' => $total,
        ]);
        $invoiceId = (int) $conn->lastInsertId();

        // Create item + invoice_item rows.
        foreach ($items as $it) {
            $qty  = (float) ($it['quantity'] ?? 1);
            $rate = (float) ($it['rate'] ?? 0);
            $itemIns = $conn->prepare(
                "INSERT INTO items (item_code, company_id, name, hsn_code, description, category, currency, tax_rate, selling_price)
                 VALUES (:code, :co, :name, :hsn, :desc, :cat, 'NGN', :rate, :price)"
            );
            $itemIns->execute([
                ':code' => $it['item_code'] ?? null, ':co' => $companyId, ':name' => $it['name'] ?? 'Item',
                ':hsn' => $it['hsn_code'] ?? 'CC-001', ':desc' => $it['description'] ?? ($it['name'] ?? 'Item'),
                ':cat' => $it['category'] ?? 'General', ':rate' => $taxRate, ':price' => $rate,
            ]);
            $itemId = (int) $conn->lastInsertId();
            $liIns = $conn->prepare(
                "INSERT INTO invoice_items (invoice_id, item_code, quantity, rate, amount, item_id)
                 VALUES (:inv, :code, :qty, :rate, :amount, :item)"
            );
            $liIns->execute([
                ':inv' => $invoiceId, ':code' => $it['item_code'] ?? null, ':qty' => $qty,
                ':rate' => $rate, ':amount' => round($qty * $rate, 2), ':item' => $itemId,
            ]);
        }

        $inbound = $conn->prepare(
            "INSERT INTO api_inbound_invoices (api_client_id, external_reference, invoice_id, status, payload)
             VALUES (:c, :r, :inv, 'received', :p)"
        );
        $inbound->execute([':c' => $client['id'], ':r' => $reference, ':inv' => $invoiceId, ':p' => json_encode($body, JSON_UNESCAPED_SLASHES)]);
        $inboundId = (int) $conn->lastInsertId();

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        respond(500, ['error' => 'persist_failed', 'message' => $e->getMessage()]);
    }

    // Submit through the same validated/logged/retried pipeline as the UI.
    $result = (new FirsService($conn))->submit($invoiceId);

    $conn->prepare("UPDATE api_inbound_invoices SET irn = :irn, status = :st WHERE id = :id")
         ->execute([':irn' => $result['irn'], ':st' => $result['status'], ':id' => $inboundId]);

    $httpCode = $result['ok'] ? 201 : ($result['status'] === 'queued_retry' ? 202 : 422);
    respond($httpCode, [
        'reference'   => $reference,
        'invoice_id'  => $invoiceId,
        'irn'         => $result['irn'],
        'firs_status' => $result['status'],
        'qr_present'  => $result['qr'] !== null,
        'message'     => $result['message'],
        'status_url'  => '/api/v1/invoices/' . rawurlencode($reference) . '/status',
    ]);
}

// ── GET /api/v1/invoices/{reference}/status ──────────────────────────────────
if ($method === 'GET' && preg_match('#^v1/invoices/(.+)/status$#', $route, $m)) {
    $reference = urldecode($m[1]);
    $stmt = $conn->prepare(
        "SELECT a.external_reference, a.status AS inbound_status, a.irn,
                i.id AS invoice_id, i.firs_status, i.transmit_attempts, i.transmitted_at, i.next_retry_at, i.last_error
         FROM api_inbound_invoices a
         LEFT JOIN invoices i ON i.id = a.invoice_id
         WHERE a.api_client_id = :c AND a.external_reference = :r"
    );
    $stmt->execute([':c' => $client['id'], ':r' => $reference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        respond(404, ['error' => 'not_found', 'reference' => $reference]);
    }
    respond(200, [
        'reference'       => $row['external_reference'],
        'invoice_id'      => (int) $row['invoice_id'],
        'irn'             => $row['irn'],
        'firs_status'     => $row['firs_status'] ?? $row['inbound_status'],
        'attempts'        => (int) $row['transmit_attempts'],
        'transmitted_at'  => $row['transmitted_at'],
        'next_retry_at'   => $row['next_retry_at'],
        'last_error'      => $row['last_error'],
    ]);
}

respond(404, ['error' => 'route_not_found', 'route' => $route, 'method' => $method]);
