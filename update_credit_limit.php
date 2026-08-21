<?php
require_once __DIR__ . '/config/app.php';
$db = getDB();
$db->exec("UPDATE dealers SET credit_limit = 2000");
echo "Updated credit_limit to 2000 for all existing dealers.\n";
