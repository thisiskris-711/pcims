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

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token
$csrf_token = $input['csrf_token'] ?? '';
if (!verify_csrf_token($csrf_token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if marking all as read or specific notification
    if (isset($input['notification_id'])) {
        // Mark specific notification as read
        $query = "UPDATE notifications SET is_read = TRUE 
                  WHERE notification_id = :notification_id 
                  AND (user_id = :user_id OR user_id IS NULL)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':notification_id', $input['notification_id']);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        
        $affected_rows = $stmt->rowCount();
        
    } else {
        // Mark all notifications as read for this user
        $query = "UPDATE notifications SET is_read = TRUE 
                  WHERE user_id = :user_id AND is_read = FALSE";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        
        $affected_rows = $stmt->rowCount();
    }
    
    echo json_encode(['success' => true, 'affected_rows' => $affected_rows]);
    
} catch(PDOException $exception) {
    error_log('Notifications Mark Read API Database Error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
