<?php
/**
 * Users API (Admin only)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN);

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $results = $db->query("SELECT id, username, email, full_name, role, status, last_login, created_at FROM users ORDER BY created_at DESC")->fetchAll();
        jsonResponse(['data' => $results]);
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $username = trim($input['username'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $fullName = trim($input['full_name'] ?? '');
        $role = $input['role'] ?? 'staff';
        
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
        
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $email, $hash, $fullName, $role]);
        
        jsonResponse(['success' => true, 'message' => 'User created', 'id' => $db->lastInsertId()]);
        break;
        
    case 'PUT':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'User ID required'], 400);
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $fields = [];
        $values = [];
        
        if (isset($input['full_name'])) { $fields[] = "full_name = ?"; $values[] = $input['full_name']; }
        if (isset($input['email'])) { $fields[] = "email = ?"; $values[] = $input['email']; }
        if (isset($input['role'])) { $fields[] = "role = ?"; $values[] = $input['role']; }
        if (isset($input['status'])) { $fields[] = "status = ?"; $values[] = $input['status']; }
        
        if (!empty($input['password'])) {
            if (strlen($input['password']) < 6) jsonResponse(['error' => 'Password must be at least 6 characters'], 400);
            $fields[] = "password_hash = ?";
            $values[] = password_hash($input['password'], PASSWORD_DEFAULT);
        }
        
        if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);
        
        $values[] = $id;
        $stmt = $db->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($values);
        
        jsonResponse(['success' => true, 'message' => 'User updated']);
        break;
        
    case 'DELETE':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'User ID required'], 400);
        if ($id == getCurrentUserId()) jsonResponse(['error' => 'Cannot delete yourself'], 400);
        
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'User deleted']);
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
