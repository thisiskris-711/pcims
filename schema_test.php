<?php
require 'config/app.php';
$db = getDB();
$stmt = $db->query("DESCRIBE sales");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
