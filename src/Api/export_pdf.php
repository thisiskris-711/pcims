<?php
/**
 * PDF Export API
 * Currently outputs a formatted HTML page for printing.
 * For full PDF generation, install TCPDF: composer require tecnickcom/tcpdf
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER);

$action = $_GET['action'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// For now, redirect to CSV export
// Full TCPDF implementation can be added by running: composer require tecnickcom/tcpdf
header('Location: ' . APP_URL . "/api/reports.php?action=$action&date_from=$dateFrom&date_to=$dateTo&export=csv");
exit;
