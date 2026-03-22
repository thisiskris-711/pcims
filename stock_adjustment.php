<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

$page_title = 'Stock Adjustment';

// Handle stock adjustment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: stock_adjustment.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $product_id = $_POST['product_id'];
        $adjustment_type = $_POST['adjustment_type']; // 'add' or 'subtract'
        $quantity = abs(intval($_POST['quantity']));
        $notes = $_POST['notes'] ?? '';
        
        if (empty($product_id) || empty($quantity)) {
            $_SESSION['error'] = 'Please select a product and enter quantity.';
            header('Location: stock_adjustment.php');
            exit();
        }
        
        // Get current inventory
        $query = "SELECT * FROM inventory WHERE product_id = :product_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$inventory) {
            $_SESSION['error'] = 'Product not found in inventory.';
            header('Location: stock_adjustment.php');
            exit();
        }
        
        // Calculate new quantity
        $new_quantity = $adjustment_type === 'add' ? 
            $inventory['quantity_on_hand'] + $quantity : 
            max(0, $inventory['quantity_on_hand'] - $quantity);
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Update inventory
            $query = "UPDATE inventory SET quantity_on_hand = :new_quantity WHERE product_id = :product_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':new_quantity', $new_quantity);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            
            // Record stock movement
            $movement_type = $adjustment_type === 'add' ? 'in' : 'out';
            $actual_quantity = $adjustment_type === 'add' ? $quantity : abs($quantity);
            
            $query = "INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, user_id, movement_date) 
                      VALUES (:product_id, :movement_type, :quantity, 'adjustment', :notes, :user_id, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->bindParam(':movement_type', $movement_type);
            $stmt->bindParam(':quantity', $actual_quantity);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            
            // Get product details for logging
            $query = "SELECT product_name FROM products WHERE product_id = :product_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->execute();
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Log activity
            log_activity($_SESSION['user_id'], 'stock_adjustment', 
                "Stock adjustment for {$product['product_name']}: {$adjustment_type} {$quantity} units");
            
            // Check for low stock and create notification if needed
            if ($new_quantity <= $inventory['reorder_level']) {
                add_notification(
                    $_SESSION['user_id'],
                    'Low Stock Alert',
                    "Product {$product['product_name']} is running low on stock ({$new_quantity} units remaining)",
                    'warning',
                    'low_stock',
                    $product_id
                );
            }
            
            $db->commit();
            
            $_SESSION['success'] = 'Stock adjusted successfully!';
            header('Location: stock_movements.php');
            exit();
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Stock Adjustment Error: " . $exception->getMessage());
    }
}

