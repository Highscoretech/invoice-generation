<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireRole('accountant');

$database = new Database();
$conn = $database->getConnection();

// Get invoices for current user
$query = "SELECT i.*, c.name as customer_name 
          FROM invoices i 
          JOIN customers c ON i.customer_id = c.id 
          WHERE i.user_id = :user_id 
          ORDER BY i.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'My Invoices';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">My Invoices</h1>
    <div>
        <a href="create_invoice.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Create Invoice
        </a>
    </div>
</div>

<?php if (empty($invoices)): ?>
    <div class="text-center py-5">
        <h5 class="text-secondary">No invoices created yet.</h5>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">API Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($invoice['date'])); ?></td>
                            <td>
                                <?php if ($invoice['due_date']): ?>
                                    <?php echo date('M d, Y', strtotime($invoice['due_date'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <strong>$<?php echo number_format($invoice['total_amount'], 2); ?></strong>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?php 
                                    echo match($invoice['status']) {
                                        'draft' => 'secondary',
                                        'sent' => 'info',
                                        'paid' => 'success',
                                        'cancelled' => 'danger',
                                        default => 'secondary'
                                    };
                                ?>">
                                    <?php echo ucfirst($invoice['status']); ?>
                                </span>
                            </td>
                            <td class="text-center">
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
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="view_invoice.php?id=<?php echo $invoice['id']; ?>" 
                                       class="btn btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($invoice['api_status'] === 'pending'): ?>
                                        <a href="send_to_api.php?id=<?php echo $invoice['id']; ?>" 
                                           class="btn btn-outline-success" title="Send to Government Portal">
                                            <i class="fas fa-paper-plane"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-primary"><?php echo count($invoices); ?></h5>
                    <p class="card-text text-muted">Total Invoices</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-warning">
                        <?php echo count(array_filter($invoices, fn($i) => $i['api_status'] === 'pending')); ?>
                    </h5>
                    <p class="card-text text-muted">Pending API</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-success">
                        <?php echo count(array_filter($invoices, fn($i) => $i['api_status'] === 'success')); ?>
                    </h5>
                    <p class="card-text text-muted">Sent to Gov</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-info">
                        $<?php echo number_format(array_sum(array_column($invoices, 'total_amount')), 2); ?>
                    </h5>
                    <p class="card-text text-muted">Total Value</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>