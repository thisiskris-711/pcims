<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

$page_title = 'Purchase Orders';
$action = $_GET['action'] ?? 'list';
$po_id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: purchase_orders.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($action === 'add') {
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Generate PO number
                $po_number = 'PO-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Create purchase order
                $query = "INSERT INTO purchase_orders (po_number, supplier_id, order_date, expected_date, status, total_amount, notes, created_by) 
                          VALUES (:po_number, :supplier_id, :order_date, :expected_date, :status, :total_amount, :notes, :created_by)";
                
                $stmt = $db->prepare($query);
                
                $stmt->bindParam(':po_number', $po_number);
                $stmt->bindParam(':supplier_id', $_POST['supplier_id']);
                $stmt->bindParam(':order_date', $_POST['order_date']);
                $stmt->bindParam(':expected_date', $_POST['expected_date']);
                $stmt->bindParam(':status', $_POST['status']);
                $stmt->bindParam(':total_amount', $_POST['total_amount']);
                $stmt->bindParam(':notes', $_POST['notes']);
                $stmt->bindParam(':created_by', $_SESSION['user_id']);
                
                $stmt->execute();
                
                $po_id = $db->lastInsertId();
                
                // Add purchase order items
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    foreach ($_POST['items'] as $item) {
                        if (!empty($item['product_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                            $query = "INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, unit_price) 
                                      VALUES (:po_id, :product_id, :quantity_ordered, :unit_price)";
                            
                            $stmt = $db->prepare($query);
                            
                            $stmt->bindParam(':po_id', $po_id);
                            $stmt->bindParam(':product_id', $item['product_id']);
                            $stmt->bindParam(':quantity_ordered', $item['quantity']);
                            $stmt->bindParam(':unit_price', $item['unit_price']);
                            
                            $stmt->execute();
                        }
                    }
                }
                
                $db->commit();
                
                // Log activity
                log_activity($_SESSION['user_id'], 'po_add', 'Created purchase order: ' . $po_number);
                
                $_SESSION['success'] = 'Purchase order created successfully!';
                header('Location: purchase_orders.php');
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
        } elseif ($action === 'edit' && $po_id) {
            // Update purchase order
            $query = "UPDATE purchase_orders SET supplier_id = :supplier_id, order_date = :order_date, 
                      expected_date = :expected_date, status = :status, total_amount = :total_amount, 
                      notes = :notes WHERE po_id = :po_id";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(':supplier_id', $_POST['supplier_id']);
            $stmt->bindParam(':order_date', $_POST['order_date']);
            $stmt->bindParam(':expected_date', $_POST['expected_date']);
            $stmt->bindParam(':status', $_POST['status']);
            $stmt->bindParam(':total_amount', $_POST['total_amount']);
            $stmt->bindParam(':notes', $_POST['notes']);
            $stmt->bindParam(':po_id', $po_id);
            
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'po_edit', 'Updated purchase order ID: ' . $po_id);
            
            $_SESSION['success'] = 'Purchase order updated successfully!';
            header('Location: purchase_orders.php');
            exit();
            
        } elseif ($action === 'delete' && $po_id) {
            // Delete purchase order (soft delete by setting status to cancelled)
            $query = "UPDATE purchase_orders SET status = 'cancelled' WHERE po_id = :po_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':po_id', $po_id);
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'po_delete', 'Cancelled purchase order ID: ' . $po_id);
            
            $_SESSION['success'] = 'Purchase order cancelled successfully!';
            header('Location: purchase_orders.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Purchase Orders Error: " . $exception->getMessage());
    }
}

// Get purchase order data for editing
$purchase_order = null;
if ($action === 'edit' && $po_id) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT po.*, s.supplier_name FROM purchase_orders po 
                  LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id 
                  WHERE po.po_id = :po_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':po_id', $po_id);
        $stmt->execute();
        
        $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$purchase_order) {
            $_SESSION['error'] = 'Purchase order not found.';
            header('Location: purchase_orders.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: purchase_orders.php');
        exit();
    }
}

