<?php
require_once 'config/config.php';
require_once 'includes/intelligence.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

// Image upload handler function
function handleImageUpload($file) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $max_size = 2 * 1024 * 1024; // 2MB
    $upload_dir = 'uploads/products/';
    
    // Create upload directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Use enhanced validation
    if (!is_valid_image_file($file)) {
        throw new Exception('Invalid file type, size, or corrupted image. Only JPG, PNG, and GIF files under 2MB are allowed.');
    }
    
    // Generate unique filename
    $filename = uniqid('product_') . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filename;
    } else {
        throw new Exception('Failed to upload file.');
    }
}

// Generate unique product code function
function generateUniqueProductCode($db) {
    $prefix = 'PC';
    $timestamp = date('Ymd');
    $random = mt_rand(1000, 9999);
    $product_code = $prefix . $timestamp . $random;
    
    // Check if the generated code already exists
    $check_query = "SELECT COUNT(*) as count FROM products WHERE product_code = :product_code";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':product_code', $product_code);
    $check_stmt->execute();
    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    // If code exists, generate a new one
    if ($result['count'] > 0) {
        return generateUniqueProductCode($db); // Recursive call with new random number
    }
    
    return $product_code;
}

$page_title = 'Products';
$action = $_GET['action'] ?? 'list';
$product_id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: products.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($action === 'add') {
            // Start transaction for data consistency
            $db->beginTransaction();
            
            try {
                // Auto-generate product code if not provided
                if (empty($_POST['product_code'])) {
                    $_POST['product_code'] = generateUniqueProductCode($db);
                }
                
                // Validate required fields
                $required_fields = ['product_name', 'unit_price', 'cost_price'];
                foreach ($required_fields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception(ucwords(str_replace('_', ' ', $field)) . ' is required.');
                    }
                }
                
                // Validate category_id and supplier_id if provided
                if (!empty($_POST['category_id']) && !is_numeric($_POST['category_id'])) {
                    throw new Exception('Category must be a valid selection.');
                }
                if (!empty($_POST['supplier_id']) && !is_numeric($_POST['supplier_id'])) {
                    throw new Exception('Supplier must be a valid selection.');
                }
                
                // Validate numeric fields
                if (!is_numeric($_POST['unit_price']) || $_POST['unit_price'] < 0) {
                    throw new Exception('Unit price must be a positive number.');
                }
                if (!is_numeric($_POST['cost_price']) || $_POST['cost_price'] < 0) {
                    throw new Exception('Cost price must be a positive number.');
                }
                if (!empty($_POST['lead_time_days']) && (!is_numeric($_POST['lead_time_days']) || $_POST['lead_time_days'] < 1)) {
                    throw new Exception('Lead time must be at least 1 day.');
                }
                
                // Check for duplicate product code
                $check_query = "SELECT COUNT(*) as count FROM products WHERE product_code = :product_code";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':product_code', $_POST['product_code']);
                $check_stmt->execute();
                $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    throw new Exception('Product code already exists. Please use a unique code.');
                }
                
                // Add new product
                $query = "INSERT INTO products (product_code, product_name, description, category_id, supplier_id, 
                          unit_price, cost_price, reorder_level, lead_time_days, unit_of_measure, status, image_url) 
                          VALUES (:product_code, :product_name, :description, :category_id, :supplier_id, 
                          :unit_price, :cost_price, :reorder_level, :lead_time_days, :unit_of_measure, :status, :image_url)";
                
                $stmt = $db->prepare($query);
                
                $stmt->bindParam(':product_code', $_POST['product_code']);
                $stmt->bindParam(':product_name', $_POST['product_name']);
                $stmt->bindParam(':description', $_POST['description']);
                
                // Handle nullable fields
                $category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
                $supplier_id = !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
                $stmt->bindParam(':category_id', $category_id);
                $stmt->bindParam(':supplier_id', $supplier_id);
                
                $stmt->bindParam(':unit_price', $_POST['unit_price']);
                $stmt->bindParam(':cost_price', $_POST['cost_price']);
                $stmt->bindParam(':reorder_level', $_POST['reorder_level']);
                $lead_time_days = max(1, (int) ($_POST['lead_time_days'] ?? 3));
                $stmt->bindValue(':lead_time_days', $lead_time_days, PDO::PARAM_INT);
                $stmt->bindParam(':unit_of_measure', $_POST['unit_of_measure']);
                $stmt->bindParam(':status', $_POST['status']);
                
                // Handle image upload
                $image_url = '';
                if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $image_url = handleImageUpload($_FILES['product_image']);
                    } catch (Exception $e) {
                        $_SESSION['error'] = 'Image upload failed: ' . $e->getMessage();
                        // Continue without image upload
                    }
                }
                $stmt->bindParam(':image_url', $image_url);
                
                $stmt->execute();
                
                // Get the new product ID
                $product_id = $db->lastInsertId();

                // Save predefined pair suggestions
                pcims_save_product_pairs($db, $product_id, $_POST['pair_suggestions'] ?? [], $_SESSION['user_id']);
                
                // Add initial quantity to inventory if provided
                if (!empty($_POST['initial_quantity']) && $_POST['initial_quantity'] > 0) {
                    $inventory_query = "INSERT INTO inventory (product_id, quantity_on_hand, last_updated) 
                                       VALUES (:product_id, :quantity, NOW())";
                    $inventory_stmt = $db->prepare($inventory_query);
                    $inventory_stmt->bindParam(':product_id', $product_id);
                    $inventory_stmt->bindParam(':quantity', $_POST['initial_quantity']);
                    $inventory_stmt->execute();
                }
                
                // Commit transaction
                $db->commit();
                
                // Log activity
                log_activity($_SESSION['user_id'], 'product_add', 'Added new product: ' . $_POST['product_name']);
                
                $_SESSION['success'] = 'Product added successfully!';
                header('Location: products.php');
                exit();
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                $db->rollback();
                
                // Handle specific database errors
                if ($e->getCode() == 23000) {
                    // Integrity constraint violation
                    if (strpos($e->getMessage(), 'product_code') !== false) {
                        $_SESSION['error'] = 'Product code already exists. Please use a unique code.';
                    } else {
                        $_SESSION['error'] = 'Unable to save the product because of a data integrity issue.';
                    }
                } else {
                    $_SESSION['error'] = 'Failed to add product. Please try again.';
                }
                
                error_log("Product Add Error: " . $e->getMessage());
                header('Location: products.php?action=add');
                exit();
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollback();
                $_SESSION['error'] = 'Failed to add product. Please try again.';
                error_log("Product Add Error: " . $e->getMessage());
                header('Location: products.php?action=add');
                exit();
            }
        } elseif ($action === 'edit' && $product_id) {
            // Start transaction for data consistency
            $db->beginTransaction();
            
            try {
                // Validate required fields
                $required_fields = ['product_code', 'product_name', 'unit_price', 'cost_price'];
                foreach ($required_fields as $field) {
                    if (empty($_POST[$field])) {
                        throw new Exception(ucwords(str_replace('_', ' ', $field)) . ' is required.');
                    }
                }
                
                // Validate category_id and supplier_id if provided
                if (!empty($_POST['category_id']) && !is_numeric($_POST['category_id'])) {
                    throw new Exception('Category must be a valid selection.');
                }
                if (!empty($_POST['supplier_id']) && !is_numeric($_POST['supplier_id'])) {
                    throw new Exception('Supplier must be a valid selection.');
                }
                
                // Validate numeric fields
                if (!is_numeric($_POST['unit_price']) || $_POST['unit_price'] < 0) {
                    throw new Exception('Unit price must be a positive number.');
                }
                if (!is_numeric($_POST['cost_price']) || $_POST['cost_price'] < 0) {
                    throw new Exception('Cost price must be a positive number.');
                }
                if (!empty($_POST['lead_time_days']) && (!is_numeric($_POST['lead_time_days']) || $_POST['lead_time_days'] < 1)) {
                    throw new Exception('Lead time must be at least 1 day.');
                }
                
                // Check for duplicate product code (excluding current product)
                $check_query = "SELECT COUNT(*) as count FROM products WHERE product_code = :product_code AND product_id != :product_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':product_code', $_POST['product_code']);
                $check_stmt->bindParam(':product_id', $product_id);
                $check_stmt->execute();
                $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result['count'] > 0) {
                    throw new Exception('Product code already exists. Please use a unique code.');
                }
                
                // Update existing product
                $query = "UPDATE products SET product_code = :product_code, product_name = :product_name, 
                          description = :description, category_id = :category_id, supplier_id = :supplier_id, 
                          unit_price = :unit_price, cost_price = :cost_price, reorder_level = :reorder_level, lead_time_days = :lead_time_days,
                          unit_of_measure = :unit_of_measure, status = :status, image_url = :image_url 
                          WHERE product_id = :product_id";
                
                $stmt = $db->prepare($query);
                
                $stmt->bindParam(':product_code', $_POST['product_code']);
                $stmt->bindParam(':product_name', $_POST['product_name']);
                $stmt->bindParam(':description', $_POST['description']);
                
                // Handle nullable fields
                $category_id = !empty($_POST['category_id']) ? $_POST['category_id'] : null;
                $supplier_id = !empty($_POST['supplier_id']) ? $_POST['supplier_id'] : null;
                $stmt->bindParam(':category_id', $category_id);
                $stmt->bindParam(':supplier_id', $supplier_id);
                
                $stmt->bindParam(':unit_price', $_POST['unit_price']);
                $stmt->bindParam(':cost_price', $_POST['cost_price']);
                $stmt->bindParam(':reorder_level', $_POST['reorder_level']);
                $lead_time_days = max(1, (int) ($_POST['lead_time_days'] ?? 3));
                $stmt->bindValue(':lead_time_days', $lead_time_days, PDO::PARAM_INT);
                $stmt->bindParam(':unit_of_measure', $_POST['unit_of_measure']);
                $stmt->bindParam(':status', $_POST['status']);
                $stmt->bindParam(':product_id', $product_id);
                
                // Handle image upload
                $image_url = $_POST['current_image_url'] ?? '';
                if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
                    try {
                        $new_image_url = handleImageUpload($_FILES['product_image']);
                        if ($new_image_url) {
                            $image_url = $new_image_url;
                        }
                    } catch (Exception $e) {
                        $_SESSION['error'] = 'Image upload failed: ' . $e->getMessage();
                        // Continue with current image
                    }
                }
                $stmt->bindParam(':image_url', $image_url);
                
                $stmt->execute();
                
                // Update inventory quantity if provided
                if (isset($_POST['initial_quantity']) && is_numeric($_POST['initial_quantity'])) {
                    // Check if inventory record exists
                    $check_inventory = "SELECT inventory_id FROM inventory WHERE product_id = :product_id";
                    $check_stmt = $db->prepare($check_inventory);
                    $check_stmt->bindParam(':product_id', $product_id);
                    $check_stmt->execute();
                    
                    if ($check_stmt->fetch()) {
                        // Get current quantity before update
                        $current_query = "SELECT quantity_on_hand FROM inventory WHERE product_id = :product_id";
                        $current_stmt = $db->prepare($current_query);
                        $current_stmt->bindParam(':product_id', $product_id);
                        $current_stmt->execute();
                        $old_quantity = $current_stmt->fetchColumn();
                        $new_quantity = $_POST['initial_quantity'];
                        
                        // Update existing inventory
                        $update_inventory = "UPDATE inventory SET quantity_on_hand = :quantity, last_updated = NOW() WHERE product_id = :product_id";
                        $update_stmt = $db->prepare($update_inventory);
                        $update_stmt->bindParam(':quantity', $new_quantity);
                        $update_stmt->bindParam(':product_id', $product_id);
                        $update_stmt->execute();
                        
                        // Record stock movement
                        $quantity_change = $new_quantity - $old_quantity;
                        $movement_type = $quantity_change >= 0 ? 'in' : 'out';
                        $movement_quantity = abs($quantity_change);
                        
                        if ($quantity_change != 0) {
                            $stock_movement_query = "INSERT INTO stock_movements (product_id, user_id, movement_type, quantity, movement_date, reference_type, reference_id) 
                                                   VALUES (:product_id, :user_id, :movement_type, :quantity, NOW(), 'adjustment', :product_id)";
                            $stock_stmt = $db->prepare($stock_movement_query);
                            $stock_stmt->bindParam(':product_id', $product_id);
                            $stock_stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $stock_stmt->bindParam(':movement_type', $movement_type);
                            $stock_stmt->bindParam(':quantity', $movement_quantity);
                            $stock_stmt->bindParam(':reference_id', $product_id);
                            $stock_stmt->execute();
                        }
                    } else {
                        // Create new inventory record
                        $insert_inventory = "INSERT INTO inventory (product_id, quantity_on_hand, last_updated) VALUES (:product_id, :quantity, NOW())";
                        $insert_stmt = $db->prepare($insert_inventory);
                        $insert_stmt->bindParam(':product_id', $product_id);
                        $insert_stmt->bindParam(':quantity', $_POST['initial_quantity']);
                        $insert_stmt->execute();
                        
                        // Record initial stock movement
                        $stock_movement_query = "INSERT INTO stock_movements (product_id, user_id, movement_type, quantity, movement_date, reference_type, reference_id) 
                                               VALUES (:product_id, :user_id, :movement_type, :quantity, NOW(), 'adjustment', :product_id)";
                        $stock_stmt = $db->prepare($stock_movement_query);
                        $stock_stmt->bindParam(':product_id', $product_id);
                        $stock_stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $movement_type = 'in';
                        $stock_stmt->bindParam(':movement_type', $movement_type);
                        $stock_stmt->bindParam(':quantity', $_POST['initial_quantity']);
                        $stock_stmt->bindParam(':reference_id', $product_id);
                        $stock_stmt->execute();
                    }
                }

                pcims_save_product_pairs($db, $product_id, $_POST['pair_suggestions'] ?? [], $_SESSION['user_id']);
                
                // Commit transaction
                $db->commit();
                
                // Log activity
                log_activity($_SESSION['user_id'], 'product_edit', 'Updated product: ' . $_POST['product_name']);
                
                $_SESSION['success'] = 'Product updated successfully!';
                header('Location: products.php');
                exit();
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                $db->rollback();
                
                // Handle specific database errors
                if ($e->getCode() == 23000) {
                    // Integrity constraint violation
                    if (strpos($e->getMessage(), 'product_code') !== false) {
                        $_SESSION['error'] = 'Product code already exists. Please use a unique code.';
                    } else {
                        $_SESSION['error'] = 'Unable to save the product because of a data integrity issue.';
                    }
                } else {
                    $_SESSION['error'] = 'Failed to update product. Please try again.';
                }
                
                error_log("Product Edit Error: " . $e->getMessage());
                header('Location: products.php?action=edit&id=' . $product_id);
                exit();
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollback();
                $_SESSION['error'] = 'Failed to update product. Please try again.';
                error_log("Product Edit Error: " . $e->getMessage());
                header('Location: products.php?action=edit&id=' . $product_id);
                exit();
            }
            
        } elseif ($action === 'deactivate' && $product_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Check if user has permission to deactivate
            if (!has_permission('admin')) {
                $_SESSION['error'] = 'You do not have permission to deactivate products.';
                header('Location: products.php');
                exit();
            }
            
            // Start transaction for data consistency
            $db->beginTransaction();
            
            try {
                // Verify product exists before deactivation
                $check_query = "SELECT product_name, status FROM products WHERE product_id = :product_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':product_id', $product_id);
                $check_stmt->execute();
                $product = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception('Product not found.');
                }
                
                // Check current status
                if ($product['status'] === 'inactive') {
                    $_SESSION['info'] = 'Product is already inactive.';
                    header('Location: products.php');
                    exit();
                }
                
                // Deactivate product (soft delete by setting status to inactive)
                $query = "UPDATE products SET status = 'inactive' WHERE product_id = :product_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':product_id', $product_id);
                $stmt->execute();
                
                // Commit transaction
                $db->commit();
                
                // Log activity
                log_activity($_SESSION['user_id'], 'product_deactivate', 'Deactivated product: ' . $product['product_name'] . ' (ID: ' . $product_id . ')');
                
                $_SESSION['success'] = 'Product deactivated successfully!';
                header('Location: products.php');
                exit();
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                $db->rollback();
                $_SESSION['error'] = 'Failed to deactivate product: ' . $e->getMessage();
                error_log("Product Deactivate Error: " . $e->getMessage());
                header('Location: products.php');
                exit();
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollback();
                $_SESSION['error'] = 'Failed to deactivate product: ' . $e->getMessage();
                error_log("Product Deactivate Error: " . $e->getMessage());
                header('Location: products.php');
                exit();
            }
        }
         elseif ($action === 'reactivate' && $product_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Check if user has permission to reactivate
            if (!has_permission('admin')) {
                $_SESSION['error'] = 'You do not have permission to reactivate products.';
                header('Location: products.php');
                exit();
            }
            
            // Start transaction for data consistency
            $db->beginTransaction();
            
            try {
                // Verify product exists before reactivation
                $check_query = "SELECT product_name, status FROM products WHERE product_id = :product_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':product_id', $product_id);
                $check_stmt->execute();
                $product = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$product) {
                    throw new Exception('Product not found.');
                }
                
                // Check current status
                if ($product['status'] === 'active') {
                    $_SESSION['info'] = 'Product is already active.';
                    header('Location: products.php');
                    exit();
                }
                
                // Reactivate product (set status to active)
                $query = "UPDATE products SET status = 'active' WHERE product_id = :product_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':product_id', $product_id);
                $stmt->execute();
                
                // Commit transaction
                $db->commit();
                
                // Log activity
                log_activity($_SESSION['user_id'], 'product_reactivate', 'Reactivated product: ' . $product['product_name'] . ' (ID: ' . $product_id . ')');
                
                $_SESSION['success'] = 'Product reactivated successfully!';
                header('Location: products.php');
                exit();
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                $db->rollback();
                $_SESSION['error'] = 'Failed to reactivate product: ' . $e->getMessage();
                error_log("Product Reactivate Error: " . $e->getMessage());
                header('Location: products.php');
                exit();
            } catch (Exception $e) {
                // Rollback transaction on error
                $db->rollback();
                $_SESSION['error'] = 'Failed to reactivate product: ' . $e->getMessage();
                error_log("Product Reactivate Error: " . $e->getMessage());
                header('Location: products.php');
                exit();
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'An unexpected error occurred: ' . $e->getMessage();
        error_log("Products POST Error: " . $e->getMessage());
        header('Location: products.php');
        exit();
    }
}

