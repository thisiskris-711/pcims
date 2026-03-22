<?php
require_once '../config/config.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get recent notifications
    $query = "SELECT n.*, p.product_name 
              FROM notifications n 
              LEFT JOIN products p ON n.related_id = p.product_id AND n.related_to = 'low_stock'
              WHERE (n.user_id = :user_id OR n.user_id IS NULL) 
              ORDER BY n.created_at DESC 
              LIMIT 5";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates for display
    foreach ($notifications as &$notif) {
        $notif['created_at'] = format_date($notif['created_at'], 'Y-m-d H:i:s');
    }
    
    echo json_encode(['success' => true, 'notifications' => $notifications]);
    
} catch(PDOException $exception) {
    error_log('Recent Notifications API Database Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
