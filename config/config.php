<?php

/**
 * PCIMS - Personal Collection Inventory Management System
 * Configuration File
 */

// Database Configuration
// define('DB_HOST', 'sql213.infinityfree.com');
// define('DB_NAME', 'if0_41400722_pcims_db');
// define('DB_USER', 'if0_41400722');
// define('DB_PASS', 'nwciOXKYUa2');
define('DB_HOST', 'localhost');
define('DB_NAME', 'pcims_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Development Mode (set to true in development, false in production)
define('DEVELOPMENT_MODE', true);

// Application Configuration
define('APP_NAME', 'PCIMS - Personal Collection Inventory Management');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'http://localhost/pcims');

// Security Configuration
define('SESSION_LIFETIME', 3600); // 1 hour
define('HASH_COST', 12);

// Environment Configuration
define('ENVIRONMENT', 'development'); // Change to 'development' for local development

// Email Configuration (for notifications)
define('SMTP_HOST', getenv('PCIMS_SMTP_HOST') ?: '');
define('SMTP_PORT', (int) (getenv('PCIMS_SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('PCIMS_SMTP_USER') ?: '');
define('SMTP_PASS', getenv('PCIMS_SMTP_PASS') ?: '');
define('SMTP_FROM', getenv('PCIMS_SMTP_FROM') ?: '');
define('SMTP_FROM_NAME', getenv('PCIMS_SMTP_FROM_NAME') ?: 'PCIMS System');
define('SMTP_ENCRYPTION', getenv('PCIMS_SMTP_ENCRYPTION') ?: 'tls');
define('SMTP_TIMEOUT', (int) (getenv('PCIMS_SMTP_TIMEOUT') ?: 30));
define('EMAIL_ENABLED', filter_var(getenv('PCIMS_EMAIL_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN));

// Pagination
define('ITEMS_PER_PAGE', 25);

// File Upload Configuration
define('UPLOAD_PATH', 'uploads/');
define('PROFILE_UPLOAD_PATH', UPLOAD_PATH . 'profiles/');
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);

// Error Reporting - Disabled in production for security
if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
ini_set('log_errors', 1);
ini_set('error_log', 'logs/error.log');

// Timezone
date_default_timezone_set('Asia/Manila');

/**
 * Resolve the actual directory component from PHP's session.save_path value.
 */
function get_session_storage_directory($path)
{
    if (empty($path)) {
        return null;
    }

    $segments = array_filter(array_map('trim', explode(';', $path)), 'strlen');
    if (empty($segments)) {
        return null;
    }

    return end($segments);
}

/**
 * Verify that PHP can really create files in the session directory.
 */
function can_write_to_directory($directory)
{
    if (empty($directory) || !is_dir($directory)) {
        return false;
    }

    $test_file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pcims_session_test_' . uniqid('', true);
    $written = @file_put_contents($test_file, 'session-test');

    if ($written === false) {
        return false;
    }

    @unlink($test_file);
    return true;
}

/**
 * Use a project-local session directory when the server default is unavailable.
 */
function configure_session_storage()
{
    $current_path = get_session_storage_directory(session_save_path());
    if (can_write_to_directory($current_path)) {
        return;
    }

    $fallback_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'sessions';

    if (!is_dir($fallback_path)) {
        @mkdir($fallback_path, 0775, true);
    }

    if (can_write_to_directory($fallback_path)) {
        session_save_path($fallback_path);
    }
}

// Start Session
if (session_status() === PHP_SESSION_NONE) {
    configure_session_storage();
    session_start();
}

// Database Connection Class
class Database
{
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private $conn;

    public function getConnection()
    {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }

        return $this->conn;
    }
}

// Security Helper Functions
function sanitize_input($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

function get_user_role()
{
    return $_SESSION['role'] ?? null;
}

function has_permission($required_role)
{
    $role_hierarchy = [
        'viewer' => 1,
        'staff' => 2,
        'manager' => 3,
        'admin' => 4
    ];

    $user_role = get_user_role();
    return isset($role_hierarchy[$user_role]) &&
        $role_hierarchy[$user_role] >= $role_hierarchy[$required_role];
}

function redirect_if_not_logged_in()
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit();
    }
}

function redirect_if_no_permission($required_role)
{
    if (!has_permission($required_role)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: dashboard.php');
        exit();
    }
}

// Notification System
function add_notification($user_id, $title, $message, $type = 'info', $related_to = 'system', $related_id = null)
{
    try {
        $database = new Database();
        $db = $database->getConnection();

        $query = "INSERT INTO notifications (user_id, title, message, type, related_to, related_id) 
                  VALUES (:user_id, :title, :message, :type, :related_to, :related_id)";

        $stmt = $db->prepare($query);

        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':message', $message);
        $stmt->bindParam(':type', $type);
        $stmt->bindParam(':related_to', $related_to);
        $stmt->bindParam(':related_id', $related_id);

        $stmt->execute();

    } catch (PDOException $exception) {
        error_log("Notification Error: " . $exception->getMessage());
    }
}

function send_low_stock_alert($product_id)
{
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get product details
        $query = "SELECT p.product_name, i.quantity_on_hand 
                  FROM products p 
                  JOIN inventory i ON p.product_id = i.product_id 
                  WHERE p.product_id = :product_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':product_id', $product_id);
        $stmt->execute();
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Create low stock notification for managers and admins
            add_notification(
                null, // Global notification
                'Low Stock Alert',
                "Product '{$product['product_name']}' is running low on stock ({$product['quantity_on_hand']} units remaining).",
                'warning',
                'low_stock',
                $product_id
            );
            
            // Send email notification if enabled
            if (defined('EMAIL_ENABLED') && EMAIL_ENABLED) {
                try {
                    require_once __DIR__ . '/../includes/email.php';
                    $emailHelper = new EmailHelper();
                    $emailHelper->sendLowStockAlert($product['product_name'], $product['quantity_on_hand']);
                } catch (Exception $e) {
                    error_log("Email notification failed: " . $e->getMessage());
                }
            }
            
            error_log("Low stock alert sent for product: {$product['product_name']} (Stock: {$product['quantity_on_hand']})");
        }
    } catch (PDOException $exception) {
        error_log("Low Stock Alert Error: " . $exception->getMessage());
    }
}

