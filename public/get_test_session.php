<?php
require_once __DIR__ . '/config/app.php';
$_SESSION['user_id'] = 999;
$_SESSION['role'] = 'sales_associate'; // non-admin
$_SESSION['username'] = 'test';
$csrf = generateCSRFToken();
echo json_encode([
    'session_id' => session_id(),
    'session_name' => session_name(),
    'csrf_token' => $csrf
]);
