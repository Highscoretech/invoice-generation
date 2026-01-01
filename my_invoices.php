<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireRole('accountant');

$database = new Database();
$conn = $database->getConnection();

// Check for customers and items
$query = "SELECT COUNT(*) as count FROM customers WHERE company_id = :company_id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':company_id', $_SESSION['company_id']);
$stmt->execute();
$customer_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$query = "SELECT COUNT(*) as count FROM items WHERE company_id = :company_id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':company_id', $_SESSION['company_id']);
$stmt->execute();
$item_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Fetch invoices
$query = "SELECT i.*, c.name as customer_name
          FROM invoices i
          JOIN customers c ON i.customer_id = c.id
          WHERE i.user_id = :user_id
          ORDER BY i.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->execute(['user_id' => $_SESSION['user_id']]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'All Invoices';
include 'includes/header.php';
?>

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

    .btn-create {
        background-color: #6366f1; /* Primary blue/purple from Figma */
        color: white; border: none; border-radius: 8px;
        padding: 10px 20px; font-weight: 500;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">All Invoices</h4>
    </div>

    <div class="text-center py-5">
        <div class="mb-3 text-muted" style="opacity: 0.3;"><i class="fas fa-file-invoice fa-4x"></i></div>
        <h5 class="text-secondary">No invoices created yet.</h5>
    </div>
</div>

<?php include 'includes/footer.php'; ?>