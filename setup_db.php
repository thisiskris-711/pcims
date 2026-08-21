<?php
require 'config/app.php';
$db = getDB();

try {
    // 1. Create audit_logs table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `audit_logs` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(10) unsigned DEFAULT NULL,
            `action` varchar(50) NOT NULL,
            `target_user_id` int(10) unsigned DEFAULT NULL,
            `old_value` text DEFAULT NULL,
            `new_value` text DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `fk_audit_log_user` (`user_id`),
            KEY `fk_audit_log_target` (`target_user_id`),
            CONSTRAINT `fk_audit_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_audit_log_target` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // 2. Update role display_names
    $stmt = $db->prepare("UPDATE roles SET display_name = ? WHERE name = ?");
    $stmt->execute(['System Administrator', 'admin']);
    $stmt->execute(['Inventory/Warehouse Manager', 'manager']);
    $stmt->execute(['Warehouse Staff/Stock Associate', 'stocker']);
    $stmt->execute(['Cashier/Sales Associate', 'cashier']);
    
    echo "Database setup completed successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
