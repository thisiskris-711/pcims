<?php
/**
 * Low Stock Alerts Engine
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/auth.php';

function processLowStockAlerts(PDO $db) {
    try {
        // 1. Reset alert flag for replenished products
        $db->exec("UPDATE products SET low_stock_alert_sent = 0 WHERE quantity > low_stock_threshold AND low_stock_alert_sent = 1");

        // 2. Find all products that reached or fell below threshold and haven't triggered an alert
        $stmt = $db->query("SELECT id, sku, name, quantity, low_stock_threshold FROM products WHERE quantity <= low_stock_threshold AND low_stock_alert_sent = 0 AND status = 'active'");
        $lowStockProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($lowStockProducts)) {
            return; // Nothing to process
        }

        // 3. Find all users with manage_products permission
        $usersToAlert = [];
        
        // Find roles with manage_products permission
        $roleStmt = $db->query("SELECT name, permissions FROM roles");
        $rolesWithPerm = [];
        while ($role = $roleStmt->fetch(PDO::FETCH_ASSOC)) {
            if ($role['name'] === ROLE_ADMIN) {
                $rolesWithPerm[] = $role['name'];
                continue;
            }
            $perms = json_decode($role['permissions'] ?? '[]', true) ?: [];
            if (in_array('manage_products', $perms)) {
                $rolesWithPerm[] = $role['name'];
            }
        }
        
        // Find active users
        $userStmt = $db->query("SELECT id, username, email, full_name, role, permissions FROM users WHERE status = 'active' AND email IS NOT NULL AND email != ''");
        while ($user = $userStmt->fetch(PDO::FETCH_ASSOC)) {
            $hasPerm = false;
            if ($user['role'] === ROLE_ADMIN) {
                $hasPerm = true;
            } else {
                if ($user['permissions'] !== null) {
                    $perms = json_decode($user['permissions'], true) ?: [];
                    $hasPerm = in_array('manage_products', $perms);
                } else {
                    $hasPerm = in_array($user['role'], $rolesWithPerm);
                }
            }
            
            if ($hasPerm) {
                $usersToAlert[] = $user;
            }
        }
        
        if (empty($usersToAlert)) {
            return; // No one to alert
        }
        
        // 4. Compose Email Content
        $subject = "Low Stock Alert: " . count($lowStockProducts) . " product(s) need attention";
        
        $bodyHtml = "<div style='font-family: Arial, sans-serif; color: #333;'>";
        $bodyHtml .= "<h2 style='color: #d9534f;'>Low Stock Alert</h2>";
        $bodyHtml .= "<p>The following product(s) have reached or fallen below their minimum stock threshold:</p>";
        $bodyHtml .= "<table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; width: 100%; margin-bottom: 20px;'>";
        $bodyHtml .= "<tr style='background-color: #f8f9fa;'><th>SKU</th><th>Product Name</th><th>Current Stock</th><th>Min. Threshold</th></tr>";
        
        $productIds = [];
        foreach ($lowStockProducts as $p) {
            $productIds[] = $p['id'];
            $bodyHtml .= "<tr>";
            $bodyHtml .= "<td>" . htmlspecialchars($p['sku']) . "</td>";
            $bodyHtml .= "<td>" . htmlspecialchars($p['name']) . "</td>";
            $bodyHtml .= "<td style='color: #d9534f; font-weight: bold; text-align: center;'>" . htmlspecialchars($p['quantity']) . "</td>";
            $bodyHtml .= "<td style='text-align: center;'>" . htmlspecialchars($p['low_stock_threshold']) . "</td>";
            $bodyHtml .= "</tr>";
        }
        $bodyHtml .= "</table>";
        $bodyHtml .= "<p>Please review and replenish the inventory as soon as possible.</p>";
        $bodyHtml .= "<p><a href='" . APP_URL . "/products' style='display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;'>View Products</a></p>";
        $bodyHtml .= "<p style='font-size: 0.8rem; color: #777; margin-top: 30px;'>This is an automated message from " . APP_NAME . ".</p>";
        $bodyHtml .= "</div>";

        // 5. Send Emails & Create System Notifications
        foreach ($usersToAlert as $user) {
            // Send Email
            sendEmail($user['email'], $subject, $bodyHtml, true);
            
            // System Notification
            $notifTitle = "Low Stock Alert";
            $notifMsg = count($lowStockProducts) . " product(s) are low on stock and need replenishment.";
            $notifLink = "/products";
            
            $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, 'warning', ?, ?, ?, NOW())");
            $notifStmt->execute([$user['id'], $notifTitle, $notifMsg, $notifLink]);
        }
        
        // 6. Audit Log
        $auditStmt = $db->prepare("INSERT INTO audit_logs (user_id, action, new_value, created_at) VALUES (?, 'system_alert', ?, NOW())");
        $systemUserId = $_SESSION['user_id'] ?? null;
        $auditLogMsg = "Low stock alert sent to " . count($usersToAlert) . " user(s) for " . count($productIds) . " product(s)";
        $auditStmt->execute([$systemUserId, $auditLogMsg]);
        
        // 7. Update products to mark alert sent
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        $updateStmt = $db->prepare("UPDATE products SET low_stock_alert_sent = 1 WHERE id IN ($placeholders)");
        $updateStmt->execute($productIds);
        
    } catch (Exception $e) {
        error_log("Failed to process low stock alerts: " . $e->getMessage());
    }
}
