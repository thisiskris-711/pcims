<?php
/**
 * Root entry point — serves the public front controller directly.
 * Works on both XAMPP (DocumentRoot = htdocs/) and Railway (DocumentRoot = project root or public/).
 */

// Handle static assets if the server is incorrectly pointing to this root folder
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Remove APP_URL base if it exists
$basePath = parse_url($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '', PHP_URL_PATH);
if ($basePath && strpos($requestUri, $basePath) === 0) {
    $requestUri = substr($requestUri, strlen($basePath));
}

$staticFile = __DIR__ . '/public' . $requestUri;
if ($requestUri !== '/' && $requestUri !== '/index.php' && file_exists($staticFile) && !is_dir($staticFile)) {
    // Determine MIME type
    $ext = pathinfo($staticFile, PATHINFO_EXTENSION);
    $mimeTypes = [
        'css' => 'text/css',
        'js'  => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg'=> 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff'=> 'font/woff',
        'woff2'=>'font/woff2',
        'ttf' => 'font/ttf'
    ];
    
    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
    }
    
    // Serve the file and exit
    readfile($staticFile);
    exit;
}

require_once __DIR__ . '/public/index.php';
