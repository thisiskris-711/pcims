<?php
/**
 * Reports API
 */
require_once dirname(__DIR__) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER);

$db = getDB();
$action = $_GET['action'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$export = $_GET['export'] ?? '';

switch ($action) {
    case 'sales':
        $stmt = $db->prepare("
            SELECT s.invoice_no, s.customer_name, s.subtotal, s.discount, s.tax, s.total,
                   s.payment_method, s.payment_status, s.created_at, u.full_name as cashier,
                   (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
            FROM sales s
            LEFT JOIN users u ON s.created_by = u.id
            WHERE DATE(s.created_at) BETWEEN ? AND ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $data = $stmt->fetchAll();
        
        // Summary
        $summaryStmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total), 0) as revenue, COALESCE(SUM(discount), 0) as discounts, COALESCE(SUM(tax), 0) as taxes FROM sales WHERE DATE(created_at) BETWEEN ? AND ?");
        $summaryStmt->execute([$dateFrom, $dateTo]);
        $summary = $summaryStmt->fetch();
        
        if ($export === 'csv') {
            exportCSV('sales_report', ['Invoice', 'Customer', 'Subtotal', 'Discount', 'Tax', 'Total', 'Payment', 'Status', 'Date', 'Cashier'], $data, 
                fn($r) => [$r['invoice_no'], $r['customer_name'], $r['subtotal'], $r['discount'], $r['tax'], $r['total'], $r['payment_method'], $r['payment_status'], $r['created_at'], $r['cashier']]);
        }
        
        jsonResponse(['data' => $data, 'summary' => $summary]);
        break;
        
    case 'inventory':
        $stmt = $db->query("
            SELECT p.sku, p.name, c.name as category, p.cost_price, p.selling_price, p.quantity,
                   (p.quantity * p.cost_price) as cost_value,
                   (p.quantity * p.selling_price) as retail_value,
                   p.low_stock_threshold, p.status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            ORDER BY c.name, p.name
        ");
        $data = $stmt->fetchAll();
        
        $totalCost = array_sum(array_column($data, 'cost_value'));
        $totalRetail = array_sum(array_column($data, 'retail_value'));
        $totalItems = array_sum(array_column($data, 'quantity'));
        
        if ($export === 'csv') {
            exportCSV('inventory_report', ['SKU', 'Product', 'Category', 'Cost', 'Price', 'Qty', 'Cost Value', 'Retail Value', 'Status'], $data,
                fn($r) => [$r['sku'], $r['name'], $r['category'], $r['cost_price'], $r['selling_price'], $r['quantity'], $r['cost_value'], $r['retail_value'], $r['status']]);
        }
        
        jsonResponse(['data' => $data, 'summary' => ['total_cost' => $totalCost, 'total_retail' => $totalRetail, 'total_items' => $totalItems]]);
        break;
        
    case 'stock_movement':
        $stmt = $db->prepare("
            SELECT st.reference_no, st.type, st.quantity, st.balance_after, st.notes, st.created_at,
                   p.sku, p.name as product_name, u.full_name as user_name
            FROM stock_transactions st
            JOIN products p ON st.product_id = p.id
            LEFT JOIN users u ON st.created_by = u.id
            WHERE DATE(st.created_at) BETWEEN ? AND ?
            ORDER BY st.created_at DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $data = $stmt->fetchAll();
        
        $inCount = 0; $outCount = 0; $inQty = 0; $outQty = 0;
        foreach ($data as $d) {
            if ($d['type'] === 'in') { $inCount++; $inQty += $d['quantity']; }
            else { $outCount++; $outQty += $d['quantity']; }
        }
        
        if ($export === 'csv') {
            exportCSV('stock_movement_report', ['Reference', 'Type', 'Product', 'SKU', 'Qty', 'Balance', 'Notes', 'Date', 'User'], $data,
                fn($r) => [$r['reference_no'], $r['type'], $r['product_name'], $r['sku'], $r['quantity'], $r['balance_after'], $r['notes'], $r['created_at'], $r['user_name']]);
        }
        
        jsonResponse(['data' => $data, 'summary' => ['in_count' => $inCount, 'out_count' => $outCount, 'in_qty' => $inQty, 'out_qty' => $outQty]]);
        break;
        
    case 'low_stock':
        $data = $db->query("
            SELECT p.sku, p.name, c.name as category, p.quantity, p.low_stock_threshold,
                   (p.low_stock_threshold - p.quantity) as deficit, p.cost_price, p.selling_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.status = 'active' AND p.quantity <= p.low_stock_threshold
            ORDER BY p.quantity ASC
        ")->fetchAll();
        
        if ($export === 'csv') {
            exportCSV('low_stock_report', ['SKU', 'Product', 'Category', 'Stock', 'Threshold', 'Deficit', 'Cost', 'Price'], $data,
                fn($r) => [$r['sku'], $r['name'], $r['category'], $r['quantity'], $r['low_stock_threshold'], $r['deficit'], $r['cost_price'], $r['selling_price']]);
        }
        
        jsonResponse(['data' => $data]);
        break;
        
    default:
        jsonResponse(['error' => 'Invalid report type'], 400);
}

/**
 * CSV Export helper
 */
function exportCSV(string $filename, array $headers, array $data, callable $rowMapper): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    
    foreach ($data as $row) {
        fputcsv($output, $rowMapper($row));
    }
    
    fclose($output);
    exit;
}
