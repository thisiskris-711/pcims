<?php
/**
 * Products API
 * GET    — List/search products
 * POST   — Create product
 * PUT    — Update product (id in query)
 * DELETE — Delete product (id in query)
 */
require_once dirname(__DIR__) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $search = $_GET['search'] ?? '';
        $category = $_GET['category'] ?? '';
        $status = $_GET['status'] ?? '';
        $filter = $_GET['filter'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        
        $where = "1=1";
        $params = [];
        
        if ($search) {
            $where .= " AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
        }
        
        if ($category) {
            $where .= " AND p.category_id = ?";
            $params[] = $category;
        }
        
        if ($status) {
            $where .= " AND p.status = ?";
            $params[] = $status;
        }
        
        if ($filter === 'low_stock') {
            $where .= " AND p.quantity <= p.low_stock_threshold AND p.status = 'active'";
        }
        
        // Count
        $countStmt = $db->prepare("SELECT COUNT(*) FROM products p WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        
        // Fetch
        $stmt = $db->prepare("
            SELECT p.*, c.name as category_name, c.color as category_color, u.full_name as creator_name
            FROM products p 
            LEFT JOIN categories c ON p.category_id = c.id 
            LEFT JOIN users u ON p.created_by = u.id
            WHERE $where
            ORDER BY p.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        jsonResponse([
            'data' => $products,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
        ]);
        break;
        
    case 'POST':
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = $_POST['category_id'] ?: null;
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $lowStockThreshold = (int)($_POST['low_stock_threshold'] ?? 10);
        $barcode = trim($_POST['barcode'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($name)) {
            jsonResponse(['error' => 'Product name is required'], 400);
        }
        
        // Generate SKU
        $prefix = 'GN';
        if ($categoryId) {
            $catName = $db->prepare("SELECT name FROM categories WHERE id = ?");
            $catName->execute([$categoryId]);
            $catResult = $catName->fetchColumn();
            if ($catResult) $prefix = getCategoryPrefix($catResult);
        }
        $sku = generateSKU($prefix);
        
        // Handle image upload
        $image = null;
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $image = handleImageUpload($_FILES['image']);
        }
        
        $stmt = $db->prepare("
            INSERT INTO products (sku, name, description, category_id, cost_price, selling_price, quantity, low_stock_threshold, image, barcode, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sku, $name, $description, $categoryId, $costPrice, $sellingPrice, $quantity, $lowStockThreshold, $image, $barcode, $status, getCurrentUserId()]);
        
        $productId = $db->lastInsertId();
        
        // Log initial stock if quantity > 0
        if ($quantity > 0) {
            $ref = generateReferenceNo('in');
            $stStmt = $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'in', ?, ?, ?, 'Initial stock', ?)");
            $stStmt->execute([$productId, $quantity, $quantity, $ref, getCurrentUserId()]);
        }
        
        jsonResponse(['success' => true, 'message' => 'Product created successfully', 'id' => $productId]);
        break;
        
    case 'PUT':
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        
        $id = $_GET['id'] ?? 0;
        if (!$id) jsonResponse(['error' => 'Product ID required'], 400);
        
        // Parse PUT data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            parse_str(file_get_contents('php://input'), $input);
        }
        
        $fields = [];
        $values = [];
        
        $allowedFields = ['name', 'description', 'category_id', 'cost_price', 'selling_price', 'low_stock_threshold', 'barcode', 'status'];
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = ?";
                $values[] = $input[$field] === '' ? null : $input[$field];
            }
        }
        
        if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);
        
        $values[] = $id;
        $stmt = $db->prepare("UPDATE products SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = ?");
        $stmt->execute($values);
        
        jsonResponse(['success' => true, 'message' => 'Product updated successfully']);
        break;
        
    case 'DELETE':
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        
        $id = $_GET['id'] ?? 0;
        if (!$id) jsonResponse(['error' => 'Product ID required'], 400);
        
        // Get image to delete
        $stmt = $db->prepare("SELECT image FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        
        if ($product && $product['image']) {
            deleteUploadedFile($product['image']);
        }
        
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        
        jsonResponse(['success' => true, 'message' => 'Product deleted successfully']);
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
