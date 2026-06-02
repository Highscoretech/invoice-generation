<?php
/**
 * FirsClient — thin, well-tested wrapper around the FIRS / NRS MBS e-invoice API.
 *
 * Responsibilities:
 *   - attach the verified auth headers (x-api-key / x-api-secret) to every call
 *   - look up the entity and resolve the business id + IRN template
 *   - build the IRN, validate, sign and transmit an invoice
 *   - generate the QR payload using the documented RSA-PKCS1 + base64 scheme
 *
 * Every HTTP call returns a normalised array:
 *   [ 'ok' => bool, 'http' => int, 'body' => array|null, 'raw' => string,
 *     'error' => string|null, 'network_error' => bool ]
 *
 * 'network_error' is true only when the request never reached the server
 * (DNS / timeout / connection reset) — the retry queue keys off this so we
 * never auto-resend an invoice the portal already rejected on its merits.
 */

require_once __DIR__ . '/../config/env.php';

class FirsClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $serviceId;
    private string $entityId;
    private string $businessId;
    private string $publicKeyB64;
    private string $certificateB64;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl        = rtrim((string) env('FIRS_API_URL', 'https://eivc-k6z6d.ondigitalocean.app'), '/');
        $this->apiKey         = (string) env('FIRS_API_KEY', '');
        $this->apiSecret      = (string) env('FIRS_API_SECRET', '');
        $this->serviceId      = (string) env('FIRS_SERVICE_ID', '');
        $this->entityId       = (string) env('FIRS_ENTITY_ID', '');
        $this->businessId     = (string) env('FIRS_BUSINESS_ID', '');
        $this->publicKeyB64   = (string) env('FIRS_PUBLIC_KEY', '');
        $this->certificateB64 = (string) env('FIRS_CERTIFICATE', '');
        $this->timeout        = (int) env('FIRS_TIMEOUT', 30);
    }

    // ── Configuration helpers ───────────────────────────────────────────────

    public function getBusinessId(): string
    {
        return $this->businessId;
    }

    /** True once a real business UUID (not the placeholder) is configured. */
    public function isConfigured(): bool
    {
        return $this->apiKey !== ''
            && $this->businessId !== ''
            && stripos($this->businessId, 'YOUR_BUSINESS') === false;
    }

    // ── Endpoints ───────────────────────────────────────────────────────────

    public function healthCheck(): array
    {
        return $this->request('GET', '/api');
    }

    /** GET /api/v1/entity/{id} — returns the entity (incl. nested businesses). */
    public function getEntity(?string $entityId = null): array
    {
        $id = $entityId ?: $this->entityId;
        return $this->request('GET', '/api/v1/entity/' . rawurlencode($id));
    }

    /** POST /api/v1/invoice/validate */
    public function validateInvoice(array $payload): array
    {
        return $this->request('POST', '/api/v1/invoice/validate', $payload);
    }

    /** POST /api/v1/invoice/sign */
    public function signInvoice(array $payload): array
    {
        return $this->request('POST', '/api/v1/invoice/sign', $payload);
    }

    /** POST /api/v1/invoice/transmit/{IRN} */
    public function transmitInvoice(string $irn, array $payload = []): array
    {
        return $this->request('POST', '/api/v1/invoice/transmit/' . rawurlencode($irn), $payload ?: null);
    }

    /** GET /api/v1/invoice/confirm/{IRN} — delivery/transmission status. */
    public function confirmInvoice(string $irn): array
    {
        return $this->request('GET', '/api/v1/invoice/confirm/' . rawurlencode($irn));
    }

    /** GET /api/v1/invoice/resources/{name} — reference data (tax categories, etc.). */
    public function getResource(string $name): array
    {
        return $this->request('GET', '/api/v1/invoice/resources/' . rawurlencode($name));
    }

    // ── IRN ─────────────────────────────────────────────────────────────────

    /**
     * Resolve the IRN template for the configured business. Falls back to the
     * service-id based pattern if the entity does not expose a template.
     */
    public function resolveIrnTemplate(): ?string
    {
        $res = $this->getEntity();
        if (!$res['ok'] || empty($res['body']['data']['businesses'])) {
            return null;
        }
        foreach ($res['body']['data']['businesses'] as $biz) {
            if (($biz['id'] ?? '') === $this->businessId && !empty($biz['irn_template'])) {
                return $biz['irn_template'];
            }
        }
        // No exact match — use the first business that has a template.
        foreach ($res['body']['data']['businesses'] as $biz) {
            if (!empty($biz['irn_template'])) {
                return $biz['irn_template'];
            }
        }
        return null;
    }

    /**
     * Build an IRN for the given invoice number / date.
     *
     * The entity's irn_template looks like
     *   "{{invoice_id(e.g:INV00XXX)}}-5AF9E02D-{{YYYYMMDD(e.g:20260602)}}"
     * so we substitute the two placeholders and keep the fixed middle segment.
     * When no template is available we fall back to INV-SERVICEID-YYYYMMDD.
     */
    public function buildIrn(string $invoiceNumber, string $date, ?string $template = null): string
    {
        $ymd = date('Ymd', strtotime($date) ?: time());
        $invoiceNumber = preg_replace('/[^A-Za-z0-9]/', '', $invoiceNumber);

        if ($template) {
            $out = preg_replace('/\{\{\s*invoice_id[^}]*\}\}/i', $invoiceNumber, $template);
            $out = preg_replace('/\{\{\s*YYYYMMDD[^}]*\}\}/i', $ymd, $out);
            return $out;
        }
        return $invoiceNumber . '-' . $this->serviceId . '-' . $ymd;
    }

    // ── QR signing ──────────────────────────────────────────────────────────

    /**
     * Produce the QR payload exactly as the FIRS spec describes: JSON-encode
     * { irn, certificate }, RSA-encrypt it with the entity public key
     * (RSA/ECB/PKCS1Padding) and base64-encode the ciphertext.
     *
     * Returns null (and sets $error) when the public key is missing/invalid so
     * callers can fall back gracefully rather than emit a broken QR.
     */
    public function generateQrPayload(string $irn, ?string &$error = null): ?string
    {
        $error = null;
        if ($this->publicKeyB64 === '') {
            $error = 'No FIRS public key configured';
            return null;
        }
        $pem = base64_decode($this->publicKeyB64, true);
        if ($pem === false || strpos($pem, 'BEGIN PUBLIC KEY') === false) {
            $error = 'FIRS public key is not valid base64 PEM';
            return null;
        }
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            $error = 'Unable to load FIRS public key: ' . openssl_error_string();
            return null;
        }
        $plain = json_encode([
            'irn'         => $irn,
            'certificate' => $this->certificateB64,
        ], JSON_UNESCAPED_SLASHES);

        $cipher = '';
        if (!openssl_public_encrypt($plain, $cipher, $key, OPENSSL_PKCS1_PADDING)) {
            $error = 'RSA encryption failed: ' . openssl_error_string();
            return null;
        }
        return base64_encode($cipher);
    }

    // ── HTTP core ───────────────────────────────────────────────────────────

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'Accept: application/json',
            'x-api-key: ' . $this->apiKey,
            'x-api-secret: ' . $this->apiSecret,
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw     = curl_exec($ch);
        $errno   = curl_errno($ch);
        $errstr  = curl_error($ch);
        $http    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            // Never reached the portal (DNS, timeout, reset, TLS) → retryable.
            return [
                'ok'            => false,
                'http'          => 0,
                'body'          => null,
                'raw'           => '',
                'error'         => 'Network error (' . $errno . '): ' . $errstr,
                'network_error' => true,
            ];
        }

        $decoded = json_decode((string) $raw, true);
        $ok      = $http >= 200 && $http < 300;
        $error   = null;
        if (!$ok) {
            $error = $decoded['error']['details']
                ?? $decoded['error']['public_message']
                ?? $decoded['message']
                ?? ('HTTP ' . $http);
        }

        return [
            'ok'            => $ok,
            'http'          => $http,
            'body'          => is_array($decoded) ? $decoded : null,
            'raw'           => (string) $raw,
            'error'         => $error,
            'network_error' => false,
        ];
    }
}
