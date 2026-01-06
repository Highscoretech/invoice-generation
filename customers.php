<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireRole('admin');

$database = new Database();
$conn = $database->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';

// Handle delete action
if ($action === 'delete' && isset($_GET['id'])) {
    $query = "DELETE FROM customers WHERE id = :id AND company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    if ($stmt->execute()) {
        header('Location: customers.php');
        exit;
    } else {
        $message = 'Error deleting customer.';
    }
}

// ... [Keep your existing POST handling logic here exactly as it is] ...
if ($_POST) {
    // [Existing PHP Logic Omitted for Brevity - No changes needed to this block]
    if ($action === 'create' || $action === 'edit') {
        $data = [
            'name' => $_POST['name'],
            'email' => $_POST['email'],
            'phone' => $_POST['phone'],
            'website' => $_POST['website'],
            'tax_id' => $_POST['tax_id'],
            'registration_number' => $_POST['registration_number'],
            'billing_address' => $_POST['billing_address'],
            'billing_city' => $_POST['billing_city'],
            'billing_state' => $_POST['billing_state'],
            'billing_country' => $_POST['billing_country'],
            'billing_postal_code' => $_POST['billing_postal_code'],
            'shipping_address' => $_POST['shipping_address'],
            'contact_person' => $_POST['contact_person'],
            'contact_designation' => $_POST['contact_designation'],
            'credit_limit' => $_POST['credit_limit'],
            'payment_terms' => $_POST['payment_terms'],
            'discount_percentage' => $_POST['discount_percentage'],
            'currency' => $_POST['currency'],
            'notes' => $_POST['notes'],
            'status' => $_POST['status']
        ];
        
        if ($action === 'create') {
            $data['company_id'] = $_SESSION['company_id'];
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $query = "INSERT INTO customers ($fields) VALUES ($placeholders)";
            $stmt = $conn->prepare($query);
            if ($stmt->execute($data)) {
                header('Location: customers.php');
                exit;
            } else { $message = 'Error creating customer.'; }
        } elseif ($action === 'edit') {
            $id = $_POST['id'];
            $set_clause = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($data)));
            $query = "UPDATE customers SET $set_clause WHERE id = :id AND company_id = :company_id";
            $stmt = $conn->prepare($query);
            $data['id'] = $id;
            $data['company_id'] = $_SESSION['company_id'];
            if ($stmt->execute($data)) {
                header('Location: customers.php');
                exit;
            } else { $message = 'Error updating customer.'; }
        }
    }
}

