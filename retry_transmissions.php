<?php
/**
 * Retry runner — re-attempts every invoice queued after a transient transmit
 * failure whose backoff window is now due.
 *
 * Intended to run on a schedule. On cPanel add a cron job, e.g. every 5 min:
 *     * /5 * * * * /usr/bin/php /home/USER/public_html/einvoice/retry_transmissions.php
 *
 * It can also be triggered over HTTP for environments without cron by setting
 * RETRY_CRON_TOKEN in .env and calling:
 *     https://host/retry_transmissions.php?token=THE_TOKEN
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/includes/FirsService.php';
require_once __DIR__ . '/includes/WebhookDispatcher.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $expected = (string) env('RETRY_CRON_TOKEN', '');
    $given    = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
    header('Content-Type: application/json');
}

$conn = (new Database())->getConnection();
$service = new FirsService($conn);

// 1. Re-attempt transmits that were queued after a transient failure.
$results = $service->runDueRetries(50);
// 2. Re-poll /confirm for transmitted-but-not-delivered invoices.
$confirmed = $service->runDueConfirmations(50);
// 3. Re-send any customer webhook callbacks that previously failed.
$webhooks = (new WebhookDispatcher($conn))->runDueRetries(50);

$summary = ['ran' => count($results), 'transmitted' => 0, 'requeued' => 0, 'failed' => 0, 'details' => []];
foreach ($results as $id => $r) {
    if ($r['status'] === 'transmitted') {
        $summary['transmitted']++;
    } elseif ($r['status'] === 'queued_retry') {
        $summary['requeued']++;
    } else {
        $summary['failed']++;
    }
    $summary['details'][] = ['invoice_id' => $id, 'status' => $r['status'], 'message' => $r['message']];
}

$summary['confirmations_polled'] = $confirmed;
$summary['webhooks_resent'] = $webhooks;

if ($isCli) {
    echo '[' . date('Y-m-d H:i:s') . "] retry run: "
        . "ran={$summary['ran']} transmitted={$summary['transmitted']} "
        . "requeued={$summary['requeued']} failed={$summary['failed']} "
        . "confirmed={$confirmed} webhooks_resent={$webhooks}\n";
} else {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
