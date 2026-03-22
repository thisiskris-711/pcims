<?php
require_once 'config/config.php';
require_once 'includes/intelligence.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

$page_title = 'Inventory Management';

// Handle stock adjustments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: inventory.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($_POST['action'] === 'adjust_stock') {
            $product_id = $_POST['product_id'];
            $adjustment_type = $_POST['adjustment_type']; // 'add' or 'subtract'
            $quantity = abs(intval($_POST['quantity']));
            $notes = $_POST['notes'] ?? '';
            
            // Get current inventory
            $query = "SELECT * FROM inventory WHERE product_id = :product_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$inventory) {
                $_SESSION['error'] = 'Product not found in inventory.';
                header('Location: inventory.php');
                exit();
            }
            
            // Calculate new quantity
            $new_quantity = $adjustment_type === 'add' ? 
                $inventory['quantity_on_hand'] + $quantity : 
                max(0, $inventory['quantity_on_hand'] - $quantity);
            
            // Update inventory
            $query = "UPDATE inventory SET quantity_on_hand = :new_quantity WHERE product_id = :product_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':new_quantity', $new_quantity);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            
            // Record stock movement
            $movement_type = $adjustment_type === 'add' ? 'in' : 'out';
            $actual_quantity = $adjustment_type === 'add' ? $quantity : -$quantity;
            
            $query = "INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, user_id) 
                      VALUES (:product_id, :movement_type, :quantity, 'adjustment', :notes, :user_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->bindParam(':movement_type', $movement_type);
            $stmt->bindParam(':quantity', $actual_quantity);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'stock_adjustment', "Stock adjustment for product ID: $product_id, Quantity: $actual_quantity");
            
            $_SESSION['success'] = 'Stock adjusted successfully!';
            header('Location: inventory.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Inventory Error: " . $exception->getMessage());
    }
}

