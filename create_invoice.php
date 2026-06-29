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
        $typeStmt = $conn->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'status'");
        $typeStmt->execute();
        $columnType = $typeStmt->fetchColumn();
        if ($columnType && strpos($columnType, "'verified'") === false) {
            $conn->exec("ALTER TABLE invoices MODIFY status ENUM('draft','sent','paid','cancelled','verified') DEFAULT 'draft'");
        }
        $qrColStmt = $conn->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices' AND COLUMN_NAME = 'qr_url'");
        $qrColStmt->execute();
        $qrExists = (int)$qrColStmt->fetchColumn();
        if ($qrExists === 0) {
            $conn->exec("ALTER TABLE invoices ADD COLUMN qr_url VARCHAR(255) NULL");
        }
        $conn->beginTransaction();
        
        // Generate invoice number
        $invoice_number = 'INV-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $qr_url = 'https://example.com/invoice?no=' . urlencode($invoice_number);
        
        // Insert invoice — includes the FIRS payload fields (with sensible
        // defaults) so each invoice stores everything needed to build the
        // submission. The note is encrypted at rest.
        require_once 'includes/Crypto.php';

        // ── Server-side financial computation (security: never trust client
        // price fields). The items catalogue is the authoritative source of
        // pricing; all submitted rate/amount/subtotal/tax/total values are
        // ignored and recomputed here. (Pentest finding H1.)
        $VAT_RATE = 7.5; // server-controlled standard VAT (FIRS)
        $postedItems = (isset($_POST['items']) && is_array($_POST['items'])) ? $_POST['items'] : [];
        $lineItems = [];
        $cSubtotal = 0.0;
        foreach ($postedItems as $row) {
            $itemId = (int) ($row['item_id'] ?? 0);
            $qty    = (float) ($row['quantity'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            // Authoritative price from THIS company's active catalogue only.
            $look = $conn->prepare("SELECT item_code, selling_price FROM items
                                     WHERE id = :id AND company_id = :co AND status = 'active'");
            $look->execute([':id' => $itemId, ':co' => $_SESSION['company_id']]);
            $cat = $look->fetch(PDO::FETCH_ASSOC);
            if (!$cat) {
                continue; // not a catalogue item for this company — skip
            }
            $rate   = round((float) $cat['selling_price'], 2);
            $amount = round($rate * $qty, 2);
            $cSubtotal += $amount;
            $lineItems[] = ['item_id' => $itemId, 'item_code' => $cat['item_code'],
                            'quantity' => $qty, 'rate' => $rate, 'amount' => $amount];
        }
        if (empty($lineItems)) {
            throw new Exception('At least one valid catalogue item is required.');
        }
        $cSubtotal  = round($cSubtotal, 2);
        // Discount is a legitimate business input, but bounded to [0, subtotal].
        $cDiscount  = round(max(0.0, min((float) ($_POST['discount_amount'] ?? 0), $cSubtotal)), 2);
        $cTaxRate   = $VAT_RATE;
        $cTaxExcl   = round($cSubtotal - $cDiscount, 2);
        $cTaxAmount = round($cTaxExcl * $cTaxRate / 100, 2);
        $cTaxIncl   = round($cTaxExcl + $cTaxAmount, 2);
        $cTotal     = $cTaxIncl;
        $cCurrency  = in_array($_POST['document_currency_code'] ?? 'NGN', ['NGN', 'USD', 'EUR', 'GBP'], true)
                    ? $_POST['document_currency_code'] : 'NGN';

        $query = "INSERT INTO invoices (invoice_number, date, time, customer_id, company_id, user_id,
                  due_date, subtotal, line_extension_amount, tax_rate, tax_category_id, tax_amount,
                  discount_amount, discount_rate, allowance_total_amount, allowance_charge_reason, charge_total_amount,
                  tax_exclusive_amount, tax_inclusive_amount, total_amount, payable_amount, qr_url, status,
                  invoice_type_code, payment_status, document_currency_code, tax_currency_code, tax_point_date, notes)
                  VALUES (:invoice_number, :date, :time, :customer_id, :company_id, :user_id,
                  :due_date, :subtotal, :line_extension_amount, :tax_rate, :tax_category_id, :tax_amount,
                  :discount_amount, :discount_rate, :allowance_total_amount, :allowance_charge_reason, :charge_total_amount,
                  :tax_exclusive_amount, :tax_inclusive_amount, :total_amount, :payable_amount, :qr_url, :status,
                  :invoice_type_code, :payment_status, :document_currency_code, :tax_currency_code, :tax_point_date, :notes)";

        $stmt = $conn->prepare($query);
        $stmt->execute([
            'invoice_number' => $invoice_number,
            'date' => $_POST['invoice_date'],
            'time' => date('H:i:s'),
            'customer_id' => $_POST['customer_id'],
            'company_id' => $_SESSION['company_id'],
            'user_id' => $_SESSION['user_id'],
            'due_date' => $_POST['due_date'],
            'subtotal' => $cSubtotal,
            'line_extension_amount' => $cSubtotal,
            'tax_rate' => $cTaxRate,
            'tax_category_id' => 'STANDARD_VAT',
            'tax_amount' => $cTaxAmount,
            'discount_amount' => $cDiscount,
            // Derive the discount rate from the amount so both columns are stored.
            'discount_rate' => ($cSubtotal > 0) ? round(($cDiscount / $cSubtotal) * 100, 2) : 0,
            'allowance_total_amount' => $cDiscount,
            'allowance_charge_reason' => $cDiscount > 0 ? 'Discount' : null,
            'charge_total_amount' => 0,
            'tax_exclusive_amount' => $cTaxExcl,
            'tax_inclusive_amount' => $cTaxIncl,
            'total_amount' => $cTotal,
            'payable_amount' => $cTotal,
            'qr_url' => $qr_url,
            // New invoices are drafts until they are actually verified by FIRS
            // (FirsService promotes them to 'verified' on a successful transmit).
            'status' => 'draft',
            'invoice_type_code' => $_POST['invoice_type_code'] ?? '381',
            'payment_status' => $_POST['payment_status'] ?? 'PENDING',
            'document_currency_code' => $cCurrency,
            'tax_currency_code' => $cCurrency,
            'tax_point_date' => !empty($_POST['tax_point_date']) ? $_POST['tax_point_date'] : null,
            'notes' => !empty($_POST['notes']) ? Crypto::encrypt($_POST['notes']) : null,
        ]);
        
        $invoice_id = $conn->lastInsertId();
        
        // Insert invoice items using the server-computed line items (rate/amount
        // come from the catalogue, not the client). (Pentest finding H1.)
        foreach ($lineItems as $item) {
            $query = "INSERT INTO invoice_items (invoice_id, item_id, item_code, quantity, rate, amount)
                      VALUES (:invoice_id, :item_id, :item_code, :quantity, :rate, :amount)";
            $stmt = $conn->prepare($query);
            $stmt->execute([
                'invoice_id' => $invoice_id,
                'item_id' => $item['item_id'],
                'item_code' => $item['item_code'],
                'quantity' => $item['quantity'],
                'rate' => $item['rate'],
                'amount' => $item['amount'],
            ]);
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
$query = "SELECT id, name, tax_id FROM customers WHERE company_id = :company_id AND status = 'active' ORDER BY name";
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
    <button type="submit" form="invoiceForm" class="btn btn-primary" id="topSubmitBtn">
        <i class="fas fa-save"></i> Create Invoice
    </button>
</div>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>


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
                                            <?php if ($customer['tax_id']): ?>
                                                (TIN: <?php echo htmlspecialchars($customer['tax_id']); ?>)
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
                                <label class="form-label">Status</label>
                                <input type="text" class="form-control" value="Auto" disabled>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3"><i class="fas fa-file-invoice"></i> FIRS Details</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Invoice Type</label>
                                <select class="form-select" name="invoice_type_code">
                                    <option value="381" selected>381 — Commercial Invoice</option>
                                    <option value="380">380 — Credit Note</option>
                                    <option value="384">384 — Debit Note</option>
                                    <option value="385">385 — Self Billed Invoice</option>
                                    <option value="390">390 — Proforma Invoice</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Currency</label>
                                <select class="form-select" name="document_currency_code">
                                    <option value="NGN" selected>NGN — Nigerian Naira</option>
                                    <option value="USD">USD — US Dollar</option>
                                    <option value="EUR">EUR — Euro</option>
                                    <option value="GBP">GBP — British Pound</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Payment Status</label>
                                <select class="form-select" name="payment_status">
                                    <option value="PENDING" selected>PENDING</option>
                                    <option value="PAID">PAID</option>
                                    <option value="PARTIALLY_PAID">PARTIALLY_PAID</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Tax Point Date <small class="text-muted">(optional)</small></label>
                                <input type="date" class="form-control" name="tax_point_date">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label">Note <small class="text-muted">(optional — stored encrypted)</small></label>
                                <input type="text" class="form-control" name="notes" placeholder="Internal/FIRS note">
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
                            Invoice will be saved as Verified
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
let itemCount = 0;
// JSON_HEX_* escapes angle brackets/ampersands/quotes so item data can never
// break out of this script block. (Pentest finding H2.)
const items = <?php echo json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS); ?>;

function addItem() {
    itemCount++;
    const container = document.getElementById('items-container');
    const noItemsMessage = document.getElementById('no-items-message');
    
    // Hide the no items message
    if (noItemsMessage) {
        noItemsMessage.style.display = 'none';
    }
    
    // Static markup only — the only interpolated value is itemCount (a number),
    // so no user-controlled data is parsed as HTML here. The <select> is left
    // empty and its options are added via the DOM API below. (Pentest finding H2.)
    const itemHtml = `
        <div class="row mb-3 item-row border-bottom pb-3" id="item-${itemCount}">
            <div class="col-12 col-md-4 mb-2 mb-md-0">
                <label class="form-label small">Item</label>
                <select class="form-select" name="items[${itemCount}][item_id]" id="item_select_${itemCount}" onchange="updateItemDetails(${itemCount})" required>
                    <option value="">Select Item</option>
                </select>
                <input type="hidden" name="items[${itemCount}][item_code]" id="item_code_${itemCount}">
            </div>
            <div class="col-6 col-md-2 mb-2 mb-md-0">
                <label class="form-label small">Quantity</label>
                <input type="number" class="form-control" name="items[${itemCount}][quantity]"
                       placeholder="0" step="1" min="1" onchange="calculateItemAmount(${itemCount})" required>
            </div>
            <div class="col-6 col-md-2 mb-2 mb-md-0">
                <label class="form-label small">Rate</label>
                <input type="number" class="form-control" name="items[${itemCount}][rate]"
                       placeholder="0.00" step="0.01" readonly>
            </div>
            <div class="col-6 col-md-2 mb-2 mb-md-0">
                <label class="form-label small">Amount</label>
                <input type="number" class="form-control" name="items[${itemCount}][amount]"
                       placeholder="0.00" step="0.01" readonly>
            </div>
            <div class="col-6 col-md-2 pt-0 pt-md-0">
                <label class="form-label small d-none d-md-block">&nbsp;</label>
                <label class="form-label small d-block d-md-none text-danger">Action</label>
                <button type="button" class="btn btn-danger btn-sm d-block w-100 w-md-auto" onclick="removeItem(${itemCount})" title="Remove Item">
                    <i class="fas fa-trash"></i> Remove
                </button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', itemHtml);

    // Build the <select> options with the DOM API so item names/codes are set
    // as TEXT (textContent) and attributes (setAttribute) — never parsed as
    // HTML. This neutralises any markup stored in an item name. (Finding H2.)
    const select = document.getElementById(`item_select_${itemCount}`);
    items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.id;
        opt.setAttribute('data-code', item.item_code || '');
        opt.setAttribute('data-price', item.selling_price);
        opt.setAttribute('data-hsn', item.hsn_code || '');
        opt.textContent = item.name + (item.item_code ? ' (' + item.item_code + ')' : '');
        select.appendChild(opt);
    });
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

    const topSubmitBtn = document.getElementById('topSubmitBtn');
    if (topSubmitBtn) {
        topSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Invoice...';
        topSubmitBtn.disabled = true;
    }
});
</script>

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
