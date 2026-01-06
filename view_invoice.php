<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$invoice_id = $_GET['id'] ?? 0;

// Get invoice details
$query = "SELECT i.*, c.name as customer_name, c.email as customer_email, c.billing_address, c.billing_city, c.billing_state, c.billing_country, c.billing_postal_code,
          comp.name as company_name, comp.email as company_email, comp.phone as company_phone, comp.address as company_address
          FROM invoices i 
          JOIN customers c ON i.customer_id = c.id 
          JOIN companies comp ON i.company_id = comp.id
          WHERE i.id = :id AND i.company_id = :company_id";

$stmt = $conn->prepare($query);
$stmt->bindParam(':id', $invoice_id);
$stmt->bindParam(':company_id', $_SESSION['company_id']);
$stmt->execute();
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header('Location: dashboard.php');
    exit();
}

// Get invoice items
$query = "SELECT ii.*, i.name as item_name, i.currency as item_currency 
          FROM invoice_items ii 
          JOIN items i ON ii.item_id = i.id 
          WHERE ii.invoice_id = :invoice_id";

$stmt = $conn->prepare($query);
$stmt->bindParam(':invoice_id', $invoice_id);
$stmt->execute();
$invoice_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$currency = !empty($invoice_items) ? ($invoice_items[0]['item_currency'] ?? 'USD') : 'USD';
$sym = $currency === 'INR' ? '₹' : ($currency === 'EUR' ? '€' : ($currency === 'GBP' ? '£' : ($currency === 'NGN' ? '₦' : '$')));
$page_title = 'Invoice ' . $invoice['invoice_number'];
include 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Invoice <?php echo htmlspecialchars($invoice['invoice_number']); ?></h1>
    <div>
        <button onclick="window.print()" class="btn btn-secondary">
            <i class="fas fa-print"></i> Print
        </button>
        <?php if ($_SESSION['role'] === 'accountant' && $invoice['api_status'] === 'pending'): ?>
            <a href="send_to_api.php?id=<?php echo $invoice['id']; ?>" class="btn btn-success">
                <i class="fas fa-paper-plane"></i> Send to Government Portal
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="invoice-container">
    <div class="row">
        <div class="col-md-6">
            <h4><?php echo htmlspecialchars($invoice['company_name']); ?></h4>
            <p>
                <?php echo htmlspecialchars($invoice['company_address']); ?><br>
                Email: <?php echo htmlspecialchars($invoice['company_email']); ?><br>
                Phone: <?php echo htmlspecialchars($invoice['company_phone']); ?>
            </p>
        </div>
        <div class="col-md-6 text-end">
            <h2>INVOICE</h2>
            <p>
                <strong>Invoice #:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?><br>
                <strong>Date:</strong> <?php echo date('M d, Y', strtotime($invoice['date'])); ?><br>
                <?php if ($invoice['due_date']): ?>
                    <strong>Due Date:</strong> <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?><br>
                <?php endif; ?>
            </p>
            <?php if (!empty($invoice['qr_url']) && $invoice['api_status'] === 'success'): ?>
                <div class="mt-2">
                    <a href="<?php echo htmlspecialchars($invoice['qr_url']); ?>" target="_blank" title="Open QR URL">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?php echo urlencode($invoice['qr_url']); ?>" 
                             alt="QR" width="120" height="120" style="border-radius:8px;">
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <hr>
    
    <div class="row">
        <div class="col-md-6">
            <h5>Bill To:</h5>
            <p>
                <strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong><br>
                <?php echo htmlspecialchars($invoice['billing_address']); ?><br>
                <?php echo htmlspecialchars($invoice['billing_city']); ?>, <?php echo htmlspecialchars($invoice['billing_state']); ?> <?php echo htmlspecialchars($invoice['billing_postal_code']); ?><br>
                <?php echo htmlspecialchars($invoice['billing_country']); ?><br>
                <?php if ($invoice['customer_email']): ?>
                    Email: <?php echo htmlspecialchars($invoice['customer_email']); ?>
                <?php endif; ?>
            </p>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-6"><strong>Status:</strong></div>
                        <div class="col-6">
                            <span class="badge bg-<?php 
                                echo match($invoice['status']) {
                                    'draft' => 'secondary',
                                    'sent' => 'primary',
                                    'paid' => 'success',
                                    'verified' => 'success',
                                    'cancelled' => 'danger',
                                    default => 'secondary'
                                };
                            ?>">
                                <?php echo ucfirst($invoice['status']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-6"><strong>API Status:</strong></div>
                        <div class="col-6">
                            <span class="badge bg-<?php 
                                echo match($invoice['api_status']) {
                                    'pending' => 'warning',
                                    'sent' => 'info',
                                    'success' => 'success',
                                    'failed' => 'danger',
                                    default => 'secondary'
                                };
                            ?>">
                                <?php echo ucfirst($invoice['api_status']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="table-responsive mt-4">
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th class="text-center">Quantity</th>
                    <th class="text-end">Rate</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoice_items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td class="text-center"><?php echo number_format($item['quantity'], 0); ?></td>
                    <td class="text-end">
                        <?php
                            $icur = $item['item_currency'] ?? $currency;
                            $isym = $icur === 'INR' ? '₹' : ($icur === 'EUR' ? '€' : ($icur === 'GBP' ? '£' : ($icur === 'NGN' ? '₦' : '$')));
                            echo $isym . number_format($item['rate'], 2);
                        ?>
                    </td>
                    <td class="text-end">
                        <?php
                            $icur = $item['item_currency'] ?? $currency;
                            $isym = $icur === 'INR' ? '₹' : ($icur === 'EUR' ? '€' : ($icur === 'GBP' ? '£' : ($icur === 'NGN' ? '₦' : '$')));
                            echo $isym . number_format($item['amount'], 2);
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <?php if ($invoice['notes']): ?>
                <h6>Notes:</h6>
                <p><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></p>
            <?php endif; ?>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-6"><strong>Subtotal:</strong></div>
                        <div class="col-6 text-end"><?php echo $sym . number_format($invoice['subtotal'], 2); ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-6"><strong>Tax (<?php echo $invoice['tax_rate']; ?>%):</strong></div>
                        <div class="col-6 text-end"><?php echo $sym . number_format($invoice['tax_amount'], 2); ?></div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-6"><strong>Total:</strong></div>
                        <div class="col-6 text-end"><strong><?php echo $sym . number_format($invoice['total_amount'], 2); ?></strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .sidebar, .navbar, .btn, .border-bottom { display: none !important; }
    .col-md-10 { margin-left: 0 !important; }
    .invoice-container { margin-top: 0 !important; }
}
</style>

<?php include 'includes/footer.php'; ?>