// Get inventory data
$inventory_data = [];
$inventory_intelligence = [];
$search = $_GET['search'] ?? '';
$stock_filter = $_GET['stock_filter'] ?? '';
$category_filter = $_GET['category'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT p.*, c.category_name, s.supplier_name, i.quantity_on_hand, i.quantity_reserved, 
              i.quantity_available, p.reorder_level 
              FROM inventory i 
              JOIN products p ON i.product_id = p.product_id 
              LEFT JOIN categories c ON p.category_id = c.category_id 
              LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
              WHERE p.status IN ('active', 'inactive')";
    
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search)";
        $search_param = "%$search%";
        $params[':search'] = $search_param;
    }
    
    if (!empty($category_filter)) {
        $query .= " AND p.category_id = :category_id";
        $params[':category_id'] = $category_filter;
    }
    
    // Stock level filtering
    if ($stock_filter === 'out') {
        $query .= " AND i.quantity_on_hand = 0";
    } elseif ($stock_filter === 'available') {
        $query .= " AND i.quantity_available > 0";
    }
    
    $query .= " ORDER BY p.product_name";
    
    $stmt = $db->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($inventory_data)) {
        $product_ids = array_map('intval', array_column($inventory_data, 'product_id'));
        $inventory_intelligence = pcims_get_product_intelligence($db, $product_ids);

        if ($stock_filter === 'low') {
            $inventory_data = array_values(array_filter($inventory_data, function($item) use ($inventory_intelligence) {
                $intelligence = $inventory_intelligence[$item['product_id']] ?? null;
                return !empty($intelligence['is_low_stock']);
            }));
        }

        if ($stock_filter === 'recommended') {
            $inventory_data = array_values(array_filter($inventory_data, function($item) use ($inventory_intelligence) {
                $intelligence = $inventory_intelligence[$item['product_id']] ?? null;
                return !empty($intelligence['is_restock_recommended']);
            }));
        }
    }
    
    // Get categories for filter
    $query = "SELECT * FROM categories WHERE status = 'active' ORDER BY category_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $exception) {
    $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
    error_log("Inventory List Error: " . $exception->getMessage());
}

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-warehouse me-2"></i>Inventory Management
        </h1>
        <div>
            <a href="stock_adjustment.php" class="btn btn-success">
                <i class="fas fa-exchange-alt me-2"></i>Stock Adjustment
            </a>
            <a href="inventory.php?export=1" class="btn btn-outline-primary">
                <i class="fas fa-download me-2"></i>Export
            </a>
        </div>
    </div>
    
    <!-- Inventory Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Items</h5>
                    <h2><?php echo count($inventory_data); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">In Stock</h5>
                    <h2>
                        <?php 
                        $in_stock = array_filter($inventory_data, function($item) {
                            return $item['quantity_on_hand'] > 0;
                        });
                        echo count($in_stock);
                        ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Low Stock</h5>
                    <h2>
                        <?php 
                        $low_stock = array_filter($inventory_data, function($item) use ($inventory_intelligence) {
                            $intelligence = $inventory_intelligence[$item['product_id']] ?? null;
                            return !empty($intelligence['is_low_stock']);
                        });
                        echo count($low_stock);
                        ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Out of Stock</h5>
                    <h2>
                        <?php 
                        $out_of_stock = array_filter($inventory_data, function($item) {
                            return $item['quantity_on_hand'] == 0;
                        });
                        echo count($out_of_stock);
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
                <div class="col-md-4">
                    <input type="text" class="form-control" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['category_id']; ?>" <?php echo $category_filter == $category['category_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="stock_filter">
                        <option value="">All Stock Levels</option>
                        <option value="available" <?php echo $stock_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                        <option value="low" <?php echo $stock_filter === 'low' ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="recommended" <?php echo $stock_filter === 'recommended' ? 'selected' : ''; ?>>Restock Recommended</option>
                        <option value="out" <?php echo $stock_filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                        <i class="fas fa-times me-2"></i>Clear
                    </button>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Inventory Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Supplier</th>
                            <th>On Hand</th>
                            <th>Reserved</th>
                            <th>Available</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inventory_data)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                    <p>No inventory items found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inventory_data as $item): ?>
                                <?php $intelligence = $inventory_intelligence[$item['product_id']] ?? null; ?>
                                <tr data-product-id="<?php echo $item['product_id']; ?>" class="<?php echo !empty($intelligence['is_out_of_stock']) ? 'table-danger' : (!empty($intelligence['is_low_stock']) || !empty($intelligence['is_restock_recommended']) ? 'table-warning' : ''); ?>">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php echo get_product_image($item['image_url'], $item['product_name'], 'small', ['class' => 'img-thumbnail me-3', 'style' => 'width: 40px; height: 40px; object-fit: cover;']); ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                <?php if (!empty($intelligence['is_best_seller'])): ?>
                                                    <br><span class="badge bg-success mt-1">Best Seller</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($item['supplier_name'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="fw-bold stock-on-hand <?php echo !empty($intelligence['is_low_stock']) || !empty($intelligence['is_out_of_stock']) ? 'text-danger' : ''; ?>">
                                            <?php echo number_format($item['quantity_on_hand']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($item['quantity_reserved']); ?></td>
                                    <td>
                                        <span class="fw-bold text-success stock-available">
                                            <?php echo number_format($item['quantity_available']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo number_format($item['reorder_level']); ?>
                                        <?php if (!empty($intelligence['is_low_stock'])): ?>
                                            <i class="fas fa-exclamation-triangle text-warning" title="Low Stock"></i>
                                        <?php endif; ?>
                                        <?php if ($intelligence): ?>
                                            <small class="text-muted d-block">
                                                Lead time: <?php echo number_format($intelligence['lead_time_days']); ?>d
                                            </small>
                                            <small class="text-muted d-block">
                                                Forecast (<?php echo number_format($intelligence['forecast_days']); ?>d): <?php echo number_format($intelligence['forecast_quantity']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="stock-status">
                                        <?php if (!empty($intelligence['is_out_of_stock'])): ?>
                                            <span class="badge bg-danger">Out of Stock</span>
                                        <?php elseif (!empty($intelligence['is_low_stock'])): ?>
                                            <span class="badge bg-warning">Low Stock</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">In Stock</span>
                                        <?php endif; ?>
                                        <?php if (!empty($intelligence['is_restock_recommended'])): ?>
                                            <br><span class="badge bg-info mt-1">Restock Recommended</span>
                                        <?php endif; ?>
                                        <?php if ($intelligence): ?>
                                            <small class="text-muted d-block mt-1">
                                                Avg/day: <?php echo number_format($intelligence['average_daily_sales'], 2); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="showAdjustmentModal(<?php echo $item['product_id']; ?>, '<?php echo htmlspecialchars($item['product_name']); ?>', <?php echo $item['quantity_on_hand']; ?>)"
                                                    title="Adjust Stock">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="product_details.php?id=<?php echo $item['product_id']; ?>" 
                                               class="btn btn-sm btn-outline-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="stock_movements.php?product_id=<?php echo $item['product_id']; ?>" 
                                               class="btn btn-sm btn-outline-secondary" title="View Movements">
                                                <i class="fas fa-history"></i>
                                            </a>
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

<!-- Stock Adjustment Modal -->
<div class="modal fade" id="adjustmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Adjust Stock</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="adjustmentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="adjust_stock">
                    <input type="hidden" id="adjustment_product_id" name="product_id">
                    
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" id="adjustment_product_name" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="text" class="form-control" id="adjustment_current_stock" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Adjustment Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="adjustment_type" id="add_stock" value="add" checked>
                            <label class="btn btn-outline-success" for="add_stock">
                                <i class="fas fa-plus me-2"></i>Add Stock
                            </label>
                            
                            <input type="radio" class="btn-check" name="adjustment_type" id="subtract_stock" value="subtract">
                            <label class="btn btn-outline-danger" for="subtract_stock">
                                <i class="fas fa-minus me-2"></i>Subtract Stock
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="adjustment_quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="adjustment_quantity" name="quantity" min="1" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="adjustment_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="adjustment_notes" name="notes" rows="2" placeholder="Reason for adjustment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Adjust Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const inventoryTableBody = document.querySelector('#inventoryTable tbody');

    function fetchStockLevels() {
        fetch('api/stock_levels.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateInventoryTable(data.inventory);
                }
            })
            .catch(error => console.error('Error fetching stock levels:', error));
    }

    function updateInventoryTable(inventory) {
        inventory.forEach(item => {
            const row = inventoryTableBody.querySelector(`tr[data-product-id="${item.product_id}"]`);
            if (row) {
                const onHandCell = row.querySelector('.stock-on-hand');
                const availableCell = row.querySelector('.stock-available');
                const statusCell = row.querySelector('.stock-status');

                if (onHandCell && onHandCell.textContent.trim() !== item.quantity_on_hand) {
                    onHandCell.textContent = item.quantity_on_hand;
                    highlightRow(row);
                }

                if (availableCell && availableCell.textContent.trim() !== item.quantity_available) {
                    availableCell.textContent = item.quantity_available;
                    highlightRow(row);
                }

                if (statusCell) {
                    let statusBadge = '';
                    if (item.stock_status === 'out_of_stock') {
                        statusBadge = '<span class="badge bg-danger">Out of Stock</span>';
                    } else if (item.stock_status === 'low_stock') {
                        statusBadge = '<span class="badge bg-warning">Low Stock</span>';
                    } else {
                        statusBadge = '<span class="badge bg-success">In Stock</span>';
                    }
                    if (statusCell.innerHTML.trim() !== statusBadge) {
                        statusCell.innerHTML = statusBadge;
                        highlightRow(row);
                    }
                }
            }
        });
    }

    function highlightRow(row) {
        row.classList.add('table-info');
        setTimeout(() => {
            row.classList.remove('table-info');
        }, 2000);
    }

    // Fetch stock levels every 5 seconds
    setInterval(fetchStockLevels, 5000);
});
</script>

<?php include 'includes/footer.php'; ?>

<script>
function showAdjustmentModal(productId, productName, currentStock) {
    document.getElementById('adjustment_product_id').value = productId;
    document.getElementById('adjustment_product_name').value = productName;
    document.getElementById('adjustment_current_stock').value = currentStock;
    document.getElementById('adjustment_quantity').value = '';
    document.getElementById('adjustment_notes').value = '';
    
    var modal = new bootstrap.Modal(document.getElementById('adjustmentModal'));
    modal.show();
}

function clearFilters() {
    // Reset all filter inputs
    document.querySelector('input[name="search"]').value = '';
    document.querySelector('select[name="category"]').value = '';
    document.querySelector('select[name="stock_filter"]').value = '';
    
    // Reload page without filters
    window.location.href = 'inventory.php';
}
</script>
</script>

<!-- // Handle export functionality -->
<?php if (isset($_GET['export']) && $_GET['export'] == '1'): ?>
    exportTableToCSV('inventoryTable', 'inventory_export_<?php echo date('Y-m-d'); ?>.csv');
<?php endif; ?>
</script>