// Get product data for editing
$product = null;
$selected_pair_suggestions = [];
if ($action === 'edit' && $product_id) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT p.*, i.quantity_on_hand,
                     c.category_name, s.supplier_name 
              FROM products p 
              LEFT JOIN inventory i ON p.product_id = i.product_id 
              LEFT JOIN categories c ON p.category_id = c.category_id 
              LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
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

        $pair_query = "SELECT suggested_product_id FROM product_pair_suggestions WHERE source_product_id = :product_id ORDER BY display_order ASC";
        $pair_stmt = $db->prepare($pair_query);
        $pair_stmt->bindParam(':product_id', $product_id);
        $pair_stmt->execute();
        $selected_pair_suggestions = array_map('intval', $pair_stmt->fetchAll(PDO::FETCH_COLUMN));
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error. Please try again.';
        header('Location: products.php');
        exit();
    }
}

// Get categories and suppliers for dropdowns
$categories = [];
$suppliers = [];
$pair_candidate_products = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT * FROM categories WHERE status = 'active' ORDER BY category_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $query = "SELECT * FROM suppliers WHERE status = 'active' ORDER BY supplier_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $query = "SELECT product_id, product_name, product_code
              FROM products
              WHERE product_id != :current_product_id
              ORDER BY product_name";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':current_product_id', (int) ($product_id ?? 0), PDO::PARAM_INT);
    $stmt->execute();
    $pair_candidate_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $exception) {
    error_log("Categories/Suppliers Error: " . $exception->getMessage());
}

