<?php
require_once dirname(__DIR__) . '/config/app.php';
$db = getDB();
$stmt = $db->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$user = $stmt->fetch();
$_SESSION['user_id'] = $user['id'];
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
echo json_encode(['session_id' => session_id(), 'csrf_token' => $_SESSION['csrf_token']]);
