<?php
require 'c:/xampp/htdocs/pcims/config/app.php';
try {
    $db = getDB();
    $stmt = $db->query('SELECT COUNT(*) FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id WHERE 1=1');
    var_dump($stmt->fetchColumn());

    $stmt2 = $db->query('SELECT po.*, s.name as supplier_name FROM purchase_orders po LEFT JOIN suppliers s ON po.supplier_id = s.id ORDER BY po.created_at DESC LIMIT 15');
    var_dump($stmt2->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
