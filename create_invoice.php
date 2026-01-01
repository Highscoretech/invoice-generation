<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$auth = new Auth();
$auth->requireRole('accountant');

$database = new Database();
$conn = $database->getConnection();

$message = '';
$error = '';

// Handle form submission
if ($_POST && $_POST['action'] === 'create_invoice') {
    try {
        $conn->beginTransaction();
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert invoice
        $query = "INSERT INTO invoices (invoice_number, date, time, customer_id, company_id, user_id, 
                  due_date, subtotal, tax_rate, tax_amount, discount_amount, total_amount, status) 
                  VALUES (:invoice_number, :date, :time, :customer_id, :company_id, :user_id, 
                  :due_date, :subtotal, :tax_rate, :tax_amount, :discount_amount, :total_amount, :status)";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([
            'invoice_number' => $invoice_number,
            'date' => $_POST['invoice_date'],
            'time' => date('H:i:s'),
            'customer_id' => $_POST['customer_id'],
            'company_id' => $_SESSION['company_id'],
            'user_id' => $_SESSION['user_id'],
            'due_date' => $_POST['due_date'],
            'subtotal' => $_POST['subtotal'],
            'tax_rate' => $_POST['tax_rate'],
            'tax_amount' => $_POST['tax_amount'],
            'discount_amount' => $_POST['discount_amount'] ?? 0,
            'total_amount' => $_POST['total_amount'],
            'status' => $_POST['status'] ?? 'draft'
        ]);
        
        $invoice_id = $conn->lastInsertId();
        
        // Insert invoice items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['item_id']) && !empty($item['quantity']) && !empty($item['rate'])) {
                    $query = "INSERT INTO invoice_items (invoice_id, item_id, item_code, quantity, rate, amount) 
                              VALUES (:invoice_id, :item_id, :item_code, :quantity, :rate, :amount)";
                    
                    $stmt = $conn->prepare($query);
                    $stmt->execute([
                        'invoice_id' => $invoice_id,
                        'item_id' => $item['item_id'],
                        'item_code' => $item['item_code'],
                        'quantity' => $item['quantity'],
                        'rate' => $item['rate'],
                        'amount' => $item['amount']
                    ]);
                }
            }
        }
        
        $conn->commit();
        $message = 'Invoice created successfully! Invoice Number: ' . $invoice_number;
        
        // Redirect to view invoice
        header('Location: view_invoice.php?id=' . $invoice_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error creating invoice: ' . $e->getMessage();
    }
}

// Get customers and items for dropdowns
$query = "SELECT id, name, tin FROM customers WHERE company_id = :company_id AND status = 'active' ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->bindParam(':company_id', $_SESSION['company_id']);
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT id, item_code, name, hsn_code, selling_price, tax_rate FROM items WHERE company_id = :company_id AND status = 'active' ORDER BY name";
$stmt = $conn->prepare($query);
$stmt->bindParam(':company_id', $_SESSION['company_id']);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Create Invoice';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2">Create New Invoice</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a href="my_invoices.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Back to My Invoices
        </a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if (empty($customers) || empty($items)): ?>
    <div class="alert alert-warning">
        <h5>Setup Required</h5>
        <p>Before creating invoices, please ensure you have:</p>
        <ul>
            <?php if (empty($customers)): ?>
                <li>At least one customer added</li>
            <?php endif; ?>
            <?php if (empty($items)): ?>
                <li>At least one item/service added</li>
            <?php endif; ?>
        </ul>
        <p>Please contact your administrator to add customers and items.</p>
    </div>
<?php else: ?>

