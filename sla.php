<?php
// Public page — accessible without logging in (NRS requirement). The auth
// include only starts the session so the shared layout can detect staff users.
require_once 'includes/auth.php';
require_once 'config/database.php';
$page_title = 'Service Level Agreement';

// Live operational numbers from the transmission log so the SLA page reflects
// the actual system, not just static promises.
$conn = (new Database())->getConnection();
$stats = ['total' => 0, 'success' => 0, 'failed' => 0, 'network' => 0];
try {
    $rows = $conn->query("SELECT status, COUNT(*) c FROM firs_transmissions WHERE stage='transmit' GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $stats['total'] += (int) $r['c'];
        if ($r['status'] === 'success') $stats['success'] += (int) $r['c'];
        elseif ($r['status'] === 'network_error') $stats['network'] += (int) $r['c'];
        else $stats['failed'] += (int) $r['c'];
    }
} catch (Throwable $e) { /* table may be empty */ }
$successRate = $stats['total'] ? round($stats['success'] / $stats['total'] * 100, 1) : 100.0;

include 'includes/header.php';
?>
<div class="pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Service Level Agreement (SLA)</h1>
    <p class="text-muted">Operational commitments for the e-invoice middleware that sits between customer systems and
        the FIRS / NRS e-invoicing portal.</p>
</div>

<div class="row mb-4">
    <div class="col-md-3"><div class="stat-card"><div><div class="label">Uptime target</div><div class="value">99.5%</div></div><div class="icon-box text-online"><i class="fas fa-heart-pulse"></i></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div><div class="label">Transmit attempts logged</div><div class="value"><?php echo $stats['total']; ?></div></div><div class="icon-box text-primary"><i class="fas fa-paper-plane"></i></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div><div class="label">Transmit success rate</div><div class="value"><?php echo $successRate; ?>%</div></div><div class="icon-box text-online"><i class="fas fa-check"></i></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div><div class="label">Auto-retry queue</div><div class="value"><?php echo $stats['network'] + $stats['failed']; ?></div></div><div class="icon-box text-warning"><i class="fas fa-rotate"></i></div></div></div>
</div>

<div class="card mb-3"><div class="card-body">
    <h5>1. Availability</h5>
    <ul>
        <li>Target service availability: <strong>99.5%</strong> measured monthly, excluding scheduled maintenance.</li>
        <li>Scheduled maintenance is announced at least <strong>24 hours</strong> in advance and runs in off-peak hours.</li>
        <li>Dependency note: invoice transmission also depends on the FIRS portal and its access points. Portal
            downtime is outside this SLA but is fully handled by the retry queue (below).</li>
    </ul>

    <h5>2. Performance</h5>
    <ul>
        <li>Customer API response time: <strong>&lt; 800 ms</strong> (p95) for accept/status calls, excluding the
            synchronous portal round-trip.</li>
        <li>An accepted invoice is validated, signed and a first transmit attempt made within <strong>the same
            request</strong>; if the portal is unreachable it is queued immediately.</li>
    </ul>

    <h5>3. Reliability &amp; retries</h5>
    <ul>
        <li>Every portal call (validate / sign / transmit) is persisted in <code>firs_transmissions</code> with the
            full request, response and HTTP code for audit.</li>
        <li>Transient failures (network errors, HTTP 5xx, "access points offline") are retried automatically with
            exponential backoff: <strong>2 → 5 → 15 → 60 → 180 minutes</strong>, up to 6 attempts.</li>
        <li>Already-validated/signed invoices resume at the failed stage — an invoice is never double-signed.</li>
        <li>Permanent (business-rule) rejections are surfaced to the customer immediately and not retried.</li>
    </ul>

    <h5>4. Security</h5>
    <ul>
        <li>All portal traffic is over HTTPS/TLS. Invoice QR payloads are RSA-encrypted (PKCS#1) with the FIRS
            public key per the QR-code specification.</li>
        <li>Customer API secrets and user passwords are stored only as <strong>bcrypt hashes</strong>, never in
            plain text. FIRS API keys live in <code>.env</code>, which is blocked from web access.</li>
    </ul>

    <h5>5. Support</h5>
    <ul>
        <li>Support channel: email to the project owner. Target first response: <strong>1 business day</strong>.</li>
        <li>Severity 1 (transmission fully down): best-effort response within <strong>4 hours</strong> on business days.</li>
    </ul>

    <p class="text-muted mb-0"><small>This SLA covers the middleware application only. It does not extend the FIRS
        portal's own service levels.</small></p>
</div></div>

<?php include 'includes/footer.php'; ?>
