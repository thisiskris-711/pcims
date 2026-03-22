<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';

// Check if user is logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();

    // Get dashboard statistics
    $query = "SELECT COUNT(*) as total FROM products WHERE status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Low stock products
    $query = "SELECT COUNT(*) as total FROM inventory i 
              JOIN products p ON i.product_id = p.product_id 
              WHERE i.quantity_on_hand <= 5 AND p.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $low_stock_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Total inventory value
    $query = "SELECT SUM(i.quantity_on_hand * p.unit_price) as total_value 
              FROM inventory i 
              JOIN products p ON i.product_id = p.product_id 
              WHERE p.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $total_inventory_value = $stmt->fetch(PDO::FETCH_ASSOC)['total_value'] ?? 0;

    // Recent stock movements
    $query = "SELECT sm.*, p.product_name, u.full_name 
              FROM stock_movements sm 
              JOIN products p ON sm.product_id = p.product_id 
              JOIN users u ON sm.user_id = u.user_id 
              ORDER BY sm.movement_date DESC LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $recent_movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Unread notifications
    $query = "SELECT COUNT(*) as total FROM notifications 
              WHERE user_id = ? AND is_read = FALSE";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $_SESSION['user_id']);
    $stmt->execute();
    $unread_notifications = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Stock status distribution
    $query = "SELECT
                SUM(CASE WHEN i.quantity_on_hand = 0 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN i.quantity_on_hand BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS low_stock,
                SUM(CASE WHEN i.quantity_on_hand > 5 THEN 1 ELSE 0 END) AS in_stock
              FROM inventory i
              JOIN products p ON i.product_id = p.product_id
              WHERE p.status = 'active'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stock_status_counts = [
        'out_of_stock' => (int)($row['out_of_stock'] ?? 0),
        'low_stock' => (int)($row['low_stock'] ?? 0),
        'in_stock' => (int)($row['in_stock'] ?? 0),
    ];

    $response = [
        'success' => true,
        'data' => [
            'total_products' => (int)$total_products,
            'low_stock_products' => (int)$low_stock_products,
            'total_inventory_value' => (float)$total_inventory_value,
            'unread_notifications' => (int)$unread_notifications,
            'recent_movements' => $recent_movements,
            'stock_status_counts' => $stock_status_counts,
            'user' => [
                'id' => $_SESSION['user_id'],
                'name' => $_SESSION['full_name'],
                'role' => $_SESSION['role']
            ]
        ]
    ];

    echo json_encode($response);

} catch (PDOException $exception) {
    error_log('Dashboard API Database Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
} catch (Exception $exception) {
    error_log('Dashboard API Unexpected Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error'
    ]);
}
?>
