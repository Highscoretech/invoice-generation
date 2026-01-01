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

$page_title = 'Create Invoice';
include 'includes/header.php';
?>

<div class="alert alert-warning">
    Please contact your administrator to add customers and items before creating invoices.
</div>

<?php include 'includes/footer.php'; ?>