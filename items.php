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
    $query = "DELETE FROM items WHERE id = :id AND company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    if ($stmt->execute()) {
        header('Location: items.php');
        exit;
    } else {
        $message = 'Error deleting item.';
    }
}

// Handle form submissions
if ($_POST) {
    if ($action === 'create' || $action === 'edit') {
        $data = [
            'item_code' => $_POST['item_code'],
            'name' => $_POST['name'],
            'description' => $_POST['description'],
            'sku' => $_POST['sku'],
            'barcode' => $_POST['barcode'],
            'category' => $_POST['category'],
            'subcategory' => $_POST['subcategory'],
            'brand' => $_POST['brand'],
            'model' => $_POST['model'],
            'color' => $_POST['color'],
            'size' => $_POST['size'],
            'weight' => $_POST['weight'],
            'dimensions' => $_POST['dimensions'],
            'unit' => $_POST['unit'],
            'cost_price' => $_POST['cost_price'],
            'selling_price' => $_POST['selling_price'],
            'mrp' => $_POST['mrp'],
            'discount_percentage' => $_POST['discount_percentage'],
            'minimum_stock' => $_POST['minimum_stock'],
            'current_stock' => $_POST['current_stock'] ?? 0,
            'reorder_level' => $_POST['reorder_level'],
            'supplier' => $_POST['supplier'],
            'warranty_period' => $_POST['warranty_period'],
            'hsn_code' => $_POST['hsn_code'],
            'status' => $_POST['status']
        ];
        
        if ($action === 'create') {
            $data['company_id'] = $_SESSION['company_id'];
            
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            
            $query = "INSERT INTO items ($fields) VALUES ($placeholders)";
            $stmt = $conn->prepare($query);
            
            if ($stmt->execute($data)) {
                header('Location: items.php');
                exit;
            } else {
                $message = 'Error creating item.';
            }
        } elseif ($action === 'edit') {
            $id = $_POST['id'];
            $set_clause = implode(', ', array_map(fn($key) => "$key = :$key", array_keys($data)));
            
            $query = "UPDATE items SET $set_clause WHERE id = :id AND company_id = :company_id";
            $stmt = $conn->prepare($query);
            $data['id'] = $id;
            $data['company_id'] = $_SESSION['company_id'];
            
            if ($stmt->execute($data)) {
                header('Location: items.php');
                exit;
            } else {
                $message = 'Error updating item.';
            }
        }
    }
}

// Get item for editing
$item = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $query = "SELECT * FROM items WHERE id = :id AND company_id = :company_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $_GET['id']);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get items list
$items = [];
if ($action === 'list') {
    $query = "SELECT * FROM items WHERE company_id = :company_id ORDER BY name";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':company_id', $_SESSION['company_id']);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Items';
