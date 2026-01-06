<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireLogin();

$database = new Database();
$conn = $database->getConnection();

$stats = [];

if ($_SESSION['role'] == 'accountant') {
    // Fetch summary stats for Accountant
    $stmt = $conn->prepare("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN api_status = 'success' THEN 1 ELSE 0 END) as uploaded,
        SUM(CASE WHEN api_status = 'failed' THEN 1 ELSE 0 END) as failed
        FROM invoices WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $query = "SELECT COUNT(*) as count FROM customers WHERE company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $stats['customers'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $query = "SELECT COUNT(*) as count FROM items WHERE company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $stats['items'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $query = "SELECT COUNT(*) as count FROM invoices WHERE company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $stats['invoices'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

    $query = "SELECT COUNT(*) as count FROM invoices WHERE company_id = :company_id AND status = 'draft'";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $stats['pending_invoices'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

$page_title = 'Dashboard';
include 'includes/header.php';
?>

<?php if ($_SESSION['role'] == 'accountant'): ?>
<style>
    /* Pixel Perfect green theme for Accountant */
    :root {
        --acc-green: #065f46;
        --acc-light-green: #ecfdf5;
    }
    .stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        border: 1px solid #f3f4f6;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .stat-label { color: #6b7280; font-size: 0.85rem; margin-bottom: 8px; }
    .stat-value { font-size: 1.75rem; font-weight: 700; color: #111827; }
    .stat-icon { font-size: 2rem; }
</style>
<?php endif; ?>

<div class="mb-4">
    <h5 class="fw-bold text-muted"><?php echo ucfirst($_SESSION['role']); ?> Dashboard</h5>
</div>

<?php if ($_SESSION['role'] == 'accountant'): ?>
<div class="row g-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <div class="stat-label">Total Invoices</div>
                <div class="stat-value"><?php echo $stats['total'] ?? 0; ?></div>
            </div>
            <div class="stat-icon text-primary"><i class="fas fa-file-alt"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <div class="stat-label">Uploaded</div>
                <div class="stat-value text-success"><?php echo $stats['uploaded'] ?? 0; ?></div>
            </div>
            <div class="stat-icon text-success"><i class="fas fa-file-alt"></i></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <div class="stat-label">Failed</div>
                <div class="stat-value text-danger"><?php echo $stats['failed'] ?? 0; ?></div>
            </div>
            <div class="stat-icon text-danger"><i class="fas fa-file-alt"></i></div>
        </div>
    </div>
</div>
<?php else: ?>
<div class="row g-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <div class="label">Total Customers</div>
                <div class="value"><?php echo $stats['customers']; ?></div>
            </div>
            <div class="icon-box text-primary opacity-50">
                <i class="fas fa-users"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <div class="label">Total Items</div>
                <div class="value"><?php echo $stats['items']; ?></div>
            </div>
            <div class="icon-box text-success opacity-50">
                <i class="fas fa-cube"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="stat-card">
            <div>
                <div class="label">Total invoice</div>
                <div class="value"><?php echo $stats['invoices']; ?></div>
            </div>
            <div class="icon-box text-primary">
                <i class="fas fa-th-list"></i>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
