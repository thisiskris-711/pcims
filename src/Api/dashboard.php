<?php
/**
 * Dashboard API
 * GET ?action=sales_chart&days=30
 * GET ?action=category_chart
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'sales_chart':
        $days = max(7, min(365, (int)($_GET['days'] ?? 30)));
        
        $stmt = $db->prepare("
            SELECT DATE(created_at) as date, SUM(total) as revenue
            FROM sales
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->execute([$days]);
        $results = $stmt->fetchAll();
        
        // Fill gaps (missing days get 0)
        $labels = [];
        $values = [];
        $dataMap = [];
        foreach ($results as $r) {
            $dataMap[$r['date']] = (float) $r['revenue'];
        }
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('M j', strtotime($date));
            $values[] = $dataMap[$date] ?? 0;
        }
        
        jsonResponse(['labels' => $labels, 'values' => $values]);
        break;
        
    case 'category_chart':
        $results = $db->query("
            SELECT c.name, c.color, COUNT(p.id) as product_count
            FROM categories c
            LEFT JOIN products p ON p.category_id = c.id AND p.status = 'active'
            GROUP BY c.id
            HAVING product_count > 0
            ORDER BY product_count DESC
        ")->fetchAll();
        
        $labels = array_column($results, 'name');
        $values = array_map('intval', array_column($results, 'product_count'));
        $colors = array_column($results, 'color');
        
        jsonResponse(['labels' => $labels, 'values' => $values, 'colors' => $colors]);
        break;
        
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