if ($action === 'list') {
    // Get products list with pagination
    $products = [];
    $product_intelligence = [];
    $search = $_GET['search'] ?? '';
    $category_filter = $_GET['category'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = 10; // Products per page
    $offset = ($page - 1) * $per_page;
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get total count for pagination
        $count_query = "SELECT COUNT(*) as total 
                        FROM products p 
                        LEFT JOIN categories c ON p.category_id = c.category_id 
                        LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                        LEFT JOIN inventory i ON p.product_id = i.product_id 
                        WHERE 1=1";
        
        $count_params = [];
        
        if (!empty($search)) {
            $count_query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search)";
            $search_param = "%$search%";
            $count_params[':search'] = $search_param;
        }
        
        if (!empty($category_filter)) {
            $count_query .= " AND p.category_id = :category_id";
            $count_params[':category_id'] = $category_filter;
        }
        
        if (!empty($status_filter)) {
            $count_query .= " AND p.status = :status";
            $count_params[':status'] = $status_filter;
        }
        
        $count_stmt = $db->prepare($count_query);
        foreach ($count_params as $key => $value) {
            $count_stmt->bindValue($key, $value);
        }
        $count_stmt->execute();
        $total_products = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Calculate pagination
        $total_pages = ceil($total_products / $per_page);
        $page = max(1, min($page, $total_pages)); // Ensure page is within bounds
        
        // Get products for current page
        $query = "SELECT p.*, c.category_name, s.supplier_name, i.quantity_on_hand 
                  FROM products p 
                  LEFT JOIN categories c ON p.category_id = c.category_id 
                  LEFT JOIN suppliers s ON p.supplier_id = s.supplier_id 
                  LEFT JOIN inventory i ON p.product_id = i.product_id 
                  WHERE 1=1";
        
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
        
        if (!empty($status_filter)) {
            $query .= " AND p.status = :status";
            $params[':status'] = $status_filter;
        }
        
        $query .= " ORDER BY p.product_name LIMIT :per_page OFFSET :offset";
        
        $stmt = $db->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($products)) {
            $product_ids = array_map('intval', array_column($products, 'product_id'));
            $product_intelligence = pcims_get_product_intelligence($db, $product_ids);
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error. Please try again.';
        error_log("Products List Error: " . $exception->getMessage());
    }
    
    include 'includes/header.php';
    ?>
    
    <script>
    function clearFilters() {
        console.log('clearFilters called from list section');
        // Reset all filter inputs
        const searchInput = document.querySelector('input[name="search"]');
        const categorySelect = document.querySelector('select[name="category"]');
        const statusSelect = document.querySelector('select[name="status"]');
        
        if (searchInput) searchInput.value = '';
        if (categorySelect) categorySelect.value = '';
        if (statusSelect) statusSelect.value = '';
        
        // Reload the page without filters
        window.location.href = 'products.php';
    }

    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.querySelector('input[name="search"]');
        const rows = document.querySelectorAll('#productsTable tbody tr[data-product-name]');

        if (!searchInput || rows.length === 0) {
            return;
        }

        searchInput.addEventListener('input', function() {
            const term = this.value.trim().toLowerCase();
            rows.forEach((row) => {
                const name = row.dataset.productName || '';
                const code = row.dataset.productCode || '';
                row.style.display = !term || name.includes(term) || code.includes(term) ? '' : 'none';
            });
        });
    });
    </script>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-box me-2"></i>Products Management
            </h1>
            <a href="products.php?action=add" class="btn btn-primary">
                <i class="fas fa-plus me-2"></i>Add New Product
            </a>
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
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="discontinued" <?php echo $status_filter === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary flex-fill">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                        <button type="button" class="btn btn-outline-secondary flex-fill" onclick="clearFilters()">
                            <i class="fas fa-times me-2"></i>Clear
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Products Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="productsTable">
                        <thead>
                            <tr>
                                <th>Image</th>
                                <th>Code</th>
                                <th>Product Name</th>
                                <th>Category</th>
                                <th>Supplier</th>
                                <th>Stock</th>
                                <th>Unit Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <p>No products found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <?php $intelligence = $product_intelligence[$product['product_id']] ?? null; ?>
                                    <tr data-product-name="<?php echo htmlspecialchars(strtolower($product['product_name'])); ?>" data-product-code="<?php echo htmlspecialchars(strtolower($product['product_code'])); ?>">
                                        <td class="text-center">
                                            <?php echo get_product_image($product['image_url'], $product['product_name'], 'thumbnail', ['style' => 'width: 50px; height: 50px; object-fit: cover;', 'class' => 'img-thumbnail']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                            <?php if ($product['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($product['description'], 0, 50)); ?>...</small>
                                            <?php endif; ?>
                                            <?php if ($intelligence): ?>
                                                <div class="mt-2 d-flex flex-wrap gap-1">
                                                    <?php if (!empty($intelligence['is_best_seller'])): ?>
                                                        <span class="badge bg-success">Best Seller</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($intelligence['is_restock_recommended'])): ?>
                                                        <span class="badge bg-warning text-dark">Restock Recommended</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($intelligence['is_out_of_stock'])): ?>
                                                        <span class="badge bg-danger">Out of Stock</span>
                                                    <?php endif; ?>
                                                </div>
                                                <small class="text-muted d-block mt-1">
                                                    Forecast (<?php echo $intelligence['forecast_days']; ?>d): <?php echo number_format($intelligence['forecast_quantity']); ?>
                                                    | Avg/day: <?php echo number_format($intelligence['average_daily_sales'], 2); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['category_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($product['supplier_name'] ?? 'N/A'); ?></td>
                                        <td>
                                            <span id="stock-<?php echo $product['product_id']; ?>" 
                                                  class="<?php echo !empty($intelligence['is_low_stock']) || !empty($intelligence['is_out_of_stock']) ? 'text-danger fw-bold' : ''; ?>">
                                                <?php echo number_format($product['quantity_on_hand']); ?>
                                            </span>
                                            <?php if (!empty($intelligence['is_low_stock']) || (!empty($intelligence['is_out_of_stock']))): ?>
                                                <i class="fas fa-exclamation-triangle text-danger" title="Low Stock"></i>
                                            <?php endif; ?>
                                            <?php if ($intelligence): ?>
                                                <small class="text-muted d-block">
                                                    Lead time: <?php echo (int) ($product['lead_time_days'] ?? $intelligence['lead_time_days']); ?>d
                                                    | Alert at: <?php echo number_format($intelligence['lead_time_threshold']); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo format_currency($product['unit_price']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $product['status'] === 'active' ? 'success' : 
                                                     ($product['status'] === 'inactive' ? 'secondary' : 'warning'); 
                                            ?>">
                                                <?php echo ucfirst($product['status']); ?>
                                            </span>
                                            <?php if (!empty($intelligence['is_low_stock']) && empty($intelligence['is_out_of_stock'])): ?>
                                                <br><span class="badge bg-light text-danger border border-danger mt-1">Low Stock</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="products.php?action=edit&id=<?php echo $product['product_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="product_details.php?id=<?php echo $product['product_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (has_permission('admin')): ?>
                                                <?php if ($product['status'] === 'active'): ?>
                                                <form method="POST" action="products.php?action=deactivate&id=<?php echo $product['product_id']; ?>" 
                                                      style="display: inline;" onsubmit="return confirm('Are you sure you want to deactivate this product?\n\nProduct: <?php echo htmlspecialchars($product['product_name']); ?>\nCode: <?php echo htmlspecialchars($product['product_code']); ?>\n\nThis will set the product status to inactive and hide it from active listings.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Deactivate Product">
                                                        <i class="fas fa-pause-circle"></i>
                                                    </button>
                                                </form>
                                                <?php else: ?>
                                                <form method="POST" action="products.php?action=reactivate&id=<?php echo $product['product_id']; ?>" 
                                                      style="display: inline;" onsubmit="return confirm('Are you sure you want to reactivate this product?\n\nProduct: <?php echo htmlspecialchars($product['product_name']); ?>\nCode: <?php echo htmlspecialchars($product['product_code']); ?>\n\nThis will set the product status back to active.');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Reactivate Product">
                                                        <i class="fas fa-play-circle"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled title="Deactivate (Admin Only)">
                                                    <i class="fas fa-pause-circle"></i>
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
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Products pagination">
                    <ul class="pagination justify-content-center">
                        <!-- Previous -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link"><i class="fas fa-chevron-left"></i> Previous</span>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <?php if ($i === $page): ?>
                                    <span class="page-link"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo !empty($category_filter) ? '&category=' . urlencode($category_filter) : ''; ?><?php echo !empty($status_filter) ? '&status=' . urlencode($status_filter) : ''; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <!-- Results Info -->
                <div class="text-center text-muted mt-3">
                    <small>
                        Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $per_page, $total_products); ?> 
                        of <?php echo $total_products; ?> products
                        <?php if (!empty($search) || !empty($category_filter) || !empty($status_filter)): ?>
                            (filtered)
                        <?php endif; ?>
                    </small>
                </div>
                <?php endif; ?>
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
                <i class="fas fa-box me-2"></i><?php echo $action === 'add' ? 'Add New Product' : 'Edit Product'; ?>
            </h1>
            <a href="products.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Products
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <?php if ($action === 'edit'): ?>
                        <input type="hidden" name="current_image_url" value="<?php echo htmlspecialchars(!empty($product) ? ($product['image_url'] ?? '') : ''); ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="product_code" class="form-label">Product Code <small class="text-muted">(Optional - will be auto-generated if empty)</small></label>
                                <input type="text" class="form-control" id="product_code" name="product_code" 
                                       value="<?php echo htmlspecialchars(!empty($product) ? ($product['product_code'] ?? '') : ''); ?>" 
                                       placeholder="Leave empty to auto-generate (e.g., PC202503161234)">
                            </div>
                            
                            <div class="mb-3">
                                <label for="product_name" class="form-label">Product Name *</label>
                                <input type="text" class="form-control" id="product_name" name="product_name" 
                                       value="<?php echo htmlspecialchars(!empty($product) ? ($product['product_name'] ?? '') : ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars(!empty($product) ? ($product['description'] ?? '') : ''); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category_id']; ?>" 
                                                <?php echo !empty($product) && isset($product['category_id']) && $product['category_id'] == $category['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="supplier_id" class="form-label">Supplier</label>
                                <select class="form-select" id="supplier_id" name="supplier_id">
                                    <option value="">Select Supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?php echo $supplier['supplier_id']; ?>" 
                                                <?php echo !empty($product) && isset($product['supplier_id']) && $product['supplier_id'] == $supplier['supplier_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="unit_price" class="form-label">Unit Price *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" id="unit_price" name="unit_price" 
                                           value="<?php echo htmlspecialchars(!empty($product) ? ($product['unit_price'] ?? '') : ''); ?>" 
                                           step="0.01" min="0" data-format="currency" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="cost_price" class="form-label">Cost Price *</label>
                                <div class="input-group">
                                    <span class="input-group-text">₱</span>
                                    <input type="number" class="form-control" id="cost_price" name="cost_price" 
                                           value="<?php echo htmlspecialchars(!empty($product) ? ($product['cost_price'] ?? '') : ''); ?>" 
                                           step="0.01" min="0" data-format="currency" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="reorder_level" class="form-label">Reorder Level</label>
                                <input type="number" class="form-control" id="reorder_level" name="reorder_level" 
                                       value="<?php echo htmlspecialchars(!empty($product) ? ($product['reorder_level'] ?? 10) : 10); ?>" min="0">
                            </div>

                            <div class="mb-3">
                                <label for="lead_time_days" class="form-label">Lead Time (Days)</label>
                                <input type="number" class="form-control" id="lead_time_days" name="lead_time_days" 
                                       value="<?php echo htmlspecialchars(!empty($product) ? ($product['lead_time_days'] ?? 3) : 3); ?>" min="1">
                                <small class="form-text text-muted">Used for predictive low-stock alerts and restock recommendations.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="unit_of_measure" class="form-label">Unit of Measure</label>
                                <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure" 
                                       value="<?php echo htmlspecialchars(!empty($product) ? ($product['unit_of_measure'] ?? 'pcs') : 'pcs'); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="initial_quantity" class="form-label">
                                    <?php echo $action === 'add' ? 'Initial Quantity' : 'Current Quantity'; ?>
                                </label>
                                <input type="number" class="form-control" id="initial_quantity" name="initial_quantity" 
                                       value="<?php echo htmlspecialchars(!empty($product) ? ($product['quantity_on_hand'] ?? $product['initial_quantity'] ?? 0) : 0); ?>" min="0" step="1">
                                <small class="form-text text-muted">
                                    <?php echo $action === 'add' ? 'Set initial stock quantity for new products' : 'Update current stock quantity'; ?>
                                </small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo (!empty($product) ? ($product['status'] ?? '') : '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (!empty($product) ? ($product['status'] ?? '') : '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="discontinued" <?php echo (!empty($product) ? ($product['status'] ?? '') : '') === 'discontinued' ? 'selected' : ''; ?>>Discontinued</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="pair_suggestions" class="form-label">Suggested Pair Products</label>
                                <select class="form-select" id="pair_suggestions" name="pair_suggestions[]" multiple size="6">
                                    <?php foreach ($pair_candidate_products as $pair_product): ?>
                                        <option value="<?php echo $pair_product['product_id']; ?>"
                                                <?php echo in_array((int) $pair_product['product_id'], $selected_pair_suggestions, true) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pair_product['product_name'] . ' (' . $pair_product['product_code'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Hold Ctrl or Cmd to select multiple products that should be suggested together at checkout.</small>
                            </div>
                            
                            <div class="mb-3">
                                <label for="product_image" class="form-label">Product Image</label>
                                <input type="file" class="form-control" id="product_image" name="product_image" accept="image/*">
                                <small class="form-text text-muted">Upload product image (JPG, PNG, GIF - Max 2MB)</small>
                                <?php if ($action === 'edit' && !empty($product) && !empty($product['image_url'])): ?>
                                    <div class="mt-2">
                                        <?php echo get_product_image($product['image_url'], $product['product_name'], 'small', ['class' => 'img-thumbnail', 'style' => 'max-height: 100px;']); ?>
                                        <br><small class="text-muted">Current image</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="products.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i><?php echo $action === 'add' ? 'Add Product' : 'Update Product'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php if ($action === 'add'): ?>
    <script>
    // Show example of auto-generated product code
    document.addEventListener('DOMContentLoaded', function() {
        const productCodeField = document.getElementById('product_code');
        const form = productCodeField.closest('form');
        
        // Generate example code on page load
        updateExampleCode();
        
        // Update example when field changes
        productCodeField.addEventListener('input', updateExampleCode);
        
        function updateExampleCode() {
            const prefix = 'PC';
            const timestamp = new Date().toISOString().slice(0,10).replace(/-/g,'');
            const random = Math.floor(Math.random() * 9000) + 1000;
            const exampleCode = prefix + timestamp + random;
            
            if (productCodeField.value.trim() === '') {
                productCodeField.title = 'Example: ' + exampleCode;
                productCodeField.style.borderColor = '#28a745';
            } else {
                productCodeField.title = 'Custom product code';
                productCodeField.style.borderColor = '';
            }
        }
        
        // Clear visual feedback on form submission
        form.addEventListener('submit', function() {
            productCodeField.style.borderColor = '';
        });
    });
    </script>
    <?php endif; ?>
    
    <?php if (!in_array($action, ['add', 'edit'])): ?>
    <script>
    console.log('Loading clearFilters function. Action:', '<?php echo $action; ?>');
    function clearFilters() {
        console.log('clearFilters called');
        // Reset all filter inputs
        document.querySelector('input[name="search"]').value = '';
        document.querySelector('select[name="category"]').value = '';
        document.querySelector('select[name="status"]').value = '';
        
        // Reload the page without filters
        window.location.href = 'products.php';
    }
    </script>
    <?php endif; ?>
    
    <!-- Fallback: Always include clearFilters function -->
    <script>
    // Ensure clearFilters is always available
    if (typeof clearFilters !== 'function') {
        function clearFilters() {
            console.log('Fallback clearFilters called');
            // Reset all filter inputs
            const searchInput = document.querySelector('input[name="search"]');
            const categorySelect = document.querySelector('select[name="category"]');
            const statusSelect = document.querySelector('select[name="status"]');
            
            if (searchInput) searchInput.value = '';
            if (categorySelect) categorySelect.value = '';
            if (statusSelect) statusSelect.value = '';
            
            // Reload the page without filters
            window.location.href = 'products.php';
        }
    }
    </script>
    
    <?php
} else {
    header('Location: products.php');
    exit();
}
?>
