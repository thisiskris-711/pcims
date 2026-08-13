<?php
/**
 * Sales API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'detail') {
            $id = (int)($_GET['id'] ?? 0);
            $sale = $db->prepare("SELECT s.*, u.full_name as cashier FROM sales s LEFT JOIN users u ON s.created_by = u.id WHERE s.id = ?");
            $sale->execute([$id]);
            $saleData = $sale->fetch();
            
            if (!$saleData) jsonResponse(['error' => 'Sale not found'], 404);
            
            $items = $db->prepare("SELECT si.*, p.sku FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
            $items->execute([$id]);
            $saleData['items'] = $items->fetchAll();
            
            jsonResponse($saleData);
        }
        
        // List
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo = $_GET['date_to'] ?? '';
        $paymentMethod = $_GET['payment_method'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        
        $where = "1=1";
        $params = [];
        
        if ($dateFrom) {
            $where .= " AND DATE(s.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $where .= " AND DATE(s.created_at) <= ?";
            $params[] = $dateTo;
        }
        if ($paymentMethod) {
            $where .= " AND s.payment_method = ?";
            $params[] = $paymentMethod;
        }
        
        $countStmt = $db->prepare("SELECT COUNT(*) FROM sales s WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        
        $stmt = $db->prepare("
            SELECT s.*, u.full_name as cashier,
                   (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
            FROM sales s 
            LEFT JOIN users u ON s.created_by = u.id
            WHERE $where
            ORDER BY s.created_at DESC
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
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) jsonResponse(['error' => 'Invalid request body'], 400);
        
        $items = $input['items'] ?? [];
        $customerName = trim($input['customer_name'] ?? 'Walk-in Customer');
        $discount = (float)($input['discount'] ?? 0);
        $paymentMethod = $input['payment_method'] ?? 'cash';
        $notes = trim($input['notes'] ?? '');
        
        if (empty($items)) jsonResponse(['error' => 'Cart is empty'], 400);
        
        $db->beginTransaction();
        try {
            $subtotal = 0;
            $validatedItems = [];
            
            // Validate all items first
            foreach ($items as $item) {
                $prodStmt = $db->prepare("SELECT id, name, selling_price, quantity FROM products WHERE id = ? AND status = 'active'");
                $prodStmt->execute([$item['product_id']]);
                $product = $prodStmt->fetch();
                
                if (!$product) throw new Exception("Product not found: ID {$item['product_id']}");
                
                $qty = (int)$item['quantity'];
                if ($qty <= 0) throw new Exception("Invalid quantity for {$product['name']}");
                if ($qty > $product['quantity']) throw new Exception("Insufficient stock for {$product['name']} (available: {$product['quantity']})");
                
                $unitPrice = (float)($item['unit_price'] ?? $product['selling_price']);
                $itemDiscount = (float)($item['discount'] ?? 0);
                $itemTotal = ($unitPrice * $qty) - $itemDiscount;
                $subtotal += $itemTotal;
                
                $validatedItems[] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount' => $itemDiscount,
                    'total' => $itemTotal,
                    'current_stock' => $product['quantity'],
                ];
            }
            
            $taxRate = (float) getSetting('tax_rate', '12');
            $taxableAmount = $subtotal - $discount;
            $tax = round($taxableAmount * ($taxRate / 100), 2);
            $total = round($taxableAmount + $tax, 2);
            
            $invoiceNo = generateInvoiceNo();
            
            // Insert sale
            $saleStmt = $db->prepare("
                INSERT INTO sales (invoice_no, customer_name, subtotal, discount, tax, total, payment_method, payment_status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)
            ");
            $saleStmt->execute([$invoiceNo, $customerName, $subtotal, $discount, $tax, $total, $paymentMethod, $notes, getCurrentUserId()]);
            $saleId = $db->lastInsertId();
            
            // Insert sale items + update stock
            foreach ($validatedItems as $vi) {
                $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$saleId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['discount'], $vi['total']]);
                
                $newStock = $vi['current_stock'] - $vi['quantity'];
                $db->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newStock, $vi['product_id']]);
                
                // Log stock transaction
                $ref = generateReferenceNo('out');
                $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'out', ?, ?, ?, ?, ?)")
                    ->execute([$vi['product_id'], $vi['quantity'], $newStock, $ref, "POS Sale: $invoiceNo", getCurrentUserId()]);
            }
            
            $db->commit();
            
            jsonResponse([
                'success' => true,
                'message' => 'Sale completed',
                'sale_id' => $saleId,
                'invoice_no' => $invoiceNo,
                'total' => $total,
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
