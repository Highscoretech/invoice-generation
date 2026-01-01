<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

// Get all invoices for the company
$query = "SELECT i.*, c.name as customer_name, u.username 
          FROM invoices i 
          JOIN customers c ON i.customer_id = c.id 
          JOIN users u ON i.user_id = u.id
          WHERE i.company_id = :company_id 
          ORDER BY i.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bindParam(':company_id', $_SESSION['company_id']);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'All Invoices';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">All Invoices</h1>
</div>

<div class="table-responsive">
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Invoice #</th>
                <th>Customer</th>
                <th>Created By</th>
                <th>Date</th>
                <th>Amount</th>
                <th>Status</th>
                <th>API Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
                <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                <td><?php echo htmlspecialchars($invoice['username']); ?></td>
                <td><?php echo date('M d, Y', strtotime($invoice['invoice_date'])); ?></td>
                <td>$<?php echo number_format($invoice['total_amount'], 2); ?></td>
                <td>
                    <span class="badge bg-<?php 
                        echo match($invoice['status']) {
                            'draft' => 'secondary',
                            'sent' => 'primary',
                            'paid' => 'success',
                            'cancelled' => 'danger',
                            default => 'secondary'
                        };
                    ?>">
                        <?php echo ucfirst($invoice['status']); ?>
                    </span>
                </td>
                <td>
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
                </td>
                <td>
                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <?php if ($invoice['api_status'] === 'pending'): ?>
                        <a href="send_to_api.php?id=<?php echo $invoice['id']; ?>" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-paper-plane"></i> Send to API
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (empty($invoices)): ?>
    <div class="text-center py-5">
        <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
        <h5>No invoices found</h5>
        <p class="text-muted">No invoices have been created yet.</p>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>