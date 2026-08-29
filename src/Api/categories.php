<?php
/**
 * Categories API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_products');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

switch ($method) {
    case 'GET':
        $results = $db->query("
            SELECT c.*, COUNT(p.id) as product_count 
            FROM categories c 
            LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
            GROUP BY c.id 
            ORDER BY c.name ASC
        ")->fetchAll();
        
        jsonResponse(['data' => $results]);
        break;
        
    case 'POST':
        verifyCSRFToken();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $color = $input['color'] ?? '#8b5cf6';
        $icon = $input['icon'] ?? 'box';
        
        if (empty($name)) jsonResponse(['error' => 'Category name is required'], 400);
        
        // Check duplicate
        $check = $db->prepare("SELECT id FROM categories WHERE name = ?");
        $check->execute([$name]);
        if ($check->fetch()) jsonResponse(['error' => 'Category already exists'], 400);
        
        $stmt = $db->prepare("INSERT INTO categories (name, description, color, icon) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $description, $color, $icon]);
        
        jsonResponse(['success' => true, 'message' => 'Category created', 'id' => $db->lastInsertId()]);
        break;
        
    case 'PUT':
        verifyCSRFToken();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Category ID required'], 400);
        
        $checkStmt = $db->prepare("SELECT id FROM categories WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) jsonResponse(['error' => 'Category not found'], 404);
        
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $color = $input['color'] ?? '#8b5cf6';
        
        if (empty($name)) jsonResponse(['error' => 'Category name is required'], 400);
        
        // Check duplicate (exclude self)
        $check = $db->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
        $check->execute([$name, $id]);
        if ($check->fetch()) jsonResponse(['error' => 'Category name already exists'], 400);
        
        $stmt = $db->prepare("UPDATE categories SET name = ?, description = ?, color = ? WHERE id = ?");
        $stmt->execute([$name, $description, $color, $id]);
        
        jsonResponse(['success' => true, 'message' => 'Category updated']);
        break;
        
    case 'DELETE':
        verifyCSRFToken();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['error' => 'Category ID required'], 400);
        
        $checkStmt = $db->prepare("SELECT id FROM categories WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) jsonResponse(['error' => 'Category not found'], 404);
        
        // Set products to uncategorized
        $db->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        
        $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        
        jsonResponse(['success' => true, 'message' => 'Category deleted']);
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
