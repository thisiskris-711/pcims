<?php
/**
 * Auto-Backup Script
 * Intended to be run periodically via cron or Windows Task Scheduler.
 */

// Ensure we are running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

// Need to set a dummy session ID to prevent warnings from session_start in app.php
session_id('cli_dummy_session');

require_once dirname(__DIR__) . '/config/app.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting auto-backup check...\n";

// Fetch settings
$pdo = getDB();
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_backup_enabled', 'auto_backup_time', 'auto_backup_retention_days', 'last_auto_backup')");
$settings = [];
while ($row = $stmt->fetch()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$enabled = isset($settings['auto_backup_enabled']) && $settings['auto_backup_enabled'] === '1';
$time = $settings['auto_backup_time'] ?? '02:00';
$retentionDays = (int)($settings['auto_backup_retention_days'] ?? 7);
$lastBackup = $settings['last_auto_backup'] ?? '';

if (!$enabled) {
    echo "Auto-backup is disabled in settings. Exiting.\n";
    exit(0);
}

// Time check logic
// We want to run if the current hour matches the target hour, and we haven't already run today.
$currentHour = (int)date('H');
$targetHour = (int)substr($time, 0, 2);
$currentDate = date('Y-m-d');
$lastBackupDate = $lastBackup ? date('Y-m-d', strtotime($lastBackup)) : '';

if ($currentDate === $lastBackupDate) {
    echo "Auto-backup already completed today ($lastBackupDate). Exiting.\n";
    exit(0);
}

if ($currentHour < $targetHour) {
    echo "Current hour ($currentHour) is before target hour ($targetHour). Waiting.\n";
    exit(0);
}

echo "Conditions met. Initiating backup...\n";

$backupDir = ROOT_PATH . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$filename = 'autobackup_' . date('Y-m-d_H-i-s') . '.sql';
$filepath = escapeshellarg($backupDir . '/' . $filename);

$host = escapeshellarg(DB_HOST);
$user = escapeshellarg(DB_USER);
$pass = DB_PASS ? '-p' . escapeshellarg(DB_PASS) : '';
$name = escapeshellarg(DB_NAME);

$mysqldumpPath = 'C:\xampp\mysql\bin\mysqldump.exe';
if (!file_exists($mysqldumpPath)) {
    $mysqldumpPath = 'mysqldump'; // Fallback
}

$command = "\"$mysqldumpPath\" -h $host -u $user $pass $name > $filepath 2>&1";

exec($command, $output, $resultCode);

if ($resultCode === 0) {
    echo "Backup created successfully: $filename\n";
    
    // Update last run time
    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'last_auto_backup'");
    $stmt->execute([date('Y-m-d H:i:s')]);
    
    // Prune old backups
    echo "Pruning backups older than $retentionDays days...\n";
    $cutoffTime = time() - ($retentionDays * 86400);
    $deletedCount = 0;
    
    if ($handle = opendir($backupDir)) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && pathinfo($entry, PATHINFO_EXTENSION) === 'sql') {
                $fullPath = $backupDir . '/' . $entry;
                if (filemtime($fullPath) < $cutoffTime) {
                    unlink($fullPath);
                    $deletedCount++;
                }
            }
        }
        closedir($handle);
    }
    
    echo "Pruned $deletedCount old backup(s).\n";
    
} else {
    echo "Backup failed: \n" . implode("\n", $output) . "\n";
    exit(1);
}

echo "Auto-backup process completed successfully.\n";
