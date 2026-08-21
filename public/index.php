<?php

/**
 * Front Controller (Router)
 */
require_once dirname(__DIR__) . '/config/app.php';

// Get the requested path
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Remove the APP_URL base from the request URI to get the relative route
$basePath = parse_url(APP_URL, PHP_URL_PATH);
if (strpos($requestUri, $basePath) === 0) {
    $route = substr($requestUri, strlen($basePath));
} else {
    $route = $requestUri;
}

// Clean up slashes
$route = '/' . trim($route, '/');

// Extremely simple router
switch ($route) {
    case '/':
    case '/index.php':
    case '/dashboard':
        require dirname(__DIR__) . '/views/pages/dashboard.php';
        break;

    case '/login':
        require dirname(__DIR__) . '/views/auth/login.php';
        break;

    case '/logout':
        require dirname(__DIR__) . '/src/Utils/logout.php';
        break;

    case '/verify-email':
        require dirname(__DIR__) . '/views/auth/verify_email.php';
        break;

    case '/forgot-password':
        require dirname(__DIR__) . '/views/auth/forgot_password.php';
        break;

    case '/reset-password':
        require dirname(__DIR__) . '/views/auth/reset_password.php';
        break;

    case '/create-invoice':
        require dirname(__DIR__) . '/views/pages/create_invoice.php';
        break;

    case '/invoice_print':
        require dirname(__DIR__) . '/views/pages/invoice_print.php';
        break;

    case '/products':
        require dirname(__DIR__) . '/views/pages/products.php';
        break;

    case '/product_form':
        require dirname(__DIR__) . '/views/pages/product_form.php';
        break;

    case '/categories':
        require dirname(__DIR__) . '/views/pages/categories.php';
        break;

    case '/stock':
        require dirname(__DIR__) . '/views/pages/stock.php';
        break;

    case '/sales':
        require dirname(__DIR__) . '/views/pages/sales.php';
        break;

    case '/reports':
        require dirname(__DIR__) . '/views/pages/reports.php';
        break;

    case '/notifications':
        require dirname(__DIR__) . '/views/pages/notifications.php';
        break;

    case '/users':
        require dirname(__DIR__) . '/views/pages/users.php';
        break;

    case '/profile':
        require dirname(__DIR__) . '/views/pages/profile.php';
        break;

    case '/dealers':
        require dirname(__DIR__) . '/views/pages/dealers.php';
        break;

    case '/dealer-application':
        require dirname(__DIR__) . '/views/pages/dealer_application_form.php';
        break;

    case '/suppliers':
        require dirname(__DIR__) . '/views/pages/suppliers.php';
        break;

    case '/purchase-orders':
        require dirname(__DIR__) . '/views/pages/purchase_orders.php';
        break;

    case '/purchase-order-form':
        require dirname(__DIR__) . '/views/pages/purchase_order_form.php';
        break;

    case '/promotions':
        require dirname(__DIR__) . '/views/pages/promotions.php';
        break;

    // --- API ROUTES ---
    case '/api/products':
        require dirname(__DIR__) . '/src/Api/products.php';
        break;

    case '/api/categories':
        require dirname(__DIR__) . '/src/Api/categories.php';
        break;

    case '/api/stock':
        require dirname(__DIR__) . '/src/Api/stock.php';
        break;

    case '/api/sales':
        require dirname(__DIR__) . '/src/Api/sales.php';
        break;

    case '/api/dashboard':
        require dirname(__DIR__) . '/src/Api/dashboard.php';
        break;

    case '/api/reports':
        require dirname(__DIR__) . '/src/Api/reports.php';
        break;

    case '/api/users':
        require dirname(__DIR__) . '/src/Api/users.php';
        break;

    case '/api/roles':
        require dirname(__DIR__) . '/src/Api/roles.php';
        break;

    case '/api/export_pdf':
        require dirname(__DIR__) . '/src/Api/export_pdf.php';
        break;

    case '/api/dealers':
        require dirname(__DIR__) . '/src/Api/dealers.php';
        break;

    case '/api/dealer_applications':
        require dirname(__DIR__) . '/src/Api/dealer_applications.php';
        break;

    case '/api/suppliers':
        require dirname(__DIR__) . '/src/Api/suppliers.php';
        break;

    case '/api/purchase_orders':
        require dirname(__DIR__) . '/src/Api/purchase_orders.php';
        break;

    case '/api/credits':
        require dirname(__DIR__) . '/src/Api/credits.php';
        break;

    case '/api/notifications':
        require dirname(__DIR__) . '/src/Api/notifications.php';
        break;

    case '/api/promotions':
        require dirname(__DIR__) . '/src/Api/promotions.php';
        break;

    case '/api/keep_alive':
        require dirname(__DIR__) . '/src/Api/keep_alive.php';
        break;

    default:
        http_response_code(404);
        echo "404 Not Found: " . htmlspecialchars($route);
        break;
}
