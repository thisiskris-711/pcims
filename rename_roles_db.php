<?php
require 'config/app.php';
$db = getDB();

try {
    $db->beginTransaction();
    
    // Define mappings
    $mappings = [
        'admin' => 'system_admin',
        'manager' => 'inventory_manager',
        'stocker' => 'stock_associate',
        'cashier' => 'sales_associate'
    ];
    
    // Update users table
    $stmtUser = $db->prepare("UPDATE users SET role = ? WHERE role = ?");
    // Update roles table
    $stmtRole = $db->prepare("UPDATE roles SET name = ? WHERE name = ?");
    
    foreach ($mappings as $old => $new) {
        $stmtUser->execute([$new, $old]);
        $stmtRole->execute([$new, $old]);
    }
    
    $db->commit();
    echo "Database roles updated successfully.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
