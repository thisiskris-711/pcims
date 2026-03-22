<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

$category_label_singular = pcims_get_business_label('category_singular', 'Category');
$category_label_plural = pcims_get_business_label('category_plural', 'Categories');
$product_label_plural = pcims_get_business_label('product_plural', 'Products');
$page_title = $category_label_plural;
$action = $_GET['action'] ?? 'list';
$category_id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: categories.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($action === 'add') {
            // Add new category
            $query = "INSERT INTO categories (category_name, description, status) 
                      VALUES (:category_name, :description, :status)";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(':category_name', $_POST['category_name']);
            $stmt->bindParam(':description', $_POST['description']);
            $stmt->bindParam(':status', $_POST['status']);
            
            $stmt->execute();
            
            $_SESSION['success'] = $category_label_singular . ' added successfully!';
            header('Location: categories.php');
            exit();
            
        } elseif ($action === 'edit' && $category_id) {
            // Update existing category
            $query = "UPDATE categories SET category_name = :category_name, description = :description, 
                      status = :status WHERE category_id = :category_id";
            
            $stmt = $db->prepare($query);
            
            $stmt->bindParam(':category_name', $_POST['category_name']);
            $stmt->bindParam(':description', $_POST['description']);
            $stmt->bindParam(':status', $_POST['status']);
            $stmt->bindParam(':category_id', $category_id);
            
            $stmt->execute();
            
            $_SESSION['success'] = $category_label_singular . ' updated successfully!';
            header('Location: categories.php');
            exit();
            
        } elseif ($action === 'delete' && $category_id) {
            // Delete category
            $query = "DELETE FROM categories WHERE category_id = :category_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->execute();
            
            $_SESSION['success'] = $category_label_singular . ' deleted successfully!';
            header('Location: categories.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: categories.php');
        exit();
    }
}

// Get category data for editing
$category = null;
if ($action === 'edit' && $category_id) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM categories WHERE category_id = :category_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':category_id', $category_id);
        $stmt->execute();
        
        $category = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$category) {
            $_SESSION['error'] = 'Category not found.';
            header('Location: categories.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: categories.php');
        exit();
    }
}

if ($action === 'list') {
    // Get categories list
    $categories = [];
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM categories WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (category_name LIKE :search OR description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($status_filter)) {
            $query .= " AND status = :status";
            $params[':status'] = $status_filter;
        }
        
        $query .= " ORDER BY category_name ASC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Categories List Error: " . $exception->getMessage());
    }
    
    include 'includes/header.php';
    ?>
    
    <style>
/* Categories Page Responsive Styles */
@media (max-width: 1200px) {
    .categories-filter-card {
        margin-bottom: 1rem;
    }
    
    .categories-table-container {
        font-size: 0.9rem;
    }
}

