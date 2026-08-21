<?php
/**
 * Stock Transactions API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_inventory');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $productId = $_GET['product_id'] ?? '';
        $type = $_GET['type'] ?? '';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo = $_GET['date_to'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        
        $where = "1=1";
        $params = [];
        
        if ($productId) {
            $where .= " AND st.product_id = ?";
            $params[] = $productId;
        }
        if ($type) {
            $where .= " AND st.type = ?";
            $params[] = $type;
        }
        if ($dateFrom) {
            $where .= " AND DATE(st.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND DATE(st.created_at) <= ?";
            $params[] = $dateTo;
        }
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM stock_transactions st WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        
        $stmt = $db->prepare("
            SELECT st.*, p.name as product_name, p.sku, u.full_name as user_name
            FROM stock_transactions st
            JOIN products p ON st.product_id = p.id
            LEFT JOIN users u ON st.created_by = u.id
            WHERE $where
            ORDER BY st.created_at DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        
        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
        ]);
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $productId = (int)($input['product_id'] ?? 0);
        $type = $input['type'] ?? '';
        $quantity = (int)($input['quantity'] ?? 0);
        $notes = trim($input['notes'] ?? '');
        
        if (!$productId) jsonResponse(['error' => 'Product is required'], 400);
        if (!in_array($type, ['in', 'out', 'adjustment'])) jsonResponse(['error' => 'Invalid transaction type'], 400);
        if ($quantity <= 0) jsonResponse(['error' => 'Quantity must be positive'], 400);
        
        // Get current stock and threshold
        $prodStmt = $db->prepare("SELECT quantity, name, low_stock_threshold FROM products WHERE id = ?");
        $prodStmt->execute([$productId]);
        $product = $prodStmt->fetch();
        
        if (!$product) jsonResponse(['error' => 'Product not found'], 404);
        
        $currentQty = (int) $product['quantity'];
        
        if ($type === 'out' && $quantity > $currentQty) {
            jsonResponse(['error' => "Insufficient stock. Current: $currentQty"], 400);
        }
        
        $newBalance = $type === 'in' ? $currentQty + $quantity : $currentQty - $quantity;
        $refNo = generateReferenceNo($type);
        
        $db->beginTransaction();
        try {
            // Insert transaction
            $stmt = $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$productId, $type, $quantity, $newBalance, $refNo, $notes, getCurrentUserId()]);
            
            // Update product quantity
            $db->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newBalance, $productId]);
            
            // Check for low stock alert
            if ($newBalance <= (int)$product['low_stock_threshold'] && $currentQty > (int)$product['low_stock_threshold']) {
                createNotification(
                    null, // global alert
                    "Low Stock Alert",
                    "Product '{$product['name']}' has fallen below its low stock threshold. Current stock: $newBalance",
                    "warning",
                    "/products?filter=low_stock"
                );
            }
            
            $db->commit();
            processLowStockAlerts($db);
            jsonResponse(['success' => true, 'message' => "Stock $type recorded: $quantity × {$product['name']}", 'balance' => $newBalance]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => 'Transaction failed: ' . $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
