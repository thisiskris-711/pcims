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
                SELECT n.id, n.type, n.title, n.message, n.link, 
                       (CASE WHEN nr.id IS NOT NULL THEN 1 ELSE n.is_read END) as is_read, 
                       n.created_at 
                FROM notifications n
                LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
                WHERE n.user_id = ? OR n.user_id IS NULL 
                ORDER BY n.created_at DESC 
                LIMIT $perPage OFFSET $offset
            ");
            $stmt->execute([$userId, $userId]);
            $notifications = $stmt->fetchAll();
            
            // Unread count
            $countStmt = $db->prepare("
                SELECT COUNT(*) 
                FROM notifications n
                LEFT JOIN notification_reads nr ON n.id = nr.notification_id AND nr.user_id = ?
                WHERE (n.user_id = ? OR n.user_id IS NULL) 
                  AND n.is_read = 0 
                  AND nr.id IS NULL
            ");
            $countStmt->execute([$userId, $userId]);
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
                // Verify the notification belongs to user or is global
                $chkStmt = $db->prepare("SELECT user_id FROM notifications WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
                $chkStmt->execute([$notificationId, $userId]);
                $notif = $chkStmt->fetch();
                
                if ($notif) {
                    if ($notif['user_id'] === null) {
                        // Global notification: insert into notification_reads
                        $stmt = $db->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?, ?)");
                        $stmt->execute([$notificationId, $userId]);
                    } else {
                        // Private notification: just update is_read
                        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
                        $stmt->execute([$notificationId]);
                    }
                }
            } else {
                // Mark all as read
                // Update private notifications
                $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
                $stmt->execute([$userId]);
                
                // Insert into notification_reads for all unread global notifications
                $stmt = $db->prepare("
                    INSERT IGNORE INTO notification_reads (notification_id, user_id)
                    SELECT id, ? FROM notifications 
                    WHERE user_id IS NULL
                ");
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
