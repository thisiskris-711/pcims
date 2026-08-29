<?php
/**
 * System Settings API (Admin only)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN); // Only Admin can access system settings

$method = $_SERVER['REQUEST_METHOD'];
$db = getDB();

// Define allowed settings and their sanitization/validation rules
$allowedSettings = [
    'company_name' => 'string',
    'company_address' => 'string',
    'company_tin' => 'string',
    'vat_rate' => 'float',
    'tax_inclusive' => 'boolean',
    'invoice_prefix' => 'string',
    'low_stock_threshold' => 'int',
    'notify_low_stock' => 'boolean'
];

switch ($method) {
    case 'GET':
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        
        while ($row = $stmt->fetch()) {
            $key = $row['setting_key'];
            $val = $row['setting_value'];
            
            // Cast based on allowed rules
            if (isset($allowedSettings[$key])) {
                if ($allowedSettings[$key] === 'boolean') {
                    $val = (bool)$val;
                } elseif ($allowedSettings[$key] === 'float') {
                    $val = (float)$val;
                } elseif ($allowedSettings[$key] === 'int') {
                    $val = (int)$val;
                }
            }
            $settings[$key] = $val;
        }
        
        jsonResponse($settings);
        break;

    case 'POST':
        verifyCSRFToken();
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        
        $updates = [];
        
        foreach ($input as $key => $value) {
            if (!isset($allowedSettings[$key])) {
                continue; // Ignore unknown settings
            }
            
            // Validate and sanitize
            $type = $allowedSettings[$key];
            $cleanValue = null;
            
            if ($type === 'string') {
                $cleanValue = sanitize($value);
            } elseif ($type === 'float') {
                if (!is_numeric($value) || $value < 0) {
                    jsonResponse(['error' => "Invalid value for {$key}. Must be a positive number."], 400);
                }
                $cleanValue = (float)$value;
            } elseif ($type === 'int') {
                if (!is_numeric($value) || $value < 0) {
                    jsonResponse(['error' => "Invalid value for {$key}. Must be a positive integer."], 400);
                }
                $cleanValue = (int)$value;
            } elseif ($type === 'boolean') {
                $cleanValue = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            
            $updates[$key] = $cleanValue;
        }
        
        if (empty($updates)) {
            jsonResponse(['error' => 'No valid settings provided'], 400);
        }
        
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            
            foreach ($updates as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            
            $db->commit();
            jsonResponse(['success' => true, 'message' => 'Settings updated successfully']);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Settings update error: " . $e->getMessage());
            jsonResponse(['error' => 'Database error occurred while updating settings'], 500);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed']);
        break;
}
