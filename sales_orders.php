<?php
require_once 'config/config.php';
require_once 'includes/intelligence.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

function generate_unique_so_number(PDO $db)
{
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = 'SO-' . date('Y') . '-' . str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT COUNT(*) FROM sales_orders WHERE so_number = :so_number');
        $stmt->bindValue(':so_number', $candidate);
        $stmt->execute();

        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Unable to generate a unique sales number. Please try again.');
}

function format_customer_type_label($customer_type)
{
    return $customer_type === 'registered' ? 'Registered' : 'Walk-in';
}

function pcims_normalize_customer_name_for_storage($customer_type, $customer_name)
{
    $customer_name = trim((string) $customer_name);

    if ($customer_type !== 'registered' || $customer_name === '') {
        return '';
    }

    return $customer_name;
}

function pcims_get_customer_name_for_display(array $sales_order, $walk_in_label = 'Walk-in')
{
    $customer_type = $sales_order['customer_type'] ?? pcims_get_customer_type(
        $sales_order['customer_name'] ?? '',
        $sales_order['customer_email'] ?? '',
        $sales_order['customer_phone'] ?? ''
    );

    if ($customer_type === 'walk_in') {
        return $walk_in_label;
    }

    $customer_name = trim((string) ($sales_order['customer_name'] ?? ''));
    if ($customer_name === '' || in_array(strtolower($customer_name), ['walk-in customer', 'walkin', 'walk in', 'null'], true)) {
        return $walk_in_label;
    }

    return $customer_name;
}

function pcims_consolidate_sales_order_items_for_display(array $raw_items)
{
    $consolidated = [];

    foreach ($raw_items as $item) {
        $product_id = (int) ($item['product_id'] ?? 0);
        $unit_price = (float) ($item['unit_price'] ?? 0);
        $discount_percent = (float) ($item['discount_percent'] ?? 0);
        $discount_type = trim((string) ($item['discount_type'] ?? ''));
        $key = implode('|', [
            $product_id,
            number_format($unit_price, 2, '.', ''),
            number_format($discount_percent, 2, '.', ''),
            strtolower($discount_type),
        ]);

        if (!isset($consolidated[$key])) {
            $item['quantity'] = (int) ($item['quantity'] ?? 0);
            $item['unit_price'] = $unit_price;
            $item['discount_percent'] = $discount_percent;
            $item['discount_type'] = $discount_type !== '' ? $discount_type : null;
            $consolidated[$key] = $item;
            continue;
        }

        $consolidated[$key]['quantity'] += (int) ($item['quantity'] ?? 0);
    }

    return array_values($consolidated);
}

function pcims_resolve_created_sales_order_id(PDO $db, $so_number)
{
    $so_number = trim((string) $so_number);
    if ($so_number === '') {
        throw new RuntimeException('Cannot resolve a sales order without a sales number.');
    }

    $last_insert_id = (int) $db->lastInsertId();
    if ($last_insert_id > 0) {
        return $last_insert_id;
    }

    try {
        $stmt = $db->query('SELECT LAST_INSERT_ID()');
        $select_last_insert_id = (int) $stmt->fetchColumn();
        if ($select_last_insert_id > 0) {
            return $select_last_insert_id;
        }
    } catch (Throwable $exception) {
        // Fall through to lookup-based recovery.
    }

    $lookup_stmt = $db->prepare('SELECT so_id FROM sales_orders WHERE so_number = :so_number ORDER BY so_id DESC LIMIT 1');
    $lookup_stmt->bindValue(':so_number', $so_number, PDO::PARAM_STR);
    $lookup_stmt->execute();
    $resolved_id = $lookup_stmt->fetchColumn();
    if ($resolved_id !== false && (int) $resolved_id > 0) {
        return (int) $resolved_id;
    }

    // Recovery path for databases where so_id is misconfigured and new rows land at 0.
    $zero_id_stmt = $db->prepare('SELECT COUNT(*) FROM sales_orders WHERE so_number = :so_number AND so_id = 0');
    $zero_id_stmt->bindValue(':so_number', $so_number, PDO::PARAM_STR);
    $zero_id_stmt->execute();
    $has_zero_id_row = (int) $zero_id_stmt->fetchColumn() > 0;

    if ($has_zero_id_row) {
        $max_stmt = $db->query('SELECT COALESCE(MAX(so_id), 0) FROM sales_orders');
        $next_id = max(1, ((int) $max_stmt->fetchColumn()) + 1);

        $repair_stmt = $db->prepare(
            'UPDATE sales_orders
             SET so_id = :new_so_id
             WHERE so_number = :so_number AND so_id = 0'
        );
        $repair_stmt->bindValue(':new_so_id', $next_id, PDO::PARAM_INT);
        $repair_stmt->bindValue(':so_number', $so_number, PDO::PARAM_STR);
        $repair_stmt->execute();

        $verify_stmt = $db->prepare('SELECT so_id FROM sales_orders WHERE so_number = :so_number ORDER BY so_id DESC LIMIT 1');
        $verify_stmt->bindValue(':so_number', $so_number, PDO::PARAM_STR);
        $verify_stmt->execute();
        $verified_id = (int) $verify_stmt->fetchColumn();
        if ($verified_id > 0) {
            return $verified_id;
        }
    }

    throw new RuntimeException('Unable to resolve the created sales order ID.');
}

$page_title = 'Sales Orders';
$action = $_GET['action'] ?? 'list';
$so_id = $_GET['id'] ?? null;

// Store the original ID for view action to prevent overwriting
$view_so_id = $so_id;

