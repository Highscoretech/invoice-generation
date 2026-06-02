<?php
/**
 * FirsService — orchestrates the full submit pipeline for one invoice:
 *
 *     build payload → validate → sign → generate QR → transmit
 *
 * Every portal call is written to firs_transmissions (request + response) and
 * the invoice's firs_status is advanced as it moves through the stages. When a
 * transmit fails for a *transient* reason (network error, 5xx, access point
 * offline) the invoice is queued for retry with exponential backoff instead of
 * being marked permanently failed.
 *
 * This same service backs the UI button (send_to_api.php), the retry runner
 * (retry_transmissions.php) and the customer-facing API, so behaviour is
 * identical no matter how an invoice is submitted.
 */

require_once __DIR__ . '/FirsClient.php';
require_once __DIR__ . '/InvoicePayload.php';
require_once __DIR__ . '/WebhookDispatcher.php';

class FirsService
{
    private PDO $conn;
    private FirsClient $client;
    private WebhookDispatcher $webhooks;

    /** Backoff schedule (minutes) per attempt; last value repeats. */
    private const BACKOFF_MINUTES = [2, 5, 15, 60, 180];
    private const MAX_ATTEMPTS = 6;

    public function __construct(PDO $conn, ?FirsClient $client = null)
    {
        $this->conn     = $conn;
        $this->client   = $client ?: new FirsClient();
        $this->webhooks = new WebhookDispatcher($conn);
    }

    public function client(): FirsClient
    {
        return $this->client;
    }

    /**
     * Run (or resume) the pipeline for an invoice.
     * @return array { ok:bool, stage:string, status:string, irn:string, message:string, qr:?string }
     */
    public function submit(int $invoiceId): array
    {
        $invoice = $this->loadInvoice($invoiceId);
        if (!$invoice) {
            return ['ok' => false, 'stage' => 'load', 'status' => 'failed', 'irn' => '', 'message' => 'Invoice not found', 'qr' => null];
        }
        if (!$this->client->isConfigured()) {
            return ['ok' => false, 'stage' => 'config', 'status' => 'failed', 'irn' => '', 'message' => 'FIRS business id / API key not configured yet', 'qr' => null];
        }

        $items    = $this->loadItems($invoiceId);
        $company  = $this->loadCompany((int) $invoice['company_id']);
        $customer = $this->loadCustomer((int) $invoice['customer_id']);

        // IRN is built once and reused on every retry (FIRS treats it as the id).
        $irn = $invoice['irn'] ?: $this->client->buildIrn(
            $invoice['invoice_number'],
            $invoice['date'],
            $this->client->resolveIrnTemplate()
        );
        $businessId = $this->client->getBusinessId();

        $payload = InvoicePayload::build($invoice, $items, $company, $customer, $irn, $businessId);

        $attempt = ((int) $invoice['transmit_attempts']) + 1;
        $this->touchAttempt($invoiceId, $irn, $businessId, $attempt);

        // Stage gating keys off the persisted timestamps, not the coarse status,
        // so a retry resumes exactly where it left off. validate and sign are
        // NOT idempotent on the portal (re-signing a signed IRN returns 400), so
        // we must never repeat a stage that already succeeded.

        // ── 1. Validate (only if not already validated) ──────────────────────
        if (empty($invoice['validated_at'])) {
            $res = $this->client->validateInvoice($payload);
            $this->log($invoiceId, $irn, 'validate', $attempt, $res, $payload);
            if (!$res['ok']) {
                return $this->finishFailure($invoiceId, $irn, 'validate', $res, $attempt);
            }
            $this->setStatus($invoiceId, 'validated', ['validated_at' => date('Y-m-d H:i:s')]);
        }

        // ── 2. Sign (only if not already signed) ─────────────────────────────
        if (empty($invoice['signed_at'])) {
            $res = $this->client->signInvoice($payload);
            $this->log($invoiceId, $irn, 'sign', $attempt, $res, $payload);
            if (!$res['ok']) {
                return $this->finishFailure($invoiceId, $irn, 'sign', $res, $attempt);
            }
            $this->setStatus($invoiceId, 'signed', ['signed_at' => date('Y-m-d H:i:s')]);
        }

        // ── 3. QR (RSA-encrypted IRN+certificate) — generate once ────────────
        $qrErr = null;
        $qr = !empty($invoice['qr_data']) ? $invoice['qr_data'] : $this->client->generateQrPayload($irn, $qrErr);
        if ($qr !== null && empty($invoice['qr_data'])) {
            $stmt = $this->conn->prepare("UPDATE invoices SET qr_data = :q WHERE id = :id");
            $stmt->execute([':q' => $qr, ':id' => $invoiceId]);
        }

        // ── 4. Transmit ──────────────────────────────────────────────────────
        $res = $this->client->transmitInvoice($irn, $payload);
        $this->log($invoiceId, $irn, 'transmit', $attempt, $res, $payload);
        if ($res['ok']) {
            $this->setStatus($invoiceId, 'transmitted', [
                'transmitted_at' => date('Y-m-d H:i:s'),
                'next_retry_at'  => null,
                'last_error'     => null,
                'status'         => 'verified',
                'api_status'     => 'success',
                'api_response'   => $res['raw'],
            ]);
            $this->webhooks->notify($invoiceId, 'invoice.transmitted');
            // Pull the authoritative delivery state and notify the customer of
            // any further change (delivered / rejected).
            $this->confirmStatus($invoiceId);
            return ['ok' => true, 'stage' => 'transmit', 'status' => 'transmitted', 'irn' => $irn, 'message' => 'Invoice transmitted to FIRS', 'qr' => $qr];
        }

        return $this->finishFailure($invoiceId, $irn, 'transmit', $res, $attempt);
    }