function check_low_stock_notifications()
{
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check for products with low stock (5 or less units)
        $query = "SELECT p.product_id, p.product_name, i.quantity_on_hand 
                  FROM products p 
                  JOIN inventory i ON p.product_id = i.product_id 
                  WHERE p.status = 'active' 
                  AND i.quantity_on_hand <= 5 
                  AND i.quantity_on_hand > 0";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $low_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($low_stock_products as $product) {
            // Check if we already sent a notification for this product recently (within last 24 hours)
            $check_query = "SELECT notification_id FROM notifications 
                           WHERE related_to = 'low_stock' 
                           AND related_id = :product_id 
                           AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
            
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':product_id', $product['product_id']);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                // Send low stock notification
                add_notification(
                    null, // Global notification
                    'Low Stock Alert',
                    "Product '{$product['product_name']}' is running low on stock ({$product['quantity_on_hand']} units remaining).",
                    'warning',
                    'low_stock',
                    $product['product_id']
                );
            }
        }
        
        // Check for out of stock products
        $query = "SELECT p.product_id, p.product_name 
                  FROM products p 
                  JOIN inventory i ON p.product_id = i.product_id 
                  WHERE p.status = 'active' 
                  AND i.quantity_on_hand = 0";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $out_of_stock_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($out_of_stock_products as $product) {
            // Check if we already sent an out of stock notification recently (within last 6 hours)
            $check_query = "SELECT notification_id FROM notifications 
                           WHERE related_to = 'low_stock' 
                           AND related_id = :product_id 
                           AND type = 'error'
                           AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR)";
            
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':product_id', $product['product_id']);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() === 0) {
                // Send out of stock notification
                add_notification(
                    null, // Global notification
                    'Out of Stock',
                    "Product '{$product['product_name']}' is now out of stock!",
                    'error',
                    'low_stock',
                    $product['product_id']
                );
                
                // Send email notification for out of stock
                if (defined('EMAIL_ENABLED') && EMAIL_ENABLED) {
                    try {
                        require_once __DIR__ . '/../includes/email.php';
                        $emailHelper = new EmailHelper();
                        $emailHelper->sendOutOfStockAlert($product['product_name']);
                    } catch (Exception $e) {
                        error_log("Email notification failed: " . $e->getMessage());
                    }
                }
            }
        }
        
    } catch (PDOException $exception) {
        error_log("Low Stock Check Error: " . $exception->getMessage());
    }
}

