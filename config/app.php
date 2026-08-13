<?php
/**
 * Application Configuration
 */

// Load environment variables first
require_once __DIR__ . '/env_loader.php';
loadEnv(dirname(__DIR__) . '/.env');

// App info
define('APP_NAME', $_ENV['APP_NAME'] ?? getenv('APP_NAME') ?: 'InventoryPro');
define('APP_VERSION', $_ENV['APP_VERSION'] ?? getenv('APP_VERSION') ?: '1.0.0');
define('APP_URL', $_ENV['APP_URL'] ?? getenv('APP_URL') ?: '/antigravitytest');

// File paths
define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_DIR', ROOT_PATH . '/uploads/products/');
define('UPLOAD_URL', APP_URL . '/uploads/products/');

// Pagination
define('ITEMS_PER_PAGE', 15);

// Tax
define('DEFAULT_TAX_RATE', 12); // percent

// Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_MANAGER', 'manager');
define('ROLE_STAFF', 'staff');

// Session config
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}

// Timezone
date_default_timezone_set('Asia/Manila');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Include core files
require_once __DIR__ . '/database.php';
require_once ROOT_PATH . '/includes/helpers.php';
require_once ROOT_PATH . '/includes/auth.php';