    /**
     * Poll GET /invoice/confirm/{IRN} for the authoritative lifecycle state and
     * persist it. When the invoice flips to delivered (or rejected) the customer
     * is notified. Returns the confirm payload, or null if it couldn't be read.
     *
     * This is the reliable status source: it works even where FIRS cannot reach
     * our inbound webhook (e.g. local testing), and complements the push events.
     */
    public function confirmStatus(int $invoiceId): ?array
    {
        $stmt = $this->conn->prepare("SELECT irn, delivered, entry_status FROM invoices WHERE id = :id");
        $stmt->execute([':id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['irn'])) {
            return null;
        }

        $res = $this->client->confirmInvoice($row['irn']);
        $this->log($invoiceId, $row['irn'], 'confirm', 0, $res, []);
        if (!$res['ok'] || !isset($res['body']['data'])) {
            return null;
        }

        $data = $res['body']['data'];
        $delivered = !empty($data['delivered']);
        $entry = $data['entry_status'] ?? null;

        $this->setStatus($invoiceId, $delivered ? 'transmitted' : ($this->currentFirsStatus($invoiceId)), [
            'delivered'    => $delivered ? 1 : 0,
            'entry_status' => $entry,
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);

        // Notify only on a *transition* into delivered.
        if ($delivered && !((int) $row['delivered'])) {
            $this->webhooks->notify($invoiceId, 'invoice.delivered', ['entry_status' => $entry]);
        }
        return $data;
    }

    private function currentFirsStatus(int $invoiceId): string
    {
        $stmt = $this->conn->prepare("SELECT firs_status FROM invoices WHERE id = :id");
        $stmt->execute([':id' => $invoiceId]);
        return (string) ($stmt->fetchColumn() ?: 'not_sent');
    }

    /** Re-attempt every invoice whose retry window is due. */
    public function runDueRetries(int $limit = 25): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id FROM invoices
             WHERE firs_status = 'queued_retry' AND next_retry_at IS NOT NULL AND next_retry_at <= NOW()
             ORDER BY next_retry_at ASC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($ids as $id) {
            $results[(int) $id] = $this->submit((int) $id);
        }
        return $results;
    }

    /** Re-poll /confirm for invoices transmitted but not yet delivered. */
    public function runDueConfirmations(int $limit = 50): int
    {
        $stmt = $this->conn->prepare(
            "SELECT id FROM invoices WHERE firs_status = 'transmitted' AND delivered = 0 ORDER BY transmitted_at ASC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->confirmStatus((int) $id);
        }
        return count($ids);
    }

    // ── Failure handling / retry policy ─────────────────────────────────────

    private function finishFailure(int $invoiceId, string $irn, string $stage, array $res, int $attempt): array
    {
        $transient = $this->isTransient($res);
        $error = substr((string) ($res['error'] ?? 'unknown error'), 0, 500);

        if ($transient && $attempt < self::MAX_ATTEMPTS) {
            $delay = self::BACKOFF_MINUTES[min($attempt - 1, count(self::BACKOFF_MINUTES) - 1)];
            $next  = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
            $this->setStatus($invoiceId, 'queued_retry', [
                'next_retry_at' => $next,
                'last_error'    => $error,
                'api_status'    => 'sent',
            ]);
            return ['ok' => false, 'stage' => $stage, 'status' => 'queued_retry', 'irn' => $irn,
                    'message' => "Could not reach portal ({$error}). Queued for retry at {$next}.", 'qr' => null];
        }

        $this->setStatus($invoiceId, 'failed', [
            'next_retry_at' => null,
            'last_error'    => $error,
            'api_status'    => 'failed',
            'api_response'  => $res['raw'] ?? null,
        ]);
        $this->webhooks->notify($invoiceId, 'invoice.failed', ['stage' => $stage, 'error' => $error]);
        return ['ok' => false, 'stage' => $stage, 'status' => 'failed', 'irn' => $irn, 'message' => $error, 'qr' => null];
    }

    /** A failure we should retry rather than give up on. */
    private function isTransient(array $res): bool
    {
        if (!empty($res['network_error'])) {
            return true;
        }
        if (($res['http'] ?? 0) >= 500) {
            return true;
        }
        $msg = strtolower((string) ($res['error'] ?? ''));
        foreach (['offline', 'timeout', 'timed out', 'temporarily', 'unavailable', 'try again'] as $needle) {
            if (strpos($msg, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    // ── Persistence helpers ─────────────────────────────────────────────────

    private function touchAttempt(int $invoiceId, string $irn, string $businessId, int $attempt): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE invoices SET irn = :irn, business_id = :bid, transmit_attempts = :att, last_attempt_at = NOW() WHERE id = :id"
        );
        $stmt->execute([':irn' => $irn, ':bid' => $businessId, ':att' => $attempt, ':id' => $invoiceId]);
    }

    private function setStatus(int $invoiceId, string $firsStatus, array $extra = []): void
    {
        $fields = ['firs_status = :firs_status'];
        $params = [':firs_status' => $firsStatus, ':id' => $invoiceId];
        foreach ($extra as $col => $val) {
            $fields[] = "$col = :$col";
            $params[":$col"] = $val;
        }
        $sql = "UPDATE invoices SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->conn->prepare($sql)->execute($params);
    }

    private function log(int $invoiceId, string $irn, string $stage, int $attempt, array $res, array $payload): void
    {
        $status = !empty($res['network_error']) ? 'network_error' : ($res['ok'] ? 'success' : 'failed');
        $stmt = $this->conn->prepare(
            "INSERT INTO firs_transmissions
                (invoice_id, irn, stage, attempt, http_code, status, request_payload, response_body, error_message)
             VALUES (:iid, :irn, :stage, :att, :http, :status, :req, :resp, :err)"
        );
        $stmt->execute([
            ':iid'    => $invoiceId,
            ':irn'    => $irn,
            ':stage'  => $stage,
            ':att'    => $attempt,
            ':http'   => $res['http'] ?? 0,
            ':status' => $status,
            ':req'    => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':resp'   => substr((string) ($res['raw'] ?? ''), 0, 60000),
            ':err'    => isset($res['error']) ? substr((string) $res['error'], 0, 1000) : null,
        ]);
    }

    // ── Loaders ─────────────────────────────────────────────────────────────

    private function loadInvoice(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function loadItems(int $invoiceId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT ii.*, i.name AS item_name, i.description, i.hsn_code, i.category, i.currency AS item_currency
             FROM invoice_items ii JOIN items i ON ii.item_id = i.id
             WHERE ii.invoice_id = :iid"
        );
        $stmt->execute([':iid' => $invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadCompany(int $id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM companies WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadCustomer(int $id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM customers WHERE id = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