include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success border-0 shadow-sm mb-4"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($action === 'list'): ?>
    <div class="page-header mb-4">
        <h4 class="fw-bold mb-3">Items & Rates</h4>
        <a href="?action=create" class="btn btn-primary px-4 py-2" style="background-color: #6366f1; border: none; border-radius: 8px;">
            <i class="fas fa-plus me-2"></i> Add New Item
        </a>
    </div>

    <?php if (!empty($items)): ?>
        <div class="content-card shadow-sm border-0 rounded-4 overflow-hidden bg-white">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr style="background-color: #f9fafb;">
                            <th class="ps-4 py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Name</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Item Code</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Category</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Selling Rate</th>
                            <th class="py-3 text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Stock</th>
                            <th class="pe-4 py-3 text-end text-uppercase small fw-bold text-secondary" style="font-size: 0.7rem; letter-spacing: 0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $itm): ?>
                        <tr class="align-middle border-bottom">
                            <td class="ps-4 py-3 fw-medium" style="color: #111827;"><?php echo htmlspecialchars($itm['name']); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($itm['sku'] ?: '-'); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo htmlspecialchars($itm['category'] ?: '-'); ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo $itm['selling_price'] ? '$' . number_format($itm['selling_price'], 2) : '-'; ?></td>
                            <td class="py-3 text-muted" style="font-size: 0.85rem;"><?php echo $itm['current_stock'] ?? 0; ?></td>
                            <td class="pe-4 py-3 text-end">
                                <a href="?action=edit&id=<?php echo $itm['id']; ?>" class="text-decoration-none px-2 text-primary" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="?action=delete&id=<?php echo $itm['id']; ?>" class="text-decoration-none px-2 text-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this item?')">
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
        <h5 class="fw-bold mb-0">New Item Form</h5>
        <a href="items.php" class="text-dark"><i class="fas fa-times"></i></a>
    </div>

    <form method="POST">
        <?php if ($action === 'edit'): ?>
            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
        <?php endif; ?>

        <div class="form-section-header">Basic Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Item Name *</label>
                <input type="text" class="form-control" name="name" value="<?php echo $item['name'] ?? ''; ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Item Code *</label>
                <input type="text" class="form-control" name="item_code" value="<?php echo $item['item_code'] ?? ''; ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">SKU</label>
                <input type="text" class="form-control" name="sku" value="<?php echo $item['sku'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Category</label>
                <input type="text" class="form-control" name="category" value="<?php echo $item['category'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Subcategory</label>
                <input type="text" class="form-control" name="subcategory" value="<?php echo $item['subcategory'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Unit</label>
                <select class="form-select" name="unit">
                    <option selected>Select...</option>
                    <option value="PCS" <?php echo ($item['unit'] ?? '') === 'PCS' ? 'selected' : ''; ?>>PCS</option>
                    <option value="KG" <?php echo ($item['unit'] ?? '') === 'KG' ? 'selected' : ''; ?>>KG</option>
                    <option value="METER" <?php echo ($item['unit'] ?? '') === 'METER' ? 'selected' : ''; ?>>METER</option>
                    <option value="LITER" <?php echo ($item['unit'] ?? '') === 'LITER' ? 'selected' : ''; ?>>LITER</option>
                    <option value="BOX" <?php echo ($item['unit'] ?? '') === 'BOX' ? 'selected' : ''; ?>>BOX</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Barcode</label>
                <input type="text" class="form-control" name="barcode" value="<?php echo $item['barcode'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea class="form-control" name="description" rows="3"><?php echo $item['description'] ?? ''; ?></textarea>
            </div>
        </div>

        <div class="form-section-header">Tax Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">HSN Code</label>
                <input type="text" class="form-control" name="hsn_code" value="<?php echo $item['hsn_code'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">SAC Code</label>
                <input type="text" class="form-control" name="sac_code" value="<?php echo $item['sac_code'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Tax Category</label>
                <select class="form-select" name="tax_category">
                    <option selected>Select...</option>
                    <option value="GST 5%">GST 5%</option>
                    <option value="GST 12%">GST 12%</option>
                    <option value="GST 18%">GST 18%</option>
                    <option value="GST 28%">GST 28%</option>
                    <option value="Exempt">Exempt</option>
                </select>
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">CESS</label>
                <input type="number" step="0.01" class="form-control" name="cess" value="<?php echo $item['cess'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Pricing Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Base Rate</label>
                <input type="number" step="0.01" class="form-control" name="cost_price" value="<?php echo $item['cost_price'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Selling Rate *</label>
                <input type="number" step="0.01" class="form-control" name="selling_price" value="<?php echo $item['selling_price'] ?? ''; ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Purchase Rate</label>
                <input type="number" step="0.01" class="form-control" name="purchase_rate" value="<?php echo $item['purchase_rate'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">MRP</label>
                <input type="number" step="0.01" class="form-control" name="mrp" value="<?php echo $item['mrp'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Discount %</label>
                <input type="number" step="0.01" class="form-control" name="discount_percentage" value="<?php echo $item['discount_percentage'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Stock Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Minimum Stock</label>
                <input type="number" class="form-control" name="minimum_stock" value="<?php echo $item['minimum_stock'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Reorder Level</label>
                <input type="number" class="form-control" name="reorder_level" value="<?php echo $item['reorder_level'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Current Stock</label>
                <input type="number" class="form-control" name="current_stock" value="<?php echo $item['current_stock'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Product Details</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Manufacturer</label>
                <input type="text" class="form-control" name="supplier" value="<?php echo $item['supplier'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Brand</label>
                <input type="text" class="form-control" name="brand" value="<?php echo $item['brand'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Model</label>
                <input type="text" class="form-control" name="model" value="<?php echo $item['model'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Weight</label>
                <input type="number" step="0.001" class="form-control" name="weight" value="<?php echo $item['weight'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Dimensions</label>
                <input type="text" class="form-control" name="dimensions" value="<?php echo $item['dimensions'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Color</label>
                <input type="text" class="form-control" name="color" value="<?php echo $item['color'] ?? ''; ?>">
            </div>
        </div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Size</label>
                <input type="text" class="form-control" name="size" value="<?php echo $item['size'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Material</label>
                <input type="text" class="form-control" name="material" value="<?php echo $item['material'] ?? ''; ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Warranty Period</label>
                <input type="text" class="form-control" name="warranty_period" value="<?php echo $item['warranty_period'] ?? ''; ?>">
            </div>
        </div>

        <div class="form-section-header">Additional Information</div>
        <div class="row row-gap text-start">
            <div class="col-md-4">
                <label class="form-label">Active Status</label>
                <select class="form-select" name="status">
                    <option value="active" <?php echo ($item['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($item['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Custom Field 30</label>
                <input type="text" class="form-control" name="custom_field" value="<?php echo $item['custom_field'] ?? ''; ?>">
            </div>
        </div>

        <div class="mt-4 border-top pt-4">
            <button type="submit" class="btn btn-save">
                <i class="fas fa-save me-2"></i> Save Item
            </button>
            <a href="items.php" class="btn btn-cancel">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>