// Enhanced validation for view action
if ($action === 'view' && ($so_id === null || $so_id === '')) {
    $_SESSION['error'] = 'Invalid receipt link. Please select a receipt from the sales orders list.';
    header('Location: sales_orders.php?action=list');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: sales_orders.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($action === 'add') {
            $db->beginTransaction();
            
            try {
                $status = 'completed';
                $so_number = generate_unique_so_number($db);
                $customer_name_input = trim((string) ($_POST['customer_name'] ?? ''));
                $customer_email = trim((string) ($_POST['customer_email'] ?? ''));
                $customer_phone = trim((string) ($_POST['customer_phone'] ?? ''));
                $customer_type = pcims_get_customer_type($customer_name_input, $customer_email, $customer_phone);
                $customer_name = pcims_normalize_customer_name_for_storage($customer_type, $customer_name_input);
                $order_date = !empty($_POST['order_date']) ? $_POST['order_date'] : date('Y-m-d');
                $global_discount_type = pcims_normalize_global_discount_type($_POST['global_discount_type'] ?? 'none');
                $global_discount_value = max(0, (float) ($_POST['global_discount_value'] ?? 0));
                $notes = trim((string) ($_POST['notes'] ?? ''));

                $sale_payload = pcims_calculate_sale_payload(
                    $db,
                    isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : [],
                    $global_discount_type,
                    $global_discount_value
                );

                $query = "INSERT INTO sales_orders (
                              so_number, customer_name, customer_email, customer_phone, customer_type,
                              order_date, status, global_discount_type, global_discount_value,
                              global_discount_amount, total_amount, notes, created_by
                          ) VALUES (
                              :so_number, :customer_name, :customer_email, :customer_phone, :customer_type,
                              :order_date, :status, :global_discount_type, :global_discount_value,
                              :global_discount_amount, :total_amount, :notes, :created_by
                          )";

                $stmt = $db->prepare($query);
                $stmt->bindValue(':so_number', $so_number);
                $stmt->bindValue(':customer_name', $customer_name, PDO::PARAM_STR);
                $stmt->bindValue(':customer_email', $customer_email !== '' ? $customer_email : null, $customer_email !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':customer_phone', $customer_phone !== '' ? $customer_phone : null, $customer_phone !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':customer_type', $customer_type);
                $stmt->bindValue(':order_date', $order_date);
                $stmt->bindValue(':status', $status);
                $stmt->bindValue(':global_discount_type', $sale_payload['global_discount_type']);
                $stmt->bindValue(':global_discount_value', $sale_payload['global_discount_value']);
                $stmt->bindValue(':global_discount_amount', $sale_payload['global_discount_amount']);
                $stmt->bindValue(':total_amount', $sale_payload['total_amount']);
                $stmt->bindValue(':notes', $notes !== '' ? $notes : null, $notes !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmt->bindValue(':created_by', (int) $_SESSION['user_id'], PDO::PARAM_INT);
                $stmt->execute();

                $so_id = pcims_resolve_created_sales_order_id($db, $so_number);

                $item_stmt = $db->prepare(
                    "INSERT INTO sales_order_items (so_id, product_id, quantity, unit_price, discount_percent, discount_type)
                     VALUES (:so_id, :product_id, :quantity, :unit_price, :discount_percent, :discount_type)
                     ON DUPLICATE KEY UPDATE
                        quantity = quantity + VALUES(quantity),
                        unit_price = VALUES(unit_price),
                        discount_percent = VALUES(discount_percent),
                        discount_type = VALUES(discount_type)"
                );
                $inventory_stmt = $db->prepare(
                    "UPDATE inventory
                     SET quantity_on_hand = quantity_on_hand - :quantity
                     WHERE product_id = :product_id"
                );
                $movement_stmt = $db->prepare(
                    "INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, reference_id, notes, user_id)
                     VALUES (:product_id, 'out', :quantity, 'sale', :reference_id, :notes, :user_id)"
                );

                foreach ($sale_payload['items'] as $item) {
                    $item_stmt->bindValue(':so_id', $so_id, PDO::PARAM_INT);
                    $item_stmt->bindValue(':product_id', $item['product_id'], PDO::PARAM_INT);
                    $item_stmt->bindValue(':quantity', $item['quantity'], PDO::PARAM_INT);
                    $item_stmt->bindValue(':unit_price', $item['unit_price']);
                    $item_stmt->bindValue(':discount_percent', $item['discount_percent']);
                    $item_stmt->bindValue(':discount_type', $item['discount_type'], $item['discount_type'] !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    $item_stmt->execute();

                    $inventory_stmt->bindValue(':quantity', $item['quantity'], PDO::PARAM_INT);
                    $inventory_stmt->bindValue(':product_id', $item['product_id'], PDO::PARAM_INT);
                    $inventory_stmt->execute();

                    $movement_notes = sprintf(
                        'POS sale completed for %s (%s)',
                        $item['product_name'],
                        $so_number
                    );
                    $movement_stmt->bindValue(':product_id', $item['product_id'], PDO::PARAM_INT);
                    $movement_stmt->bindValue(':quantity', -1 * $item['quantity'], PDO::PARAM_INT);
                    $movement_stmt->bindValue(':reference_id', $so_id, PDO::PARAM_INT);
                    $movement_stmt->bindValue(':notes', $movement_notes);
                    $movement_stmt->bindValue(':user_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
                    $movement_stmt->execute();
                }
                
                $db->commit();
                
                check_low_stock_notifications();
                
                log_activity(
                    $_SESSION['user_id'],
                    'pos_sale',
                    sprintf(
                        'POS sale completed: %s | Total: %s | Savings: %s',
                        $so_number,
                        format_currency($sale_payload['total_amount']),
                        format_currency($sale_payload['total_savings'])
                    )
                );
                
                $customer_display = $customer_type === 'registered' && $customer_name !== '' ? $customer_name : 'Walk-in customer';
                add_notification(
                    null,
                    'New Sale Completed',
                    "Sale #{$so_number} for {$customer_display} has been completed successfully.",
                    'success',
                    'order',
                    $so_id
                );
                
                $_SESSION['success'] = 'Sale completed successfully! Receipt generated.';
                header('Location: sales_orders.php?action=view&id=' . $so_id);
                exit();
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            
        } elseif ($action === 'edit' && $so_id) {
            $customer_name_input = trim((string) ($_POST['customer_name'] ?? ''));
            $customer_email = trim((string) ($_POST['customer_email'] ?? ''));
            $customer_phone = trim((string) ($_POST['customer_phone'] ?? ''));
            $customer_type = pcims_get_customer_type($customer_name_input, $customer_email, $customer_phone);
            $customer_name = pcims_normalize_customer_name_for_storage($customer_type, $customer_name_input);

            $query = "UPDATE sales_orders SET customer_name = :customer_name, customer_email = :customer_email, 
                      customer_phone = :customer_phone, customer_type = :customer_type, order_date = :order_date, status = :status, 
                      total_amount = :total_amount, notes = :notes WHERE so_id = :so_id";
            
            $stmt = $db->prepare($query);

            $stmt->bindValue(':customer_name', $customer_name, PDO::PARAM_STR);
            $stmt->bindValue(':customer_email', $customer_email !== '' ? $customer_email : null, $customer_email !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':customer_phone', $customer_phone !== '' ? $customer_phone : null, $customer_phone !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':customer_type', $customer_type);
            $stmt->bindParam(':order_date', $_POST['order_date']);
            $stmt->bindParam(':status', $_POST['status']);
            $stmt->bindParam(':total_amount', $_POST['total_amount']);
            $stmt->bindParam(':notes', $_POST['notes']);
            $stmt->bindParam(':so_id', $so_id);
            
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'so_edit', 'Updated sales order ID: ' . $so_id);
            
            $_SESSION['success'] = 'Sales order updated successfully!';
            header('Location: sales_orders.php');
            exit();
            
        } elseif ($action === 'delete' && $so_id) {
            // Get sales order details before deletion
            $query = "SELECT * FROM sales_orders WHERE so_id = :so_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':so_id', $so_id);
            $stmt->execute();
            $sales_order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sales_order['status'] === 'shipped' || $sales_order['status'] === 'delivered' || $sales_order['status'] === 'completed') {
                $_SESSION['error'] = 'Cannot delete sales order that has been shipped, delivered, or completed.';
                header('Location: sales_orders.php');
                exit();
            }
            
            // Check if user has permission to cancel
            if (!has_permission('manager')) {
                $_SESSION['error'] = 'You do not have permission to cancel sales orders.';
                header('Location: sales_orders.php');
                exit();
            }
            
            // Return reserved stock to inventory
            $query = "SELECT soi.product_id, soi.quantity FROM sales_order_items soi WHERE soi.so_id = :so_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':so_id', $so_id);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $query = "UPDATE inventory SET quantity_reserved = quantity_reserved - :quantity WHERE product_id = :product_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':quantity', $item['quantity']);
                $stmt->bindParam(':product_id', $item['product_id']);
                $stmt->execute();
            }
            
            // Delete sales order (soft delete by setting status to cancelled)
            $query = "UPDATE sales_orders SET status = 'cancelled' WHERE so_id = :so_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':so_id', $so_id);
            $stmt->execute();
            
            // Log activity
            log_activity($_SESSION['user_id'], 'so_delete', 'Cancelled sales order ID: ' . $so_id);
            
            $_SESSION['success'] = 'Sales order cancelled successfully!';
            header('Location: sales_orders.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Sales Orders Error: " . $exception->getMessage());
    }
}

// Get sales order data for editing
$sales_order = null;
if ($action === 'edit' && $so_id) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT so.*, u.full_name as created_by_name FROM sales_orders so 
                  LEFT JOIN users u ON so.created_by = u.user_id 
                  WHERE so.so_id = :so_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':so_id', $so_id);
        $stmt->execute();
        
        $sales_order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sales_order) {
            $_SESSION['error'] = 'Sales order not found.';
            header('Location: sales_orders.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: sales_orders.php');
        exit();
    }
}