// Activity Logger
function log_activity($user_id, $action, $details = '')
{
    try {
        $database = new Database();
        $db = $database->getConnection();

        $query = "INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                  VALUES (:user_id, :action, :details, :ip_address, :user_agent)";

        $stmt = $db->prepare($query);

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':details', $details);
        $stmt->bindParam(':ip_address', $ip_address);
        $stmt->bindParam(':user_agent', $user_agent);

        $stmt->execute();
    } catch (PDOException $exception) {
        error_log("Activity Log Error: " . $exception->getMessage());
    }
}

// System Settings
function get_setting($key, $default = null)
{
    try {
        $database = new Database();
        $db = $database->getConnection();

        $query = "SELECT setting_value FROM system_settings WHERE setting_key = :key";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':key', $key);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (PDOException $exception) {
        error_log("Settings Error: " . $exception->getMessage());
        return $default;
    }
}

function set_setting(PDO $db, $key, $value, $description = null, $updated_by = null)
{
    $query = "INSERT INTO system_settings (setting_key, setting_value, description, updated_by)
              VALUES (:key, :value, :description, :updated_by)
              ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                description = COALESCE(VALUES(description), description),
                updated_by = VALUES(updated_by)";

    $stmt = $db->prepare($query);
    $stmt->bindValue(':key', (string) $key, PDO::PARAM_STR);
    $stmt->bindValue(':value', (string) $value, PDO::PARAM_STR);
    if ($description !== null) {
        $stmt->bindValue(':description', (string) $description, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':description', null, PDO::PARAM_NULL);
    }
    if ($updated_by !== null) {
        $stmt->bindValue(':updated_by', (int) $updated_by, PDO::PARAM_INT);
    } else {
        $stmt->bindValue(':updated_by', null, PDO::PARAM_NULL);
    }
    $stmt->execute();
}

function get_json_setting($key, array $default = [])
{
    $raw_value = get_setting($key);
    if (!is_string($raw_value) || trim($raw_value) === '') {
        return $default;
    }

    $decoded = json_decode($raw_value, true);
    return is_array($decoded) ? $decoded : $default;
}

function pcims_get_currency_code()
{
    return strtoupper((string) get_setting('default_currency', get_setting('currency', 'PHP')));
}

