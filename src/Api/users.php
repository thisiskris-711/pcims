<?php
/**
 * Users API (Admin only)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_users');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        $offset = ($page - 1) * $perPage;

        $total = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $totalPages = max(1, ceil($total / $perPage));

        $stmt = $db->prepare("SELECT id, username, email, full_name, role, status, permissions, last_login, created_at FROM users ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
        $stmt->execute();
        
        jsonResponse([
            'data' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages
        ]);
        break;
        
    case 'POST':
        // Prevent abuse: Max 10 user creations per hour per IP
        enforceRateLimit('admin_create_user', 10, 3600);
        
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $fullName = trim($input['full_name'] ?? '');
        $role = $input['role'] ?? 'sales_associate';
        
        if (empty($username) || empty($email) || empty($password) || empty($fullName)) {
            jsonResponse(['error' => 'All fields are required'], 400);
        }
        
        if (strlen($password) < 6) {
            jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
        }
        
        // Check duplicates
        $check = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->execute([$username, $email]);
        if ($check->fetch()) jsonResponse(['error' => 'Username or email already exists'], 400);
        
        // Do not bake presets into the user row. Use NULL to indicate inheritance.
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $token = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role, permissions, email_verification_token, email_verification_token_expires, email_verification_attempts) VALUES (?, ?, ?, ?, ?, NULL, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0)");
        $stmt->execute([$username, $email, $hash, $fullName, $role, $token]);
        
        sendEmail($email, 'Verify Your Email Address', "Your email verification PIN is: <strong>$token</strong>");
        
        $newUserId = $db->lastInsertId();
        logAudit('created_user', $newUserId, null, json_encode(['role' => $role]));
        
        jsonResponse(['success' => true, 'message' => 'User created', 'id' => $newUserId]);
        break;
        
    case 'PUT':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'User ID required'], 400);
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $fields = [];
        $values = [];
        
        if (isset($input['full_name'])) { $fields[] = "full_name = ?"; $values[] = $input['full_name']; }
        if (isset($input['email'])) { 
            // Check for duplicate email
            $checkEmail = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmail->execute([$input['email'], $id]);
            if ($checkEmail->fetch()) {
                jsonResponse(['error' => 'Email is already used by another user'], 400);
            }
            $fields[] = "email = ?"; 
            $values[] = $input['email']; 
        }
        
        if (isset($input['role'])) { 
            $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $oldRole = $stmt->fetchColumn();
            
            $fields[] = "role = ?"; 
            $values[] = $input['role']; 
            
            if ($oldRole !== $input['role']) {
                logAudit('updated_role', $id, $oldRole, $input['role']);
            }
        }
        if (isset($input['status'])) { $fields[] = "status = ?"; $values[] = $input['status']; }
        
        if (!empty($input['password'])) {
            if (strlen($input['password']) < 6) jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
            $fields[] = "password_hash = ?";
            $values[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        
        if (isset($input['permissions'])) {
            $stmt = $db->prepare("SELECT permissions FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $oldPerms = $stmt->fetchColumn();
            
            $fields[] = "permissions = ?";
            // Only allow array format for safety
            $perms = is_array($input['permissions']) ? $input['permissions'] : [];
            $newPerms = json_encode($perms);
            $values[] = $newPerms;
            
            if ($oldPerms !== $newPerms) {
                logAudit('updated_permissions', $id, $oldPerms, $newPerms);
            }
        }
        
        if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);
        
        $values[] = $id;
        
        try {
            $stmt = $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($values);
            jsonResponse(['success' => true, 'message' => 'User updated']);
        } catch (PDOException $e) {
            error_log("Failed to update user: " . $e->getMessage());
            jsonResponse(['error' => 'Database error occurred while updating user'], 500);
        }
        break;
        
    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'User ID required'], 400);
        if ($id == getCurrentUserId()) jsonResponse(['error' => 'Cannot delete yourself'], 400);
        
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        logAudit('deleted_user', $id);
        jsonResponse(['success' => true, 'message' => 'User deleted']);
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
