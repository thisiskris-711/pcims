<?php
require_once __DIR__ . '/config/app.php';
$db = getDB();
$stmt = $db->query('SELECT payment_method, COUNT(*) as c FROM sales GROUP BY payment_method');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
