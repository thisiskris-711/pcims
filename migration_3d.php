<?php
require_once __DIR__ . '/config/app.php';

$db = getDB();

try {
    // Check if column exists
    $stmt = $db->query("SHOW COLUMNS FROM products LIKE 'model_3d'");
    if (!$stmt->fetch()) {
        $db->exec("ALTER TABLE products ADD COLUMN model_3d VARCHAR(255) DEFAULT NULL AFTER image");
        echo "Successfully added model_3d column.\n";
    } else {
        echo "Column model_3d already exists.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