function pcims_get_business_presets()
{
    static $presets = null;

    if ($presets !== null) {
        return $presets;
    }

    $presets = [
        'personal_collection' => [
            'label' => 'Direct Selling Catalog',
            'description' => 'Best fit for catalog-driven beauty, wellness, and home-care assortments.',
            'labels' => [
                'category_singular' => 'Category',
                'category_plural' => 'Categories',
                'product_singular' => 'Product',
                'product_plural' => 'Products',
                'supplier_singular' => 'Supplier',
                'supplier_plural' => 'Suppliers',
            ],
            'pricing' => [
                'pricing_strategy' => 'standard_markup',
                'default_markup_percent' => 35,
                'price_rounding' => 'nearest_0_05',
                'tax_mode' => 'inclusive',
                'tax_percent' => 12,
                'discount_catalog' => [
                    'none' => ['label' => null, 'percent' => 0],
                    'senior' => ['label' => 'Senior Citizen', 'percent' => 20],
                    'pwd' => ['label' => 'PWD', 'percent' => 20],
                    'employee' => ['label' => 'Employee Discount', 'percent' => 5],
                    'regular' => ['label' => 'Regular Discount', 'percent' => 10],
                    'promo' => ['label' => 'Promo Discount', 'percent' => 15],
                ],
            ],
            'reports' => [
                'inventory' => ['label' => 'Inventory Status', 'heading' => 'Inventory Status Report', 'description' => 'Track stock levels, value, and availability across your catalog.'],
                'sales' => ['label' => 'Sales Report', 'heading' => 'Sales Report', 'description' => 'Review orders, revenue, and customer activity.'],
                'purchases' => ['label' => 'Purchase Orders', 'heading' => 'Purchase Orders Report', 'description' => 'Monitor replenishment and supplier purchasing.'],
                'stock_movements' => ['label' => 'Stock Movements', 'heading' => 'Stock Movements Report', 'description' => 'Audit stock in, stock out, and adjustments.'],
            ],
            'category_suggestions' => [
                ['name' => 'Personal Care', 'description' => 'Beauty and everyday personal care products'],
                ['name' => 'Health & Wellness', 'description' => 'Supplements, vitamins, and wellness essentials'],
                ['name' => 'Home Care', 'description' => 'Cleaning and household maintenance items'],
                ['name' => 'Food & Beverages', 'description' => 'Consumables and packaged refreshments'],
                ['name' => 'Fashion & Accessories', 'description' => 'Lifestyle, apparel, and accessories'],
            ],
        ],
        'convenience_store' => [
            'label' => 'Convenience Store',
            'description' => 'Optimized for fast-moving essentials, snacks, drinks, and daily retail replenishment.',
            'labels' => [
                'category_singular' => 'Department',
                'category_plural' => 'Departments',
                'product_singular' => 'Item',
                'product_plural' => 'Items',
                'supplier_singular' => 'Vendor',
                'supplier_plural' => 'Vendors',
            ],
            'pricing' => [
                'pricing_strategy' => 'keystone',
                'default_markup_percent' => 28,
                'price_rounding' => 'nearest_0_10',
                'tax_mode' => 'inclusive',
                'tax_percent' => 12,
                'discount_catalog' => [
                    'none' => ['label' => null, 'percent' => 0],
                    'senior' => ['label' => 'Senior Citizen', 'percent' => 20],
                    'pwd' => ['label' => 'PWD', 'percent' => 20],
                    'member' => ['label' => 'Loyalty Member', 'percent' => 3],
                    'bundle' => ['label' => 'Bundle Offer', 'percent' => 5],
                    'promo' => ['label' => 'Shelf Promo', 'percent' => 10],
                ],
            ],
            'reports' => [
                'inventory' => ['label' => 'Shelf Availability', 'heading' => 'Shelf Availability Report', 'description' => 'Surface high-risk stockouts and shelf-ready availability.'],
                'sales' => ['label' => 'Daily Sales Mix', 'heading' => 'Daily Sales Mix Report', 'description' => 'Track basket performance, order counts, and fast movers.'],
                'purchases' => ['label' => 'Vendor Replenishment', 'heading' => 'Vendor Replenishment Report', 'description' => 'Follow replenishment cycles and vendor spend.'],
                'stock_movements' => ['label' => 'Stock Flow Log', 'heading' => 'Stock Flow Report', 'description' => 'Audit stock receiving, transfers, and shrinkage adjustments.'],
            ],
            'category_suggestions' => [
                ['name' => 'Snacks', 'description' => 'Chips, biscuits, and quick snacks'],
                ['name' => 'Beverages', 'description' => 'Water, juice, soda, coffee, and ready-to-drink items'],
                ['name' => 'Instant Meals', 'description' => 'Cup noodles, canned meals, and grab-and-go food'],
                ['name' => 'Toiletries', 'description' => 'Soap, shampoo, tissue, and personal essentials'],
                ['name' => 'Household Essentials', 'description' => 'Batteries, detergents, and emergency household items'],
            ],
        ],
        'specialty_shop' => [
            'label' => 'Specialty Shop',
            'description' => 'Useful for curated stores with selective assortments, premium pricing, and targeted reporting.',
            'labels' => [
                'category_singular' => 'Collection',
                'category_plural' => 'Collections',
                'product_singular' => 'Item',
                'product_plural' => 'Items',
                'supplier_singular' => 'Brand Partner',
                'supplier_plural' => 'Brand Partners',
            ],
            'pricing' => [
                'pricing_strategy' => 'custom_margin',
                'default_markup_percent' => 45,
                'price_rounding' => 'nearest_1_00',
                'tax_mode' => 'exclusive',
                'tax_percent' => 12,
                'discount_catalog' => [
                    'none' => ['label' => null, 'percent' => 0],
                    'vip' => ['label' => 'VIP Client', 'percent' => 10],
                    'seasonal' => ['label' => 'Seasonal Sale', 'percent' => 15],
                    'launch' => ['label' => 'Launch Offer', 'percent' => 8],
                    'staff' => ['label' => 'Staff Purchase', 'percent' => 12],
                ],
            ],
            'reports' => [
                'inventory' => ['label' => 'Assortment Health', 'heading' => 'Assortment Health Report', 'description' => 'Review assortment depth, availability, and stock investment.'],
                'sales' => ['label' => 'Sell-Through', 'heading' => 'Sell-Through Report', 'description' => 'Measure conversion, premium item velocity, and period revenue.'],
                'purchases' => ['label' => 'Buying Plan', 'heading' => 'Buying Plan Report', 'description' => 'Monitor purchasing commitments and supplier performance.'],
                'stock_movements' => ['label' => 'Inventory Activity', 'heading' => 'Inventory Activity Report', 'description' => 'Track receipts, returns, and curated stock changes.'],
            ],
            'category_suggestions' => [
                ['name' => 'Featured Picks', 'description' => 'Seasonal or hero items'],
                ['name' => 'Core Assortment', 'description' => 'Always-available store essentials'],
                ['name' => 'Limited Editions', 'description' => 'Short-run or collectible merchandise'],
                ['name' => 'Accessories', 'description' => 'Companion products and add-ons'],
                ['name' => 'Gift Sets', 'description' => 'Bundled or premium packaged offers'],
            ],
        ],
    ];

    return $presets;
}

