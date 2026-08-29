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
        requirePermission('view_sales');
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
        $search = $_GET['search'] ?? '';
        $dateFrom = $_GET['date_from'] ?? '';
        $dateTo = $_GET['date_to'] ?? '';
        $paymentMethod = $_GET['payment_method'] ?? '';
        $status = $_GET['status'] ?? '';
        $dealerId = (int)($_GET['dealer_id'] ?? 0);
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (s.invoice_no LIKE ? OR d.name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($status) {
            $where .= " AND s.payment_status = ?";
            $params[] = $status;
        }
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
        if ($dealerId) {
            $where .= " AND s.dealer_id = ?";
            $params[] = $dealerId;
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
        verifyCSRFToken();
        $action = $_GET['action'] ?? '';
        if ($action === 'approve' || $action === 'reject') {
            requirePermission('approve_sales');
            $input = json_decode(file_get_contents('php://input'), true);
            $saleId = (int)($input['sale_id'] ?? 0);

            if (!$saleId) jsonResponse(['error' => 'Sale ID required'], 400);

            $db->beginTransaction();
            try {
                $saleStmt = $db->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
                $saleStmt->execute([$saleId]);
                $sale = $saleStmt->fetch();

                if (!$sale) throw new Exception('Sale not found');
                if ($sale['payment_status'] !== 'pending_approval') throw new Exception('Sale is not in pending approval state');

                if ($action === 'reject') {
                    $db->prepare("UPDATE sales SET payment_status = 'refunded', notes = CONCAT(notes, '\nRejected by ', ?) WHERE id = ?")
                        ->execute([getCurrentUserId(), $saleId]);
                    $db->commit();
                    jsonResponse(['success' => true, 'message' => 'Sale rejected']);
                }

                // Approve Action
                $dealerStmt = $db->prepare("SELECT id, name, credit_limit, credit_balance, status FROM dealers WHERE id = ? FOR UPDATE");
                $dealerStmt->execute([$sale['dealer_id']]);
                $dealer = $dealerStmt->fetch();

                if (!$dealer || $dealer['status'] !== 'active') throw new Exception("Dealer is not active");

                // If credit sale, validate credit limit (hard block)
                if (in_array($sale['payment_method'], ['credit', 'cash&credit'])) {
                    $availableCredit = (float)$dealer['credit_limit'] - (float)$dealer['credit_balance'];
                    $amountToCharge = ($sale['payment_method'] === 'cash&credit') ? max(0, $sale['total'] - $sale['cash_received']) : $sale['total'];
                    if ($amountToCharge > 0 && $amountToCharge > $availableCredit) {
                        throw new Exception("Insufficient available credit. Sale requires ₱" . number_format($amountToCharge, 2) . ", but dealer only has ₱" . number_format($availableCredit, 2) . " available.");
                    }
                }

                // Deduct stock for all items
                $itemsStmt = $db->prepare("SELECT si.*, p.type, p.low_stock_threshold, p.quantity as current_stock FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
                $itemsStmt->execute([$saleId]);
                $items = $itemsStmt->fetchAll();

                foreach ($items as $vi) {
                    if ($vi['type'] === 'bundle') {
                        $compStmt = $db->prepare("SELECT pbi.product_id, pbi.quantity as required_qty, p.quantity as current_stock, p.name, p.low_stock_threshold FROM product_bundle_items pbi JOIN products p ON pbi.product_id = p.id WHERE pbi.bundle_id = ?");
                        $compStmt->execute([$vi['product_id']]);
                        $bundleComponents = $compStmt->fetchAll();

                        foreach ($bundleComponents as $c) {
                            $qtyToDeduct = $c['required_qty'] * $vi['quantity'];
                            if ($qtyToDeduct > $c['current_stock']) {
                                throw new Exception("Insufficient stock for component {$c['name']} (needed: $qtyToDeduct, available: {$c['current_stock']})");
                            }
                            $newStock = $c['current_stock'] - $qtyToDeduct;
                            $db->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newStock, $c['product_id']]);

                            $ref = generateReferenceNo('out');
                            $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'out', ?, ?, ?, ?, ?)")
                                ->execute([$c['product_id'], $qtyToDeduct, $newStock, $ref, "POS Sale: {$sale['invoice_no']} (Bundle: {$vi['product_name']})", getCurrentUserId()]);

                            if ($newStock <= (int)$c['low_stock_threshold'] && $c['current_stock'] > (int)$c['low_stock_threshold']) {
                                createNotification(null, "Low Stock Alert", "Product '{$c['name']}' has fallen below its low stock threshold. Current stock: $newStock", "warning", "/products?filter=low_stock");
                            }
                        }
                    } else {
                        if ($vi['quantity'] > $vi['current_stock']) {
                            throw new Exception("Insufficient stock for {$vi['product_name']} (available: {$vi['current_stock']})");
                        }
                        $newStock = $vi['current_stock'] - $vi['quantity'];
                        $db->prepare("UPDATE products SET quantity = ?, updated_at = NOW() WHERE id = ?")->execute([$newStock, $vi['product_id']]);

                        $ref = generateReferenceNo('out');
                        $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'out', ?, ?, ?, ?, ?)")
                            ->execute([$vi['product_id'], $vi['quantity'], $newStock, $ref, "POS Sale: {$sale['invoice_no']}", getCurrentUserId()]);

                        if ($newStock <= (int)$vi['low_stock_threshold'] && $vi['current_stock'] > (int)$vi['low_stock_threshold']) {
                            createNotification(null, "Low Stock Alert", "Product '{$vi['product_name']}' has fallen below its low stock threshold. Current stock: $newStock", "warning", "/products?filter=low_stock");
                        }
                    }
                }

                // If credit sale, charge the dealer's account
                if (in_array($sale['payment_method'], ['credit', 'cash&credit'])) {
                    $amountToCharge = ($sale['payment_method'] === 'cash&credit') ? max(0, $sale['total'] - $sale['cash_received']) : $sale['total'];
                    if ($amountToCharge > 0) {
                        $newBalance = round((float)$dealer['credit_balance'] + $amountToCharge, 2);
                        $db->prepare("UPDATE dealers SET credit_balance = ? WHERE id = ?")->execute([$newBalance, $sale['dealer_id']]);

                        $creditRef = generateCreditReferenceNo('CR');
                        $db->prepare("
                            INSERT INTO credit_transactions (dealer_id, sale_id, type, amount, balance_after, reference_no, notes, created_by)
                            VALUES (?, ?, 'charge', ?, ?, ?, ?, ?)
                        ")->execute([$sale['dealer_id'], $saleId, $amountToCharge, $newBalance, $creditRef, "Credit sale: {$sale['invoice_no']}", getCurrentUserId()]);
                    }
                }

                // Mark as approved and paid/credit
                $finalStatus = ($sale['payment_method'] === 'credit') ? 'credit' : 'paid'; // Wait, paid/credit? It's just 'paid' or whatever it was intended to be. Let's use 'paid' unless credit. Wait, payment_status should be 'paid'. Let's just set it to 'paid'. Wait, 'credit' is not in payment_status enum? It's 'paid', 'pending', 'refunded', 'pending_approval'.
                $finalStatus = 'paid';
                $db->prepare("UPDATE sales SET payment_status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
                    ->execute([$finalStatus, getCurrentUserId(), $saleId]);

                $db->commit();
                
                // Trigger low stock alerts if any products dropped below threshold
                processLowStockAlerts($db);
                
                jsonResponse(['success' => true, 'message' => 'Sale approved']);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => $e->getMessage()], 400);
            }
            break;
        }

        // Regular POST (Create Draft)
        requirePermission('create_sales');
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) jsonResponse(['error' => 'Invalid request body'], 400);

        $items = $input['items'] ?? [];
        $dealerId = (int)($input['dealer_id'] ?? 0);
        $discount = (float)($input['discount'] ?? 0);
        $paymentMethod = $input['payment_method'] ?? 'cash';
        $cashReceived = (float)($input['cash_received'] ?? 0);
        $notes = trim($input['notes'] ?? '');
        $dueDate = !empty($input['due_date']) ? $input['due_date'] : null;
        $invoiceDate = !empty($input['invoice_date']) ? $input['invoice_date'] . ' ' . date('H:i:s') : date('Y-m-d H:i:s');

        if (empty($items)) jsonResponse(['error' => 'Cart is empty'], 400);
        if (!$dealerId) jsonResponse(['error' => 'A registered dealer must be selected'], 400);

        $db->beginTransaction();
        try {
            $dealerStmt = $db->prepare("SELECT id, name, credit_limit, credit_balance, status FROM dealers WHERE id = ? FOR UPDATE");
            $dealerStmt->execute([$dealerId]);
            $dealer = $dealerStmt->fetch();

            if (!$dealer) throw new Exception('Dealer not found');
            if ($dealer['status'] !== 'active') throw new Exception("Dealer '{$dealer['name']}' is {$dealer['status']}. Cannot process sale.");

            $subtotal = 0;
            $validatedItems = [];

            foreach ($items as $item) {
                $prodStmt = $db->prepare("SELECT id, name, selling_price, quantity, low_stock_threshold, type FROM products WHERE id = ? AND status = 'active'");
                $prodStmt->execute([$item['product_id']]);
                $product = $prodStmt->fetch();

                if (!$product) throw new Exception("Product not found: ID {$item['product_id']}");

                $qty = (int)$item['quantity'];
                if ($qty <= 0) throw new Exception("Invalid quantity for {$product['name']}");

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
                ];
            }

            // --- 1. Re-calculate Promo/Bundle Discount Backend-side ---
            $cartProductQty = [];
            foreach ($validatedItems as $vi) {
                $cartProductQty[$vi['product_id']] = ($cartProductQty[$vi['product_id']] ?? 0) + $vi['quantity'];
            }

            // Load active bundles & bundle_deal promos
            $activePromos = $db->query("SELECT * FROM promotions WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())")->fetchAll();
            $bundlesStmt = $db->query("SELECT p.id as bundle_id, p.name as bundle_name, p.selling_price as bundle_price, pbi.product_id, pbi.quantity as required_qty FROM products p JOIN product_bundle_items pbi ON p.id = pbi.bundle_id WHERE p.type = 'bundle' AND p.status = 'active'");
            $bundleRows = $bundlesStmt->fetchAll();
            
            $activeBundles = [];
            foreach ($bundleRows as $row) {
                if (!isset($activeBundles[$row['bundle_id']])) {
                    $activeBundles[$row['bundle_id']] = ['bundle_id' => $row['bundle_id'], 'name' => $row['bundle_name'], 'bundle_price' => (float)$row['bundle_price'], 'items' => []];
                }
                $activeBundles[$row['bundle_id']]['items'][] = ['product_id' => (int)$row['product_id'], 'required_qty' => (int)$row['required_qty']];
            }
            $activeBundlesList = array_values($activeBundles);
            
            foreach ($activePromos as $promo) {
                if ($promo['type'] === 'bundle_deal') {
                    $config = json_decode($promo['config'], true);
                    if ($config && !empty($config['components'])) {
                        $items = [];
                        foreach ($config['components'] as $comp) {
                            if (str_starts_with($comp['target'], 'product_')) {
                                $items[] = ['product_id' => (int)str_replace('product_', '', $comp['target']), 'required_qty' => (int)$comp['qty']];
                            }
                        }
                        if (!empty($items)) {
                            $activeBundlesList[] = ['bundle_id' => 'promo_' . $promo['id'], 'name' => $promo['name'], 'bundle_price' => (float)$config['bundle_price'], 'items' => $items];
                        }
                    }
                }
            }

            $promoDiscount = 0;
            $appliedPromos = [];
            
            foreach ($activeBundlesList as $bundle) {
                $possibleSets = PHP_INT_MAX;
                foreach ($bundle['items'] as $comp) {
                    $avail = $cartProductQty[$comp['product_id']] ?? 0;
                    $sets = floor($avail / $comp['required_qty']);
                    if ($sets < $possibleSets) $possibleSets = $sets;
                }
                
                if ($possibleSets > 0 && $possibleSets !== PHP_INT_MAX) {
                    $regularComponentTotal = 0;
                    foreach ($bundle['items'] as $comp) {
                        foreach ($validatedItems as $vi) {
                            if ($vi['product_id'] == $comp['product_id']) {
                                $regularComponentTotal += $vi['unit_price'] * $comp['required_qty'];
                                break;
                            }
                        }
                        $cartProductQty[$comp['product_id']] -= $comp['required_qty'] * $possibleSets;
                    }
                    
                    $bundleSavings = $regularComponentTotal - $bundle['bundle_price'];
                    if ($bundleSavings >= 0) {
                        $promoDiscount += $bundleSavings * $possibleSets;
                        $appliedPromos[] = $possibleSets . "x " . $bundle['name'] . " (Saved ₱" . number_format($bundleSavings * $possibleSets, 2) . ")";
                    }
                }
            }
            
            // Re-calculate category discounts (e.g. buy_x_get_y)
            foreach ($activePromos as $promo) {
                if ($promo['type'] === 'category_discount') {
                    $config = json_decode($promo['config'], true);
                    if (!$config) continue;
                    if (($config['rule'] ?? '') === 'buy_x_get_y' && str_starts_with($config['buy_target'] ?? '', 'product_')) {
                        $pId = (int)str_replace('product_', '', $config['buy_target']);
                        $buyQty = (int)($config['buy_qty'] ?? 0);
                        $getQty = (int)($config['get_qty'] ?? 0);
                        $promoPrice = (float)($config['promo_price'] ?? 0);
                        
                        $avail = $cartProductQty[$pId] ?? 0;
                        if ($avail > 0 && ($buyQty + $getQty) > 0) {
                            $sets = floor($avail / ($buyQty + $getQty));
                            if ($sets > 0) {
                                $unitPrice = 0;
                                foreach ($validatedItems as $vi) { if ($vi['product_id'] == $pId) { $unitPrice = $vi['unit_price']; break; } }
                                $savings = ($getQty * $unitPrice) - ($getQty * $promoPrice);
                                if ($savings > 0) {
                                    $promoDiscount += $savings * $sets;
                                    $appliedPromos[] = $sets . "x " . $promo['name'] . " (Saved ₱" . number_format($savings * $sets, 2) . ")";
                                }
                            }
                        }
                    }
                }
            }

            // --- 2. Dealer Discount & Totals ---
            $discountedSubtotal = $subtotal - $promoDiscount;
            $dealerDiscount = $discountedSubtotal * 0.25; // 25% basic discount on all products
            $totalDiscount = $promoDiscount + $dealerDiscount;
            
            // Append breakdown to notes
            $breakdown = [];
            if ($promoDiscount > 0) $breakdown[] = "Promo Savings: ₱" . number_format($promoDiscount, 2) . ($appliedPromos ? " [" . implode(", ", $appliedPromos) . "]" : "");
            if ($dealerDiscount > 0) $breakdown[] = "Dealer Discount (25%): ₱" . number_format($dealerDiscount, 2);
            if (!empty($breakdown)) {
                $notes = trim($notes . "\n\n--- Discount Breakdown ---\n" . implode("\n", $breakdown));
            }

            $taxRate = 12; // 12% VAT
            $total = round($subtotal - $totalDiscount, 2);
            $netOfVat = $total / 1.12;
            $tax = round($total - $netOfVat, 2);
            $taxableAmount = round($netOfVat, 2);
            
            if (in_array($paymentMethod, ['credit', 'cash&credit'])) {
                $amountToCharge = ($paymentMethod === 'cash&credit') ? max(0, $total - $cashReceived) : $total;
                if ($amountToCharge > 0) {
                    $availableCredit = (float)$dealer['credit_limit'] - (float)$dealer['credit_balance'];
                    if ($amountToCharge > $availableCredit) {
                        throw new Exception("Insufficient available credit. Sale requires ₱" . number_format($amountToCharge, 2) . ", but dealer only has ₱" . number_format($availableCredit, 2) . " available.");
                    }
                }
            }

            // Initial placeholder to satisfy UNIQUE constraint during insert
            $tempInvoiceNo = uniqid('TMP_');
            $paymentStatus = 'pending_approval'; // ALWAYS pending approval

            $saleStmt = $db->prepare("
                INSERT INTO sales (invoice_no, dealer_id, subtotal, discount, tax, total, cash_received, payment_method, payment_status, notes, due_date, created_at, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $saleStmt->execute([$tempInvoiceNo, $dealerId, $subtotal, $totalDiscount, $tax, $total, $cashReceived, $paymentMethod, $paymentStatus, $notes, $dueDate, $invoiceDate, getCurrentUserId()]);
            $saleId = $db->lastInsertId();
            
            // Generate final invoice number using the actual sale ID to prevent race conditions
            $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'invoice_prefix'");
            $prefixRow = $stmt->fetch();
            $prefix = $prefixRow ? $prefixRow['setting_value'] : 'INV-';
            if (!str_ends_with($prefix, '-')) $prefix .= '-';
            
            $invoiceNo = $prefix . date('Y', strtotime($invoiceDate)) . '-' . str_pad($saleId, 4, '0', STR_PAD_LEFT);
            $db->prepare("UPDATE sales SET invoice_no = ? WHERE id = ?")->execute([$invoiceNo, $saleId]);

            foreach ($validatedItems as $vi) {
                $db->prepare("INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$saleId, $vi['product_id'], $vi['product_name'], $vi['quantity'], $vi['unit_price'], $vi['discount'], $vi['total']]);
            }

            $db->commit();

            jsonResponse([
                'success' => true,
                'message' => 'Sale submitted for approval',
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