@media (max-width: 992px) {
    .categories-filter-card .card-body {
        padding: 1rem;
    }
    
    .categories-filter-card .row {
        gap: 0.5rem;
    }
    
    .categories-table-container {
        font-size: 0.85rem;
    }
    
    .categories-table-container .table th,
    .categories-table-container .table td {
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
    .categories-page-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    .categories-page-header h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .categories-filter-card .row {
        flex-direction: column;
    }
    
    .categories-filter-card .col-md-3,
    .categories-filter-card .col-md-4 {
        flex: 0 0 100%;
        margin-bottom: 0.75rem;
    }
    
    .categories-filter-card .btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .categories-table-container {
        font-size: 0.8rem;
    }
    
    .categories-table-container .table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .categories-table-container .table th,
    .categories-table-container .table td {
        padding: 0.5rem 0.375rem;
        min-width: 120px;
    }
    
    .categories-table-container .table .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .category-form-card {
        margin-bottom: 1rem;
    }
    
    .category-form-card .card-body {
        padding: 1rem;
    }
    
    .category-form-card .form-control,
    .category-form-card .form-select {
        font-size: 0.8rem;
        padding: 0.5rem;
    }
    
    .category-form-card .row {
        gap: 0.5rem;
    }
    
    .category-form-card .col-md-6 {
        margin-bottom: 0.5rem;
    }
}

@media (max-width: 576px) {
    .categories-page-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    .categories-page-header h1 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
    }
    
    .categories-filter-card .card-header {
        text-align: center;
        padding: 0.75rem;
    }
    
    .categories-filter-card .card-body {
        padding: 0.75rem;
    }
    
    .categories-table-container {
        font-size: 0.75rem;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .categories-table-container .table th,
    .categories-table-container .table td {
        padding: 0.375rem 0.25rem;
        min-width: 100px;
        font-size: 0.7rem;
    }
    
    .categories-table-container .table .btn {
        padding: 0.2rem 0.4rem;
        font-size: 0.7rem;
        margin: 0.1rem;
    }
    
    .category-form-card {
        margin-bottom: 1rem;
    }
    
    .category-form-card .card-body {
        padding: 0.75rem;
    }
    
    .category-form-card .form-control,
    .category-form-card .form-select {
        font-size: 0.8rem;
        padding: 0.375rem;
    }
    
    .category-form-card .row {
        gap: 0.5rem;
    }
    
    .category-form-card .col-md-6 {
        margin-bottom: 0.5rem;
    }
}

/* Touch-friendly improvements */
@media (hover: none) and (pointer: coarse) {
    .categories-table-container .table .btn {
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .category-form-card .form-control,
    .category-form-card .form-select {
        min-height: 44px;
    }
}

/* Landscape mobile adjustments */
@media (max-width: 768px) and (orientation: landscape) {
    .categories-table-container {
        max-height: 300px;
    }
    
    .categories-filter-card .row {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .categories-filter-card .col-md-3,
    .categories-filter-card .col-md-4 {
        flex: 0 0 50%;
    }
}

/* Print styles */
@media print {
    .categories-filter-card,
    .btn-group {
        display: none !important;
    }
    
    .category-form-card {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #dee2e6 !important;
    }
    
    .categories-table-container .table {
        page-break-inside: auto;
    }
    
    .categories-table-container .table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-tags me-2"></i><?php echo htmlspecialchars($category_label_plural); ?> Management
            </h1>
            <a href="categories.php?action=add" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New <?php echo htmlspecialchars($category_label_singular); ?>
            </a>
        </div>
        
        <!-- Category Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total <?php echo htmlspecialchars($category_label_plural); ?></h5>
                        <h2><?php echo count($categories); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Active</h5>
                        <h2>
                            <?php 
                            $active = array_filter($categories, function($cat) { return $cat['status'] === 'active'; });
                            echo count($active);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">Inactive</h5>
                        <h2>
                            <?php 
                            $inactive = array_filter($categories, function($cat) { return $cat['status'] === 'inactive'; });
                            echo count($inactive);
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
                        <input type="text" class="form-control" name="search" placeholder="Search <?php echo htmlspecialchars(strtolower($category_label_plural)); ?>..." value="<?php echo htmlspecialchars($search); ?>">
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
        
        <!-- Categories Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="categoriesTable">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars($category_label_singular); ?> Name</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($categories)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        <i class="fas fa-tags fa-3x mb-3"></i>
                                        <p>No <?php echo htmlspecialchars(strtolower($category_label_plural)); ?> found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($category['category_name']); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(substr($category['description'] ?? '', 0, 50)); ?>
                                            <?php if (strlen($category['description'] ?? '') > 50): ?>
                                                ...<small class="text-muted">Read more</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $category['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($category['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?php echo format_date($category['created_at'], 'M d, Y'); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="categories.php?action=edit&id=<?php echo $category['category_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="products.php?category=<?php echo $category['category_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View Products">
                                                    <i class="fas fa-box"></i>
                                                </a>
                                                <?php if (has_permission('admin')): ?>
                                                <button onclick="return confirmDelete('Are you sure you want to delete this category?')" 
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
                <i class="fas fa-tags me-2"></i><?php echo $action === 'add' ? 'Add New ' . htmlspecialchars($category_label_singular) : 'Edit ' . htmlspecialchars($category_label_singular); ?>
            </h1>
            <a href="categories.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to <?php echo htmlspecialchars($category_label_plural); ?>
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="category_name" class="form-label"><?php echo htmlspecialchars($category_label_singular); ?> Name *</label>
                                <input type="text" class="form-control" id="category_name" name="category_name" 
                                       value="<?php echo htmlspecialchars($category['category_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($category['description'] ?? ''); ?></textarea>
                                <div class="form-text">Provide a brief description of this <?php echo htmlspecialchars(strtolower($category_label_singular)); ?>.</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo ($category['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($category['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Inactive categories won't appear in product lists.</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="categories.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i><?php echo $action === 'add' ? 'Add ' . htmlspecialchars($category_label_singular) : 'Update ' . htmlspecialchars($category_label_singular); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($action === 'edit' && $category): ?>
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0"><?php echo htmlspecialchars($category_label_singular); ?> Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><?php echo htmlspecialchars($category_label_singular); ?> ID:</strong> <?php echo $category['category_id']; ?></p>
                        <p><strong>Created:</strong> <?php echo format_date($category['created_at']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><?php echo htmlspecialchars($product_label_plural); ?>:</strong> <?php echo $category['product_count'] ?? 0; ?></p>
                        <p><strong>Status:</strong> <span class="badge bg-<?php echo $category['status'] === 'active' ? 'success' : 'danger'; ?>"><?php echo ucfirst($category['status']); ?></span></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    
    <?php
} else {
    header('Location: categories.php');
    exit();
}
?>
