<?php

/**
 * Products API
 * GET    — List/search products
 * POST   — Create product
 * PUT    — Update product (id in query)
 * DELETE — Delete product (id in query)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        requireAnyPermission('manage_products', 'create_sales', 'manage_inventory');

        // ── Smart Recommendations for POS ──
        if (($_GET['action'] ?? '') === 'recommendations') {
            $cartIds = $_GET['cart_product_ids'] ?? [];
            if (!is_array($cartIds) || empty($cartIds)) {
                jsonResponse(['recommendations' => [], 'bundles' => [], 'promotions' => []]);
            }

            $cartIds = array_map('intval', $cartIds);
            $cartPlaceholders = implode(',', array_fill(0, count($cartIds), '?'));

            // 1. Co-purchase recommendations from sales history
            $coParams = array_merge($cartIds, $cartIds);
            $coStmt = $db->prepare("
                SELECT si2.product_id, p.name, p.selling_price, p.image, p.quantity as stock,
                       p.category_id, c.name as category_name,
                       COUNT(*) as co_purchase_count
                FROM sale_items si1
                JOIN sale_items si2 ON si1.sale_id = si2.sale_id AND si1.product_id != si2.product_id
                JOIN products p ON si2.product_id = p.id
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE si1.product_id IN ($cartPlaceholders)
                  AND si2.product_id NOT IN ($cartPlaceholders)
                  AND p.status = 'active' AND p.quantity > 0
                GROUP BY si2.product_id
                ORDER BY co_purchase_count DESC
                LIMIT 6
            ");
            $coStmt->execute($coParams);
            $coProducts = $coStmt->fetchAll();

            // Tag them
            foreach ($coProducts as &$r) {
                $r['reason'] = 'Frequently bought together';
            }
            unset($r);

            // 2. Fallback: same-category suggestions if co-purchase < 3
            if (count($coProducts) < 3) {
                $catStmt = $db->prepare("
                    SELECT DISTINCT p.category_id FROM products p
                    WHERE p.id IN ($cartPlaceholders) AND p.category_id IS NOT NULL
                ");
                $catStmt->execute($cartIds);
                $catIds = $catStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($catIds)) {
                    $existingIds = array_merge($cartIds, array_column($coProducts, 'product_id'));
                    $excludePlaceholders = implode(',', array_fill(0, count($existingIds), '?'));
                    $catPlaceholders2 = implode(',', array_fill(0, count($catIds), '?'));

                    $catRecStmt = $db->prepare("
                        SELECT p.id as product_id, p.name, p.selling_price, p.image, p.quantity as stock,
                               p.category_id, c.name as category_name
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.id
                        WHERE p.category_id IN ($catPlaceholders2)
                          AND p.id NOT IN ($excludePlaceholders)
                          AND p.status = 'active' AND p.quantity > 0
                        ORDER BY RAND()
                        LIMIT ?
                    ");
                    $needed = 4 - count($coProducts);
                    $catRecStmt->execute(array_merge($catIds, $existingIds, [$needed]));
                    $catRecs = $catRecStmt->fetchAll();

                    foreach ($catRecs as &$r) {
                        $r['reason'] = 'Pairs well with this item';
                    }
                    unset($r);

                    $coProducts = array_merge($coProducts, $catRecs);
                }
            }

            // Limit to 4 recommendations
            $recommendations = array_slice($coProducts, 0, 4);

            // 3. Bundle suggestions — check if cart items are partial matches for bundles
            $bundles = [];
            $bundleStmt = $db->prepare("
                SELECT p.id as bundle_id, p.name as bundle_name, p.selling_price as bundle_price, p.image
                FROM product_bundle_items pbi
                JOIN products p ON pbi.bundle_id = p.id
                WHERE pbi.product_id IN ($cartPlaceholders)
                  AND p.status = 'active'
                GROUP BY p.id
            ");
            $bundleStmt->execute($cartIds);
            $potentialBundles = $bundleStmt->fetchAll();

            foreach ($potentialBundles as $bundle) {
                // Get all components of this bundle
                $compStmt = $db->prepare("
                    SELECT pbi.product_id, pbi.quantity as required_qty, p.name, p.selling_price, p.quantity as stock
                    FROM product_bundle_items pbi
                    JOIN products p ON pbi.product_id = p.id
                    WHERE pbi.bundle_id = ?
                ");
                $compStmt->execute([$bundle['bundle_id']]);
                $components = $compStmt->fetchAll();

                $regularPrice = 0;
                $missingProducts = [];
                $bundleProducts = [];
                $totalPresentQty = 0;
                foreach ($components as $comp) {
                    $regularPrice += floatval($comp['selling_price']) * $comp['required_qty'];
                    $cartQty = intval($_GET['cart_qty'][$comp['product_id']] ?? 0);
                    $missingQty = max(0, $comp['required_qty'] - $cartQty);
                    $compObj = [
                        'product_id' => $comp['product_id'],
                        'name' => $comp['name'],
                        'required_qty' => $comp['required_qty'],
                        'cart_qty' => $cartQty,
                        'missing_qty' => $missingQty,
                        'selling_price' => floatval($comp['selling_price']),
                        'stock' => intval($comp['stock'])
                    ];
                    $bundleProducts[] = $compObj;
                    
                    if ($cartQty < $comp['required_qty']) {
                        $missingProducts[] = $compObj;
                    }
                    if ($cartQty > 0) {
                        $totalPresentQty += $cartQty;
                    }
                }

                // Suggest if some items are in cart. If missingProducts is empty, it's fully qualified!
                if ($totalPresentQty > 0) {
                    $savings = $regularPrice - floatval($bundle['bundle_price']);
                    if ($savings >= 0) {
                        $bundles[] = [
                            'bundle_id' => $bundle['bundle_id'],
                            'name' => $bundle['bundle_name'],
                            'image' => $bundle['image'],
                            'products' => $bundleProducts,
                            'missing_products' => $missingProducts,
                            'regular_price' => $regularPrice,
                            'bundle_price' => floatval($bundle['bundle_price']),
                            'savings' => $savings,
                            'qualified' => empty($missingProducts)
                        ];
                    }
                }
            }

            // 4. Active promotions evaluation
            $promoStmt = $db->query("
                SELECT * FROM promotions
                WHERE is_active = 1
                  AND (start_date IS NULL OR start_date <= CURDATE())
                  AND (end_date IS NULL OR end_date >= CURDATE())
            ");
            $activePromos = $promoStmt->fetchAll();

            // Get cart category counts for category promos
            $cartCatStmt = $db->prepare("
                SELECT p.category_id, c.name as category_name, COUNT(*) as count
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.id
                WHERE p.id IN ($cartPlaceholders)
                GROUP BY p.category_id
            ");
            $cartCatStmt->execute($cartIds);
            $cartCategories = $cartCatStmt->fetchAll();

            // Fetch image URLs for all products to use in promos
            $productImagesStmt = $db->query("SELECT id, image FROM products");
            $productImages = [];
            while ($row = $productImagesStmt->fetch()) {
                $productImages[$row['id']] = $row['image'];
            }

            // Receive subtotal from query param (calculated client-side)
            $cartSubtotal = floatval($_GET['cart_subtotal'] ?? 0);


            $promotions = [];
            foreach ($activePromos as $promo) {
                $config = json_decode($promo['config'], true);
                if (!$config) continue;

                $suggestInPos = isset($config['suggest_in_pos']) ? $config['suggest_in_pos'] : true;
                if (!$suggestInPos) continue; // Skip if not meant for POS recommendation

                $priority = $config['priority'] ?? 'normal';

                if ($promo['type'] === 'bundle_deal' && !empty($config['components'])) {
                    $regularPrice = 0;
                    $missingProducts = [];
                    $bundleProducts = [];
                    
                    // Pre-fetch product data for components
                    $compIds = [];
                    foreach ($config['components'] as $comp) {
                        if (str_starts_with($comp['target'], 'product_')) {
                            $compIds[] = intval(str_replace('product_', '', $comp['target']));
                        }
                    }
                    
                    $totalPresentQty = 0;
                    if (!empty($compIds)) {
                        $placeholders = implode(',', array_fill(0, count($compIds), '?'));
                        $stmt = $db->prepare("SELECT id, name, selling_price, image, quantity as stock FROM products WHERE id IN ($placeholders)");
                        $stmt->execute($compIds);
                        $compProductsData = [];
                        while ($row = $stmt->fetch()) {
                            $compProductsData[$row['id']] = $row;
                        }
                        
                        foreach ($config['components'] as $comp) {
                            if (!str_starts_with($comp['target'], 'product_')) continue;
                            $pId = intval(str_replace('product_', '', $comp['target']));
                            $qty = intval($comp['qty']);
                            
                            if (!isset($compProductsData[$pId])) continue;
                            $prod = $compProductsData[$pId];
                            
                            $regularPrice += (float)$prod['selling_price'] * $qty;
                            $cartQty = intval($_GET['cart_qty'][$pId] ?? 0);
                            $missingQty = max(0, $qty - $cartQty);
                            $compObj = [
                                'product_id' => $pId,
                                'name' => $prod['name'],
                                'required_qty' => $qty,
                                'cart_qty' => $cartQty,
                                'missing_qty' => $missingQty,
                                'selling_price' => floatval($prod['selling_price']),
                                'stock' => intval($prod['stock'])
                            ];
                            $bundleProducts[] = $compObj;
                            
                            if ($cartQty < $qty) {
                                $missingProducts[] = $compObj;
                            }
                            if ($cartQty > 0) {
                                $totalPresentQty += $cartQty;
                            }
                        }

                        // Suggest if some items are in cart
                        if ($totalPresentQty > 0) {
                            $savings = $regularPrice - floatval($config['bundle_price']);
                            if ($savings >= 0) {
                                $bundles[] = [
                                    'bundle_id' => 'promo_' . $promo['id'],
                                    'name' => $promo['name'],
                                    'image' => null,
                                    'products' => $bundleProducts,
                                    'missing_products' => $missingProducts,
                                    'regular_price' => $regularPrice,
                                    'bundle_price' => floatval($config['bundle_price']),
                                    'savings' => $savings,
                                    'qualified' => empty($missingProducts)
                                ];
                            }
                        }
                    }
                }

                if ($promo['type'] === 'category_discount') {
                    $rule = $config['rule'] ?? '';
                    $buyQty = intval($config['buy_qty'] ?? 0);
                    $getQty = intval($config['get_qty'] ?? 0);
                    $promoPrice = floatval($config['promo_price'] ?? 0);
                    $buyTarget = $config['buy_target'] ?? '';

                    if (!$rule || !$buyTarget) continue;

                    // Simple evaluation for buy_target product
                    if (str_starts_with($buyTarget, 'product_')) {
                        $pId = intval(str_replace('product_', '', $buyTarget));
                        $cartQty = intval($_GET['cart_qty'][$pId] ?? 0);

                        if ($cartQty > 0) {
                            $sets = floor($cartQty / ($buyQty + $getQty));
                            $remainder = $cartQty % ($buyQty + $getQty);

                            if ($remainder > 0 && $remainder < $buyQty) {
                                $promotions[] = [
                                    'id' => $promo['id'],
                                    'type' => 'category_discount',
                                    'label' => "Promo Available",
                                    'description' => "Buy " . ($buyQty - $remainder) . " more to qualify for ₱" . number_format($promoPrice, 2) . " promo.",
                                    'qualified' => false,
                                    'priority' => $priority
                                ];
                            } elseif ($remainder >= $buyQty) {
                                $promotions[] = [
                                    'id' => $promo['id'],
                                    'type' => 'category_discount',
                                    'label' => 'Promo Unlocked!',
                                    'description' => "You qualify! Add " . (($buyQty + $getQty) - $remainder) . " more to claim ₱" . number_format($promoPrice, 2) . " promo.",
                                    'qualified' => true,
                                    'priority' => $priority
                                ];
                            } else if ($sets > 0 && $remainder == 0) {
                                $promotions[] = [
                                    'id' => $promo['id'],
                                    'type' => 'category_discount',
                                    'label' => 'Promo Applied!',
                                    'description' => "Promo applied for {$sets} set(s)!",
                                    'qualified' => true,
                                    'priority' => $priority
                                ];
                            }
                        }
                    }
                }
            }
            
            // Attach active promos to recommendations and bundles for cart badging
            $promoStmt2 = $db->query("
                SELECT name, description, config, type, start_date, end_date
                FROM promotions 
                WHERE is_active = 1
                  AND (start_date IS NULL OR start_date <= CURDATE())
                  AND (end_date IS NULL OR end_date >= CURDATE())
            ");
            $activePromos2 = $promoStmt2->fetchAll(PDO::FETCH_ASSOC);

            $attachPromoToArr = function(&$arr) use ($activePromos2) {
                foreach ($arr as &$p) {
                    foreach ($activePromos2 as $promo) {
                        $config = json_decode($promo['config'], true);
                        if (!$config) continue;
                        $isMatch = false;
                        if (isset($config['buy_target']) && $config['buy_target'] === 'product_' . $p['id']) $isMatch = true;
                        if (isset($config['get_target']) && $config['get_target'] === 'product_' . $p['id']) $isMatch = true;
                        if (isset($config['buy_target']) && isset($p['category_id']) && $config['buy_target'] === 'category_' . $p['category_id']) $isMatch = true;
                        if ($promo['type'] === 'bundle_deal' && !empty($config['components'])) {
                            foreach ($config['components'] as $comp) {
                                if (isset($comp['target']) && ($comp['target'] === 'product_' . $p['id'] || (isset($p['category_id']) && $comp['target'] === 'category_' . $p['category_id']))) {
                                    $isMatch = true;
                                    break;
                                }
                            }
                        }
                        if ($isMatch) {
                            $validity = '';
                            if ($promo['start_date'] && $promo['end_date']) {
                                $validity = " (Valid: " . date('M j', strtotime($promo['start_date'])) . " to " . date('M j, Y', strtotime($promo['end_date'])) . ")";
                            } elseif ($promo['end_date']) {
                                $validity = " (Valid until " . date('M j, Y', strtotime($promo['end_date'])) . ")";
                            } elseif ($promo['start_date']) {
                                $validity = " (Valid from " . date('M j, Y', strtotime($promo['start_date'])) . ")";
                            }
                            
                            $p['active_promo'] = $promo['name'];
                            $p['promo_desc'] = ($promo['description'] ?: $promo['name']) . $validity;
                            break;
                        }
                    }
                }
            };
            
            $attachPromoToArr($recommendations);
            $attachPromoToArr($bundles);

            jsonResponse([
                'recommendations' => $recommendations,
                'bundles' => $bundles,
                'promotions' => $promotions,
            ]);
        }

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
        } elseif ($filter === 'expiring_soon') {
            $where .= " AND p.expiry_date IS NOT NULL AND p.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
        } elseif ($filter === 'expired') {
            $where .= " AND p.expiry_date IS NOT NULL AND p.expiry_date < CURDATE()";
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
            ORDER BY p.name ASC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        // Fetch active promotions for badge indicators on the main grid
        $promoStmt3 = $db->query("
            SELECT name, description, config, type, start_date, end_date
            FROM promotions 
            WHERE is_active = 1
              AND (start_date IS NULL OR start_date <= CURDATE())
              AND (end_date IS NULL OR end_date >= CURDATE())
        ");
        $activePromos3 = $promoStmt3->fetchAll(PDO::FETCH_ASSOC);

        // Dynamically calculate stock for bundles and attach promos
        foreach ($products as &$p) {
            foreach ($activePromos3 as $promo) {
                $config = json_decode($promo['config'], true);
                if (!$config) continue;
                $isMatch = false;
                if (isset($config['buy_target']) && $config['buy_target'] === 'product_' . $p['id']) $isMatch = true;
                if (isset($config['get_target']) && $config['get_target'] === 'product_' . $p['id']) $isMatch = true;
                if (isset($config['buy_target']) && isset($p['category_id']) && $config['buy_target'] === 'category_' . $p['category_id']) $isMatch = true;
                if ($promo['type'] === 'bundle_deal' && !empty($config['components'])) {
                    foreach ($config['components'] as $comp) {
                        if (isset($comp['target']) && ($comp['target'] === 'product_' . $p['id'] || (isset($p['category_id']) && $comp['target'] === 'category_' . $p['category_id']))) {
                            $isMatch = true;
                            break;
                        }
                    }
                }
                if ($isMatch) {
                    $validity = '';
                    if ($promo['start_date'] && $promo['end_date']) {
                        $validity = " (Valid: " . date('M j', strtotime($promo['start_date'])) . " to " . date('M j, Y', strtotime($promo['end_date'])) . ")";
                    } elseif ($promo['end_date']) {
                        $validity = " (Valid until " . date('M j, Y', strtotime($promo['end_date'])) . ")";
                    } elseif ($promo['start_date']) {
                        $validity = " (Valid from " . date('M j, Y', strtotime($promo['start_date'])) . ")";
                    }
                    
                    $p['active_promo'] = $promo['name'];
                    $p['promo_desc'] = ($promo['description'] ?: $promo['name']) . $validity;
                    break;
                }
            }
            if (isset($p['type']) && $p['type'] === 'bundle') {
                $compStmt = $db->prepare("
                    SELECT pbi.quantity as required_qty, cp.quantity as available_qty, cp.name as product_name
                    FROM product_bundle_items pbi
                    JOIN products cp ON pbi.product_id = cp.id
                    WHERE pbi.bundle_id = ?
                ");
                $compStmt->execute([$p['id']]);
                $components = $compStmt->fetchAll();

                $maxBundles = null;
                foreach ($components as $c) {
                    $possible = floor($c['available_qty'] / $c['required_qty']);
                    if ($maxBundles === null || $possible < $maxBundles) {
                        $maxBundles = $possible;
                    }
                }
                $p['quantity'] = $maxBundles ?? 0;

                // Always fetch components so POS can display them
                $p['components'] = $components;
            }
        }
        unset($p);

        jsonResponse([
            'data' => $products,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
        ]);
        break;

    case 'POST':
        requirePermission('manage_products');
        $action = $_GET['action'] ?? '';

        if ($action === 'bulk_delete') {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $ids = $data['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) jsonResponse(['error' => 'No products selected'], 400);

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // Check for existing sales or stock records before deleting
            $checkStmt = $db->prepare("
                SELECT product_id FROM sale_items WHERE product_id IN ($placeholders)
                UNION
                SELECT product_id FROM stock_transactions WHERE product_id IN ($placeholders)
                LIMIT 1
            ");
            $checkStmt->execute(array_merge($ids, $ids));
            if ($checkStmt->fetch()) {
                jsonResponse(['error' => 'Cannot delete these products because they have existing stock or sales records. Consider making them Inactive instead.'], 400);
            }

            try {
                $stmt = $db->prepare("DELETE FROM products WHERE id IN ($placeholders)");
                $stmt->execute($ids);
                jsonResponse(['success' => true, 'message' => count($ids) . ' products deleted successfully']);
            } catch (PDOException $e) {
                if ($e->getCode() == '23000') {
                    jsonResponse(['error' => 'Cannot delete these products because they have existing stock or sales records. Consider making them Inactive instead.'], 400);
                }
                jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
        }

        if ($action === 'bulk_category') {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $ids = $data['ids'] ?? [];
            $categoryId = $data['category_id'] ?? null;
            if ($categoryId === 'NULL') $categoryId = null;

            if (empty($ids) || !is_array($ids)) jsonResponse(['error' => 'No products selected'], 400);

            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$categoryId], $ids);
                $stmt = $db->prepare("UPDATE products SET category_id = ? WHERE id IN ($placeholders)");
                $stmt->execute($params);
                jsonResponse(['success' => true, 'message' => count($ids) . ' products updated successfully']);
            } catch (PDOException $e) {
                jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
            }
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $categoryId = $_POST['category_id'] ?: null;
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);
        $quantity = (int)($_POST['quantity'] ?? 0);
        $lowStockThreshold = (int)($_POST['low_stock_threshold'] ?? 10);
        $barcode = trim($_POST['barcode'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

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
            INSERT INTO products (sku, name, description, category_id, cost_price, selling_price, quantity, low_stock_threshold, image, barcode, expiry_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$sku, $name, $description, $categoryId, $costPrice, $sellingPrice, $quantity, $lowStockThreshold, $image, $barcode, $expiryDate, $status, getCurrentUserId()]);

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
        requirePermission('manage_products');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Product ID required'], 400);

        $checkStmt = $db->prepare("SELECT id FROM products WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) jsonResponse(['error' => 'Product not found'], 404);

        // Parse PUT data
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
        } else {
            parse_str(file_get_contents('php://input'), $input);
        }

        $fields = [];
        $values = [];

        $allowedFields = ['name', 'description', 'category_id', 'cost_price', 'selling_price', 'low_stock_threshold', 'barcode', 'expiry_date', 'status'];
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

        // A threshold or status update might trigger an alert
        processLowStockAlerts($db);

        jsonResponse(['success' => true, 'message' => 'Product updated successfully']);
        break;

    case 'DELETE':
        requirePermission('manage_products');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Product ID required'], 400);

        // Check for existing sales or stock records before deleting
        $salesCheckStmt = $db->prepare("SELECT COUNT(*) FROM sale_items WHERE product_id = ?");
        $salesCheckStmt->execute([$id]);
        if ($salesCheckStmt->fetchColumn() > 0) {
            jsonResponse(['error' => 'Cannot delete this product because it has existing sales records. Consider making it Inactive instead.'], 400);
        }

        $stockCheckStmt = $db->prepare("SELECT COUNT(*) FROM stock_transactions WHERE product_id = ?");
        $stockCheckStmt->execute([$id]);
        if ($stockCheckStmt->fetchColumn() > 0) {
            jsonResponse(['error' => 'Cannot delete this product because it has existing stock records. Consider making it Inactive instead.'], 400);
        }

        // Get image to delete
        $stmt = $db->prepare("SELECT image FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) jsonResponse(['error' => 'Product not found'], 404);

        if ($product['image']) {
            deleteUploadedFile($product['image']);
        }

        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['success' => true, 'message' => 'Product deleted successfully']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
