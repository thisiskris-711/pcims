<?php
require_once dirname(__DIR__) . '/../config/app.php';

header('Content-Type: application/json');
requirePermission('manage_roles');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

if ($method === 'GET') {
    // List all roles
    $stmt = $db->query("SELECT * FROM roles ORDER BY id ASC");
    $roles = $stmt->fetchAll();
    
    // Decode JSON permissions
    foreach ($roles as &$role) {
        $role['permissions'] = $role['permissions'] ? json_decode($role['permissions'], true) : [];
    }
    
    echo json_encode(['success' => true, 'data' => $roles]);
    exit;
}

if ($method === 'POST') {
    // Create new role
    $data = json_decode(file_get_contents('php://input'), true);
    
    $name = trim($data['name'] ?? '');
    $displayName = trim($data['display_name'] ?? '');
    $permissions = $data['permissions'] ?? [];
    
    if (empty($name) || empty($displayName)) {
        echo json_encode(['success' => false, 'message' => 'Role name and display name are required']);
        exit;
    }
    
    // Ensure name is safe (alphanumeric and underscores)
    $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    
    $stmt = $db->prepare("SELECT id FROM roles WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'A role with this unique name already exists']);
        exit;
    }
    
    $permsJson = json_encode(array_values((array)$permissions));
    
    $stmt = $db->prepare("INSERT INTO roles (name, display_name, permissions) VALUES (?, ?, ?)");
    if ($stmt->execute([$name, $displayName, $permsJson])) {
        $newRoleId = $db->lastInsertId();
        logAudit('created_role', null, null, json_encode(['role_id' => $newRoleId, 'name' => $name]));
        echo json_encode(['success' => true, 'message' => 'Role created successfully', 'id' => $newRoleId]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create role']);
    }
    exit;
}

if ($method === 'PUT') {
    // Update role
    $data = json_decode(file_get_contents('php://input'), true);
    
    $id = (int)($data['id'] ?? 0);
    $displayName = trim($data['display_name'] ?? '');
    $permissions = $data['permissions'] ?? [];
    
    if (empty($id) || empty($displayName)) {
        echo json_encode(['success' => false, 'message' => 'Role ID and display name are required']);
        exit;
    }
    
    $permsJson = json_encode(array_values((array)$permissions));
    
    $stmt = $db->prepare("UPDATE roles SET display_name = ?, permissions = ? WHERE id = ?");
    if ($stmt->execute([$displayName, $permsJson, $id])) {
        logAudit('updated_role_permissions', null, null, json_encode(['role_id' => $id, 'display_name' => $displayName, 'permissions' => $permissions]));
        echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update role']);
    }
    exit;
}

if ($method === 'DELETE') {
    // Delete role
    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['id'] ?? 0);
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Role ID is required']);
        exit;
    }
    
    // Check if role is standard
    $stmt = $db->prepare("SELECT name FROM roles WHERE id = ?");
    $stmt->execute([$id]);
    $role = $stmt->fetch();
    
    if (!$role) {
        echo json_encode(['success' => false, 'message' => 'Role not found']);
        exit;
    }
    
    if ($role['name'] === 'admin') {
        echo json_encode(['success' => false, 'message' => 'Cannot delete the core Administrator role']);
        exit;
    }
    
    // Check if users are using this role
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
    $stmt->execute([$role['name']]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete role because it is assigned to one or more users']);
        exit;
    }
    
    $stmt = $db->prepare("DELETE FROM roles WHERE id = ?");
    if ($stmt->execute([$id])) {
        logAudit('deleted_role', null, json_encode(['role_id' => $id, 'name' => $role['name']]), null);
        echo json_encode(['success' => true, 'message' => 'Role deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete role']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
