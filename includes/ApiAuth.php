<?php
/**
 * ApiAuth — authenticates external callers of the customer-facing middleware API.
 *
 * Callers present two headers:
 *     x-client-key:    the public api key (looked up in api_clients)
 *     x-client-secret: the secret, verified against the stored bcrypt hash
 *
 * The secret is NEVER stored in plain text — only password_hash()'d — mirroring
 * how user passwords are handled, so a database leak cannot reveal credentials.
 */
class ApiAuth
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    /** @return array|null the api_clients row on success, null on failure. */
    public function authenticate(): ?array
    {
        $headers = $this->headers();
        $key    = $headers['x-client-key']    ?? '';
        $secret = $headers['x-client-secret'] ?? '';
        if ($key === '' || $secret === '') {
            return null;
        }

        $stmt = $this->conn->prepare("SELECT * FROM api_clients WHERE api_key = :k AND status = 'active'");
        $stmt->execute([':k' => $key]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            return null;
        }
        if (!password_verify($secret, $client['api_secret_hash'])) {
            return null;
        }
        return $client;
    }

    /** Lower-cased request headers (works under Apache and CLI/CGI). */
    private function headers(): array
    {
        $out = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $out[strtolower($k)] = $v;
            }
        }
        // Fallback for SAPIs without getallheaders().
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = $v;
            }
        }
        return $out;
    }

    /**
     * Provision a new api client. Returns [api_key, api_secret] — the secret is
     * shown ONCE and only its hash is persisted.
     */
    public static function provision(PDO $conn, int $companyId, string $name, ?string $webhookUrl = null): array
    {
        $key           = 'ak_' . bin2hex(random_bytes(16));
        $secret        = 'sk_' . bin2hex(random_bytes(32));
        $webhookSecret = 'whsec_' . bin2hex(random_bytes(24));
        $stmt = $conn->prepare(
            "INSERT INTO api_clients (company_id, client_name, api_key, api_secret_hash, webhook_url, webhook_secret)
             VALUES (:c, :n, :k, :h, :w, :ws)"
        );
        $stmt->execute([
            ':c'  => $companyId,
            ':n'  => $name,
            ':k'  => $key,
            ':h'  => password_hash($secret, PASSWORD_DEFAULT),
            ':w'  => $webhookUrl,
            ':ws' => $webhookSecret,
        ]);
        // api_secret is hashed (one-way). webhook_secret is shared so the customer
        // can verify our HMAC signatures, so it is stored and returned as-is.
        return ['api_key' => $key, 'api_secret' => $secret, 'webhook_secret' => $webhookSecret];
    }
}