<form method="POST" id="invoiceForm">
    <input type="hidden" name="action" value="create_invoice">
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5>Invoice Details</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="customer_id" class="form-label">Customer *</label>
                                <select class="form-select" id="customer_id" name="customer_id" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?php echo $customer['id']; ?>">
                                            <?php echo htmlspecialchars($customer['name']); ?>
                                            <?php if ($customer['tin']): ?>
                                                (TIN: <?php echo htmlspecialchars($customer['tin']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="invoice_date" class="form-label">Invoice Date *</label>
                                <input type="date" class="form-control" id="invoice_date" name="invoice_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="due_date" class="form-label">Due Date</label>
                                <input type="date" class="form-control" id="due_date" name="due_date" 
                                       value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="draft">Draft</option>
                                    <option value="sent">Sent</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5>Invoice Items</h5>
                    <button type="button" class="btn btn-sm btn-primary" onclick="addItem()">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                </div>
                <div class="card-body">
                    <div id="items-container">
                        <!-- Items will be added here dynamically -->
                    </div>
                    
                    <div class="text-center mt-3" id="no-items-message">
                        <p class="text-muted">Click "Add Item" to start adding items to your invoice</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5>Invoice Summary</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="subtotal" class="form-label">Subtotal</label>
                        <input type="number" class="form-control" id="subtotal" name="subtotal" step="0.01" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="discount_amount" class="form-label">Discount Amount</label>
                        <input type="number" class="form-control" id="discount_amount" name="discount_amount" 
                               step="0.01" value="0" onchange="calculateTotal()">
                    </div>
                    
                    <div class="mb-3">
                        <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                        <input type="number" class="form-control" id="tax_rate" name="tax_rate" 
                               step="0.01" value="18" onchange="calculateTotal()">
                    </div>
                    
                    <div class="mb-3">
                        <label for="tax_amount" class="form-label">Tax Amount</label>
                        <input type="number" class="form-control" id="tax_amount" name="tax_amount" step="0.01" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label for="total_amount" class="form-label"><strong>Total Amount</strong></label>
                        <input type="number" class="form-control fw-bold" id="total_amount" name="total_amount" step="0.01" readonly>
                    </div>
                    
                    <button type="submit" class="btn btn-success w-100" id="submitBtn">
                        <i class="fas fa-save"></i> Create Invoice
                    </button>
                    
                    <div class="mt-2">
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> 
                            Invoice will be saved as draft by default
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
let itemCount = 0;
const items = <?php echo json_encode($items); ?>;

function addItem() {
    itemCount++;
    const container = document.getElementById('items-container');
    const noItemsMessage = document.getElementById('no-items-message');
    
    // Hide the no items message
    if (noItemsMessage) {
        noItemsMessage.style.display = 'none';
    }
    
    const itemHtml = `
        <div class="row mb-3 item-row border-bottom pb-3" id="item-${itemCount}">
            <div class="col-md-4">
                <label class="form-label small">Item</label>
                <select class="form-select" name="items[${itemCount}][item_id]" onchange="updateItemDetails(${itemCount})" required>
                    <option value="">Select Item</option>
                    ${items.map(item => `<option value="${item.id}" data-code="${item.item_code || ''}" data-price="${item.selling_price}" data-hsn="${item.hsn_code || ''}">${item.name} ${item.item_code ? '(' + item.item_code + ')' : ''}</option>`).join('')}
                </select>
                <input type="hidden" name="items[${itemCount}][item_code]" id="item_code_${itemCount}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Quantity</label>
                <input type="number" class="form-control" name="items[${itemCount}][quantity]" 
                       placeholder="0.00" step="0.01" onchange="calculateItemAmount(${itemCount})" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Rate</label>
                <input type="number" class="form-control" name="items[${itemCount}][rate]" 
                       placeholder="0.00" step="0.01" onchange="calculateItemAmount(${itemCount})" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Amount</label>
                <input type="number" class="form-control" name="items[${itemCount}][amount]" 
                       placeholder="0.00" step="0.01" readonly>
            </div>
            <div class="col-md-2">
                <label class="form-label small">&nbsp;</label>
                <button type="button" class="btn btn-danger btn-sm d-block" onclick="removeItem(${itemCount})" title="Remove Item">
                    <i class="fas fa-trash"></i> Remove
                </button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', itemHtml);
}

function updateItemDetails(itemIndex) {
    const select = document.querySelector(`select[name="items[${itemIndex}][item_id]"]`);
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        document.getElementById(`item_code_${itemIndex}`).value = selectedOption.dataset.code;
        document.querySelector(`input[name="items[${itemIndex}][rate]"]`).value = selectedOption.dataset.price;
        calculateItemAmount(itemIndex);
    }
}

function calculateItemAmount(itemIndex) {
    const quantity = parseFloat(document.querySelector(`input[name="items[${itemIndex}][quantity]"]`).value) || 0;
    const rate = parseFloat(document.querySelector(`input[name="items[${itemIndex}][rate]"]`).value) || 0;
    const amount = quantity * rate;
    
    document.querySelector(`input[name="items[${itemIndex}][amount]"]`).value = amount.toFixed(2);
    calculateTotal();
}

function removeItem(itemIndex) {
    document.getElementById(`item-${itemIndex}`).remove();
    calculateTotal();
    
    // Show no items message if no items remain
    const remainingItems = document.querySelectorAll('.item-row');
    const noItemsMessage = document.getElementById('no-items-message');
    if (remainingItems.length === 0 && noItemsMessage) {
        noItemsMessage.style.display = 'block';
    }
}

function calculateTotal() {
    let subtotal = 0;
    document.querySelectorAll('input[name*="[amount]"]').forEach(input => {
        subtotal += parseFloat(input.value) || 0;
    });
    
    const discountAmount = parseFloat(document.getElementById('discount_amount').value) || 0;
    const taxRate = parseFloat(document.getElementById('tax_rate').value) || 0;
    
    const taxableAmount = subtotal - discountAmount;
    const taxAmount = (taxableAmount * taxRate) / 100;
    const totalAmount = taxableAmount + taxAmount;
    
    document.getElementById('subtotal').value = subtotal.toFixed(2);
    document.getElementById('tax_amount').value = taxAmount.toFixed(2);
    document.getElementById('total_amount').value = totalAmount.toFixed(2);
}

// Add first item on page load
document.addEventListener('DOMContentLoaded', function() {
    addItem();
});

// Form validation
document.getElementById('invoiceForm').addEventListener('submit', function(e) {
    const items = document.querySelectorAll('.item-row');
    if (items.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the invoice.');
        return false;
    }
    
    const totalAmount = parseFloat(document.getElementById('total_amount').value) || 0;
    if (totalAmount <= 0) {
        e.preventDefault();
        alert('Invoice total must be greater than zero.');
        return false;
    }
    
    // Show loading state
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Invoice...';
    submitBtn.disabled = true;
});
</script>

<?php endif; ?>

<style>
.item-row {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
}

.item-row:hover {
    background-color: #e9ecef;
}

.form-label.small {
    font-size: 0.875rem;
    font-weight: 600;
    color: #495057;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border: 1px solid rgba(0, 0, 0, 0.125);
}

.btn-success {
    background-color: #198754;
    border-color: #198754;
}

.btn-success:hover {
    background-color: #157347;
    border-color: #146c43;
}

#items-container:empty + #no-items-message {
    display: block !important;
}

.invoice-summary {
    position: sticky;
    top: 20px;
}

@media (max-width: 768px) {
    .invoice-summary {
        position: static;
        margin-top: 20px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>