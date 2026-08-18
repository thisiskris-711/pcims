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
            $sale = $db->prepare("
                SELECT s.*, u.full_name as cashier, d.name as dealer_name, d.dealer_code
                FROM sales s 
                LEFT JOIN users u ON s.created_by = u.id 
                LEFT JOIN dealers d ON s.dealer_id = d.id
                WHERE s.id = ?
            ");
            $sale->execute([$id]);
            $saleData = $sale->fetch();
            
            if (!$saleData) jsonResponse(['error' => 'Sale not found'], 404);
            
            $items = $db->prepare("SELECT si.*, p.sku, p.type FROM sale_items si LEFT JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
            $items->execute([$id]);
            $saleItems = $items->fetchAll();
            
            foreach ($saleItems as &$item) {
                if (($item['type'] ?? '') === 'bundle') {
                    $compStmt = $db->prepare("SELECT p.name, pbi.quantity FROM product_bundle_items pbi JOIN products p ON pbi.product_id = p.id WHERE pbi.bundle_id = ?");
                    $compStmt->execute([$item['product_id']]);
                    $item['components'] = $compStmt->fetchAll();
                }
            }
            unset($item);
            
            $saleData['items'] = $saleItems;
            
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
            SELECT s.*, u.full_name as cashier, d.name as dealer_name, d.dealer_code,
                   (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
            FROM sales s 
            LEFT JOIN users u ON s.created_by = u.id
            LEFT JOIN dealers d ON s.dealer_id = d.id
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
        requireRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER);
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) jsonResponse(['error' => 'Invalid request body'], 400);
        
        $items = $input['items'] ?? [];
        $dealerId = (int)($input['dealer_id'] ?? 0);
        $discount = (float)($input['discount'] ?? 0);
        $paymentMethod = $input['payment_method'] ?? 'cash';
        $paymentStatus = $input['payment_status'] ?? 'paid';
        $notes = trim($input['notes'] ?? '');
        
        if (empty($items)) jsonResponse(['error' => 'Cart is empty'], 400);
        if (!$dealerId) jsonResponse(['error' => 'A registered dealer must be selected'], 400);
        if (!in_array($paymentStatus, ['paid', 'credit'])) jsonResponse(['error' => 'Invalid payment status'], 400);
        
        $db->beginTransaction();
        try {
            // Validate dealer
            $dealerStmt = $db->prepare("SELECT id, name, credit_limit, credit_balance, status FROM dealers WHERE id = ? FOR UPDATE");
            $dealerStmt->execute([$dealerId]);
            $dealer = $dealerStmt->fetch();
            
            if (!$dealer) throw new Exception('Dealer not found');
            if ($dealer['status'] !== 'active') throw new Exception("Dealer '{$dealer['name']}' is {$dealer['status']}. Cannot process sale.");
            
            $subtotal = 0;
            $validatedItems = [];
            
            // Validate all items first
            foreach ($items as $item) {
                $prodStmt = $db->prepare("SELECT id, name, selling_price, quantity, low_stock_threshold, type FROM products WHERE id = ? AND status = 'active'");
                $prodStmt->execute([$item['product_id']]);
                $product = $prodStmt->fetch();
                
                if (!$product) throw new Exception("Product not found: ID {$item['product_id']}");
                
                $qty = (int)$item['quantity'];
                if ($qty <= 0) throw new Exception("Invalid quantity for {$product['name']}");
                
                $bundleComponents = [];
                if ($product['type'] === 'bundle') {
                    $compStmt = $db->prepare("SELECT pbi.product_id, pbi.quantity as required_qty, p.quantity as current_stock, p.name, p.low_stock_threshold FROM product_bundle_items pbi JOIN products p ON pbi.product_id = p.id WHERE pbi.bundle_id = ?");
                    $compStmt->execute([$product['id']]);
                    $bundleComponents = $compStmt->fetchAll();
                    
                    foreach ($bundleComponents as $c) {
                        $totalNeeded = $c['required_qty'] * $qty;
                        if ($totalNeeded > $c['current_stock']) {
                            throw new Exception("Insufficient stock for component {$c['name']} (needed: $totalNeeded, available: {$c['current_stock']}) for bundle {$product['name']}");
                        }
                    }
                } else {
                    if ($qty > $product['quantity']) throw new Exception("Insufficient stock for {$product['name']} (available: {$product['quantity']})");
                }
                
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
                    'low_stock_threshold' => $product['low_stock_threshold'],
                    'type' => $product['type'],
                    'bundle_components' => $bundleComponents
                ];
            }
            
            $taxRate = (float) getSetting('tax_rate', '12');
            $taxableAmount = $subtotal - $discount;
            $tax = round($taxableAmount * ($taxRate / 100), 2);
            $total = round($taxableAmount + $tax, 2);
            
            // If credit sale, validate credit limit (hard block)
            if ($paymentStatus === 'credit') {
                $availableCredit = (float)$dealer['credit_limit'] - (float)$dealer['credit_balance'];
                if ($total > $availableCredit) {
                    throw new Exception("Sale total (\${$total}) exceeds dealer's available credit (\${$availableCredit}). Credit limit: \${$dealer['credit_limit']}, Outstanding: \${$dealer['credit_balance']}");
                }
            }
            
            $invoiceNo = generateInvoiceNo();
            
            // Insert sale
            $saleStmt = $db->prepare("
                INSERT INTO sales (invoice_no, dealer_id, subtotal, discount, tax, total, payment_method, payment_status, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $saleStmt->execute([$invoiceNo, $dealerId, $subtotal, $discount, $tax, $total, $paymentMethod, $paymentStatus, $notes, getCurrentUserId()]);
            $saleId = $db->lastInsertId();
            
            // Insert sale items + update stock
            foreach ($validatedItems as $vi) {
                $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$saleId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['discount'], $vi['total']]);
                
                if ($vi['type'] === 'bundle') {
                    foreach ($vi['bundle_components'] as $c) {
                        $qtyToDeduct = $c['required_qty'] * $vi['quantity'];
                        $newStock = $c['current_stock'] - $qtyToDeduct;
                        $db->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newStock, $c['product_id']]);
                        
                        $ref = generateReferenceNo('out');
                        $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'out', ?, ?, ?, ?, ?)")
                            ->execute([$c['product_id'], $qtyToDeduct, $newStock, $ref, "POS Sale: $invoiceNo (Bundle: {$vi['product_name']})", getCurrentUserId()]);
                            
                        if ($newStock <= (int)$c['low_stock_threshold'] && $c['current_stock'] > (int)$c['low_stock_threshold']) {
                            createNotification(
                                null, 
                                "Low Stock Alert",
                                "Product '{$c['name']}' has fallen below its low stock threshold after a bundle sale. Current stock: $newStock",
                                "warning",
                                "/products?filter=low_stock"
                            );
                        }
                    }
                } else {
                    $newStock = $vi['current_stock'] - $vi['quantity'];
                    $db->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newStock, $vi['product_id']]);
                    
                    // Log stock transaction
                    $ref = generateReferenceNo('out');
                    $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'out', ?, ?, ?, ?, ?)")
                        ->execute([$vi['product_id'], $vi['quantity'], $newStock, $ref, "POS Sale: $invoiceNo", getCurrentUserId()]);
    
                    // Check for low stock alert
                    if ($newStock <= (int)$vi['low_stock_threshold'] && $vi['current_stock'] > (int)$vi['low_stock_threshold']) {
                        createNotification(
                            null, // global alert
                            "Low Stock Alert",
                            "Product '{$vi['product_name']}' has fallen below its low stock threshold after a sale. Current stock: $newStock",
                            "warning",
                            "/products?filter=low_stock"
                        );
                    }
                }
            }
            
            // If credit sale, charge the dealer's account
            if ($paymentStatus === 'credit') {
                $newBalance = round((float)$dealer['credit_balance'] + $total, 2);
                $db->prepare("UPDATE dealers SET credit_balance = ? WHERE id = ?")->execute([$newBalance, $dealerId]);
                
                $creditRef = 'CR-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $db->prepare("
                    INSERT INTO credit_transactions (dealer_id, sale_id, type, amount, balance_after, reference_no, notes, created_by)
                    VALUES (?, ?, 'charge', ?, ?, ?, ?, ?)
                ")->execute([$dealerId, $saleId, $total, $newBalance, $creditRef, "Credit sale: $invoiceNo", getCurrentUserId()]);
            }
            
            $db->commit();
            
            jsonResponse([
                'success' => true,
                'message' => 'Sale completed',
                'sale_id' => $saleId,
                'invoice_no' => $invoiceNo,
                'total' => $total,
                'payment_status' => $paymentStatus,
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
