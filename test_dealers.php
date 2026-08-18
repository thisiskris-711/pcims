<?php
require 'c:\xampp\htdocs\pcims\config\app.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
$_GET['action'] = 'list';
$_GET['page'] = 1;
$_GET['per_page'] = 15;
$_SESSION['user_id'] = 1;

try {
    require 'c:\xampp\htdocs\pcims\src\Api\dealers.php';
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
