<?php
/**
 * Suppliers API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_suppliers');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';

        if ($action === 'detail') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) jsonResponse(['error' => 'Supplier ID required'], 400);

            $stmt = $db->prepare("SELECT s.*, u.full_name as created_by_name FROM suppliers s LEFT JOIN users u ON s.created_by = u.id WHERE s.id = ?");
            $stmt->execute([$id]);
            $supplier = $stmt->fetch();

            if (!$supplier) jsonResponse(['error' => 'Supplier not found'], 404);

            // Get recent purchase orders
            $poStmt = $db->prepare("
                SELECT po.id, po.po_number, po.total_amount, po.status, po.expected_date, po.created_at
                FROM purchase_orders po
                WHERE po.supplier_id = ?
                ORDER BY po.created_at DESC
                LIMIT 10
            ");
            $poStmt->execute([$id]);
            $supplier['recent_pos'] = $poStmt->fetchAll();

            jsonResponse($supplier);
        }

        // Search for PO supplier select
        if ($action === 'search') {
            $q = trim($_GET['q'] ?? '');
            $stmt = $db->prepare("
                SELECT id, supplier_code, name, contact_person, phone, status
                FROM suppliers
                WHERE status = 'active' AND (name LIKE ? OR supplier_code LIKE ? OR contact_person LIKE ?)
                ORDER BY name ASC
                LIMIT 20
            ");
            $like = "%{$q}%";
            $stmt->execute([$like, $like, $like]);
            jsonResponse(['data' => $stmt->fetchAll()]);
        }

        // List all suppliers
        $search = trim($_GET['search'] ?? '');
        $status = $_GET['status'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (s.name LIKE ? OR s.supplier_code LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like, $like]);
        }
        if ($status) {
            $where .= " AND s.status = ?";
            $params[] = $status;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM suppliers s WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT s.*, 
                   (SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = s.id) as total_pos,
                   (SELECT COALESCE(SUM(total_amount), 0) FROM purchase_orders WHERE supplier_id = s.id AND status != 'cancelled') as total_purchases
            FROM suppliers s
            WHERE $where
            ORDER BY s.name ASC
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

        $name = trim($input['name'] ?? '');
        $contactPerson = trim($input['contact_person'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $notes = trim($input['notes'] ?? '');

        if (empty($name)) jsonResponse(['error' => 'Supplier name is required'], 400);

        // Check duplicate name
        $check = $db->prepare("SELECT id FROM suppliers WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) jsonResponse(['error' => 'A supplier with this name already exists'], 400);

        // Generate supplier code
        $codeStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM suppliers");
        $nextId = $codeStmt->fetchColumn();
        $supplierCode = 'SUP-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

        // Ensure code uniqueness
        $codeCheck = $db->prepare("SELECT id FROM suppliers WHERE supplier_code = ?");
        $codeCheck->execute([$supplierCode]);
        if ($codeCheck->fetch()) {
            $supplierCode = 'SUP-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        }

        $stmt = $db->prepare("
            INSERT INTO suppliers (supplier_code, name, contact_person, email, phone, address, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$supplierCode, $name, $contactPerson, $email, $phone, $address, $notes, getCurrentUserId()]);

        jsonResponse(['success' => true, 'message' => 'Supplier registered', 'id' => $db->lastInsertId(), 'supplier_code' => $supplierCode]);
        break;

    case 'PUT':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Supplier ID required'], 400);
        
        $checkStmt = $db->prepare("SELECT id FROM suppliers WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) jsonResponse(['error' => 'Supplier not found'], 404);

        $input = json_decode(file_get_contents('php://input'), true);

        $name = trim($input['name'] ?? '');
        $contactPerson = trim($input['contact_person'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $status = $input['status'] ?? 'active';
        $notes = trim($input['notes'] ?? '');

        if (empty($name)) jsonResponse(['error' => 'Supplier name is required'], 400);
        if (!in_array($status, ['active', 'inactive'])) jsonResponse(['error' => 'Invalid status'], 400);

        // Check duplicate name (exclude self)
        $check = $db->prepare("SELECT id FROM suppliers WHERE name = ? AND id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) jsonResponse(['error' => 'A supplier with this name already exists'], 400);

        $stmt = $db->prepare("
            UPDATE suppliers SET name=?, contact_person=?, email=?, phone=?, address=?, status=?, notes=?
            WHERE id=?
        ");
        $stmt->execute([$name, $contactPerson, $email, $phone, $address, $status, $notes, $id]);

        jsonResponse(['success' => true, 'message' => 'Supplier updated']);
        break;

    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Supplier ID required'], 400);
        
        $checkStmt = $db->prepare("SELECT id FROM suppliers WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) jsonResponse(['error' => 'Supplier not found'], 404);

        // Optional: Check if there are active purchase orders, for now just set to inactive.
        $stmt = $db->prepare("UPDATE suppliers SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['success' => true, 'message' => 'Supplier deactivated']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