function pcims_parse_discount_catalog($text)
{
    $catalog = [];
    $lines = preg_split('/\\r\\n|\\r|\\n/', (string) $text) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) {
            continue;
        }

        $code = strtolower(preg_replace('/[^a-z0-9_-]+/i', '', $parts[0]));
        $label = $parts[1];
        $percent = max(0, min(100, (float) $parts[2]));

        if ($code === '') {
            continue;
        }

        $catalog[$code] = [
            'label' => $label !== '' ? $label : null,
            'percent' => $percent,
        ];
    }

    if (!isset($catalog['none'])) {
        $catalog = ['none' => ['label' => null, 'percent' => 0]] + $catalog;
    }

    return $catalog;
}

function pcims_discount_catalog_to_text(array $catalog)
{
    $lines = [];
    foreach ($catalog as $code => $rule) {
        $percent = isset($rule['percent']) ? (float) $rule['percent'] : 0;
        $label = isset($rule['label']) ? (string) $rule['label'] : '';
        $lines[] = $code . '|' . $label . '|' . number_format($percent, 2, '.', '');
    }

    return implode(PHP_EOL, $lines);
}

function pcims_get_business_configuration()
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    $presets = pcims_get_business_presets();
    $selected_type = (string) get_setting('business_type', 'personal_collection');
    if (!isset($presets[$selected_type])) {
        $selected_type = 'personal_collection';
    }

    $preset = $presets[$selected_type];
    $config = [
        'business_type' => $selected_type,
        'preset_label' => $preset['label'],
        'preset_description' => $preset['description'],
        'labels' => $preset['labels'],
        'pricing' => $preset['pricing'],
        'reports' => $preset['reports'],
        'category_suggestions' => $preset['category_suggestions'],
    ];

    $label_overrides = get_json_setting('business_module_labels', []);
    $pricing_overrides = get_json_setting('business_pricing_rules', []);
    $report_overrides = get_json_setting('business_report_templates', []);

    if (!empty($label_overrides)) {
        $config['labels'] = array_replace($config['labels'], $label_overrides);
    }

    if (!empty($pricing_overrides)) {
        if (isset($pricing_overrides['discount_catalog']) && is_array($pricing_overrides['discount_catalog'])) {
            $pricing_overrides['discount_catalog'] = array_replace($config['pricing']['discount_catalog'], $pricing_overrides['discount_catalog']);
        }
        $config['pricing'] = array_replace($config['pricing'], $pricing_overrides);
    }

    if (!empty($report_overrides)) {
        foreach ($report_overrides as $report_key => $report_definition) {
            if (!isset($config['reports'][$report_key]) || !is_array($report_definition)) {
                continue;
            }

            $config['reports'][$report_key] = array_replace($config['reports'][$report_key], $report_definition);
        }
    }

    $cached = $config;
    return $config;
}

function pcims_get_business_label($key, $default = '')
{
    $config = pcims_get_business_configuration();
    return $config['labels'][$key] ?? $default;
}

