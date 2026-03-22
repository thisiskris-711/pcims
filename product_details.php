<?php
require_once 'config/config.php';
redirect_if_not_logged_in();

$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    $_SESSION['error'] = 'Product ID is required.';
    header('Location: products.php');
    exit();
}

// Get product details
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT p.*, c.category_name, s.supplier_name, i.quantity_on_hand, i.quantity_reserved, i.quantity_available, p.reorder_level 
              FROM products p 
              LEFT JOIN categories c ON p.category_id = c.category_id 
              LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
              LEFT JOIN inventory i ON p.product_id = i.product_id 
              WHERE p.product_id = :product_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: products.php');
        exit();
    }
    
    // Get stock movements for this product
    $query = "SELECT sm.*, u.full_name, u.username 
              FROM stock_movements sm 
              JOIN users u ON sm.user_id = u.user_id 
              WHERE sm.product_id = :product_id 
              ORDER BY sm.movement_date DESC 
              LIMIT 20";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    $stock_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent sales orders containing this product
    $query = "SELECT so.so_number, so.customer_name, so.order_date, so.status, soi.quantity, soi.unit_price, soi.discount_percent 
              FROM sales_orders so 
              JOIN sales_order_items soi ON so.so_id = soi.so_id 
              WHERE soi.product_id = :product_id 
              ORDER BY so.created_at DESC 
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    $sales_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent purchase orders containing this product
    $query = "SELECT po.po_number, s.supplier_name, po.order_date, po.status, poi.quantity_ordered, poi.quantity_received, poi.unit_price 
              FROM purchase_orders po 
              JOIN purchase_order_items poi ON po.po_id = poi.po_id 
              LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id 
              WHERE poi.product_id = :product_id 
              ORDER BY po.created_at DESC 
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':product_id', $product_id);
    $stmt->execute();
    $purchase_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $exception) {
    $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
    error_log("Product Details Error: " . $exception->getMessage());
    header('Location: products.php');
    exit();
}

