<?php
/**
 * Purchase Orders API
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
            if (!$id) jsonResponse(['error' => 'PO ID required'], 400);

            $stmt = $db->prepare("
                SELECT po.*, s.name as supplier_name, s.supplier_code, u.full_name as created_by_name 
                FROM purchase_orders po 
                LEFT JOIN suppliers s ON po.supplier_id = s.id 
                LEFT JOIN users u ON po.created_by = u.id 
                WHERE po.id = ?
            ");
            $stmt->execute([$id]);
            $po = $stmt->fetch();

            if (!$po) jsonResponse(['error' => 'Purchase Order not found'], 404);

            $itemsStmt = $db->prepare("
                SELECT poi.*, p.name as product_name, p.sku, p.quantity as current_stock
                FROM purchase_order_items poi
                LEFT JOIN products p ON poi.product_id = p.id
                WHERE poi.purchase_order_id = ?
            ");
            $itemsStmt->execute([$id]);
            $po['items'] = $itemsStmt->fetchAll();

            jsonResponse($po);
        }

        // List all POs
        $search = trim($_GET['search'] ?? '');
        $status = $_GET['status'] ?? '';
        $supplier = $_GET['supplier_id'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (po.po_number LIKE ? OR s.name LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like]);
        }
        if ($status) {
            $where .= " AND po.status = ?";
            $params[] = $status;
        }
        if ($supplier) {
            $where .= " AND po.supplier_id = ?";
            $params[] = $supplier;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT po.*, s.name as supplier_name 
            FROM purchase_orders po
            LEFT JOIN suppliers s ON po.supplier_id = s.id
            WHERE $where
            ORDER BY po.created_at DESC
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
        $action = $_GET['action'] ?? '';

        if ($action === 'receive') {
            requireRole(ROLE_ADMIN, ROLE_MANAGER);
            
            $input = json_decode(file_get_contents('php://input'), true);
            $poId = (int)($input['po_id'] ?? 0);
            $items = $input['items'] ?? []; // Array of { item_id, quantity }

            if (!$poId || empty($items)) jsonResponse(['error' => 'Invalid data'], 400);

            try {
                $db->beginTransaction();

                $poStmt = $db->prepare("SELECT status FROM purchase_orders WHERE id = ?");
                $poStmt->execute([$poId]);
                $poStatus = $poStmt->fetchColumn();

                if ($poStatus === 'received') {
                    throw new Exception("This Purchase Order has already been fully received.");
                }

                $allFullyReceived = true;

                foreach ($items as $item) {
                    $itemId = (int)$item['item_id'];
                    $qtyToReceive = (int)$item['quantity'];

                    if ($qtyToReceive <= 0) continue; // Skip if nothing to receive for this item

                    $poiStmt = $db->prepare("SELECT product_id, quantity_ordered, quantity_received FROM purchase_order_items WHERE id = ? AND purchase_order_id = ?");
                    $poiStmt->execute([$itemId, $poId]);
                    $poi = $poiStmt->fetch();

                    if (!$poi) throw new Exception("Item not found in this PO.");

                    $pendingQty = $poi['quantity_ordered'] - $poi['quantity_received'];
                    
                    if ($qtyToReceive > $pendingQty) {
                        throw new Exception("Cannot receive more than ordered for item ID {$itemId}.");
                    }

                    // Update PO Item
                    $updatePoi = $db->prepare("UPDATE purchase_order_items SET quantity_received = quantity_received + ? WHERE id = ?");
                    $updatePoi->execute([$qtyToReceive, $itemId]);

                    // Add to Stock
                    $productId = $poi['product_id'];
                    $updateStock = $db->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
                    $updateStock->execute([$qtyToReceive, $productId]);

                    // Get new balance for transaction log
                    $balStmt = $db->prepare("SELECT quantity FROM products WHERE id = ?");
                    $balStmt->execute([$productId]);
                    $newBalance = $balStmt->fetchColumn();

                    // Log transaction
                    $ref = "PO:{$poId}";
                    $txStmt = $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'in', ?, ?, ?, ?, ?)");
                    $txStmt->execute([$productId, $qtyToReceive, $newBalance, $ref, "Received from PO", getCurrentUserId()]);

                    if ($poi['quantity_received'] + $qtyToReceive < $poi['quantity_ordered']) {
                        $allFullyReceived = false;
                    }
                }

                // Check all items to see if PO is fully received
                $checkAllStmt = $db->prepare("SELECT COUNT(*) FROM purchase_order_items WHERE purchase_order_id = ? AND quantity_received < quantity_ordered");
                $checkAllStmt->execute([$poId]);
                $unreceivedItemsCount = (int)$checkAllStmt->fetchColumn();

                $newStatus = ($unreceivedItemsCount === 0) ? 'received' : 'partially_received';

                $updatePoStatus = $db->prepare("UPDATE purchase_orders SET status = ?, received_date = IF(? = 'received', NOW(), received_date), updated_at = NOW() WHERE id = ?");
                $updatePoStatus->execute([$newStatus, $newStatus, $poId]);

                $db->commit();
                jsonResponse(['success' => true, 'message' => 'Items received successfully', 'status' => $newStatus]);
            } catch (Exception $e) {
                $db->rollBack();
                jsonResponse(['error' => $e->getMessage()], 400);
            }
            break;
        }

        // Create PO
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        $input = json_decode(file_get_contents('php://input'), true);

        $supplierId = (int)($input['supplier_id'] ?? 0);
        $expectedDate = !empty($input['expected_date']) ? $input['expected_date'] : null;
        $notes = trim($input['notes'] ?? '');
        $items = $input['items'] ?? [];
        
        $status = $input['status'] ?? 'pending'; // 'draft' or 'pending'

        if (!$supplierId) jsonResponse(['error' => 'Supplier is required'], 400);
        if (empty($items)) jsonResponse(['error' => 'At least one item is required'], 400);

        try {
            $db->beginTransaction();

            // Generate PO Number
            $codeStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM purchase_orders");
            $nextId = $codeStmt->fetchColumn();
            $poNumber = 'PO-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);

            $totalAmount = 0;

            $stmt = $db->prepare("
                INSERT INTO purchase_orders (po_number, supplier_id, status, expected_date, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$poNumber, $supplierId, $status, $expectedDate, $notes, getCurrentUserId()]);
            $poId = $db->lastInsertId();

            $itemStmt = $db->prepare("
                INSERT INTO purchase_order_items (purchase_order_id, product_id, quantity_ordered, unit_cost, total)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($items as $item) {
                $productId = (int)$item['product_id'];
                $qty = (int)$item['quantity'];
                $cost = (float)$item['unit_cost'];
                $total = $qty * $cost;
                $totalAmount += $total;

                if ($qty <= 0) throw new Exception("Quantity must be greater than 0");

                $itemStmt->execute([$poId, $productId, $qty, $cost, $total]);
            }

            // Update total
            $updatePo = $db->prepare("UPDATE purchase_orders SET total_amount = ? WHERE id = ?");
            $updatePo->execute([$totalAmount, $poId]);

            $db->commit();
            jsonResponse(['success' => true, 'message' => 'Purchase Order created', 'id' => $poId, 'po_number' => $poNumber]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['error' => $e->getMessage()], 400);
        }
        break;

    case 'PUT':
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        $id = (int)($_GET['id'] ?? 0);
        $action = $_GET['action'] ?? '';
        
        if (!$id) jsonResponse(['error' => 'PO ID required'], 400);

        $input = json_decode(file_get_contents('php://input'), true);

        if ($action === 'status') {
            $status = $input['status'] ?? '';
            if (!in_array($status, ['ordered', 'cancelled'])) {
                jsonResponse(['error' => 'Invalid status update'], 400);
            }
            
            // Only allow cancelling if not partially received
            if ($status === 'cancelled') {
                $checkStmt = $db->prepare("SELECT status FROM purchase_orders WHERE id = ?");
                $checkStmt->execute([$id]);
                if (in_array($checkStmt->fetchColumn(), ['received', 'partially_received'])) {
                    jsonResponse(['error' => 'Cannot cancel a PO that has been partially or fully received'], 400);
                }
            }

            $stmt = $db->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $id]);

            jsonResponse(['success' => true, 'message' => 'Status updated']);
        }
        
        // Full update (if we wanted to allow editing drafts, not fully implemented here for brevity, assume new POs if needed)
        jsonResponse(['error' => 'Editing POs not supported yet'], 400);
        break;

    case 'DELETE':
        requireRole(ROLE_ADMIN);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'PO ID required'], 400);

        // We only allow deleting drafts
        $checkStmt = $db->prepare("SELECT status FROM purchase_orders WHERE id = ?");
        $checkStmt->execute([$id]);
        if ($checkStmt->fetchColumn() !== 'draft') {
            jsonResponse(['error' => 'Only drafts can be deleted. Use cancel for other statuses.'], 400);
        }

        $stmt = $db->prepare("DELETE FROM purchase_orders WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['success' => true, 'message' => 'Purchase Order deleted']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
