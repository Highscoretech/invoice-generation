<?php
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/FirsService.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$invoice_id = (int) ($_GET['id'] ?? 0);
$message = '';
$message_type = 'info';

// Scope the invoice to the logged-in company.
$stmt = $conn->prepare(
    "SELECT i.*, c.name AS customer_name, c.email AS customer_email, c.tax_id AS customer_tin
     FROM invoices i JOIN customers c ON i.customer_id = c.id
     WHERE i.id = :id AND i.company_id = :company_id"
);
$stmt->execute([':id' => $invoice_id, ':company_id' => $_SESSION['company_id']]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: dashboard.php');
    exit();
}

$service = new FirsService($conn);
$configured = $service->client()->isConfigured();

// Handle submission to the government portal.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_api') {
    if (!$configured) {
        $message = 'FIRS business id / API key is not configured yet. Update the .env file before sending.';
        $message_type = 'danger';
    } else {
        $result = $service->submit($invoice_id);
        $message = $result['message'];
        $message_type = $result['ok'] ? 'success' : ($result['status'] === 'queued_retry' ? 'warning' : 'danger');
        // Refresh invoice after the run.
        $stmt = $conn->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->execute([':id' => $invoice_id]);
        $invoice = array_merge($invoice, $stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }
}

// Transmission history for this invoice.
$stmt = $conn->prepare(
    "SELECT stage, attempt, http_code, status, error_message, created_at
     FROM firs_transmissions WHERE invoice_id = :id ORDER BY id DESC LIMIT 25"
);
$stmt->execute([':id' => $invoice_id]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

$firs_status = $invoice['firs_status'] ?? 'not_sent';
$can_send = in_array($firs_status, ['not_sent', 'failed', 'queued_retry'], true);

function firs_badge(string $s): string
{
    return [
        'not_sent'     => 'secondary',
        'validated'    => 'info',
        'signed'       => 'info',
        'transmitted'  => 'success',
        'queued_retry' => 'warning',
        'failed'       => 'danger',
    ][$s] ?? 'secondary';
}

$page_title = 'Send to Government Portal';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Send Invoice to FIRS Portal</h1>
    <a href="view_invoice.php?id=<?php echo $invoice_id; ?>" class="btn btn-outline-primary">
        <i class="fas fa-eye"></i> View Invoice
    </a>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if (!$configured): ?>
    <div class="alert alert-warning">
        <strong>Awaiting configuration.</strong> The FIRS business id / API key has not been set in
        <code>.env</code>. The send button is disabled until then.
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-7">
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">FIRS Status</h5></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th>Pipeline status</th>
                        <td><span class="badge bg-<?php echo firs_badge($firs_status); ?>"><?php echo strtoupper(str_replace('_', ' ', $firs_status)); ?></span></td></tr>
                    <tr><th>IRN</th><td><code><?php echo htmlspecialchars($invoice['irn'] ?? '—'); ?></code></td></tr>
                    <tr><th>Business ID</th><td><code><?php echo htmlspecialchars($invoice['business_id'] ?? $service->client()->getBusinessId()); ?></code></td></tr>
                    <tr><th>Validated</th><td><?php echo htmlspecialchars($invoice['validated_at'] ?? '—'); ?></td></tr>
                    <tr><th>Signed</th><td><?php echo htmlspecialchars($invoice['signed_at'] ?? '—'); ?></td></tr>
                    <tr><th>Transmitted</th><td><?php echo htmlspecialchars($invoice['transmitted_at'] ?? '—'); ?></td></tr>
                    <tr><th>Attempts</th><td><?php echo (int) ($invoice['transmit_attempts'] ?? 0); ?></td></tr>
                    <?php if (!empty($invoice['next_retry_at']) && $firs_status === 'queued_retry'): ?>
                        <tr><th>Next retry</th><td><?php echo htmlspecialchars($invoice['next_retry_at']); ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($invoice['last_error'])): ?>
                        <tr><th>Last error</th><td class="text-danger"><?php echo htmlspecialchars($invoice['last_error']); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <div class="card-footer">
                <form method="POST" class="d-inline" id="sendForm">
                    <input type="hidden" name="action" value="send_to_api">
                    <button type="submit" class="btn btn-success" id="sendBtn" <?php echo ($can_send && $configured) ? '' : 'disabled'; ?>>
                        <span class="btn-label">
                            <i class="fas fa-paper-plane"></i>
                            <?php echo $firs_status === 'transmitted' ? 'Already Transmitted' : ($firs_status === 'queued_retry' ? 'Retry Now' : 'Validate, Sign &amp; Transmit'); ?>
                        </span>
                    </button>
                </form>
                <small class="text-muted d-block mt-2">
                    Runs the full pipeline: <strong>validate → sign → QR → transmit</strong>. Network failures are
                    logged and automatically re-queued. This can take up to ~30s while the portal responds.
                </small>
                <script>
                (function () {
                    var form = document.getElementById('sendForm'),
                        btn  = document.getElementById('sendBtn');
                    if (!form || !btn) return;
                    form.addEventListener('submit', function () {
                        if (btn.disabled) return;          // guard against double submit
                        btn.querySelector('.btn-label').innerHTML =
                            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ' +
                            'Contacting FIRS… please wait';
                        // Disable after submission is underway so the POST still goes through.
                        setTimeout(function () { btn.disabled = true; }, 1);
                    });
                })();
                </script>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">Transmission Log</h5></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Time</th><th>Stage</th><th>#</th><th>HTTP</th><th>Result</th></tr></thead>
                    <tbody>
                        <?php if (!$history): ?>
                            <tr><td colspan="5" class="text-muted text-center">No attempts yet.</td></tr>
                        <?php else: foreach ($history as $h): ?>
                            <tr>
                                <td><small><?php echo htmlspecialchars(date('H:i:s', strtotime($h['created_at']))); ?></small></td>
                                <td><?php echo htmlspecialchars($h['stage']); ?></td>
                                <td><?php echo (int) $h['attempt']; ?></td>
                                <td><?php echo (int) $h['http_code']; ?></td>
                                <td><span class="badge bg-<?php echo $h['status'] === 'success' ? 'success' : ($h['status'] === 'network_error' ? 'warning' : 'danger'); ?>"><?php echo htmlspecialchars($h['status']); ?></span></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