function pcims_get_report_templates()
{
    $config = pcims_get_business_configuration();
    return $config['reports'];
}

function pcims_get_report_template($report_type)
{
    $templates = pcims_get_report_templates();
    return $templates[$report_type] ?? [
        'label' => ucfirst(str_replace('_', ' ', (string) $report_type)),
        'heading' => ucfirst(str_replace('_', ' ', (string) $report_type)) . ' Report',
        'description' => '',
    ];
}

function pcims_apply_business_category_preset(PDO $db, $preset_key)
{
    $presets = pcims_get_business_presets();
    if (!isset($presets[$preset_key])) {
        throw new InvalidArgumentException('Invalid business preset selected.');
    }

    $existing_names = [];
    $stmt = $db->query("SELECT category_name FROM categories");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $category_name) {
        $existing_names[strtolower(trim((string) $category_name))] = true;
    }

    $insert_stmt = $db->prepare(
        "INSERT INTO categories (category_name, description, status)
         VALUES (:category_name, :description, 'active')"
    );

    $inserted = 0;
    foreach ($presets[$preset_key]['category_suggestions'] as $category) {
        $name = trim((string) ($category['name'] ?? ''));
        $lookup = strtolower($name);
        if ($name === '' || isset($existing_names[$lookup])) {
            continue;
        }

        $insert_stmt->bindValue(':category_name', $name, PDO::PARAM_STR);
        $insert_stmt->bindValue(':description', (string) ($category['description'] ?? ''), PDO::PARAM_STR);
        $insert_stmt->execute();
        $existing_names[$lookup] = true;
        $inserted++;
    }

    return $inserted;
}

function format_currency($amount)
{
    return pcims_get_currency_code() . ' ' . number_format((float) $amount, 2);
}

function format_date($date, $format = 'Y-m-d H:i:s')
{
    return date($format, strtotime($date));
}

// File Upload Helper
function handle_file_upload($file, $upload_path = UPLOAD_PATH)
{
    if (!file_exists($upload_path)) {
        mkdir($upload_path, 0755, true);
    }

    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($file_extension, ALLOWED_EXTENSIONS)) {
        throw new Exception("Invalid file type. Allowed: " . implode(', ', ALLOWED_EXTENSIONS));
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception("File size exceeds maximum limit of " . (MAX_FILE_SIZE / 1024 / 1024) . "MB");
    }

    $new_filename = uniqid() . '.' . $file_extension;
    $target_path = $upload_path . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return $new_filename;
    } else {
        throw new Exception("Failed to upload file.");
    }
}

/**
 * Get product image with fallback handling
 * 
 * @param string $image_url The stored image URL
 * @param string $product_name Product name for alt text
 * @param string $size Image size class (thumbnail, small, medium, large)
 * @param array $attributes Additional HTML attributes
 * @return string HTML img tag or fallback div
 */
function get_product_image($image_url, $product_name = '', $size = 'thumbnail', $attributes = []) {
    $default_image = 'images/pc-logo-2.png';
    $upload_path = 'uploads/products/';
    
    // Build default attributes
    $default_attrs = [
        'alt' => htmlspecialchars($product_name ?: 'Product Image'),
        'class' => 'img-' . $size
    ];
    
    // Merge with custom attributes
    $attrs = array_merge($default_attrs, $attributes);
    
    // Check if image exists and is valid
    if (!empty($image_url)) {
        $full_path = $upload_path . $image_url;
        
        // Validate file exists and is readable
        if (file_exists($full_path) && is_readable($full_path)) {
            // Additional validation: check if it's actually an image
            $image_info = @getimagesize($full_path);
            if ($image_info !== false) {
                // Valid image found
                $attrs['src'] = htmlspecialchars($full_path);
                return build_img_tag($attrs);
            }
        }
    }
    
    // Fallback to default image if it exists
    if (file_exists($default_image) && is_readable($default_image)) {
        $attrs['src'] = htmlspecialchars($default_image);
        return build_img_tag($attrs);
    }
    
    // Final fallback: icon placeholder
    return build_image_placeholder($product_name, $size);
}

/**
 * Build HTML img tag from attributes
 */
function build_img_tag($attributes) {
    $html = '<img';
    foreach ($attributes as $name => $value) {
        $html .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
    }
    $html .= '>';
    return $html;
}