// Get customer for editing
$customer = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $query = "SELECT * FROM customers WHERE id = :id AND company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get customers list
$customers = [];
if ($action === 'list') {
    $query = "SELECT * FROM customers WHERE company_id = :company_id ORDER BY name";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Customers';
include 'includes/header.php';
?>

<style>
    .page-header { margin-bottom: 2rem; }
    .content-card {
        background: white;
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.03);
        padding: 24px;
    }
    .btn-add {
        background-color: #4f46e5;
        border: none;
        padding: 10px 24px;
        font-weight: 500;
        border-radius: 8px;
        color: white;
    }
    .btn-add:hover { background-color: #4338ca; color: white; }
    .table thead th {
        background-color: #f9fafb;
        color: #6b7280;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.025em;
        border-bottom: 1px solid #edf2f7;
        padding: 12px 16px;
    }
    .table tbody td {
        padding: 16px;
        vertical-align: middle;
        color: #374151;
        font-size: 0.875rem;
    }
    .badge-active { background-color: #dcfce7; color: #166534; font-weight: 500; }
    .badge-inactive { background-color: #f3f4f6; color: #4b5563; font-weight: 500; }
    .form-section-title {
        color: #111827;
        font-weight: 600;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #f3f4f6;
    }
</style>

<?php if ($message): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <div class="page-header mb-4">
        <h4 class="fw-bold mb-3">Customer Management</h4>
        <a href="?action=create" class="btn btn-primary px-4 py-2" style="background-color: #6366f1; border: none; border-radius: 8px;">
            <i class="fas fa-plus me-2"></i> Add New Customer
        </a>
    </div>

    <?php if (!empty($customers)): ?>
        <div class="content-card shadow-sm border-0 rounded-4 overflow-hidden bg-white">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr style="background-color: #f9fafb;">
                            <th class="ps-4 py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Name</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Email</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Phone</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">GSTIN</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">City</th>
                            <th class="pe-4 py-3 text-end text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers as $cust): ?>
                        <tr class="align-middle border-bottom">
                            <td class="ps-4 py-3 fw-medium" style="color: #111827;"><?php echo htmlspecialchars($cust['name']); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($cust['email']); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($cust['phone']); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($cust['tax_id'] ?: '-'); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($cust['billing_city']); ?></td>
                            <td class="pe-4 py-3 text-end">
                                <a href="?action=edit&id=<?php echo $cust['id']; ?>" class="text-decoration-none px-2 text-primary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?action=delete&id=<?php echo $cust['id']; ?>" class="text-decoration-none px-2 text-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this customer?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

    <?php if ($action === 'create' || $action === 'edit'): ?>
<style>
    .form-container {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        padding: 32px;
        margin-top: 20px;
    }
    .form-title-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }
    .form-section-header {
        color: #6366f1; /* Figma Purple Accent */
        font-size: 0.95rem;
        font-weight: 500;
        margin-bottom: 20px;
        margin-top: 10px;
    }
    .form-label {
        font-size: 0.9rem;
        color: #374151;
        font-weight: 400;
        margin-bottom: 6px;
    }
    .form-control, .form-select {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 10px 14px;
        font-size: 0.95rem;
        background-color: #fff;
        transition: border-color 0.2s;
    }
    .form-control:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }
    .btn-save {
        background-color: #6366f1;
        color: white;
        padding: 10px 24px;
        border-radius: 8px;
        font-weight: 500;
        border: none;
    }
    .btn-save:hover { background-color: #4f46e5; color: white; }
    .btn-cancel {
        background-color: #e5e7eb;
        color: #374151;
        padding: 10px 24px;
        border-radius: 8px;
        font-weight: 500;
        border: none;
        margin-left: 12px;
    }
    .row-gap { margin-bottom: 1.5rem; }
</style>

<div class="form-container">
    <div class="form-title-row">
        <h5 class="fw-bold mb-0">New Customer Form</h5>
        <a href="customers.php" class="text-dark"><i class="fas fa-times"></i></a>
    </div>

    <form method="POST">
        <?php if ($action === 'edit'): ?>
            <input type="hidden" name="id" value="<?php echo $customer['id']; ?>">
        <?php endif; ?>

        <div class="form-section-header">Basic Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Customer Name *</label>
                <input type="text" class="form-control" name="name" value="<?php echo $customer['name'] ?? ''; ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Email *</label>
                <input type="email" class="form-control" name="email" value="<?php echo $customer['email'] ?? ''; ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone *</label>
                <input type="text" class="form-control" name="phone" value="<?php echo $customer['phone'] ?? ''; ?>" required>
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Contact Person</label>
                <input type="text" class="form-control" name="contact_person" value="<?php echo $customer['contact_person'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Designation</label>
                <input type="text" class="form-control" name="contact_designation" value="<?php echo $customer['contact_designation'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Website</label>
                <input type="text" class="form-control" name="website" value="<?php echo $customer['website'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Address Information</div>
        <div class="row row-gap text-start">
            <div class="col-12">
                <label class="form-label">Address</label>
                <textarea class="form-control" name="billing_address" rows="3"><?php echo $customer['billing_address'] ?? ''; ?></textarea>
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-3">
                <label class="form-label">City</label>
                <input type="text" class="form-control" name="billing_city" value="<?php echo $customer['billing_city'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">State</label>
                <input type="text" class="form-control" name="billing_state" value="<?php echo $customer['billing_state'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">ZIP Code</label>
                <input type="text" class="form-control" name="billing_postal_code" value="<?php echo $customer['billing_postal_code'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="billing_country" value="<?php echo $customer['billing_country'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-12">
                <label class="form-label">Billing Address</label>
                <textarea class="form-control" name="billing_address" rows="3"><?php echo $customer['billing_address'] ?? ''; ?></textarea>
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-12">
                <label class="form-label">Shipping Address</label>
                <textarea class="form-control" name="shipping_address" rows="3"><?php echo $customer['shipping_address'] ?? ''; ?></textarea>
            </div>
        </div>

        <div class="form-section-header">Tax & Legal Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">GSTIN</label>
                <input type="text" class="form-control" name="tax_id" value="<?php echo $customer['tax_id'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">PAN</label>
                <input type="text" class="form-control" name="registration_number" value="<?php echo $customer['registration_number'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tax Status</label>
                <select class="form-select" name="tax_status">
                    <option selected>Select...</option>
                    <option value="registered">Registered</option>
                    <option value="unregistered">Unregistered</option>
                    <option value="composition">Composition</option>
                </select>
            </div>
        </div>

        <div class="form-section-header">Banking Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Bank Name</label>
                <input type="text" class="form-control" name="bank_name" value="<?php echo $customer['bank_name'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Account Number</label>
                <input type="text" class="form-control" name="account_number" value="<?php echo $customer['account_number'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">IFSC Code</label>
                <input type="text" class="form-control" name="ifsc_code" value="<?php echo $customer['ifsc_code'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Business Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-3">
                <label class="form-label">Industry</label>
                <input type="text" class="form-control" name="industry" value="<?php echo $customer['industry'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Customer Type</label>
                <select class="form-select" name="customer_type">
                    <option selected>Select...</option>
                    <option value="retail">Retail</option>
                    <option value="wholesale">Wholesale</option>
                    <option value="distributor">Distributor</option>
                    <option value="corporate">Corporate</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Credit Limit</label>
                <input type="number" step="0.01" class="form-control" name="credit_limit" value="<?php echo $customer['credit_limit'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Payment Terms</label>
                <input type="text" class="form-control" name="payment_terms" value="<?php echo $customer['payment_terms'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-3">
                <label class="form-label">Credit Days</label>
                <input type="number" class="form-control" name="credit_days" value="<?php echo $customer['credit_days'] ?? ''; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Discount Percent</label>
                <input type="number" step="0.01" class="form-control" name="discount_percentage" value="<?php echo $customer['discount_percentage'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Additional Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-3">
                <label class="form-label">Registration Date</label>
                <input type="date" class="form-control" name="registration_date" value="<?php echo $customer['registration_date'] ?? date('Y-m-d'); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Active Status</label>
                <select class="form-select" name="status">
                    <option value="active" <?php echo ($customer['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($customer['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Preferred Currency</label>
                <select class="form-select" name="currency">
                    <option value="INR" <?php echo ($customer['currency'] ?? 'INR') === 'INR' ? 'selected' : ''; ?>>INR</option>
                    <option value="USD" <?php echo ($customer['currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD</option>
                    <option value="EUR" <?php echo ($customer['currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR</option>
                    <option value="GBP" <?php echo ($customer['currency'] ?? '') === 'GBP' ? 'selected' : ''; ?>>GBP</option>
                    <option value="NGN" <?php echo ($customer['currency'] ?? '') === 'NGN' ? 'selected' : ''; ?>>NGN</option>
                </select>
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-12">
                <label class="form-label">Remarks</label>
                <textarea class="form-control" name="notes" rows="3"><?php echo $customer['notes'] ?? ''; ?></textarea>
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-12">
                <label class="form-label">Custom Field 30</label>
                <input type="text" class="form-control" name="custom_field" value="<?php echo $customer['custom_field'] ?? ''; ?>">
            </div>
        </div>

        <div class="mt-4 border-top pt-4">
            <button type="submit" class="btn btn-save">
                <i class="fas fa-save me-2"></i> Save Customer
            </button>
            <a href="customers.php" class="btn btn-cancel">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
