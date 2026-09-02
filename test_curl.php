<?php
require_once __DIR__ . '/config/app.php';
$_SESSION['user_id'] = 999;
$_SESSION['role'] = 'sales_associate'; // non-admin
$_SESSION['username'] = 'test';
$csrf = generateCSRFToken();
session_write_close();

$cookie = session_name() . '=' . session_id();

echo "--- Forged Role Escalation Attempt ---\n";
echo "Command:\n";
echo "curl -X PUT http://localhost/pcims/public/api/users?id=999 \\
     -H 'Cookie: $cookie' \\
     -H 'Content-Type: application/json' \\
     -d '{\"role\":\"admin\", \"csrf_token\":\"$csrf\"}'\n\n";

echo "Response:\n";
$ch = curl_init("http://localhost/pcims/public/api/users?id=999");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['role' => 'admin', 'csrf_token' => $csrf]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Cookie: $cookie",
    "Content-Type: application/json"
]);
echo curl_exec($ch) . "\n\n";

echo "--- Missing CSRF Token Attempt ---\n";
echo "Command:\n";
echo "curl -X POST http://localhost/pcims/public/api/settings \\
     -H 'Cookie: $cookie' \\
     -H 'Content-Type: application/json' \\
     -d '{\"company_name\":\"Hacked\"}'\n\n";

echo "Response:\n";
$ch2 = curl_init("http://localhost/pcims/public/api/settings");
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['company_name' => 'Hacked']));
curl_setopt($ch2, CURLOPT_HTTPHEADER, [
    "Cookie: $cookie",
    "Content-Type: application/json"
]);
echo curl_exec($ch2) . "\n";
