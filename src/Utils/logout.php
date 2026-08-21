<?php
/**
 * Logout
 */
$reason = $_GET['reason'] ?? '';

session_start();
require_once dirname(__DIR__, 2) . '/config/app.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

if ($reason === 'timeout') {
    session_start();
    flashMessage('Your session expired due to inactivity. Please log in again.', 'warning');
}

header('Location: ' . APP_URL . '/login');
exit;
