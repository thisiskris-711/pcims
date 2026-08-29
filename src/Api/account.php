<?php
/**
 * Account Preferences API (Per User)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = getCurrentUserId();

switch ($method) {
    case 'POST':
        verifyCSRFToken();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $preferences = [
            'theme' => in_array($input['theme'] ?? '', ['light', 'dark']) ? $input['theme'] : 'light',
            'notify_email_sales' => filter_var($input['notify_email_sales'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'notify_email_inventory' => filter_var($input['notify_email_inventory'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ];
        
        $jsonPrefs = json_encode($preferences);
        
        try {
            $stmt = $db->prepare("UPDATE users SET preferences = ? WHERE id = ?");
            $stmt->execute([$jsonPrefs, $userId]);
            
            jsonResponse(['success' => true, 'message' => 'Preferences updated successfully']);
        } catch (Exception $e) {
            error_log("Account preferences update error: " . $e->getMessage());
            jsonResponse(['error' => 'Database error occurred'], 500);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        break;
}
