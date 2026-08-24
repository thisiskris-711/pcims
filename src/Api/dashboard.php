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
        
    case 'credit_aging':
        // Get overdue amounts bucketed by age
        $stmt = $db->query("
            SELECT 
                SUM(CASE WHEN DATEDIFF(CURDATE(), s.due_date) BETWEEN 1 AND 30 THEN s.total - COALESCE((SELECT SUM(amount) FROM collections WHERE sale_id = s.id AND status = 'active'), 0) ELSE 0 END) as bucket_30,
                SUM(CASE WHEN DATEDIFF(CURDATE(), s.due_date) BETWEEN 31 AND 60 THEN s.total - COALESCE((SELECT SUM(amount) FROM collections WHERE sale_id = s.id AND status = 'active'), 0) ELSE 0 END) as bucket_60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), s.due_date) BETWEEN 61 AND 90 THEN s.total - COALESCE((SELECT SUM(amount) FROM collections WHERE sale_id = s.id AND status = 'active'), 0) ELSE 0 END) as bucket_90,
                SUM(CASE WHEN DATEDIFF(CURDATE(), s.due_date) > 90 THEN s.total - COALESCE((SELECT SUM(amount) FROM collections WHERE sale_id = s.id AND status = 'active'), 0) ELSE 0 END) as bucket_over_90
            FROM sales s
            WHERE s.payment_method = 'credit' AND s.due_date < CURDATE()
        ");
        $aging = $stmt->fetch();
        
        $values = [
            round((float)$aging['bucket_30'], 2),
            round((float)$aging['bucket_60'], 2),
            round((float)$aging['bucket_90'], 2),
            round((float)$aging['bucket_over_90'], 2)
        ];
        
        $labels = ['1-30 Days', '31-60 Days', '61-90 Days', '> 90 Days'];
        $colors = ['#f59e0b', '#f97316', '#ef4444', '#7f1d1d'];
        
        jsonResponse(['labels' => $labels, 'values' => $values, 'colors' => $colors]);
        break;
        
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