// Get products data
$products = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT p.*, i.quantity_on_hand, i.quantity_reserved, i.quantity_available 
              FROM products p 
              JOIN inventory i ON p.product_id = i.product_id 
              WHERE p.status IN ('active', 'inactive') 
              ORDER BY p.product_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $exception) {
    $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
    error_log("Products Load Error: " . $exception->getMessage());
}

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-exchange-alt me-2"></i>Stock Adjustment
        </h1>
        <a href="stock_movements.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Movements
        </a>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-edit me-2"></i>Adjust Stock Levels
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        
                        <div class="mb-4">
                            <label for="product_id" class="form-label">Select Product *</label>
                            <select class="form-select" id="product_id" name="product_id" required>
                                <option value="">Choose a product...</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['product_id']; ?>"
                                            data-current-stock="<?php echo $product['quantity_on_hand']; ?>"
                                            data-reorder-level="<?php echo $product['reorder_level']; ?>"
                                            data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            data-product-code="<?php echo htmlspecialchars($product['product_code']); ?>">
                                        <?php echo htmlspecialchars($product['product_name']); ?> 
                                        (<?php echo htmlspecialchars($product['product_code']); ?>)
                                        - Current Stock: <?php echo number_format($product['quantity_on_hand']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="productDetails" class="mb-4" style="display: none;">
                            <div class="alert alert-info">
                                <h6>Product Information</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>Product:</strong> <span id="selectedProductName"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Code:</strong> <span id="selectedProductCode"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Current Stock:</strong> <span id="currentStock" class="fw-bold"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label">Adjustment Type *</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="adjustment_type" id="add_stock" value="add" required>
                                <label class="btn btn-outline-success" for="add_stock">
                                    <i class="fas fa-plus-circle me-2"></i>Add Stock
                                </label>
                                
                                <input type="radio" class="btn-check" name="adjustment_type" id="subtract_stock" value="subtract">
                                <label class="btn btn-outline-danger" for="subtract_stock">
                                    <i class="fas fa-minus-circle me-2"></i>Subtract Stock
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="quantity" class="form-label">Quantity *</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-boxes"></i>
                                </span>
                                <input type="number" class="form-control" id="quantity" name="quantity" 
                                       min="1" required placeholder="Enter quantity to adjust">
                            </div>
                            <div class="form-text">Enter the number of units to add or subtract from inventory.</div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="notes" class="form-label">Notes / Reason</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" 
                                      placeholder="Enter the reason for this stock adjustment..."></textarea>
                            <div class="form-text">Provide details about why this adjustment is being made (e.g., damage, return, recount, etc.).</div>
                        </div>
                        
                        <div id="adjustmentPreview" class="mb-4" style="display: none;">
                            <div class="alert alert-warning">
                                <h6>Adjustment Preview</h6>
                                <div class="row">
                                    <div class="col-md-4">
                                        <strong>Current Stock:</strong> <span id="previewCurrent"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>Adjustment:</strong> <span id="previewAdjustment"></span>
                                    </div>
                                    <div class="col-md-4">
                                        <strong>New Stock:</strong> <span id="previewNew" class="fw-bold"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="stock_movements.php" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Process Adjustment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>Quick Guide
                    </h5>
                </div>
                <div class="card-body">
                    <h6>Stock Adjustment Types:</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <i class="fas fa-plus-circle text-success me-2"></i>
                            <strong>Add Stock:</strong> Use when receiving returns, recounts, or corrections that increase inventory.
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-minus-circle text-danger me-2"></i>
                            <strong>Subtract Stock:</strong> Use for damages, losses, recounts, or corrections that decrease inventory.
                        </li>
                    </ul>
                    
                    <h6 class="mt-3">Important Notes:</h6>
                    <ul class="list-unstyled small">
                        <li class="mb-2">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                            All adjustments are logged and tracked.
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-bell text-info me-2"></i>
                            Low stock alerts will be triggered automatically.
                        </li>
                        <li class="mb-2">
                            <i class="fas fa-shield-alt text-primary me-2"></i>
                            You need appropriate permissions to make adjustments.
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-history me-2"></i>Recent Adjustments
                    </h5>
                </div>
                <div class="card-body">
                    <?php
                    try {
                        $query = "SELECT sm.*, p.product_name, p.product_code 
                                  FROM stock_movements sm 
                                  JOIN products p ON sm.product_id = p.product_id 
                                  WHERE sm.reference_type = 'adjustment' 
                                  ORDER BY sm.movement_date DESC LIMIT 5";
                        $stmt = $db->prepare($query);
                        $stmt->execute();
                        $recent_adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (empty($recent_adjustments)) {
                            echo '<p class="text-muted text-center">No recent adjustments found.</p>';
                        } else {
                            foreach ($recent_adjustments as $adj) {
                                echo '<div class="mb-2 pb-2 border-bottom">';
                                echo '<small class="d-block"><strong>' . htmlspecialchars($adj['product_name']) . '</strong></small>';
                                echo '<small class="text-muted">';
                                echo $adj['movement_type'] === 'in' ? '+' : '-';
                                echo abs($adj['quantity']) . ' units - ' . format_date($adj['movement_date'], 'M d, H:i');
                                echo '</small>';
                                echo '</div>';
                            }
                        }
                    } catch(PDOException $exception) {
                        echo '<p class="text-danger">Error loading recent adjustments.</p>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const productSelect = document.getElementById('product_id');
    const adjustmentTypeRadios = document.querySelectorAll('input[name="adjustment_type"]');
    const quantityInput = document.getElementById('quantity');
    
    function updatePreview() {
        const selectedOption = productSelect.options[productSelect.selectedIndex];
        const currentStock = parseInt(selectedOption.dataset.currentStock) || 0;
        const adjustmentType = document.querySelector('input[name="adjustment_type"]:checked')?.value;
        const quantity = parseInt(quantityInput.value) || 0;
        
        if (productSelect.value && adjustmentType && quantity > 0) {
            const adjustment = adjustmentType === 'add' ? quantity : -quantity;
            const newStock = Math.max(0, currentStock + adjustment);
            
            const currentStockElement = document.getElementById('currentStock');
            const productNameElement = document.getElementById('selectedProductName');
            const productCodeElement = document.getElementById('selectedProductCode');
            
            if (currentStockElement) currentStockElement.textContent = currentStock;
            if (productNameElement) productNameElement.textContent = selectedOption.dataset.productName;
            if (productCodeElement) productCodeElement.textContent = selectedOption.dataset.productCode;
            
            document.getElementById('previewCurrent').textContent = currentStock;
            document.getElementById('previewAdjustment').textContent = (adjustment > 0 ? '+' : '') + adjustment;
            document.getElementById('previewNew').textContent = newStock;
            
            const newStockElement = document.getElementById('previewNew');
            newStockElement.className = 'fw-bold';
            if (newStock === 0) {
                newStockElement.classList.add('text-danger');
            } else if (newStock <= parseInt(selectedOption.dataset.reorderLevel)) {
                newStockElement.classList.add('text-warning');
            } else {
                newStockElement.classList.add('text-success');
            }
            
            document.getElementById('adjustmentPreview').style.display = 'block';
        } else {
            document.getElementById('productDetails').style.display = 'none';
            document.getElementById('adjustmentPreview').style.display = 'none';
        }
    }
    
    productSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        
        if (this.value) {
            const productNameElement = document.getElementById('selectedProductName');
            const productCodeElement = document.getElementById('selectedProductCode');
            const currentStockElement = document.getElementById('currentStock');
            
            if (productNameElement) productNameElement.textContent = selectedOption.dataset.productName;
            if (productCodeElement) productCodeElement.textContent = selectedOption.dataset.productCode;
            if (currentStockElement) currentStockElement.textContent = selectedOption.dataset.currentStock;
            
            document.getElementById('previewCurrent').textContent = selectedOption.dataset.currentStock;
            document.getElementById('previewAdjustment').textContent = '';
            document.getElementById('previewNew').textContent = '';
            
            document.getElementById('adjustmentPreview').style.display = 'block';
        } else {
            document.getElementById('productDetails').style.display = 'none';
            document.getElementById('adjustmentPreview').style.display = 'none';
        }
        
        updatePreview();
    });
    
    adjustmentTypeRadios.forEach(radio => {
        radio.addEventListener('change', updatePreview);
    });
    
    quantityInput.addEventListener('input', updatePreview);
});
</script>