if ($action === 'edit' && $so_id && $sales_order) {
    include 'includes/header.php';
    
    // Get products for dropdowns
    $products = [];
    $so_items = [];
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get products (only active ones)
        $query = "SELECT p.product_id, p.product_name, p.product_code, p.unit_price, p.image_url, p.status,
                  COALESCE(i.quantity_available, 0) as quantity_available,
                  COALESCE(i.quantity_on_hand, 0) as quantity_on_hand
                  FROM products p 
                  LEFT JOIN inventory i ON p.product_id = i.product_id 
                  WHERE p.status = 'active'
                  ORDER BY p.product_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get categories for filter dropdown
        $categories = [];
        $categoryQuery = "SELECT DISTINCT c.category_id, c.category_name 
                        FROM categories c 
                        LEFT JOIN products p ON c.category_id = p.category_id 
                        WHERE p.status = 'active' AND c.status = 'active'
                        ORDER BY c.category_name";
        $categoryStmt = $db->prepare($categoryQuery);
        $categoryStmt->execute();
        $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get existing SO items
        $query = "SELECT soi.*, p.product_name, p.product_code 
                  FROM sales_order_items soi 
                  LEFT JOIN products p ON soi.product_id = p.product_id 
                  WHERE soi.so_id = :so_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':so_id', $so_id);
        $stmt->execute();
        $so_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        error_log("Products Error: " . $exception->getMessage());
        $_SESSION['error'] = 'Error loading products: ' . $exception->getMessage();
    }
    ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-cash-register me-2"></i>Edit Sales Order
            </h1>
            <a href="sales_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Sales Orders
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" id="soEditForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="total_amount" id="total_amount" value="<?php echo $sales_order['total_amount']; ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="customer_name" class="form-label">Customer Name</label>
                                <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                       value="<?php echo htmlspecialchars(pcims_get_customer_name_for_display($sales_order, '')); ?>"
                                       placeholder="Optional - Leave blank for walk-in customer">
                            </div>
                            
                            <div class="mb-3">
                                <label for="customer_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="customer_email" name="customer_email" 
                                       value="<?php echo htmlspecialchars($sales_order['customer_email']); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="customer_phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="customer_phone" name="customer_phone" 
                                       value="<?php echo htmlspecialchars($sales_order['customer_phone']); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="order_date" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="order_date" name="order_date" 
                                       value="<?php echo $sales_order['order_date']; ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="pending" <?php echo $sales_order['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo $sales_order['status'] === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="shipped" <?php echo $sales_order['status'] === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                    <option value="delivered" <?php echo $sales_order['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                    <option value="completed" <?php echo $sales_order['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($sales_order['notes']); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Existing Order Items -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0">Current Order Items</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Quantity</th>
                                            <th>Unit Price</th>
                                            <th>Discount</th>
                                            <th>Total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($so_items as $item): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($item['product_code']); ?></small>
                                                </td>
                                                <td><?php echo $item['quantity']; ?></td>
                                                <td><?php echo format_currency($item['unit_price']); ?></td>
                                                <td><?php echo $item['discount_percent']; ?>%</td>
                                                <td><?php echo format_currency($item['total_price']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> To modify order items, you may need to cancel and recreate this order.
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Sales Order
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif ($action === 'list') {
    // Get sales orders list
    $sales_orders = [];
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $date_filter = $_GET['date'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT so.*, u.full_name as created_by_name,
                         (SELECT COUNT(*) FROM sales_order_items WHERE so_id = so.so_id) as item_count
                  FROM sales_orders so 
                  LEFT JOIN users u ON so.created_by = u.user_id 
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (so.so_number LIKE :search OR so.customer_name LIKE :search OR so.customer_email LIKE :search)";
            $search_param = "%$search%";
            $params[':search'] = $search_param;
        }
        
        if (!empty($status_filter)) {
            $query .= " AND so.status = :status";
            $params[':status'] = $status_filter;
        }
        
        if (!empty($date_filter)) {
            $query .= " AND DATE(so.order_date) = :date";
            $params[':date'] = $date_filter;
        }
        
        $query .= " ORDER BY so.created_at DESC";
        
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $sales_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Sales Orders List Error: " . $exception->getMessage());
    }
    
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid">
        <div class="sales-page-header d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-cash-register me-2"></i>Sales Receipts
            </h1>
            <a href="sales_orders.php?action=add" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>New Sale
            </a>
        </div>
        
        <!-- SO Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Sales</h5>
                        <h2><?php echo count($sales_orders); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Today's Sales</h5>
                        <h2>
                            <?php 
                            $today = date('Y-m-d');
                            $today_sales = array_filter($sales_orders, function($so) use ($today) { 
                                return date('Y-m-d', strtotime($so['order_date'])) === $today; 
                            });
                            echo count($today_sales);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Completed Sales</h5>
                        <h2>
                            <?php 
                            $completed = array_filter($sales_orders, function($so) { return $so['status'] === 'completed'; });
                            echo count($completed);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Revenue</h5>
                        <h2>
                            <?php 
                            $completed_orders = array_filter($sales_orders, function($so) { return $so['status'] === 'completed'; });
                            $total = array_sum(array_column($completed_orders, 'total_amount'));
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
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                            <option value="shipped" <?php echo $status_filter === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                            <option value="delivered" <?php echo $status_filter === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="date" class="form-control" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
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
        
        <!-- Sales Orders Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="soTable">
                        <thead>
                            <tr>
                                <th>Receipt #</th>
                                <th>Customer</th>
                                <th>Sale Date</th>
                                <th>Items</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Cashier</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sales_orders)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fas fa-cash-register fa-3x mb-3"></i>
                                        <p>No sales receipts found.</p>
                                        <small>Start by creating a new sale using the "New Sale" button above.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sales_orders as $so): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($so['so_number']); ?></strong>
                                            <br><small class="text-muted">Receipt ID: <?php echo $so['so_id']; ?></small>
                                        </td>
                                        <td>
                                            <div>
                                                <strong><?php 
                                                    $customer_display = pcims_get_customer_name_for_display($so, 'Walk-in');
                                                    echo htmlspecialchars($customer_display);
                                                ?></strong>
                                                <?php if (!empty($so['customer_email'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($so['customer_email']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($so['customer_phone'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($so['customer_phone']); ?></small>
                                                <?php endif; ?>
                                                <br><small class="badge bg-light text-dark border"><?php echo htmlspecialchars(format_customer_type_label($so['customer_type'] ?? 'walk_in')); ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo format_date($so['order_date'], 'M d, Y'); ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $so['item_count']; ?></span>
                                            <br><small class="text-muted">items</small>
                                        </td>
                                        <td>
                                            <strong><?php echo format_currency($so['total_amount']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $so['status'] === 'pending' ? 'secondary' : 
                                                     ($so['status'] === 'processing' ? 'primary' : 
                                                     ($so['status'] === 'shipped' ? 'warning' : 
                                                     ($so['status'] === 'delivered' ? 'info' : 
                                                     ($so['status'] === 'completed' ? 'success' : 'danger')))); 
                                            ?>">
                                                <?php echo ucfirst($so['status']); ?>
                                                <?php if ($so['status'] === 'completed'): ?>
                                                    <i class="fas fa-check-circle ms-1"></i>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <i class="fas fa-user-tie text-muted me-1"></i>
                                            <?php echo htmlspecialchars($so['created_by_name']); ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="sales_orders.php?action=view&id=<?php echo $so['so_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View Receipt">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="sales_orders.php?action=view&id=<?php echo $so['so_id']; ?>" 
                                                   class="btn btn-sm btn-outline-success" title="Print Receipt" onclick="window.print(); return false;">
                                                    <i class="fas fa-print"></i>
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
    
    <script>
    function clearFilters() {
        // Reset all filter inputs
        document.querySelector('input[name="search"]').value = '';
        document.querySelector('select[name="status"]').value = '';
        document.querySelector('input[name="date"]').value = '';
        
        // Submit the form to reload with cleared filters
        window.location.href = 'sales_orders.php';
    }
    </script>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif ($action === 'add') {
    include 'includes/header.php';
    
    $products = [];
    $categories = [];
    $product_intelligence = [];
    $product_pairs = [];
    $rule_settings = [];
    try {
        $database = new Database();
        $db = $database->getConnection();
        $rule_settings = pcims_get_rule_settings($db);
        
        $query = "SELECT p.product_id, p.product_name, p.product_code, p.category_id, p.unit_price,
                  p.image_url, p.status, p.reorder_level, p.lead_time_days,
                  COALESCE(i.quantity_available, 0) as quantity_available,
                  COALESCE(i.quantity_on_hand, 0) as quantity_on_hand
                  FROM products p 
                  LEFT JOIN inventory i ON p.product_id = i.product_id 
                  WHERE p.status = 'active'
                  ORDER BY p.product_name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $categoryQuery = "SELECT category_id, category_name
                          FROM categories
                          WHERE status = 'active'
                          ORDER BY category_name";
        $categoryStmt = $db->prepare($categoryQuery);
        $categoryStmt->execute();
        $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

        $product_ids = array_map('intval', array_column($products, 'product_id'));
        if (!empty($product_ids)) {
            $product_intelligence = pcims_get_product_intelligence($db, $product_ids, $rule_settings);
            $product_pairs = pcims_get_product_pair_map($db, $product_ids);
        }
    } catch(PDOException $exception) {
        error_log("Products Error: " . $exception->getMessage());
        $_SESSION['error'] = 'Error loading products. Please try again.';
    }
    ?>
    
    <div class="container-fluid pos-page">
        <div class="d-flex justify-content-between align-items-center mb-4 pos-page-header">
            <h1 class="h3">
                <i class="fas fa-cash-register me-2"></i>Point of Sale (POS)
            </h1>
            <a href="sales_orders.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Sales
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" id="soForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="total_amount" id="total_amount" value="0">
                    <div class="pos-layout">
                    <div class="card pos-panel-card pos-meta-card">
                        <div class="card-header pos-panel-header">
                            <div>
                                <h5 class="mb-1">Order Information</h5>
                                <p class="text-muted mb-0">Add optional customer details and confirm the sale date.</p>
                            </div>
                            <span class="badge bg-light text-dark border">Walk-in supported</span>
                        </div>
                        <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="customer_name" class="form-label">Customer Name</label>
                                <input type="text" class="form-control" id="customer_name" name="customer_name" 
                                       placeholder="Optional - Leave blank for walk-in customer">
                            </div>
                            
                            <div class="mb-3">
                                <label for="customer_email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="customer_email" name="customer_email">
                            </div>
                            
                            <div class="mb-3">
                                <label for="customer_phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="customer_phone" name="customer_phone">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="order_date" class="form-label">Order Date *</label>
                                <input type="date" class="form-control" id="order_date" name="order_date" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>
                    
                    <!-- POS-style Product Selection -->
                    <div class="card mt-4 pos-panel-card pos-catalog-card">
                        <div class="card-header pos-panel-header d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1">Product Selection</h5>
                                <p class="text-muted mb-0">Choose products from the catalog to build the order.</p>
                            </div>
                            <div class="d-flex gap-2 pos-toolbar-controls">
                                <input type="text" class="form-control form-control-sm" id="productSearch" placeholder="Search products...">
                                <select class="form-select form-select-sm" id="categoryFilter">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category_id']; ?>">
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="card-body p-3 pos-catalog-body">
                            <?php if (empty($products)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Products Available</h5>
                                    <p class="text-muted">No products found in the database. Please add products first.</p>
                                    <a href="products.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Add Products
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 pos-catalog-meta">
                                    <small class="text-muted">
                                        Showing <?php echo count($products); ?> products
                                        <?php 
                                        $activeCount = count(array_filter($products, function($p) { return $p['status'] === 'active'; }));
                                        $inStockCount = count(array_filter($products, function($p) { return $p['quantity_available'] > 0; }));
                                        echo "({$activeCount} active, {$inStockCount} in stock)";
                                        ?>
                                    </small>
                                </div>
                                <div id="productGrid" class="product-grid pos-product-grid">
                                <?php foreach ($products as $index => $product): ?>
                                    <?php 
                                    $intelligence = $product_intelligence[$product['product_id']] ?? null;
                                    $isOutOfStock = $product['quantity_available'] <= 0;
                                    $isInactive = $product['status'] === 'inactive' || (isset($product['status']) && $product['status'] === 'inactive');
                                    $cardClass = 'product-card';
                                    if ($isOutOfStock) $cardClass .= ' out-of-stock';
                                    if ($isInactive) $cardClass .= ' inactive-product';
                                    ?>
                                    <div class="<?php echo $cardClass; ?>" 
                                         data-product-id="<?php echo $product['product_id']; ?>" 
                                         data-product-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                         data-product-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                                         data-price="<?php echo $product['unit_price']; ?>"
                                         data-stock="<?php echo $product['quantity_available']; ?>"
                                         data-status="<?php echo $product['status'] ?? 'active'; ?>"
                                          data-category="<?php echo $product['category_id'] ?? ''; ?>">
                                        <div class="product-badges">
                                            <?php if (!empty($intelligence['is_best_seller'])): ?>
                                                <span class="badge bg-success">Best Seller</span>
                                            <?php endif; ?>
                                            <?php if (!empty($intelligence['is_restock_recommended']) && !$isOutOfStock): ?>
                                                <span class="badge bg-warning text-dark">Restock Recommended</span>
                                            <?php endif; ?>
                                            <?php if (!empty($intelligence['is_low_stock']) && !$isOutOfStock): ?>
                                                <span class="badge bg-light text-danger border border-danger">Low Stock</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="product-image">
                                            <?php echo get_product_image($product['image_url'], $product['product_name'], 'small', ['class' => 'product-grid-image']); ?>
                                        </div>
                                        <div class="product-info">
                                            <h6 class="product-name"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                                            <p class="product-code"><?php echo htmlspecialchars($product['product_code']); ?></p>
                                            <div class="product-price"><?php echo format_currency($product['unit_price']); ?></div>
                                            <div class="product-stock">
                                                Stock: <?php echo $product['quantity_available']; ?>
                                                <?php if ($isOutOfStock): ?>
                                                    <span class="badge bg-danger ms-1">Out of Stock</span>
                                                <?php endif; ?>
                                                <?php if ($isInactive): ?>
                                                    <span class="badge bg-secondary ms-1">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($intelligence): ?>
                                                <div class="product-insight">
                                                    Avg/day: <?php echo number_format($intelligence['average_daily_sales'], 2); ?>
                                                    <br>Forecast (<?php echo $intelligence['forecast_days']; ?>d): <?php echo number_format($intelligence['forecast_quantity']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" 
                                                class="btn btn-sm add-to-cart <?php echo ($isOutOfStock || $isInactive) ? 'btn-secondary disabled' : 'btn-primary'; ?>" 
                                                onclick="addToCart(<?php echo $product['product_id']; ?>, this)"
                                                <?php echo ($isOutOfStock || $isInactive) ? 'disabled' : ''; ?>>
                                            <i class="fas fa-<?php echo ($isOutOfStock || $isInactive) ? 'times' : 'plus'; ?>"></i> 
                                            <?php echo ($isOutOfStock || $isInactive) ? 'Unavailable' : 'Add'; ?>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Order Items -->
                    <div class="card mt-4 pos-panel-card pos-order-card">
                        <div class="card-header pos-panel-header d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1">Order Summary</h5>
                                <p class="text-muted mb-0">Review items, apply discounts, and complete the sale.</p>
                            </div>
                            <div class="d-flex gap-2">
                                <span class="badge bg-light text-dark border align-self-center" id="orderItemCount">0 items</span>
                                <button type="button" class="btn btn-sm btn-warning" onclick="clearCart()">
                                    <i class="fas fa-trash me-1"></i>Clear Cart
                                </button>
                            </div>
                        </div>
                        <div class="card-body pos-order-body">
                            <div id="cartEmptyState" class="pos-empty-cart">
                                <i class="fas fa-cart-plus"></i>
                                <p class="mb-0">No items added yet. Select products from the left to start this order.</p>
                            </div>
                            <div id="itemsContainer">
                                <!-- Items will be added here dynamically -->
                            </div>
                            
                            <?php if (empty($products)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="fas fa-box-open fa-3x mb-3"></i>
                                    <p>No products available. Please add products to inventory first.</p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="row mt-4 g-3">
                                <div class="col-lg-7">
                                    <div class="card border-0 bg-light h-100" id="pairSuggestionsPanel" style="display: none;">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-lightbulb me-2 text-warning"></i>Suggested Pairings
                                                </h6>
                                                <small class="text-muted" id="pairSuggestionsHint">Smart add-on recommendations</small>
                                            </div>
                                            <div id="pairSuggestionsContainer" class="pair-suggestions-grid"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-5">
                                    <div class="card border-0 bg-light mb-3">
                                        <div class="card-body">
                                            <h6 class="mb-3">
                                                <i class="fas fa-percent me-2 text-primary"></i>Cart Discount
                                            </h6>
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <label for="global_discount_type" class="form-label small text-muted">Discount Type</label>
                                                    <select class="form-select" id="global_discount_type" name="global_discount_type">
                                                        <option value="none">None</option>
                                                        <option value="percent">Percentage</option>
                                                        <option value="fixed">Fixed Amount</option>
                                                    </select>
                                                </div>
                                                <div class="col-6">
                                                    <label for="global_discount_value" class="form-label small text-muted">Value</label>
                                                    <input type="number" class="form-control" id="global_discount_value" name="global_discount_value" value="0" min="0" step="0.01">
                                                </div>
                                            </div>
                                            <small class="text-muted d-block mt-2">Applied to the entire cart and shared across all items in this transaction.</small>
                                        </div>
                                    </div>
                                    <div class="card border-0 bg-light" id="totalSection" style="display: none;">
                                        <div class="card-body">
                                            <div class="summary-row">
                                                <span>Subtotal</span>
                                                <strong id="subtotalAmount"><?php echo format_currency(0); ?></strong>
                                            </div>
                                            <div class="summary-row text-primary">
                                                <span>Cart Discount</span>
                                                <strong id="globalDiscountAmount">-<?php echo format_currency(0); ?></strong>
                                            </div>
                                            <div class="summary-row text-success">
                                                <span>Total Savings</span>
                                                <strong id="totalSavingsAmount"><?php echo format_currency(0); ?></strong>
                                            </div>
                                            <div class="summary-row summary-grand">
                                                <span>Total Amount</span>
                                                <strong id="grandTotalDisplay"><?php echo format_currency(0); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4 pos-action-row">
                        <div class="col-12">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="sales_orders.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-success btn-lg" data-loading-text="Completing Sale...">
                                    <i class="fas fa-cash-register me-2"></i>Complete Sale
                                </button>
                            </div>
                        </div>
                    </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    let cartItems = {};
    
    // Product search functionality
    document.getElementById('productSearch').addEventListener('input', function() {
        filterProducts();
    });
    
    // Combined filter function
    function filterProducts() {
        const searchTerm = document.getElementById('productSearch').value.toLowerCase();
        const selectedCategory = document.getElementById('categoryFilter').value;
        const productCards = document.querySelectorAll('.product-card');
        
        productCards.forEach(card => {
            const productName = card.dataset.productName.toLowerCase();
            const productCode = card.dataset.productCode.toLowerCase();
            const productCategory = card.dataset.category;
            
            // Check if product matches both search term and category filter
            const matchesSearch = !searchTerm || productName.includes(searchTerm) || productCode.includes(searchTerm);
            const matchesCategory = !selectedCategory || productCategory === selectedCategory;
            
            if (matchesSearch && matchesCategory) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }
    
    // Category filter functionality
    document.getElementById('categoryFilter').addEventListener('change', function() {
        const selectedCategory = this.value;
        const searchTerm = document.getElementById('productSearch').value.toLowerCase();
        const productCards = document.querySelectorAll('.product-card');
        
        productCards.forEach(card => {
            const productName = card.dataset.productName.toLowerCase();
            const productCode = card.dataset.productCode.toLowerCase();
            const productCategory = card.dataset.category;
            
            // Check if product matches both search term and category filter
            const matchesSearch = !searchTerm || productName.includes(searchTerm) || productCode.includes(searchTerm);
            const matchesCategory = !selectedCategory || productCategory === selectedCategory;
            
            if (matchesSearch && matchesCategory) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    });
    
    // Add product to cart
    function addToCart(productId) {
        const productCard = document.querySelector(`[data-product-id="${productId}"]`);
        const productName = productCard.dataset.productName;
        const productCode = productCard.dataset.productCode;
        const price = parseFloat(productCard.dataset.price);
        const stock = parseInt(productCard.dataset.stock);
        const status = productCard.dataset.status;
        
        // Prevent multiple simultaneous clicks
        const addButton = event.target;
        if (addButton.disabled) return;
        addButton.disabled = true;
        
        setTimeout(() => { addButton.disabled = false; }, 500);
        
        // Check if product is available for ordering
        if (status === 'inactive') {
            showNotification('This product is currently inactive and cannot be ordered!', 'error');
            return;
        }
        
        if (stock <= 0) {
            showNotification('This product is out of stock!', 'error');
            return;
        }
        
        // Check if product already in cart
        if (cartItems[productId]) {
            // Increase quantity
            const currentQty = parseInt(cartItems[productId].quantity);
            if (currentQty < stock) {
                cartItems[productId].quantity = currentQty + 1;
                updateCartItem(productId);
            } else {
                showNotification('Insufficient stock available!', 'error');
                return;
            }
        } else {
            // Add new item to cart
            cartItems[productId] = {
                productId: productId,
                productName: productName,
                productCode: productCode,
                price: price,
                quantity: 1
            };
            createCartItem(productId);
        }
        
        updateTotals();
        showNotification('Product added to cart!', 'success');
    }
    
    // Create cart item in the DOM
    function createCartItem(productId) {
        const item = cartItems[productId];
        const container = document.getElementById('itemsContainer');
        
        const itemRow = document.createElement('div');
        itemRow.className = 'cart-item row align-items-center mb-3';
        itemRow.id = `cart-item-${productId}`;
        
        itemRow.innerHTML = `
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <strong>${item.productName}</strong>
                        <br><small class="text-muted">${item.productCode}</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="input-group input-group-sm">
                    <button type="button" class="btn btn-outline-secondary" onclick="updateQuantity(${productId}, -1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" class="form-control text-center" name="items[${productId}][quantity]" 
                           value="${item.quantity}" min="1" readonly>
                    <button type="button" class="btn btn-outline-secondary" onclick="updateQuantity(${productId}, 1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control form-control-sm" name="items[${productId}][unit_price]" 
                       value="${item.price}" step="0.01" min="0" readonly>
            </div>
            <div class="col-md-2">
                <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                <div class="small text-muted">Cart-level discount only</div>
            </div>
            <div class="col-md-1">
                <input type="text" class="form-control form-control-sm text-end" readonly value="${(item.price * item.quantity).toFixed(2)}">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="removeFromCart(${productId})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        
        container.appendChild(itemRow);
        
        // Show total section
        document.getElementById('totalSection').style.display = 'flex';
    }
    
    // Update existing cart item
    function updateCartItem(productId) {
        const item = cartItems[productId];
        const itemRow = document.getElementById(`cart-item-${productId}`);
        
        if (itemRow) {
            // Update the input fields with current item data
            itemRow.querySelector(`input[name="items[${productId}][quantity]"]`).value = item.quantity;
            itemRow.querySelector(`input[name="items[${productId}][unit_price]"]`).value = item.price;
            itemRow.querySelector(`input[name="items[${productId}][product_id]"]`).value = productId;
            
            // Update displayed total
            const totalInput = itemRow.querySelector('input[type="text"][readonly]');
            if (totalInput) {
                totalInput.value = (item.price * item.quantity).toFixed(2);
            }
        }
    }
    
    // Update quantity
    function updateQuantity(productId, change) {
        const productCard = document.querySelector(`[data-product-id="${productId}"]`);
        const maxStock = parseInt(productCard.dataset.stock);
        const item = cartItems[productId];
        
        const newQuantity = item.quantity + change;
        
        if (newQuantity >= 1 && newQuantity <= maxStock) {
            item.quantity = newQuantity;
            updateCartItem(productId);
            updateTotals();
        } else if (newQuantity > maxStock) {
            showNotification('Insufficient stock available!', 'error');
        }
    }
    
    // Remove from cart
    function removeFromCart(productId) {
        delete cartItems[productId];
        const itemRow = document.getElementById(`cart-item-${productId}`);
        if (itemRow) {
            itemRow.remove();
        }
        
        updateTotals();
        
        // Hide total section if cart is empty
        if (Object.keys(cartItems).length === 0) {
            document.getElementById('totalSection').style.display = 'none';
        }
    }
    
    // Clear entire cart
    function clearCart() {
        if (confirm('Are you sure you want to clear all items from the cart?')) {
            cartItems = {};
            document.getElementById('itemsContainer').innerHTML = '';
            document.getElementById('totalSection').style.display = 'none';
            updateTotals();
        }
    }
    
    // Update totals
    function updateTotals() {
        let grandTotal = 0;
        
        Object.values(cartItems).forEach(item => {
            const itemTotal = item.price * item.quantity;
            grandTotal += itemTotal;
        });
        
        const grandTotalElement = document.getElementById('grandTotal');
        if (grandTotalElement) {
            grandTotalElement.textContent = 'PHP ' + grandTotal.toFixed(2);
        }
        document.getElementById('total_amount').value = grandTotal.toFixed(2);
    }
    
    // Show notification
    function showNotification(message, type) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    }
    
    // Form validation before submit
    document.getElementById('soForm').addEventListener('submit', function(e) {
        if (Object.keys(cartItems).length === 0) {
            e.preventDefault();
            showNotification('Please add at least one product to the order!', 'error');
            return false;
        }
        
        // Additional validation: ensure no duplicate product IDs in form data
        const form = e.target;
        const productInputs = form.querySelectorAll('input[name*="[product_id]"]');
        const productIds = [];
        let hasDuplicates = false;
        
        productInputs.forEach(input => {
            const productId = input.value;
            if (productId && productIds.includes(productId)) {
                hasDuplicates = true;
            } else if (productId) {
                productIds.push(productId);
            }
        });
        
        if (hasDuplicates) {
            e.preventDefault();
            showNotification('Duplicate products detected in cart. Please refresh and try again.', 'error');
            return false;
        }
        
        // Disable submit button to prevent double submission
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
        }
    });
    </script>

    <script>
    const currencyFormatter = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP'
    });
    const productSuggestions = <?php echo json_encode($product_pairs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    cartItems = {};

    function formatCurrencyValue(value) {
        return currencyFormatter.format(Number(value || 0));
    }

    function updateOrderSummaryState() {
        const itemCountElement = document.getElementById('orderItemCount');
        const emptyState = document.getElementById('cartEmptyState');
        const totalSection = document.getElementById('totalSection');
        const count = Object.keys(cartItems).length;

        if (itemCountElement) {
            itemCountElement.textContent = `${count} item${count === 1 ? '' : 's'}`;
        }

        if (emptyState) {
            emptyState.style.display = count === 0 ? 'flex' : 'none';
        }

        if (totalSection) {
            totalSection.style.display = count === 0 ? 'none' : 'block';
        }
    }

    function filterProducts() {
        const searchTerm = document.getElementById('productSearch').value.toLowerCase();
        const selectedCategory = document.getElementById('categoryFilter').value;

        document.querySelectorAll('.product-card').forEach((card) => {
            const productName = card.dataset.productName.toLowerCase();
            const productCode = card.dataset.productCode.toLowerCase();
            const productCategory = card.dataset.category;
            const matchesSearch = !searchTerm || productName.includes(searchTerm) || productCode.includes(searchTerm);
            const matchesCategory = !selectedCategory || productCategory === selectedCategory;
            card.style.display = matchesSearch && matchesCategory ? 'block' : 'none';
        });
    }

    function renderPairSuggestions(productId) {
        const suggestions = productSuggestions[String(productId)] || productSuggestions[productId] || [];
        const panel = document.getElementById('pairSuggestionsPanel');
        const container = document.getElementById('pairSuggestionsContainer');
        const hint = document.getElementById('pairSuggestionsHint');

        if (!panel || !container || !hint) {
            return;
        }

        if (!suggestions.length) {
            panel.style.display = 'none';
            container.innerHTML = '';
            return;
        }

        hint.textContent = `Recommended companions for ${cartItems[productId]?.productName || 'this item'}`;
        container.innerHTML = suggestions.map((suggestion) => {
            const isUnavailable = Number(suggestion.quantity_available) <= 0;
            return `
                <div class="pair-suggestion-card ${isUnavailable ? 'is-disabled' : ''}">
                    <div class="fw-semibold">${suggestion.product_name}</div>
                    <div class="small text-muted">${suggestion.product_code}</div>
                    <div class="small">${formatCurrencyValue(suggestion.unit_price)} • Stock ${suggestion.quantity_available}</div>
                    <button type="button"
                            class="btn btn-sm ${isUnavailable ? 'btn-outline-secondary' : 'btn-outline-primary'} mt-2"
                            ${isUnavailable ? 'disabled' : ''}
                            onclick="addToCart(${suggestion.product_id}, this)">
                        ${isUnavailable ? 'Unavailable' : 'Add Suggestion'}
                    </button>
                </div>
            `;
        }).join('');
        panel.style.display = 'block';
    }

    function addToCart(productId, button) {
        const productCard = document.querySelector(`[data-product-id="${productId}"]`);
        if (!productCard) {
            showNotification('Product details could not be loaded.', 'error');
            return;
        }

        const productName = productCard.dataset.productName;
        const productCode = productCard.dataset.productCode;
        const price = parseFloat(productCard.dataset.price);
        const stock = parseInt(productCard.dataset.stock, 10);
        const status = productCard.dataset.status;
        const addButton = button || productCard.querySelector('.add-to-cart');

        if (addButton && addButton.disabled) {
            return;
        }

        if (status === 'inactive') {
            showNotification('This product is currently inactive and cannot be ordered.', 'error');
            return;
        }

        if (stock <= 0) {
            showNotification('This product is out of stock.', 'error');
            return;
        }

        if (addButton) {
            addButton.disabled = true;
            setTimeout(() => {
                addButton.disabled = false;
            }, 400);
        }

        if (cartItems[productId]) {
            const newQuantity = cartItems[productId].quantity + 1;
            if (newQuantity > stock) {
                showNotification('Insufficient stock available.', 'error');
                return;
            }
            cartItems[productId].quantity = newQuantity;
            updateCartItem(productId);
        } else {
            cartItems[productId] = {
                productId,
                productName,
                productCode,
                price,
                quantity: 1
            };
            createCartItem(productId);
        }

        renderPairSuggestions(productId);
        updateTotals();
        showNotification('Product added to cart.', 'success');
    }

    function createCartItem(productId) {
        const item = cartItems[productId];
        const container = document.getElementById('itemsContainer');
        const itemRow = document.createElement('div');
        itemRow.className = 'cart-item';
        itemRow.id = `cart-item-${productId}`;
        itemRow.innerHTML = `
            <div class="cart-item__top">
                <div class="cart-item__identity">
                    <strong>${item.productName}</strong>
                    <small class="text-muted">${item.productCode}</small>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm cart-item__remove" onclick="removeFromCart(${productId})">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <div class="cart-item__controls">
                <div class="input-group input-group-sm cart-item__quantity">
                    <button type="button" class="btn btn-outline-secondary" onclick="updateQuantity(${productId}, -1)">
                        <i class="fas fa-minus"></i>
                    </button>
                    <input type="number" class="form-control text-center" name="items[${productId}][quantity]" value="${item.quantity}" min="1" readonly>
                    <button type="button" class="btn btn-outline-secondary" onclick="updateQuantity(${productId}, 1)">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <input type="number" class="form-control form-control-sm cart-item__price" name="items[${productId}][unit_price]" value="${item.price}" step="0.01" min="0" readonly>
                <input type="hidden" name="items[${productId}][product_id]" value="${productId}">
                <div class="cart-item__line-total">
                    <span class="text-muted small">Line Total</span>
                    <strong class="item-total-display">${formatCurrencyValue(item.price * item.quantity)}</strong>
                </div>
            </div>
        `;
        container.appendChild(itemRow);
        updateCartItem(productId);
        updateOrderSummaryState();
    }

    function updateCartItem(productId) {
        const item = cartItems[productId];
        const itemRow = document.getElementById(`cart-item-${productId}`);
        if (!item || !itemRow) {
            return;
        }

        itemRow.querySelector(`input[name="items[${productId}][quantity]"]`).value = item.quantity;
        itemRow.querySelector(`input[name="items[${productId}][unit_price]"]`).value = item.price;
        itemRow.querySelector(`input[name="items[${productId}][product_id]"]`).value = productId;
        itemRow.querySelector('.item-total-display').textContent = formatCurrencyValue(item.price * item.quantity);
    }

    function updateQuantity(productId, change) {
        const productCard = document.querySelector(`[data-product-id="${productId}"]`);
        const maxStock = parseInt(productCard.dataset.stock, 10);
        const item = cartItems[productId];
        const newQuantity = item.quantity + change;

        if (newQuantity < 1) {
            return;
        }

        if (newQuantity > maxStock) {
            showNotification('Insufficient stock available.', 'error');
            return;
        }

        item.quantity = newQuantity;
        updateCartItem(productId);
        updateTotals();
    }

    function removeFromCart(productId) {
        delete cartItems[productId];
        const itemRow = document.getElementById(`cart-item-${productId}`);
        if (itemRow) {
            itemRow.remove();
        }

        updateOrderSummaryState();
        if (Object.keys(cartItems).length === 0) {
            document.getElementById('pairSuggestionsPanel').style.display = 'none';
        }
        updateTotals();
    }

    function clearCart() {
        if (!confirm('Are you sure you want to clear all items from the cart?')) {
            return;
        }

        cartItems = {};
        document.getElementById('itemsContainer').innerHTML = '';
        document.getElementById('pairSuggestionsPanel').style.display = 'none';
        updateOrderSummaryState();
        updateTotals();
    }

    function updateTotals() {
        let subtotal = 0;

        Object.values(cartItems).forEach((item) => {
            subtotal += item.price * item.quantity;
        });

        const globalDiscountType = document.getElementById('global_discount_type').value;
        const globalDiscountValue = Math.max(0, parseFloat(document.getElementById('global_discount_value').value || '0'));
        let globalDiscountAmount = 0;

        if (globalDiscountType === 'percent') {
            globalDiscountAmount = subtotal * Math.min(globalDiscountValue, 100) / 100;
        } else if (globalDiscountType === 'fixed') {
            globalDiscountAmount = Math.min(subtotal, globalDiscountValue);
        }

        const totalSavings = globalDiscountAmount;
        const grandTotal = Math.max(0, subtotal - globalDiscountAmount);

        document.getElementById('subtotalAmount').textContent = formatCurrencyValue(subtotal);
        document.getElementById('globalDiscountAmount').textContent = `-${formatCurrencyValue(globalDiscountAmount)}`;
        document.getElementById('totalSavingsAmount').textContent = formatCurrencyValue(totalSavings);
        document.getElementById('grandTotalDisplay').textContent = formatCurrencyValue(grandTotal);
        document.getElementById('total_amount').value = grandTotal.toFixed(2);
        updateOrderSummaryState();
    }

    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'danger' : 'success'} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(notification);
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    }

    document.getElementById('global_discount_type').addEventListener('change', updateTotals);
    document.getElementById('global_discount_value').addEventListener('input', updateTotals);
    updateOrderSummaryState();
    updateTotals();
    </script>
    
    <style>
    /* POS-style Product Grid Styles */
    .product-grid {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
        gap: 1rem;
        max-height: 600px;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 1rem;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        background: #f8f9fa;
        scrollbar-width: thin;
        scrollbar-color: #dee2e6 #f8f9fa;
    }
    
    /* Custom scrollbar for webkit browsers */
    .product-grid::-webkit-scrollbar {
        width: 8px;
    }
    
    .product-grid::-webkit-scrollbar-track {
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .product-grid::-webkit-scrollbar-thumb {
        background: #dee2e6;
        border-radius: 4px;
        border: 2px solid #f8f9fa;
    }
    
    .product-grid::-webkit-scrollbar-thumb:hover {
        background: #adb5bd;
    }
    
    .product-card {
        border: 2px solid #e9ecef;
        border-radius: 0.5rem;
        padding: 1rem;
        text-align: center;
        background: white;
        transition: all 0.3s ease;
        cursor: pointer;
        position: relative;
        min-height: 200px;
        display: flex;
        flex-direction: column;
    }
    
    .product-card:hover {
        border-color: #007bff;
        box-shadow: 0 4px 12px rgba(0,123,255,0.15);
        transform: translateY(-2px);
    }
    
    .product-card.selected {
        border-color: #28a745;
        background-color: #f8fff9;
    }
    
    .product-image {
        flex: 0 0 auto;
        margin: 0 auto 0.5rem auto;
        width: 60px;
        height: 60px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .product-image img,
    .product-grid-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 4px;
    }
    
    .product-image i {
        font-size: 2.5rem;
        color: #6c757d;
    }
    
    .product-info {
        flex: 1 1 auto;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    
    .product-name {
        font-size: 0.875rem;
        font-weight: 600;
        margin: 0 0 0.25rem 0;
        line-height: 1.2;
        height: 2.4em;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    
    .product-code {
        font-size: 0.75rem;
        color: #6c757d;
        margin: 0 0 0.5rem 0;
        font-weight: 500;
    }
    
    .product-price {
        font-size: 1rem;
        font-weight: 700;
        color: #007bff;
        margin-bottom: 0.25rem;
    }
    
    .product-stock {
        font-size: 0.75rem;
        color: #6c757d;
        margin-bottom: 0.75rem;
    }

    .product-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.25rem;
        justify-content: center;
        margin-bottom: 0.75rem;
        min-height: 1.5rem;
    }

    .product-insight {
        font-size: 0.72rem;
        color: #495057;
        line-height: 1.4;
        background: #f8f9fa;
        border-radius: 0.5rem;
        padding: 0.45rem 0.5rem;
    }

    .pair-suggestions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem;
    }

    .pair-suggestion-card {
        border: 1px solid #dee2e6;
        border-radius: 0.75rem;
        padding: 0.75rem;
        background: #fff;
    }

    .pair-suggestion-card.is-disabled {
        opacity: 0.6;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.35rem 0;
        border-bottom: 1px dashed #dee2e6;
    }

    .summary-row.summary-grand {
        border-bottom: 0;
        padding-top: 0.8rem;
        margin-top: 0.5rem;
        font-size: 1.05rem;
        font-weight: 700;
    }
    
    .add-to-cart {
        flex: 0 0 auto;
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    /* Product state styles */
    .product-card.out-of-stock {
        opacity: 0.7;
        border-color: #dc3545;
    }
    
    .product-card.inactive-product {
        opacity: 0.6;
        border-color: #6c757d;
        background-color: #f8f9fa;
    }
    
    .product-card.out-of-stock .product-name,
    .product-card.inactive-product .product-name {
        color: #6c757d;
    }
    
    /* Cart Items Styles */
    .cart-item {
        background-color: #f8f9fa;
        border-radius: 0.375rem;
        padding: 0.75rem;
        border: 1px solid #e9ecef;
    }
    
    .cart-item:hover {
        background-color: #f1f3f5;
    }
    
    .input-group-sm .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .input-group-sm .form-control {
        padding: 0.25rem;
        font-size: 0.875rem;
        text-align: center;
    }
    
    /* Responsive Grid */
    @media (max-width: 1400px) {
        .product-grid {
            grid-template-columns: repeat(5, 1fr);
        }
    }
    
    @media (max-width: 1200px) {
        .product-grid {
            grid-template-columns: repeat(4, 1fr);
        }
    }
    
    @media (max-width: 992px) {
        .product-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        
        .product-card {
            min-height: 180px;
            padding: 0.75rem;
        }
        
        .product-image {
            width: 50px;
            height: 50px;
            margin: 0 auto 0.5rem auto;
        }
        
        .product-image i {
            font-size: 2rem;
        }
        
        .product-name {
            font-size: 0.8rem;
        }
    }
    
    @media (max-width: 768px) {
        .product-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .product-card {
            min-height: 160px;
            padding: 0.5rem;
        }
        
        .product-image {
            width: 45px;
            height: 45px;
            margin: 0 auto 0.5rem auto;
        }
        
        .product-image i {
            font-size: 1.75rem;
        }
        
        .product-name {
            font-size: 0.75rem;
            height: 2em;
        }
        
        .product-code {
            font-size: 0.7rem;
        }
        
        .product-price {
            font-size: 0.875rem;
        }
        
        .product-stock {
            font-size: 0.7rem;
        }
        
        .add-to-cart {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        /* Cart items responsive */
        .cart-item {
            flex-direction: column;
            align-items: stretch;
            gap: 0.5rem;
        }
        
        .cart-item .col-md-1,
        .cart-item .col-md-2,
        .cart-item .col-md-4 {
            width: 100%;
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        .cart-item .d-flex {
            flex-direction: column;
            align-items: stretch;
        }
    }
    
    @media (max-width: 576px) {
        .product-grid {
            grid-template-columns: 1fr;
            gap: 0.5rem;
        }
        
        .product-card {
            min-height: 120px;
            padding: 0.75rem;
            flex-direction: row;
            align-items: center;
        }
        
        .product-image {
            width: 40px;
            height: 40px;
            margin: 0 0.75rem 0 0;
            flex-shrink: 0;
        }
        
        .product-image i {
            font-size: 2rem;
        }
        
        .product-info {
            flex: 1;
            text-align: left;
        }
        
        .product-name {
            height: auto;
            -webkit-line-clamp: 1;
            margin-bottom: 0.5rem;
        }
        
        .add-to-cart {
            width: auto;
            margin-left: 0.75rem;
        }
    }
    
    /* Search and filter styling */
    .card-header .d-flex {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    @media (max-width: 768px) {
        .card-header .d-flex {
            flex-direction: column;
            align-items: stretch;
        }
        
        .card-header .d-flex > * {
            width: 100%;
        }
        
        #productSearch,
        #categoryFilter {
            width: 100% !important;
        }
    }
    
    /* Notification styling */
    .alert {
        border: none;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    /* Empty state styling */
    .text-center.py-4 {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 0.5rem;
        margin: 1rem 0;
    }
    
    /* Form validation feedback */
    .was-validated .form-control:invalid {
        border-color: #dc3545;
    }
    
    /* Loading state */
    .product-card.loading {
        opacity: 0.6;
        pointer-events: none;
    }
    
    .product-card.loading::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 20px;
        height: 20px;
        margin: -10px 0 0 -10px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #007bff;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* POS layout overrides */
    .pos-page-header {
        gap: 1rem;
    }

    .pos-layout {
        display: grid;
        grid-template-columns: minmax(0, 1.7fr) minmax(340px, 0.9fr);
        grid-template-areas:
            "catalog meta"
            "catalog order"
            "catalog actions";
        gap: 1.25rem;
        align-items: start;
    }

    .pos-panel-card {
        border-radius: 22px;
        overflow: hidden;
    }

    .pos-panel-header {
        padding: 1rem 1.25rem;
        background: linear-gradient(180deg, rgba(255,255,255,0.94), rgba(248,244,237,0.9));
    }

    .pos-panel-header h5 {
        margin: 0;
    }

    .pos-meta-card {
        grid-area: meta;
    }

    .pos-catalog-card {
        grid-area: catalog;
    }

    .pos-order-card {
        grid-area: order;
        position: sticky;
        top: 6.5rem;
    }

    .pos-action-row {
        grid-area: actions;
        margin-top: 0 !important;
    }

    .pos-action-row .d-flex {
        justify-content: stretch !important;
    }

    .pos-action-row .btn {
        flex: 1;
    }

    .pos-toolbar-controls {
        flex-wrap: wrap;
        align-items: center;
    }

    .pos-toolbar-controls #productSearch {
        width: 240px;
    }

    .pos-toolbar-controls #categoryFilter {
        width: 180px;
    }

    .pos-catalog-body {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .pos-catalog-meta {
        padding: 0.35rem 0.2rem 0;
    }

    .pos-product-grid {
        max-height: calc(100vh - 18rem);
        min-height: 540px;
        align-content: start;
    }

    .pos-order-body {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .pos-order-body .row.mt-4.g-3 > [class*="col-"] {
        width: 100%;
        flex: 0 0 100%;
        max-width: 100%;
    }

    .pos-empty-cart {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem;
        margin-bottom: 0.9rem;
        border: 1px dashed rgba(95, 45, 24, 0.18);
        border-radius: 16px;
        background: rgba(255,255,255,0.7);
        color: #6f625b;
    }

    .pos-empty-cart i {
        font-size: 1.2rem;
        color: #c53d2f;
    }

    .pos-order-body .row.mt-4.g-3 {
        margin-top: 0 !important;
    }

    .pos-cart-list {
        display: flex;
        flex-direction: column;
        gap: 0.8rem;
    }

    .cart-item {
        background: #ffffff;
        border-radius: 18px;
        padding: 0.95rem;
        border: 1px solid rgba(95, 45, 24, 0.1);
        box-shadow: 0 10px 22px rgba(45, 26, 19, 0.06);
    }

    .cart-item__top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.85rem;
    }

    .cart-item__identity {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    .cart-item__identity strong {
        font-size: 0.95rem;
        line-height: 1.2;
    }

    .cart-item__identity small {
        margin-top: 0.2rem;
    }

    .cart-item__remove {
        flex-shrink: 0;
    }

    .cart-item__controls {
        display: grid;
        grid-template-columns: minmax(112px, 132px) minmax(88px, 112px);
        gap: 0.65rem;
        align-items: start;
    }

    .cart-item__quantity {
        width: 100%;
    }

    .cart-item__price,
    .cart-item__discount {
        min-height: 40px;
    }

    .cart-item__line-total {
        grid-column: 1 / -1;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding-top: 0.2rem;
    }

    .pos-order-count {
        font-weight: 700;
    }

    .pos-page .summary-row {
        border-bottom-style: solid;
        border-bottom-color: rgba(95, 45, 24, 0.08);
    }

    .pos-page .summary-row.summary-grand {
        margin-top: 0.75rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(95, 45, 24, 0.12);
    }

    @media (max-width: 1199px) {
        .pos-layout {
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
        }

        .pos-product-grid {
            min-height: 500px;
        }
    }

    @media (max-width: 991px) {
        .pos-layout {
            grid-template-columns: 1fr;
            grid-template-areas:
                "meta"
                "catalog"
                "order"
                "actions";
        }

        .pos-order-card {
            position: static;
            top: auto;
        }

        .pos-product-grid {
            max-height: none;
            min-height: 0;
        }
    }

    @media (max-width: 768px) {
        .pos-page-header {
            flex-direction: column;
            align-items: stretch !important;
        }

        .pos-toolbar-controls {
            width: 100%;
        }

        .pos-toolbar-controls #productSearch,
        .pos-toolbar-controls #categoryFilter {
            width: 100% !important;
        }

        .cart-item__controls {
            grid-template-columns: 1fr;
        }
    }
    </style>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif ($action === 'view') {
    // Get sales order details for viewing
    $sales_order = null;
    $so_items = [];
    
    // Use the stored view ID to prevent overwriting
    $so_id = $view_so_id;
    
    if ($so_id !== null && $so_id !== '') {
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            // Get SO details
            $query = "SELECT so.*, u.full_name as created_by_name
                      FROM sales_orders so 
                      LEFT JOIN users u ON so.created_by = u.user_id 
                      WHERE so.so_id = :so_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':so_id', $so_id);
            $stmt->execute();
            
            $sales_order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$sales_order) {
                $_SESSION['error'] = 'Sales order not found.';
                header('Location: sales_orders.php');
                exit();
            }
            
            // Get SO items
            $query = "SELECT soi.*, p.product_name, p.product_code 
                      FROM sales_order_items soi 
                      LEFT JOIN products p ON soi.product_id = p.product_id 
                      WHERE soi.so_id = :so_id
                      ORDER BY soi.soi_id ASC";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':so_id', $so_id);
            $stmt->execute();
            
            $raw_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $so_items = pcims_consolidate_sales_order_items_for_display($raw_items);

            $receipt_subtotal = 0;
            $receipt_item_discount_total = 0;
            foreach ($so_items as $item) {
                $line_subtotal = $item['quantity'] * $item['unit_price'];
                $line_discount = $line_subtotal * ($item['discount_percent'] / 100);
                $receipt_subtotal += $line_subtotal;
                $receipt_item_discount_total += $line_discount;
            }
            $receipt_global_discount_amount = (float) ($sales_order['global_discount_amount'] ?? 0);
            $receipt_total_savings = $receipt_item_discount_total + $receipt_global_discount_amount;
            $customer_type_label = format_customer_type_label($sales_order['customer_type'] ?? 'walk_in');
            $customer_name_display = pcims_get_customer_name_for_display($sales_order, '');
            
        } catch(PDOException $exception) {
            $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
            header('Location: sales_orders.php');
            exit();
        }
    } else {
        // Enhanced error handling with debugging
        $debug_info = [
            'action' => $action,
            'so_id' => $so_id,
            'view_so_id' => $view_so_id ?? null,
            'get_params' => $_GET,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ];
        error_log("Receipt access error: " . json_encode($debug_info));
        
        $_SESSION['error'] = 'Sales order ID not provided. Please click on a receipt link from the sales orders list.';
        header('Location: sales_orders.php?action=list');
        exit();
    }
    
    include 'includes/header.php';
    ?>
    
    <style>
    .receipt-container {
        max-width: 400px;
        margin: 0 auto;
        background: white;
        padding: 20px;
        font-family: 'Courier New', monospace;
        border: 1px solid #ddd;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .receipt-header {
        text-align: center;
        border-bottom: 2px dashed #333;
        padding-bottom: 15px;
        margin-bottom: 15px;
    }
    
    .receipt-company {
        font-size: 18px;
        font-weight: bold;
        margin-bottom: 5px;
    }
    
    .receipt-address {
        font-size: 12px;
        margin-bottom: 3px;
    }
    
    .receipt-contact {
        font-size: 11px;
        color: #666;
    }
    
    .receipt-info {
        margin-bottom: 15px;
        font-size: 12px;
    }
    
    .receipt-info-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 3px;
    }
    
    .receipt-items {
        margin-bottom: 15px;
        font-size: 11px;
    }
    
    .receipt-item {
        margin-bottom: 8px;
        border-bottom: 1px dotted #ccc;
        padding-bottom: 5px;
    }
    
    .receipt-item-name {
        font-weight: bold;
        margin-bottom: 2px;
    }
    
    .receipt-item-details {
        display: flex;
        justify-content: space-between;
        font-size: 10px;
        color: #666;
    }
    
    .receipt-totals {
        border-top: 2px dashed #333;
        padding-top: 10px;
        margin-bottom: 15px;
        font-size: 12px;
    }
    
    .receipt-total-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 3px;
    }
    
    .receipt-grand-total {
        font-weight: bold;
        font-size: 14px;
        border-top: 1px solid #333;
        padding-top: 5px;
        margin-top: 5px;
    }
    
    .receipt-footer {
        text-align: center;
        border-top: 2px dashed #333;
        padding-top: 15px;
        font-size: 11px;
        color: #666;
    }
    
    .receipt-thank-you {
        font-weight: bold;
        margin-bottom: 5px;
        font-size: 12px;
    }
    
    @media print {
        .receipt-container {
            box-shadow: none;
            border: none;
            margin: 0;
            padding: 10px;
        }
        
        body * {
            visibility: hidden;
        }
        
        .receipt-container, .receipt-container * {
            visibility: visible;
        }
        
        .receipt-container {
            position: absolute;
            left: 0;
            top: 0;
        }
    }
    </style>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-cash-register me-2"></i>
                <?php echo $sales_order['status'] === 'completed' ? 'Sales Receipt' : 'Sales Order Details'; ?>
            </h1>
            <div>
                <button onclick="window.print()" class="btn btn-outline-primary me-2">
                    <i class="fas fa-print me-2"></i><?php echo $sales_order['status'] === 'completed' ? 'Print Receipt' : 'Print'; ?>
                </button>
                <a href="sales_orders.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Sales Orders
                </a>
            </div>
        </div>
        
        <?php if ($sales_order['status'] === 'completed'): ?>
        <!-- Professional Receipt Layout -->
        <div class="receipt-container">
            <div class="receipt-header">
                <div class="receipt-company">PERSONAL COLLECTION DIRECT SELLING</div>
                <div class="receipt-address">123 Main Street, City</div>
                <div class="receipt-address">Metro Manila, Philippines</div>
                <div class="receipt-contact">Tel: (02) 1234-5678 | Email: info@pcollection.com</div>
            </div>
            
            <div class="receipt-info">
                <div class="receipt-info-row">
                    <span>Receipt #:</span>
                    <span><?php echo htmlspecialchars($sales_order['so_number']); ?></span>
                </div>
                <div class="receipt-info-row">
                    <span>Date:</span>
                    <span><?php echo format_date($sales_order['created_at'], 'M d, Y h:i A'); ?></span>
                </div>
                <div class="receipt-info-row">
                    <span>Cashier:</span>
                    <span><?php echo htmlspecialchars($sales_order['created_by_name']); ?></span>
                </div>
                <?php if ($customer_name_display !== ''): ?>
                <div class="receipt-info-row">
                    <span>Customer:</span>
                    <span><?php echo htmlspecialchars($customer_name_display); ?></span>
                </div>
                <?php endif; ?>
                <div class="receipt-info-row">
                    <span>Customer Type:</span>
                    <span><?php echo htmlspecialchars($customer_type_label); ?></span>
                </div>
            </div>
            
            <div class="receipt-items">
                <?php foreach ($so_items as $item): ?>
                <div class="receipt-item">
                    <div class="receipt-item-name">
                        <?php echo htmlspecialchars($item['product_name']); ?>
                    </div>
                    <div class="receipt-item-details">
                        <span><?php echo number_format($item['quantity']); ?> x <?php echo format_currency($item['unit_price']); ?></span>
                        <span><?php echo format_currency($item['quantity'] * $item['unit_price'] * (1 - $item['discount_percent']/100)); ?></span>
                    </div>
                    <?php if (!empty($item['discount_percent']) && $item['discount_percent'] > 0): ?>
                    <div class="receipt-item-details" style="color: #28a745;">
                        <span>
                            <?php echo htmlspecialchars($item['discount_type'] ?: 'Discount'); ?>
                            (<?php echo $item['discount_percent']; ?>%)
                        </span>
                        <span>-<?php echo format_currency($item['quantity'] * $item['unit_price'] * ($item['discount_percent']/100)); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="receipt-totals">
                <div class="receipt-total-row">
                    <span>Subtotal:</span>
                    <span><?php echo format_currency($receipt_subtotal); ?></span>
                </div>
                <?php if ($receipt_item_discount_total > 0): ?>
                <div class="receipt-total-row" style="color: #28a745;">
                    <span>Item Discounts:</span>
                    <span>-<?php echo format_currency($receipt_item_discount_total); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($receipt_global_discount_amount > 0): ?>
                <div class="receipt-total-row" style="color: #0d6efd;">
                    <span>Cart Discount:</span>
                    <span>-<?php echo format_currency($receipt_global_discount_amount); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($receipt_total_savings > 0): ?>
                <div class="receipt-total-row" style="color: #198754;">
                    <span>Total Savings:</span>
                    <span><?php echo format_currency($receipt_total_savings); ?></span>
                </div>
                <?php endif; ?>
                <div class="receipt-total-row">
                    <span>VAT (12%):</span>
                    <span><?php 
                        $vat_amount = $sales_order['total_amount'] * 0.12 / 1.12;
                        echo format_currency($vat_amount);
                    ?></span>
                </div>
                <div class="receipt-grand-total">
                    <div class="receipt-total-row">
                        <span>TOTAL:</span>
                        <span><?php echo format_currency($sales_order['total_amount']); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="receipt-footer">
                <div class="receipt-thank-you">THANK YOU FOR YOUR PURCHASE!</div>
                <div>Please come again</div>
                <div style="margin-top: 10px; font-size: 10px;">
                    <div>This is a computer-generated receipt</div>
                    <div>No signature required</div>
                </div>
                <?php if (!empty($sales_order['notes'])): ?>
                <div style="margin-top: 10px; border-top: 1px dashed #ccc; padding-top: 10px;">
                    <strong>Notes:</strong> <?php echo htmlspecialchars($sales_order['notes']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Original order details view for non-completed orders -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-file-invoice me-2"></i>
                    Sales Order #<?php echo htmlspecialchars($sales_order['so_number']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3">Order Information</h6>
                        <table class="table table-sm table-borderless">
                            <tr>
                                <td><strong>SO Number:</strong></td>
                                <td><?php echo htmlspecialchars($sales_order['so_number']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Order Date:</strong></td>
                                <td><?php echo format_date($sales_order['order_date'], 'F d, Y'); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Status:</strong></td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo $sales_order['status'] === 'pending' ? 'secondary' : 
                                             ($sales_order['status'] === 'processing' ? 'primary' : 
                                             ($sales_order['status'] === 'shipped' ? 'warning' : 
                                             ($sales_order['status'] === 'delivered' ? 'info' : 
                                             ($sales_order['status'] === 'completed' ? 'success' : 'danger')))); 
                                    ?>">
                                        <?php echo ucfirst($sales_order['status']); ?>
                                        <?php if ($sales_order['status'] === 'completed'): ?>
                                            <i class="fas fa-check-circle ms-1"></i>
                                        <?php endif; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Created By:</strong></td>
                                <td><?php echo htmlspecialchars($sales_order['created_by_name']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Customer Type:</strong></td>
                                <td><?php echo htmlspecialchars(format_customer_type_label($sales_order['customer_type'] ?? 'walk_in')); ?></td>
                            </tr>
                        </table>
                    </div>
                    <?php 
                    $customer_display = pcims_get_customer_name_for_display($sales_order, '');
                    $has_customer_info = !empty($sales_order['customer_email']) || !empty($sales_order['customer_phone']) || $customer_display !== '';
                ?>
                    
                    <?php if ($has_customer_info): ?>
                    <div class="col-md-6">
                        <h6 class="text-muted mb-3">Customer Information</h6>
                        <table class="table table-sm table-borderless">
                            <?php if ($customer_display !== ''): ?>
                            <tr>
                                <td><strong>Customer Name:</strong></td>
                                <td><?php echo htmlspecialchars($customer_display); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($sales_order['customer_email'])): ?>
                            <tr>
                                <td><strong>Email:</strong></td>
                                <td><?php echo htmlspecialchars($sales_order['customer_email']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if (!empty($sales_order['customer_phone'])): ?>
                            <tr>
                                <td><strong>Phone:</strong></td>
                                <td><?php echo htmlspecialchars($sales_order['customer_phone']); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($sales_order['notes'])): ?>
                <div class="mt-3">
                    <h6 class="text-muted mb-2">Notes</h6>
                    <p class="form-control-plaintext"><?php echo nl2br(htmlspecialchars($sales_order['notes'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- SO Items Table -->
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
                                <th class="text-center">Discount</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($so_items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo htmlspecialchars($item['product_code']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php echo number_format($item['quantity']); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo format_currency($item['unit_price']); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if (!empty($item['discount_type'])): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($item['discount_type']); ?></span>
                                            <br><small class="text-muted">(<?php echo $item['discount_percent']; ?>%)</small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo format_currency($item['quantity'] * $item['unit_price'] * (1 - $item['discount_percent']/100)); ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <th colspan="5" class="text-end">Total Amount:</th>
                                <th class="text-end">
                                    <h4 class="mb-0"><?php echo format_currency($sales_order['total_amount']); ?></h4>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <?php if ($sales_order['status'] === 'completed'): ?>
        <!-- Receipt Footer -->
        <div class="card mt-4">
            <div class="card-body text-center">
                <div class="mb-3">
                    <i class="fas fa-check-circle text-success fa-3x mb-2"></i>
                    <h5 class="text-success">Payment Completed</h5>
                    <p class="text-muted">Thank you for your purchase!</p>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <strong>Transaction Date:</strong><br>
                            <?php echo format_date($sales_order['order_date'], 'F d, Y H:i'); ?>
                        </small>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted">
                            <strong>Receipt ID:</strong><br>
                            #<?php echo htmlspecialchars($sales_order['so_number']); ?>
                        </small>
                    </div>
                </div>
                <hr>
                <small class="text-muted">
                    <em>This is a computer-generated receipt and is valid without signature.</em>
                </small>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
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
    header('Location: sales_orders.php');
    exit();
}
?>
