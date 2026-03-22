<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

$page_title = 'Suppliers';
$action = $_GET['action'] ?? 'list';
$supplier_id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: suppliers.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($action === 'add') {
            // Add new supplier
            $query = "INSERT INTO suppliers (supplier_name, contact_person, email, phone, address, tin, status) 
                      VALUES (:supplier_name, :contact_person, :email, :phone, :address, :tin, :status)";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(':supplier_name', $_POST['supplier_name']);
            $stmt->bindParam(':contact_person', $_POST['contact_person']);
            $stmt->bindParam(':email', $_POST['email']);
            $stmt->bindParam(':phone', $_POST['phone']);
            $stmt->bindParam(':address', $_POST['address']);
            $stmt->bindParam(':tin', $_POST['tin']);
            $stmt->bindParam(':status', $_POST['status']);
            
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'supplier_add', 'Added new supplier: ' . $_POST['supplier_name']);
            
            $_SESSION['success'] = 'Supplier added successfully!';
            header('Location: suppliers.php');
            exit();
            
        } elseif ($action === 'edit' && $supplier_id) {
            // Update existing supplier
            $query = "UPDATE suppliers SET supplier_name = :supplier_name, contact_person = :contact_person, 
                      email = :email, phone = :phone, address = :address, tin = :tin, status = :status 
                      WHERE supplier_id = :supplier_id";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(':supplier_name', $_POST['supplier_name']);
            $stmt->bindParam(':contact_person', $_POST['contact_person']);
            $stmt->bindParam(':email', $_POST['email']);
            $stmt->bindParam(':phone', $_POST['phone']);
            $stmt->bindParam(':address', $_POST['address']);
            $stmt->bindParam(':tin', $_POST['tin']);
            $stmt->bindParam(':status', $_POST['status']);
            $stmt->bindParam(':supplier_id', $supplier_id);
            
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'supplier_edit', 'Updated supplier: ' . $_POST['supplier_name']);
            
            $_SESSION['success'] = 'Supplier updated successfully!';
            header('Location: suppliers.php');
            exit();
            
        } elseif ($action === 'delete' && $supplier_id) {
            // Check if supplier has products
            $query = "SELECT COUNT(*) as product_count FROM products WHERE supplier_id = :supplier_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':supplier_id', $supplier_id);
            $stmt->execute();
            $product_count = $stmt->fetch(PDO::FETCH_ASSOC)['product_count'];
            
            if ($product_count > 0) {
                $_SESSION['error'] = 'Cannot delete supplier. It supplies ' . $product_count . ' products.';
            } else {
                // Delete supplier (soft delete by setting status to inactive)
                $query = "UPDATE suppliers SET status = 'inactive' WHERE supplier_id = :supplier_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':supplier_id', $supplier_id);
                $stmt->execute();
                
                // Log activity
                log_activity($_SESSION['user_id'], 'supplier_delete', 'Deleted supplier ID: ' . $supplier_id);
                
                $_SESSION['success'] = 'Supplier deleted successfully!';
            }
            header('Location: suppliers.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Suppliers Error: " . $exception->getMessage());
    }
}

// Get supplier data for editing
$supplier = null;
if ($action === 'edit' && $supplier_id) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM suppliers WHERE supplier_id = :supplier_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':supplier_id', $supplier_id);
        $stmt->execute();
        
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            $_SESSION['error'] = 'Supplier not found.';
            header('Location: suppliers.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: suppliers.php');
        exit();
    }
}

if ($action === 'list') {
    // Get suppliers list
    $suppliers = [];
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT s.*, 
                         (SELECT COUNT(*) FROM products WHERE supplier_id = s.supplier_id AND status = 'active') as product_count,
                         (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.supplier_id) as order_count
                  FROM suppliers s WHERE 1=1";
        
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (s.supplier_name LIKE :search OR s.contact_person LIKE :search OR s.email LIKE :search)";
            $search_param = "%$search%";
            $params[':search'] = $search_param;
        }
        
        if (!empty($status_filter)) {
            $query .= " AND s.status = :status";
            $params[':status'] = $status_filter;
        }
        
        $query .= " ORDER BY s.supplier_name";
        
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Suppliers List Error: " . $exception->getMessage());
    }
    
    include 'includes/header.php';
    ?>
    
    <style>
