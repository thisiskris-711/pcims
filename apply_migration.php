<?php
require_once __DIR__ . '/config/app.php';
$db = getDB();
$sql = file_get_contents(__DIR__ . '/database/migration_notification_reads.sql');
$db->exec($sql);
echo "Migration applied successfully.\n";