/**
 * Build fallback image placeholder with icon
 */
function build_image_placeholder($product_name = '', $size = 'thumbnail') {
    $size_classes = [
        'thumbnail' => 'width: 50px; height: 50px;',
        'small' => 'width: 40px; height: 40px;',
        'medium' => 'width: 100px; height: 100px;',
        'large' => 'width: 200px; height: 200px;'
    ];
    
    $style = $size_classes[$size] ?? $size_classes['thumbnail'];
    
    return '<div class="bg-light d-flex align-items-center justify-content-center rounded" style="' . $style . '">' .
           '<i class="fas fa-box text-muted"></i>' .
           '</div>';
}

/**
 * Validate uploaded image file
 * 
 * @param array $file $_FILES array element
 * @return bool True if valid image, false otherwise
 */
function is_valid_image_file($file) {
    // Check basic file upload errors
    if (!isset($file['error']) || is_array($file['error'])) {
        return false;
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    // Check file size
    if ($file['size'] > 2 * 1024 * 1024) { // 2MB limit
        return false;
    }
    
    // Check MIME type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    if (!in_array($file['type'], $allowed_types)) {
        return false;
    }
    
    // Additional validation using getimagesize
    $image_info = @getimagesize($file['tmp_name']);
    if ($image_info === false) {
        return false;
    }
    
    return true;
}

function get_user_initials($full_name, $fallback = 'US')
{
    $source = preg_replace('/[^A-Za-z0-9 ]/', '', trim((string) $full_name));
    $parts = preg_split('/\s+/', $source) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : strtoupper($fallback);
}

function get_profile_image_path($profile_image)
{
    $filename = basename((string) $profile_image);
    if ($filename === '') {
        return null;
    }

    $path = PROFILE_UPLOAD_PATH . $filename;
    return (file_exists($path) && is_readable($path)) ? $path : null;
}

function get_profile_image_url($profile_image)
{
    $path = get_profile_image_path($profile_image);
    if ($path === null) {
        return null;
    }

    return APP_URL . '/uploads/profiles/' . rawurlencode(basename($path));
}

function delete_profile_image_file($profile_image)
{
    $path = get_profile_image_path($profile_image);
    if ($path !== null) {
        @unlink($path);
    }
}

function save_uploaded_profile_image($file)
{
    if (!is_valid_image_file($file)) {
        throw new Exception('Please upload a valid JPG, PNG, or GIF image up to 2MB.');
    }

    if (!file_exists(PROFILE_UPLOAD_PATH)) {
        mkdir(PROFILE_UPLOAD_PATH, 0755, true);
    }

    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $extension = $extension === 'jpeg' ? 'jpg' : $extension;
    $filename = 'profile_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $target = PROFILE_UPLOAD_PATH . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Failed to save the uploaded profile image.');
    }

    return $filename;
}

function save_captured_profile_image($data_url)
{
    $data_url = trim((string) $data_url);
    if ($data_url === '') {
        return null;
    }

    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,([A-Za-z0-9+\/=]+)$/', $data_url, $matches)) {
        throw new Exception('Captured photo format is invalid.');
    }

    $extension = strtolower($matches[1]) === 'png' ? 'png' : 'jpg';
    $binary = base64_decode($matches[2], true);
    if ($binary === false) {
        throw new Exception('Captured photo data could not be decoded.');
    }

    if (strlen($binary) > 2 * 1024 * 1024) {
        throw new Exception('Captured photo exceeds the 2MB size limit.');
    }

    $image_info = @getimagesizefromstring($binary);
    if ($image_info === false || !in_array($image_info['mime'], ['image/jpeg', 'image/png'], true)) {
        throw new Exception('Captured photo must be a valid JPG or PNG image.');
    }

    if (!file_exists(PROFILE_UPLOAD_PATH)) {
        mkdir(PROFILE_UPLOAD_PATH, 0755, true);
    }

    $filename = 'profile_' . bin2hex(random_bytes(12)) . '.' . $extension;
    $target = PROFILE_UPLOAD_PATH . $filename;

    if (file_put_contents($target, $binary) === false) {
        throw new Exception('Failed to save the captured profile image.');
    }

    return $filename;
}

function format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