/* Suppliers Page Responsive Styles */
@media (max-width: 1200px) {
    .suppliers-filter-card {
        margin-bottom: 1rem;
    }
    
    .suppliers-table-container {
        font-size: 0.9rem;
    }
}

@media (max-width: 992px) {
    .suppliers-filter-card .card-body {
        padding: 1rem;
    }
    
    .suppliers-filter-card .row {
        gap: 0.5rem;
    }
    
    .suppliers-table-container {
        font-size: 0.85rem;
    }
    
    .suppliers-table-container .table th,
    .suppliers-table-container .table td {
        padding: 0.75rem 0.5rem;
    }
    
    .btn-group {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .btn-group .btn {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .suppliers-page-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    .suppliers-page-header h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .suppliers-filter-card .row {
        flex-direction: column;
    }
    
    .suppliers-filter-card .col-md-3,
    .suppliers-filter-card .col-md-4 {
        flex: 0 0 100%;
        margin-bottom: 0.75rem;
    }
    
    .suppliers-filter-card .btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .suppliers-table-container {
        font-size: 0.8rem;
    }
    
    .suppliers-table-container .table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .suppliers-table-container .table th,
    .suppliers-table-container .table td {
        padding: 0.5rem 0.375rem;
        min-width: 120px;
    }
    
    .suppliers-table-container .table .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .supplier-form-card {
        margin-bottom: 1rem;
    }
    
    .supplier-form-card .card-body {
        padding: 1rem;
    }
    
    .supplier-form-card .form-control,
    .supplier-form-card .form-select {
        font-size: 0.8rem;
        padding: 0.5rem;
    }
    
    .supplier-form-card .row {
        gap: 0.5rem;
    }
    
    .supplier-form-card .col-md-6 {
        margin-bottom: 0.5rem;
    }
}

@media (max-width: 576px) {
    .suppliers-page-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    .suppliers-page-header h1 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
    }
    
    .suppliers-filter-card .card-header {
        text-align: center;
        padding: 0.75rem;
    }
    
    .suppliers-filter-card .card-body {
        padding: 0.75rem;
    }
    
    .suppliers-table-container {
        font-size: 0.75rem;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .suppliers-table-container .table th,
    .suppliers-table-container .table td {
        padding: 0.375rem 0.25rem;
        min-width: 100px;
        font-size: 0.7rem;
    }
    
    .suppliers-table-container .table .btn {
        padding: 0.2rem 0.4rem;
        font-size: 0.7rem;
        margin: 0.1rem;
    }
    
    .supplier-form-card {
        margin-bottom: 1rem;
    }
    
    .supplier-form-card .card-body {
        padding: 0.75rem;
    }
    
    .supplier-form-card .form-control,
    .supplier-form-card .form-select {
        font-size: 0.8rem;
        padding: 0.375rem;
    }
    
    .supplier-form-card .row {
        gap: 0.5rem;
    }
    
    .supplier-form-card .col-md-6 {
        margin-bottom: 0.5rem;
    }
}

/* Touch-friendly improvements */
@media (hover: none) and (pointer: coarse) {
    .suppliers-table-container .table .btn {
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .supplier-form-card .form-control,
    .supplier-form-card .form-select {
        min-height: 44px;
    }
}

/* Landscape mobile adjustments */
@media (max-width: 768px) and (orientation: landscape) {
    .suppliers-table-container {
        max-height: 300px;
    }
    
    .suppliers-filter-card .row {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .suppliers-filter-card .col-md-3,
    .suppliers-filter-card .col-md-4 {
        flex: 0 0 50%;
    }
}

/* Print styles */
@media print {
    .suppliers-filter-card,
    .btn-group {
        display: none !important;
    }
    
    .supplier-form-card {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #dee2e6 !important;
    }
    
    .suppliers-table-container .table {
        page-break-inside: auto;
    }
    
    .suppliers-table-container .table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-truck me-2"></i>Suppliers Management
            </h1>
            <a href="suppliers.php?action=add" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New Supplier
            </a>
        </div>
        
        <!-- Supplier Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Suppliers</h5>
                        <h2><?php echo count($suppliers); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Active</h5>
                        <h2>
                            <?php 
                            $active = array_filter($suppliers, function($sup) { return $sup['status'] === 'active'; });
                            echo count($active);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-secondary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Inactive</h5>
                        <h2>
                            <?php 
                            $inactive = array_filter($suppliers, function($sup) { return $sup['status'] === 'inactive'; });
                            echo count($inactive);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">With Products</h5>
                        <h2>
                            <?php 
                            $with_products = array_filter($suppliers, function($sup) { return $sup['product_count'] > 0; });
                            echo count($with_products);
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
                    <div class="col-md-6">
                        <input type="text" class="form-control" name="search" placeholder="Search suppliers..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-4">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary w-100">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Suppliers Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="suppliersTable">
                        <thead>
                            <tr>
                                <th>Supplier Name</th>
                                <th>Contact Person</th>
                                <th>Contact Info</th>
                                <th>Products</th>
                                <th>Orders</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($suppliers)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-truck fa-3x mb-3"></i>
                                        <p>No suppliers found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($supplier['supplier_name']); ?></strong>
                                            <?php if ($supplier['tin']): ?>
                                                <br><small class="text-muted">TIN: <?php echo htmlspecialchars($supplier['tin']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?>
                                        </td>
                                        <td>
                                            <?php if ($supplier['email']): ?>
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($supplier['email']); ?>
                                                <br>
                                            <?php endif; ?>
                                            <?php if ($supplier['phone']): ?>
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($supplier['phone']); ?>
                                            <?php endif; ?>
                                            <?php if (!$supplier['email'] && !$supplier['phone']): ?>
                                                <span class="text-muted">No contact info</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $supplier['product_count']; ?></span>
                                            <?php if ($supplier['product_count'] > 0): ?>
                                                <br><small class="text-muted">products</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning"><?php echo $supplier['order_count']; ?></span>
                                            <?php if ($supplier['order_count'] > 0): ?>
                                                <br><small class="text-muted">orders</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $supplier['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($supplier['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="suppliers.php?action=edit&id=<?php echo $supplier['supplier_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="products.php?supplier=<?php echo $supplier['supplier_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View Products">
                                                    <i class="fas fa-box"></i>
                                                </a>
                                                <a href="purchase_orders.php?supplier=<?php echo $supplier['supplier_id']; ?>" 
                                                   class="btn btn-sm btn-outline-success" title="View Orders">
                                                    <i class="fas fa-shopping-cart"></i>
                                                </a>
                                                <?php if (has_permission('admin') && $supplier['product_count'] == 0): ?>
                                                <button onclick="return confirmDelete('Are you sure you want to delete this supplier?')" 
                                                        class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
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
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif (in_array($action, ['add', 'edit'])) {
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-truck me-2"></i><?php echo $action === 'add' ? 'Add New Supplier' : 'Edit Supplier'; ?>
            </h1>
            <a href="suppliers.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Suppliers
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="supplier_name" class="form-label">Supplier Name *</label>
                                <input type="text" class="form-control" id="supplier_name" name="supplier_name" 
                                       value="<?php echo htmlspecialchars($supplier['supplier_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                       value="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="tin" class="form-label">Tax Identification Number (TIN)</label>
                                <input type="text" class="form-control" id="tin" name="tin" 
                                       value="<?php echo htmlspecialchars($supplier['tin'] ?? ''); ?>">
                                <div class="form-text">Business tax identification number.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="4"><?php echo htmlspecialchars($supplier['address'] ?? ''); ?></textarea>
                                <div class="form-text">Complete business address.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo ($supplier['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($supplier['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Inactive suppliers won't appear in purchase order lists.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="suppliers.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i><?php echo $action === 'add' ? 'Add Supplier' : 'Update Supplier'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($action === 'edit' && $supplier): ?>
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0">Supplier Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Supplier ID:</strong> <?php echo $supplier['supplier_id']; ?></p>
                        <p><strong>Created:</strong> <?php echo format_date($supplier['created_at']); ?></p>
                        <p><strong>Products Supplied:</strong> <?php echo $supplier['product_count'] ?? 0; ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Purchase Orders:</strong> <?php echo $supplier['order_count'] ?? 0; ?></p>
                        <p><strong>Status:</strong> <span class="badge bg-<?php echo $supplier['status'] === 'active' ? 'success' : 'danger'; ?>"><?php echo ucfirst($supplier['status']); ?></span></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} else {
    header('Location: suppliers.php');
    exit();
}
?>