if ($action === 'list') {
    // Get purchase orders list
    $purchase_orders = [];
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $supplier_filter = $_GET['supplier'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT po.*, s.supplier_name, u.full_name as created_by_name,
                         (SELECT COUNT(*) FROM purchase_order_items WHERE po_id = po.po_id) as item_count
                  FROM purchase_orders po 
                  LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id 
                  LEFT JOIN users u ON po.created_by = u.user_id 
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (po.po_number LIKE :search OR s.supplier_name LIKE :search)";
            $search_param = "%$search%";
            $params[':search'] = $search_param;
        }
        
        if (!empty($status_filter)) {
            $query .= " AND po.status = :status";
            $params[':status'] = $status_filter;
        }
        
        if (!empty($supplier_filter)) {
            $query .= " AND po.supplier_id = :supplier_id";
            $params[':supplier_id'] = $supplier_filter;
        }
        
        $query .= " ORDER BY po.created_at DESC";
        
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get suppliers for filter
        $query = "SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Purchase Orders List Error: " . $exception->getMessage());
    }
    
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-shopping-cart me-2"></i>Purchase Orders
            </h1>
            <a href="purchase_orders.php?action=add" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>New Purchase Order
            </a>
        </div>
        
        <!-- PO Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Orders</h5>
                        <h2><?php echo count($purchase_orders); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">Pending</h5>
                        <h2>
                            <?php 
                            $pending = array_filter($purchase_orders, function($po) { return $po['status'] === 'draft' || $po['status'] === 'sent'; });
                            echo count($pending);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Received</h5>
                        <h2>
                            <?php 
                            $received = array_filter($purchase_orders, function($po) { return $po['status'] === 'received'; });
                            echo count($received);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Value</h5>
                        <h2>
                            <?php 
                            $total = array_sum(array_column($purchase_orders, 'total_amount'));
                            echo format_currency($total);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="search" placeholder="Search orders..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="sent" <?php echo $status_filter === 'sent' ? 'selected' : ''; ?>>Sent</option>
                            <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>Received</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="supplier">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['supplier_id']; ?>" <?php echo $supplier_filter == $supplier['supplier_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                            <i class="fas fa-times me-2"></i>Clear
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Purchase Orders Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="poTable">
                        <thead>
                            <tr>
                                <th>PO Number</th>
                                <th>Supplier</th>
                                <th>Order Date</th>
                                <th>Expected Date</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purchase_orders)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                                        <p>No purchase orders found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($purchase_orders as $po): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                                            <br><small class="text-muted">ID: <?php echo $po['po_id']; ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($po['supplier_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td>
                                            <?php echo format_date($po['order_date'], 'M d, Y'); ?>
                                        </td>
                                        <td>
                                            <?php echo $po['expected_date'] ? format_date($po['expected_date'], 'M d, Y') : 'Not set'; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $po['item_count']; ?></span>
                                            <br><small class="text-muted">items</small>
                                        </td>
                                        <td>
                                            <strong><?php echo format_currency($po['total_amount']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $po['status'] === 'draft' ? 'secondary' : 
                                                     ($po['status'] === 'sent' ? 'primary' : 
                                                     ($po['status'] === 'partial' ? 'warning' : 
                                                     ($po['status'] === 'received' ? 'success' : 'danger'))); 
                                            ?>">
                                                <?php echo ucfirst($po['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($po['created_by_name']); ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="purchase_orders.php?action=view&id=<?php echo $po['po_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($po['status'] === 'draft'): ?>
                                                <a href="purchase_orders.php?action=edit&id=<?php echo $po['po_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if (has_permission('admin') && $po['status'] === 'draft'): ?>
                                                <button onclick="return confirmDelete('Are you sure you want to cancel this purchase order?')" 
                                                        class="btn btn-sm btn-outline-danger" title="Cancel">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function clearFilters() {
        // Reset all filter inputs
        document.querySelector('input[name="search"]').value = '';
        document.querySelector('select[name="status"]').value = '';
        document.querySelector('select[name="supplier"]').value = '';
        
        // Submit the form to reload with cleared filters
        window.location.href = 'purchase_orders.php';
    }
    </script>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif ($action === 'add') {
    include 'includes/header.php';
    
    // Get suppliers and products for dropdowns
    $suppliers = [];
    $products = [];
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT supplier_id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $query = "SELECT product_id, product_name, product_code, unit_price FROM products WHERE status = 'active' ORDER BY product_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        error_log("Suppliers/Products Error: " . $exception->getMessage());
    }
    ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-shopping-cart me-2"></i>New Purchase Order
            </h1>
            <a href="purchase_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Purchase Orders
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" id="poForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="total_amount" id="total_amount" value="0">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="supplier_id" class="form-label">Supplier *</label>
                                <select class="form-select" id="supplier_id" name="supplier_id" required>
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['supplier_id']; ?>">
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="order_date" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="order_date" name="order_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="expected_date" class="form-label">Expected Delivery Date</label>
                                <input type="date" class="form-control" id="expected_date" name="expected_date">
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
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PO Items -->
                    <div class="card mt-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Order Items</h5>
                            <button type="button" class="btn btn-sm btn-success" onclick="addItem()">
                                <i class="fas fa-plus me-1"></i>Add Item
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="itemsContainer">
                                <div class="row align-items-center mb-2">
                                    <div class="col-md-5">
                                        <label class="form-label">Product</label>
                                        <select class="form-select" name="items[0][product_id]" required>
                                            <option value="">Select Product</option>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?php echo $product['product_id']; ?>" 
                                                        data-price="<?php echo $product['unit_price']; ?>">
                                                    <?php echo htmlspecialchars($product['product_name']); ?> 
                                                    (<?php echo htmlspecialchars($product['product_code']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Quantity</label>
                                        <input type="number" class="form-control" name="items[0][quantity]" min="1" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Unit Price</label>
                                        <input type="number" class="form-control" name="items[0][unit_price]" step="0.01" min="0" required>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">Total</label>
                                        <input type="text" class="form-control" readonly value="0.00">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="btn btn-outline-danger w-100" onclick="removeItem(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-9">
                                    <div class="d-flex justify-content-end">
                                        <h5>Total Amount: <span id="grandTotal">₱0.00</span></h5>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="purchase_orders.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Create Purchase Order
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    let itemCount = 1;
    
    function addItem() {
        const container = document.getElementById('itemsContainer');
        const newRow = document.createElement('div');
        newRow.className = 'row align-items-center mb-2';
        newRow.innerHTML = `
            <div class="col-md-5">
                <label class="form-label">Product</label>
                <select class="form-select" name="items[${itemCount}][product_id]" required>
                    <option value="">Select Product</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['product_id']; ?>" 
                                data-price="<?php echo $product['unit_price']; ?>">
                            <?php echo htmlspecialchars($product['product_name']); ?> 
                            (<?php echo htmlspecialchars($product['product_code']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="items[${itemCount}][quantity]" min="1" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Unit Price</label>
                <input type="number" class="form-control" name="items[${itemCount}][unit_price]" step="0.01" min="0" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Total</label>
                <input type="text" class="form-control" readonly value="0.00">
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <button type="button" class="btn btn-outline-danger w-100" onclick="removeItem(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(newRow);
        itemCount++;
        
        // Add event listeners to new row
        attachRowListeners(newRow);
    }
    
    function removeItem(button) {
        button.closest('.row').remove();
        calculateTotal();
    }
    
    function attachRowListeners(row) {
        const productSelect = row.querySelector('select[name*="product_id"]');
        const quantityInput = row.querySelector('input[name*="quantity"]');
        const priceInput = row.querySelector('input[name*="unit_price"]');
        const totalInput = row.querySelector('input[readonly]');
        
        productSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            priceInput.value = selectedOption.dataset.price || 0;
            calculateRowTotal(row);
        });
        
        quantityInput.addEventListener('input', () => calculateRowTotal(row));
        priceInput.addEventListener('input', () => calculateRowTotal(row));
    }
    
    function calculateRowTotal(row) {
        const quantity = parseFloat(row.querySelector('input[name*="quantity"]').value) || 0;
        const price = parseFloat(row.querySelector('input[name*="unit_price"]').value) || 0;
        const total = quantity * price;
        
        row.querySelector('input[readonly]').value = total.toFixed(2);
        calculateTotal();
    }
    
    function calculateTotal() {
        let grandTotal = 0;
        document.querySelectorAll('#itemsContainer .row').forEach(row => {
            const totalInput = row.querySelector('input[readonly]');
            if (totalInput) {
                grandTotal += parseFloat(totalInput.value) || 0;
            }
        });
        
        document.getElementById('grandTotal').textContent = '₱' + grandTotal.toFixed(2);
        document.getElementById('total_amount').value = grandTotal.toFixed(2);
    }
    
    // Initialize event listeners
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('#itemsContainer .row').forEach(attachRowListeners);
    });
    </script>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif ($action === 'view') {
    // Get purchase order details for viewing
    $purchase_order = null;
    $po_items = [];
    
    if ($po_id) {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Get PO details
            $query = "SELECT po.*, s.supplier_name, s.contact_person, s.email, s.phone, u.full_name as created_by_name
                      FROM purchase_orders po 
                      LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id 
                      LEFT JOIN users u ON po.created_by = u.user_id 
                      WHERE po.po_id = :po_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':po_id', $po_id);
            $stmt->execute();
            
            $purchase_order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$purchase_order) {
                $_SESSION['error'] = 'Purchase order not found.';
                header('Location: purchase_orders.php');
                exit();
            }
            
            // Get PO items
            $query = "SELECT poi.*, p.product_name, p.product_code 
                      FROM purchase_order_items poi 
                      LEFT JOIN products p ON poi.product_id = p.product_id 
                      WHERE poi.po_id = :po_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':po_id', $po_id);
            $stmt->execute();
            
            $po_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $exception) {
            $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
            header('Location: purchase_orders.php');
            exit();
        }
    } else {
        $_SESSION['error'] = 'Purchase order ID not provided.';
        header('Location: purchase_orders.php');
        exit();
    }
    
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-shopping-cart me-2"></i>Purchase Order Details
            </h1>
            <div>
                <button onclick="window.print()" class="btn btn-outline-primary me-2">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <a href="purchase_orders.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Purchase Orders
                </a>
            </div>
        </div>
        
        <!-- PO Header Information -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-file-invoice me-2"></i>Purchase Order #<?php echo htmlspecialchars($purchase_order['po_number']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3">Order Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td><strong>PO Number:</strong></td>
                                <td><?php echo htmlspecialchars($purchase_order['po_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Order Date:</strong></td>
                                <td><?php echo format_date($purchase_order['order_date'], 'F d, Y'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Expected Date:</strong></td>
                                <td><?php echo $purchase_order['expected_date'] ? format_date($purchase_order['expected_date'], 'F d, Y') : 'Not set'; ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $purchase_order['status'] === 'draft' ? 'secondary' : 
                                             ($purchase_order['status'] === 'sent' ? 'primary' : 
                                             ($purchase_order['status'] === 'partial' ? 'warning' : 
                                             ($purchase_order['status'] === 'received' ? 'success' : 'danger'))); 
                                    ?>">
                                        <?php echo ucfirst($purchase_order['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Created By:</strong></td>
                                <td><?php echo htmlspecialchars($purchase_order['created_by_name']); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3">Supplier Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td><strong>Supplier:</strong></td>
                                <td><?php echo htmlspecialchars($purchase_order['supplier_name']); ?></td>
                            </tr>
                            <?php if (!empty($purchase_order['contact_person'])): ?>
                            <tr>
                                <td><strong>Contact Person:</strong></td>
                                <td><?php echo htmlspecialchars($purchase_order['contact_person']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($purchase_order['email'])): ?>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td><?php echo htmlspecialchars($purchase_order['email']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($purchase_order['phone'])): ?>
                            <tr>
                                <td><strong>Phone:</strong></td>
                                <td><?php echo htmlspecialchars($purchase_order['phone']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                
                <?php if (!empty($purchase_order['notes'])): ?>
                <div class="mt-3">
                    <h6 class="text-muted mb-2">Notes</h6>
                    <p class="form-control-plaintext"><?php echo nl2br(htmlspecialchars($purchase_order['notes'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- PO Items Table -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Order Items
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Code</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($po_items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['product_code']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php echo number_format($item['quantity_ordered']); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo format_currency($item['unit_price']); ?>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo format_currency($item['quantity_ordered'] * $item['unit_price']); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <th colspan="4" class="text-end">Total Amount:</th>
                                <th class="text-end">
                                    <h4 class="mb-0"><?php echo format_currency($purchase_order['total_amount']); ?></h4>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <style>
    @media (max-width: 768px) {
        .table-responsive {
            font-size: 0.875rem;
        }
        
        .table th, .table td {
            padding: 0.5rem;
            vertical-align: middle;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        .btn-group .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .badge {
            font-size: 0.75rem;
        }
        
        .d-flex.justify-content-between {
            flex-direction: column;
            gap: 1rem;
        }
        
        .d-flex.justify-content-between h1 {
            font-size: 1.5rem;
        }
    }
    
    @media (max-width: 576px) {
        .table-responsive {
            font-size: 0.75rem;
        }
        
        .table th, .table td {
            padding: 0.25rem;
        }
        
        .card-body {
            padding: 0.75rem;
        }
        
        .row {
            margin: 0;
        }
        
        .col-md-6 {
            padding: 0;
            margin-bottom: 1rem;
        }
        
        h4 {
            font-size: 1.25rem;
        }
    }
    
    @media print {
        .btn, .no-print {
            display: none !important;
        }
        
        .card {
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
        }
        
        .table {
            page-break-inside: auto;
        }
        
        .table tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
    }
    </style>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} else {
    header('Location: purchase_orders.php');
    exit();
}
?>
