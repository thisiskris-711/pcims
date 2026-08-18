<?php
/**
 * Dealers API
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
            if (!$id) jsonResponse(['error' => 'Dealer ID required'], 400);

            $stmt = $db->prepare("SELECT d.*, u.full_name as created_by_name FROM dealers d LEFT JOIN users u ON d.created_by = u.id WHERE d.id = ?");
            $stmt->execute([$id]);
            $dealer = $stmt->fetch();

            if (!$dealer) jsonResponse(['error' => 'Dealer not found'], 404);

            // Get recent credit transactions
            $txStmt = $db->prepare("
                SELECT ct.*, u.full_name as processed_by 
                FROM credit_transactions ct 
                LEFT JOIN users u ON ct.created_by = u.id 
                WHERE ct.dealer_id = ? 
                ORDER BY ct.created_at DESC 
                LIMIT 20
            ");
            $txStmt->execute([$id]);
            $dealer['credit_history'] = $txStmt->fetchAll();

            // Get recent sales
            $salesStmt = $db->prepare("
                SELECT s.id, s.invoice_no, s.total, s.payment_method, s.payment_status, s.created_at
                FROM sales s
                WHERE s.dealer_id = ?
                ORDER BY s.created_at DESC
                LIMIT 10
            ");
            $salesStmt->execute([$id]);
            $dealer['recent_sales'] = $salesStmt->fetchAll();

            jsonResponse($dealer);
        }

        // Search for POS dealer select
        if ($action === 'search') {
            $q = trim($_GET['q'] ?? '');
            $stmt = $db->prepare("
                SELECT id, dealer_code, name, phone, credit_limit, credit_balance, status
                FROM dealers
                WHERE status = 'active' AND (name LIKE ? OR dealer_code LIKE ?)
                ORDER BY name ASC
                LIMIT 20
            ");
            $like = "%{$q}%";
            $stmt->execute([$like, $like]);
            jsonResponse(['data' => $stmt->fetchAll()]);
        }

        // List all dealers
        $search = trim($_GET['search'] ?? '');
        $status = $_GET['status'] ?? '';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);

        $where = "1=1";
        $params = [];

        if ($search) {
            $where .= " AND (d.name LIKE ? OR d.dealer_code LIKE ? OR d.phone LIKE ?)";
            $like = "%{$search}%";
            $params = array_merge($params, [$like, $like, $like]);
        }
        if ($status) {
            $where .= " AND d.status = ?";
            $params[] = $status;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM dealers d WHERE $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT d.*, 
                   (SELECT COUNT(*) FROM sales WHERE dealer_id = d.id) as total_sales,
                   (SELECT COALESCE(SUM(total), 0) FROM sales WHERE dealer_id = d.id) as total_revenue
            FROM dealers d
            WHERE $where
            ORDER BY d.name ASC
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
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $creditLimit = (float)($input['credit_limit'] ?? 0);
        $notes = trim($input['notes'] ?? '');

        if (empty($name)) jsonResponse(['error' => 'Dealer name is required'], 400);
        if ($creditLimit < 0) jsonResponse(['error' => 'Credit limit cannot be negative'], 400);

        // Check duplicate name
        $check = $db->prepare("SELECT id FROM dealers WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) jsonResponse(['error' => 'A dealer with this name already exists'], 400);

        // Generate dealer code
        $codeStmt = $db->query("SELECT COALESCE(MAX(id), 0) + 1 FROM dealers");
        $nextId = $codeStmt->fetchColumn();
        $dealerCode = 'DLR-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);

        // Ensure code uniqueness
        $codeCheck = $db->prepare("SELECT id FROM dealers WHERE dealer_code = ?");
        $codeCheck->execute([$dealerCode]);
        if ($codeCheck->fetch()) {
            $dealerCode = 'DLR-' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        }

        $stmt = $db->prepare("
            INSERT INTO dealers (dealer_code, name, email, phone, address, credit_limit, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$dealerCode, $name, $email, $phone, $address, $creditLimit, $notes, getCurrentUserId()]);

        jsonResponse(['success' => true, 'message' => 'Dealer registered', 'id' => $db->lastInsertId(), 'dealer_code' => $dealerCode]);
        break;

    case 'PUT':
        requireRole(ROLE_ADMIN, ROLE_MANAGER);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Dealer ID required'], 400);

        $input = json_decode(file_get_contents('php://input'), true);

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $address = trim($input['address'] ?? '');
        $creditLimit = (float)($input['credit_limit'] ?? 0);
        $status = $input['status'] ?? 'active';
        $notes = trim($input['notes'] ?? '');

        if (empty($name)) jsonResponse(['error' => 'Dealer name is required'], 400);
        if ($creditLimit < 0) jsonResponse(['error' => 'Credit limit cannot be negative'], 400);
        if (!in_array($status, ['active', 'suspended', 'inactive'])) jsonResponse(['error' => 'Invalid status'], 400);

        // Check duplicate name (exclude self)
        $check = $db->prepare("SELECT id FROM dealers WHERE name = ? AND id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) jsonResponse(['error' => 'A dealer with this name already exists'], 400);

        // Check if reducing credit limit below current balance
        $current = $db->prepare("SELECT credit_balance FROM dealers WHERE id = ?");
        $current->execute([$id]);
        $currentBalance = (float)$current->fetchColumn();
        if ($creditLimit < $currentBalance) {
            jsonResponse(['error' => "Credit limit cannot be less than outstanding balance (\${$currentBalance})"], 400);
        }

        $stmt = $db->prepare("
            UPDATE dealers SET name=?, email=?, phone=?, address=?, credit_limit=?, status=?, notes=?
            WHERE id=?
        ");
        $stmt->execute([$name, $email, $phone, $address, $creditLimit, $status, $notes, $id]);

        jsonResponse(['success' => true, 'message' => 'Dealer updated']);
        break;

    case 'DELETE':
        requireRole(ROLE_ADMIN);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Dealer ID required'], 400);

        // Check for outstanding balance
        $balanceCheck = $db->prepare("SELECT credit_balance FROM dealers WHERE id = ?");
        $balanceCheck->execute([$id]);
        $balance = (float)$balanceCheck->fetchColumn();
        if ($balance > 0) {
            jsonResponse(['error' => "Cannot deactivate dealer with outstanding balance (\${$balance})"], 400);
        }

        $stmt = $db->prepare("UPDATE dealers SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$id]);

        jsonResponse(['success' => true, 'message' => 'Dealer deactivated']);
        break;

    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