$page_title = 'Product Details - ' . htmlspecialchars($product['product_name']);
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-box me-2"></i>Product Details
        </h1>
        <div>
            <a href="products.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Products
            </a>
            <?php if (has_permission('staff')): ?>
            <a href="products.php?action=edit&id=<?php echo $product['product_id']; ?>" class="btn btn-primary">
                <i class="fas fa-edit me-2"></i>Edit Product
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Product Overview -->
    <div class="row mb-4">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <?php echo get_product_image($product['image_url'], $product['product_name'], 'medium', ['class' => 'img-fluid rounded']); ?>
                        </div>
                        <div class="col-md-9">
                            <h2><?php echo htmlspecialchars($product['product_name']); ?></h2>
                            <p class="text-muted">Product Code: <?php echo htmlspecialchars($product['product_code']); ?></p>
                            
                            <?php if ($product['description']): ?>
                            <p class="mt-3"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                            <?php endif; ?>
                            
                            <div class="row mt-4">
                                <div class="col-md-4">
                                    <small class="text-muted">Category</small>
                                    <p><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Supplier</small>
                                    <p><?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></p>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Unit of Measure</small>
                                    <p><?php echo htmlspecialchars($product['unit_of_measure'] ?? 'pcs'); ?></p>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <small class="text-muted">Unit Price</small>
                                    <h4 class="text-success"><?php echo format_currency($product['unit_price']); ?></h4>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Cost Price</small>
                                    <h4 class="text-info"><?php echo format_currency($product['cost_price']); ?></h4>
                                </div>
                                <div class="col-md-4">
                                    <small class="text-muted">Profit Margin</small>
                                    <h4 class="text-primary">
                                        <?php 
                                        $margin = $product['unit_price'] > 0 ? 
                                            (($product['unit_price'] - $product['cost_price']) / $product['unit_price'] * 100) : 0;
                                        echo number_format($margin, 1) . '%';
                                        ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <!-- Stock Status Card -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Stock Status</h5>
                </div>
                <div class="card-body text-center">
                    <div class="mb-3">
                        <h1 class="<?php echo $product['quantity_on_hand'] <= $product['reorder_level'] ? 'text-danger' : 'text-success'; ?>">
                            <?php echo number_format($product['quantity_on_hand']); ?>
                        </h1>
                        <p class="text-muted">Units On Hand</p>
                    </div>
                    
                    <div class="row text-center">
                        <div class="col-4">
                            <small class="text-muted">Reserved</small>
                            <p><?php echo number_format($product['quantity_reserved']); ?></p>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Available</small>
                            <p class="fw-bold text-success"><?php echo number_format($product['quantity_available']); ?></p>
                        </div>
                        <div class="col-4">
                            <small class="text-muted">Reorder Level</small>
                            <p><?php echo number_format($product['reorder_level']); ?></p>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <?php if ($product['quantity_on_hand'] == 0): ?>
                            <span class="badge bg-danger">Out of Stock</span>
                        <?php elseif ($product['quantity_on_hand'] <= $product['reorder_level']): ?>
                            <span class="badge bg-warning">Low Stock</span>
                        <?php else: ?>
                            <span class="badge bg-success">In Stock</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (has_permission('staff')): ?>
                    <div class="mt-3">
                        <button onclick="showAdjustmentModal()" class="btn btn-sm btn-outline-primary w-100">
                            <i class="fas fa-edit me-2"></i>Adjust Stock
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabs -->
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="productTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="movements-tab" data-bs-toggle="tab" data-bs-target="#movements" type="button" role="tab">
                        <i class="fas fa-exchange-alt me-2"></i>Stock Movements
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales" type="button" role="tab">
                        <i class="fas fa-shopping-cart me-2"></i>Sales Orders
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="purchases-tab" data-bs-toggle="tab" data-bs-target="#purchases" type="button" role="tab">
                        <i class="fas fa-truck me-2"></i>Purchase Orders
                    </button>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <div class="tab-content" id="productTabContent">
                <!-- Stock Movements Tab -->
                <div class="tab-pane fade show active" id="movements" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Recent Stock Movements</h5>
                        <a href="stock_movements.php?product_id=<?php echo $product['product_id']; ?>" class="btn btn-sm btn-outline-primary">
                            View All
                        </a>
                    </div>
                    
                    <?php if (empty($stock_movements)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-history fa-2x mb-2"></i>
                            <p>No stock movements found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Quantity</th>
                                        <th>Reference</th>
                                        <th>User</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stock_movements as $movement): ?>
                                        <tr>
                                            <td><?php echo format_date($movement['movement_date'], 'M d, H:i'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $movement['movement_type'] === 'in' ? 'success' : 
                                                         ($movement['movement_type'] === 'out' ? 'danger' : 'warning'); 
                                                ?>">
                                                    <?php echo ucfirst($movement['movement_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="<?php echo $movement['movement_type'] === 'in' ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $movement['movement_type'] === 'in' ? '+' : '-'; ?>
                                                    <?php echo abs($movement['quantity']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo ucfirst($movement['reference_type']); ?></small>
                                                <?php if ($movement['reference_id']): ?>
                                                    <br><small class="text-muted">#<?php echo $movement['reference_id']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($movement['full_name']); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Sales Orders Tab -->
                <div class="tab-pane fade" id="sales" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Recent Sales Orders</h5>
                        <a href="sales_orders.php" class="btn btn-sm btn-outline-primary">
                            View All
                        </a>
                    </div>
                    
                    <?php if (empty($sales_orders)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-shopping-cart fa-2x mb-2"></i>
                            <p>No sales orders found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Date</th>
                                        <th>Quantity</th>
                                        <th>Price</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales_orders as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($order['so_number']); ?></td>
                                            <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                            <td><?php echo format_date($order['order_date'], 'M d, Y'); ?></td>
                                            <td><?php echo number_format($order['quantity']); ?></td>
                                            <td>
                                                <?php 
                                                $total = $order['quantity'] * $order['unit_price'];
                                                $discount = $total * ($order['discount_percent'] / 100);
                                                $final_total = $total - $discount;
                                                echo format_currency($final_total);
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $order['status'] === 'completed' ? 'success' : 
                                                         ($order['status'] === 'delivered' ? 'info' : 
                                                         ($order['status'] === 'shipped' ? 'warning' : 'secondary')); 
                                                ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Purchase Orders Tab -->
                <div class="tab-pane fade" id="purchases" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Recent Purchase Orders</h5>
                        <a href="purchase_orders.php" class="btn btn-sm btn-outline-primary">
                            View All
                        </a>
                    </div>
                    
                    <?php if (empty($purchase_orders)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-truck fa-2x mb-2"></i>
                            <p>No purchase orders found.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Order #</th>
                                        <th>Supplier</th>
                                        <th>Date</th>
                                        <th>Ordered</th>
                                        <th>Received</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($purchase_orders as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($order['po_number']); ?></td>
                                            <td><?php echo htmlspecialchars($order['supplier_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo format_date($order['order_date'], 'M d, Y'); ?></td>
                                            <td><?php echo number_format($order['quantity_ordered']); ?></td>
                                            <td><?php echo number_format($order['quantity_received']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $order['status'] === 'received' ? 'success' : 
                                                         ($order['status'] === 'sent' ? 'primary' : 'secondary'); 
                                                ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Stock Adjustment Modal -->
<?php if (has_permission('staff')): ?>
<div class="modal fade" id="adjustmentModal" tabindex="-1" aria-labelledby="adjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adjustmentModalLabel">Quick Stock Adjustment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="stock_adjustment.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="adjust_stock">
                    <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Product</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($product['product_name']); ?>" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Current Stock</label>
                        <input type="text" class="form-control" value="<?php echo $product['quantity_on_hand']; ?>" readonly>
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
function showAdjustmentModal() {
    var modal = new bootstrap.Modal(document.getElementById('adjustmentModal'));
    modal.show();
}
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
