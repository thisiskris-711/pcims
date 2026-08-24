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
define('UPLOAD_DIR', ROOT_PATH . '/public/uploads/products/');
define('UPLOAD_URL', APP_URL . '/uploads/products/');

// Pagination
define('ITEMS_PER_PAGE', 15);

// Tax
define('DEFAULT_TAX_RATE', 12); // percent

// Credit
define('DEFAULT_CREDIT_LIMIT', 2000.00);

// Roles
define('ROLE_ADMIN', 'system_admin');
define('ROLE_MANAGER', 'inventory_manager');
define('ROLE_CASHIER', 'sales_associate');
define('ROLE_STOCKER', 'stock_associate');
define('ROLE_AUDITOR', 'auditor');
define('ROLE_AP_CLERK', 'ap_clerk');

// Require composer autoload
if (file_exists(ROOT_PATH . '/vendor/autoload.php')) {
    require_once ROOT_PATH . '/vendor/autoload.php';
}

// Session config
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 900); // 15 minutes
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
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
require_once ROOT_PATH . '/src/Utils/helpers.php';
require_once ROOT_PATH . '/src/Utils/auth.php';
require_once ROOT_PATH . '/src/Utils/RateLimiter.php';
require_once ROOT_PATH . '/src/Utils/mailer.php';
require_once ROOT_PATH . '/src/Utils/stock_alerts.php';

// Enforce Rate Limiting for all API endpoints
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    // Global API limit (max 100 requests per 60 seconds)
    enforceRateLimit('api_global', 100, 60);
    
    // Dynamic per-endpoint limit (max 30 requests per 60 seconds to prevent aggressive scraping)
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $basePath = parse_url(APP_URL, PHP_URL_PATH);
    $route = $path;
    if ($basePath && strpos($path, $basePath) === 0) {
        $route = substr($path, strlen($basePath));
    }
    $route = trim($route, '/');
    if (!empty($route)) {
        enforceRateLimit('api_route_' . md5($route), 30, 60);
    }
}
