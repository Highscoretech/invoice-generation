<?php
/**
 * CLI helper to create a customer API client.
 *
 *   php provision_api_client.php "Customer Name" [company_id] [webhook_url]
 *
 * Prints the api key and secret ONCE — only the bcrypt hash of the secret is
 * stored, so copy the secret now; it cannot be recovered later.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/ApiAuth.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$name      = $argv[1] ?? null;
$companyId = (int) ($argv[2] ?? 1);
$webhook   = $argv[3] ?? null;

if (!$name) {
    fwrite(STDERR, "Usage: php provision_api_client.php \"Customer Name\" [company_id] [webhook_url]\n");
    exit(1);
}

$conn = (new Database())->getConnection();
$creds = ApiAuth::provision($conn, $companyId, $name, $webhook);

echo "API client created for: {$name}\n";
echo "  x-client-key:    {$creds['api_key']}\n";
echo "  x-client-secret: {$creds['api_secret']}\n";
echo "  webhook_secret:  {$creds['webhook_secret']}  (verify our x-webhook-signature HMAC with this)\n";
echo "Store the secret now — it is not recoverable.\n";
