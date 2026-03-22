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
    
    // Get unread notifications count
    $query = "SELECT COUNT(*) as unread_count 
              FROM notifications 
              WHERE (user_id = :user_id OR user_id IS NULL) AND is_read = FALSE";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    $unread_count = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];
    
    echo json_encode(['unread_count' => (int)$unread_count]);
    
} catch(PDOException $exception) {
    error_log('Notifications API Database Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
