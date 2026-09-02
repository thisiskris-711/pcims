<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/pcims/public/api/purchase_orders';
$_GET['action'] = 'list';
$_GET['page'] = '1';
$_GET['per_page'] = '15';

// Mock session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'system_admin';

require 'c:/xampp/htdocs/pcims/src/Api/purchase_orders.php';
