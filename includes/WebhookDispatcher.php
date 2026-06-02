<?php
/**
 * WebhookDispatcher — notifies a customer's system when one of their invoices
 * changes FIRS status (e.g. transmitted, delivered, rejected).
 *
 * The invoice is linked back to the API client that submitted it via
 * api_inbound_invoices, so we know which webhook_url to call. The payload is
 * signed with the client's webhook_secret (HMAC-SHA256) and sent in the
 * x-webhook-signature header so the customer can verify it came from us.
 *
 * Every attempt is recorded in webhook_deliveries; transient failures are
 * queued for retry (the same runner that drains transmit retries also drains
 * these).
 */
class WebhookDispatcher
{
    private PDO $conn;
    private const BACKOFF_MINUTES = [1, 5, 15, 60];
    private const MAX_ATTEMPTS = 5;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /**
     * Notify the customer that owns $invoiceId about $event. No-op (logged as
     * 'skipped') when the invoice did not come from an API client or the client
     * has no webhook_url configured.
     */
    public function notify(int $invoiceId, string $event, array $extra = []): void
    {
        $stmt = $this->conn->prepare(
            "SELECT a.external_reference, c.id AS client_id, c.webhook_url, c.webhook_secret,
                    i.irn, i.firs_status, i.delivered, i.entry_status
             FROM api_inbound_invoices a
             JOIN api_clients c ON c.id = a.api_client_id
             JOIN invoices i ON i.id = a.invoice_id
             WHERE a.invoice_id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || empty($row['webhook_url'])) {
            // UI-created invoice or no callback configured — nothing to send.
            $this->record(null, $invoiceId, $event, $row['webhook_url'] ?? null, 1, null, 'skipped', [], null, null);
            return;
        }

        $payload = array_merge([
            'event'        => $event,
            'reference'    => $row['external_reference'],
            'irn'          => $row['irn'],
            'firs_status'  => $row['firs_status'],
            'transmitted'  => $row['firs_status'] === 'transmitted' || (bool) $row['delivered'],
            'delivered'    => (bool) $row['delivered'],
            'entry_status' => $row['entry_status'],
        ], $extra);

        $this->send((int) $row['client_id'], $invoiceId, $event, $row['webhook_url'], $row['webhook_secret'], $payload, 1);
    }

    /** Re-send webhook deliveries whose retry window is due. */
    public function runDueRetries(int $limit = 50): int
    {
        $stmt = $this->conn->prepare(
            "SELECT d.*, c.webhook_url, c.webhook_secret
             FROM webhook_deliveries d JOIN api_clients c ON c.id = d.api_client_id
             WHERE d.status IN ('failed','network_error') AND d.next_retry_at IS NOT NULL AND d.next_retry_at <= NOW()
             ORDER BY d.next_retry_at ASC LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $payload = json_decode($r['payload'], true) ?: [];
            $this->send((int) $r['api_client_id'], (int) $r['invoice_id'], $r['event'], $r['webhook_url'], $r['webhook_secret'], $payload, ((int) $r['attempt']) + 1);
        }
        return count($rows);
    }

    // ── core send + log ──────────────────────────────────────────────────────

    private function send(int $clientId, int $invoiceId, string $event, string $url, ?string $secret, array $payload, int $attempt): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig  = 'sha256=' . hash_hmac('sha256', $body, (string) $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-webhook-event: ' . $event,
                'x-webhook-signature: ' . $sig,
            ],
        ]);
        $resp  = curl_exec($ch);
        $errno = curl_errno($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $status = 'network_error';
        } elseif ($http >= 200 && $http < 300) {
            $status = 'success';
        } else {
            $status = 'failed';
        }

        $nextRetry = null;
        if ($status !== 'success' && $attempt < self::MAX_ATTEMPTS) {
            $delay = self::BACKOFF_MINUTES[min($attempt - 1, count(self::BACKOFF_MINUTES) - 1)];
            $nextRetry = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
        }

        $this->record($clientId, $invoiceId, $event, $url, $attempt, $http, $status, $payload, is_string($resp) ? substr($resp, 0, 4000) : null, $nextRetry);
    }

    private function record(?int $clientId, int $invoiceId, string $event, ?string $url, int $attempt, ?int $http, string $status, array $payload, ?string $resp, ?string $nextRetry): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO webhook_deliveries
                (api_client_id, invoice_id, event, target_url, attempt, http_code, status, payload, response_body, next_retry_at)
             VALUES (:c, :i, :e, :u, :a, :h, :s, :p, :r, :n)"
        );
        $stmt->execute([
            ':c' => $clientId, ':i' => $invoiceId, ':e' => $event, ':u' => $url, ':a' => $attempt,
            ':h' => $http, ':s' => $status, ':p' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ':r' => $resp, ':n' => $nextRetry,
        ]);
    }
}
