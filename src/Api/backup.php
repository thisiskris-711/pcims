<?php

/**
 * Backup & Restore API
 * Handles database dumps and restoration using mysqldump and mysql CLI.
 */

require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$backupDir = ROOT_PATH . '/backups';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Ensure the directory is secured from web access if not already handled
if (!file_exists($backupDir . '/.htaccess')) {
    file_put_contents($backupDir . '/.htaccess', "Deny from all\n");
}

switch ($action) {
    case 'list':
        $files = [];
        $totalSize = 0;
        
        if ($handle = opendir($backupDir)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != ".." && pathinfo($entry, PATHINFO_EXTENSION) === 'sql') {
                    $filepath = $backupDir . '/' . $entry;
                    $size = filesize($filepath);
                    $totalSize += $size;
                    $files[] = [
                        'filename' => $entry,
                        'size' => $size,
                        'created_at' => date("Y-m-d H:i:s", filemtime($filepath)),
                        'timestamp' => filemtime($filepath)
                    ];
                }
            }
            closedir($handle);
        }
        
        // Sort by newest first
        usort($files, function($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });
        
        $summary = [
            'total_backups' => count($files),
            'total_size_bytes' => $totalSize,
            'last_backup' => count($files) > 0 ? $files[0]['created_at'] : null,
            'status' => 'Healthy' // Can be extended with more logic later
        ];
        
        jsonResponse(['success' => true, 'data' => $files, 'summary' => $summary]);
        break;

    case 'create':
        verifyCSRFToken();
        
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filepath = escapeshellarg($backupDir . '/' . $filename);
        
        $host = escapeshellarg(DB_HOST);
        $user = escapeshellarg(DB_USER);
        $pass = DB_PASS ? '-p' . escapeshellarg(DB_PASS) : '';
        $name = escapeshellarg(DB_NAME);
        
        // Use full path to mysqldump to be safe
        $mysqldumpPath = 'C:\xampp\mysql\bin\mysqldump.exe';
        if (!file_exists($mysqldumpPath)) {
             $mysqldumpPath = 'mysqldump'; // Fallback
        }
        
        $command = "\"$mysqldumpPath\" -h $host -u $user $pass $name > $filepath 2>&1";
        
        exec($command, $output, $resultCode);
        
        if ($resultCode === 0) {
            logAudit('backup_created', null, null, $filename);
            jsonResponse(['success' => true, 'message' => 'Backup created successfully', 'filename' => $filename]);
        } else {
            error_log("Backup failed: " . implode("\n", $output));
            jsonResponse(['error' => 'Failed to create backup. See error logs.'], 500);
        }
        break;

    case 'download':
        $filename = $_GET['file'] ?? '';
        
        // Basic validation
        if (empty($filename) || !preg_match('/^[a-zA-Z0-9_-]+\.sql$/', $filename)) {
            die("Invalid filename");
        }
        
        $filepath = $backupDir . '/' . $filename;
        if (!file_exists($filepath)) {
            die("File not found");
        }
        
        logAudit('backup_downloaded', null, null, $filename);
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
        
    case 'delete':
        verifyCSRFToken();
        
        $filename = $_POST['file'] ?? '';
        
        if (empty($filename) || !preg_match('/^[a-zA-Z0-9_-]+\.sql$/', $filename)) {
            jsonResponse(['error' => 'Invalid filename'], 400);
        }
        
        $filepath = $backupDir . '/' . $filename;
        if (file_exists($filepath)) {
            unlink($filepath);
            logAudit('backup_deleted', null, $filename, null);
            jsonResponse(['success' => true, 'message' => 'Backup deleted successfully']);
        } else {
            jsonResponse(['error' => 'File not found'], 404);
        }
        break;

    case 'restore':
        verifyCSRFToken();
        
        $filename = $_POST['file'] ?? '';
        
        if (empty($filename) || !preg_match('/^[a-zA-Z0-9_-]+\.sql$/', $filename)) {
            jsonResponse(['error' => 'Invalid filename'], 400);
        }
        
        $filepath = $backupDir . '/' . $filename;
        if (!file_exists($filepath)) {
            jsonResponse(['error' => 'File not found'], 404);
        }
        
        // 1. Create a safety backup first
        $safetyFilename = 'safety_prerestore_' . date('Y-m-d_H-i-s') . '.sql';
        $safetyFilepath = escapeshellarg($backupDir . '/' . $safetyFilename);
        
        $host = escapeshellarg(DB_HOST);
        $user = escapeshellarg(DB_USER);
        $pass = DB_PASS ? '-p' . escapeshellarg(DB_PASS) : '';
        $name = escapeshellarg(DB_NAME);
        
        $mysqldumpPath = 'C:\xampp\mysql\bin\mysqldump.exe';
        if (!file_exists($mysqldumpPath)) {
             $mysqldumpPath = 'mysqldump';
        }
        
        $safetyCommand = "\"$mysqldumpPath\" -h $host -u $user $pass $name > $safetyFilepath 2>&1";
        exec($safetyCommand, $output, $resultCode);
        
        if ($resultCode !== 0) {
            error_log("Safety backup failed before restore: " . implode("\n", $output));
            jsonResponse(['error' => 'Failed to create safety backup. Restore aborted.'], 500);
        }
        
        // 2. Perform the restore
        $mysqlPath = 'C:\xampp\mysql\bin\mysql.exe';
        if (!file_exists($mysqlPath)) {
            $mysqlPath = 'mysql';
        }
        
        $restoreFilepath = escapeshellarg($filepath);
        $restoreCommand = "\"$mysqlPath\" -h $host -u $user $pass $name < $restoreFilepath 2>&1";
        
        exec($restoreCommand, $restoreOutput, $restoreResultCode);
        
        if ($restoreResultCode === 0) {
            logAudit('backup_restored', null, $filename, null);
            jsonResponse(['success' => true, 'message' => 'Database restored successfully']);
        } else {
            error_log("Restore failed: " . implode("\n", $restoreOutput));
            jsonResponse(['error' => 'Restore failed. Check error logs.'], 500);
        }
        break;

    case 'get_settings':
        $pdo = getDB();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('auto_backup_enabled', 'auto_backup_time', 'auto_backup_retention_days', 'last_auto_backup')");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        jsonResponse(['success' => true, 'data' => $settings]);
        break;

    case 'save_settings':
        verifyCSRFToken();
        
        $enabled = isset($_POST['auto_backup_enabled']) && $_POST['auto_backup_enabled'] === '1' ? '1' : '0';
        $time = $_POST['auto_backup_time'] ?? '02:00';
        $retention = (int)($_POST['auto_backup_retention_days'] ?? 7);
        
        if (!preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $time = '02:00'; // fallback
        }
        if ($retention < 1 || $retention > 365) {
            $retention = 7;
        }

        $pdo = getDB();
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        $stmt->execute([$enabled, 'auto_backup_enabled']);
        $stmt->execute([$time, 'auto_backup_time']);
        $stmt->execute([$retention, 'auto_backup_retention_days']);
        
        logAudit('auto_backup_settings_updated', null, null, null);
        jsonResponse(['success' => true, 'message' => 'Auto-backup settings saved successfully']);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
        break;
}
