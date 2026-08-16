<?php
/**
 * Notifications API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();
$userId = getCurrentUserId();

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['per_page'] ?? 50);
            $offset = ($page - 1) * $perPage;

            // Total count for pagination (both read and unread)
            $totalStmt = $db->prepare("
                SELECT COUNT(*) 
                FROM notifications 
                WHERE user_id = ? OR user_id IS NULL
            ");
            $totalStmt->execute([$userId]);
            $total = (int) $totalStmt->fetchColumn();
            $totalPages = max(1, ceil($total / $perPage));

            // Fetch notifications
            $stmt = $db->prepare("
                SELECT id, type, title, message, link, is_read, created_at 
                FROM notifications 
                WHERE user_id = ? OR user_id IS NULL 
                ORDER BY created_at DESC 
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute([$userId]);
            $notifications = $stmt->fetchAll();
            
            // Unread count
            $countStmt = $db->prepare("
                SELECT COUNT(*) 
                FROM notifications 
                WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0
            ");
            $countStmt->execute([$userId]);
            $unreadCount = (int) $countStmt->fetchColumn();
            
            jsonResponse([
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
                'total' => $total,
                'page' => $page,
                'total_pages' => $totalPages
            ]);
        }
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $action = $input['action'] ?? '';
        
        if ($action === 'mark_read') {
            $notificationId = (int)($input['id'] ?? 0);
            
            if ($notificationId) {
                $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
                $stmt->execute([$notificationId, $userId]);
            } else {
                // Mark all as read
                $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0");
                $stmt->execute([$userId]);
            }
            
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Invalid action'], 400);
        }
        break;
        
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}
