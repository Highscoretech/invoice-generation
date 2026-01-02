<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$invoice_id = $_GET['id'] ?? 0;
$message = '';

// Get invoice details
$query = "SELECT i.*, c.name as customer_name, c.email as customer_email, c.tax_id as customer_tin
          FROM invoices i 
          JOIN customers c ON i.customer_id = c.id 
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
$query = "SELECT ii.*, i.name as item_name, i.item_code, i.hsn_code, i.tax_rate as item_tax_rate
          FROM invoice_items ii 
          JOIN items i ON ii.item_id = i.id 
          WHERE ii.invoice_id = :invoice_id";

$stmt = $conn->prepare($query);
$stmt->bindParam(':invoice_id', $invoice_id);
$stmt->execute();
$invoice_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle API submission
if ($_POST && $_POST['action'] === 'send_to_api') {
    try {
        // Prepare invoice data for API
        $api_data = [
            'invoice_number' => $invoice['invoice_number'],
            'invoice_date' => $invoice['date'],
            'invoice_time' => $invoice['time'],
            'due_date' => $invoice['due_date'],
            'customer' => [
                'name' => $invoice['customer_name'],
                'email' => $invoice['customer_email'],
                'tin' => $invoice['customer_tin']
            ],
            'items' => [],
            'subtotal' => $invoice['subtotal'],
            'tax_rate' => $invoice['tax_rate'],
            'tax_amount' => $invoice['tax_amount'],
            'total_amount' => $invoice['total_amount']
        ];
        
        foreach ($invoice_items as $item) {
            $api_data['items'][] = [
                'name' => $item['item_name'],
                'item_code' => $item['item_code'],
                'hsn_code' => $item['hsn_code'],
                'quantity' => $item['quantity'],
                'rate' => $item['rate'],
                'amount' => $item['amount']
            ];
        }
        
        // Simulate API call to government portal
        // In real implementation, replace this with actual API endpoint
        $api_url = 'https://government-portal-api.example.com/invoices';
        
        // For demo purposes, we'll simulate the API response
        $api_success = rand(0, 1); // Random success/failure for demo
        
        if ($api_success) {
            $api_response = json_encode([
                'status' => 'success',
                'message' => 'Invoice submitted successfully',
                'reference_id' => 'GOV-' . time(),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Update invoice status
            $query = "UPDATE invoices SET api_status = 'success', api_response = :response WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'response' => $api_response,
                'id' => $invoice_id
            ]);
            
            $message = 'Invoice successfully sent to government portal!';
        } else {
            $api_response = json_encode([
                'status' => 'error',
                'message' => 'API validation failed',
                'errors' => ['Invalid tax calculation', 'Missing customer details'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Update invoice status
            $query = "UPDATE invoices SET api_status = 'failed', api_response = :response WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'response' => $api_response,
                'id' => $invoice_id
            ]);
            
            $message = 'Failed to send invoice to government portal. Please check the errors and try again.';
        }
        
        // Refresh invoice data
        $stmt = $conn->prepare("SELECT * FROM invoices WHERE id = :id");
        $stmt->bindParam(':id', $invoice_id);
        $stmt->execute();
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $message = 'Error sending invoice to API: ' . $e->getMessage();
    }
}

$page_title = 'Send to Government Portal';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Send Invoice to Government Portal</h1>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo strpos($message, 'successfully') !== false ? 'success' : 'danger'; ?>">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5>Invoice Details</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Invoice Number:</strong> <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
                        <p><strong>Customer:</strong> <?php echo htmlspecialchars($invoice['customer_name']); ?></p>
                        <p><strong>Date:</strong> <?php echo date('M d, Y', strtotime($invoice['date'])); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Total Amount:</strong> $<?php echo number_format($invoice['total_amount'], 2); ?></p>
                        <p><strong>Tax Amount:</strong> $<?php echo number_format($invoice['tax_amount'], 2); ?></p>
                        <p><strong>Items Count:</strong> <?php echo count($invoice_items); ?></p>
                    </div>
                </div>
                
                <h6>Items:</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Quantity</th>
                                <th>Rate</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoice_items as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                                <td><?php echo number_format($item['quantity'], 2); ?></td>
                                <td>$<?php echo number_format($item['rate'], 2); ?></td>
                                <td>$<?php echo number_format($item['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-header">
                <h5>API Status</h5>
            </div>
            <div class="card-body">
                <p><strong>Current Status:</strong> 
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
                </p>
                
                <?php if ($invoice['api_status'] === 'pending'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="send_to_api">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-paper-plane"></i> Send to Government Portal
                        </button>
                    </form>
                    <small class="text-muted mt-2 d-block">
                        This will submit the invoice data to the government portal API for processing.
                    </small>
                <?php endif; ?>
                
                <?php if ($invoice['api_response']): ?>
                    <hr>
                    <h6>API Response:</h6>
                    <div class="bg-light p-2 rounded">
                        <pre class="mb-0" style="font-size: 0.8em;"><?php echo htmlspecialchars($invoice['api_response']); ?></pre>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mt-3">
            <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-outline-primary">
                <i class="fas fa-eye"></i> View Invoice
            </a>
            <a href="<?php echo $_SESSION['role'] === 'admin' ? 'invoices.php' : 'my_invoices.php'; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Invoices
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>