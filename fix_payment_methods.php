<?php
require_once __DIR__ . '/config/app.php';

$db = getDB();

try {
    $db->beginTransaction();

    // 1. Change column to VARCHAR temporarily so we don't lose data
    $db->exec("ALTER TABLE sales MODIFY COLUMN payment_method VARCHAR(50)");

    // 2. Fetch all sales and assign a new random method
    $paymentMethods = ['cash', 'credit', 'cash&credit'];
    
    $stmt = $db->query("SELECT id FROM sales");
    $sales = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $updateStmt = $db->prepare("UPDATE sales SET payment_method = ? WHERE id = ?");
    
    foreach ($sales as $saleId) {
        $method = $paymentMethods[array_rand($paymentMethods)];
        $updateStmt->execute([$method, $saleId]);
    }

    // 3. Alter the column to the new ENUM
    $db->exec("ALTER TABLE sales MODIFY COLUMN payment_method ENUM('cash','credit','cash&credit') NOT NULL DEFAULT 'cash'");

    $db->commit();
    echo "Payment methods successfully updated.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Error updating payment methods: " . $e->getMessage() . "\n";
}
