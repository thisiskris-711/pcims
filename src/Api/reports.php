<?php
/**
 * Reports API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('view_reports');

$db = getDB();
$action = $_GET['action'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$export = $_GET['export'] ?? '';

try {
    switch ($action) {
    case 'sales':
        $stmt = $db->prepare("
            SELECT s.invoice_no, COALESCE(d.name, 'Walk-in Customer') as customer_name, s.subtotal, s.discount, s.tax, s.total,
                   s.payment_method, s.payment_status, s.created_at, u.full_name as cashier,
                   (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
            FROM sales s
            LEFT JOIN users u ON s.created_by = u.id
            LEFT JOIN dealers d ON s.dealer_id = d.id
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
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        $offset = ($page - 1) * $perPage;
        
        $baseQuery = "
            SELECT p.sku, p.name, c.name as category, p.quantity, p.low_stock_threshold,
                   (p.low_stock_threshold - p.quantity) as deficit, p.cost_price, p.selling_price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE p.status = 'active' AND p.quantity <= p.low_stock_threshold
        ";
        
        if ($export === 'csv') {
            $data = $db->query($baseQuery . " ORDER BY p.quantity ASC")->fetchAll();
            exportCSV('low_stock_report', ['SKU', 'Product', 'Category', 'Stock', 'Threshold', 'Deficit', 'Cost', 'Price'], $data,
                fn($r) => [$r['sku'], $r['name'], $r['category'], $r['quantity'], $r['low_stock_threshold'], $r['deficit'], $r['cost_price'], $r['selling_price']]);
        } else {
            $count = (int)$db->query("SELECT COUNT(*) FROM products p WHERE p.status = 'active' AND p.quantity <= p.low_stock_threshold")->fetchColumn();
            $totalPages = max(1, ceil($count / $perPage));
            $data = $db->query($baseQuery . " ORDER BY p.quantity ASC LIMIT $perPage OFFSET $offset")->fetchAll();
            jsonResponse(['data' => $data, 'page' => $page, 'total_pages' => $totalPages, 'total' => $count]);
        }
        break;
        
    case 'forecast':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? ITEMS_PER_PAGE);
        $offset = ($page - 1) * $perPage;

        $stmt = $db->query("
            SELECT p.id, p.sku, p.name, c.name as category, p.quantity, p.cost_price,
                   COALESCE(rs.sales_7d, 0) as sales_7d,
                   COALESCE(rs.sales_30d, 0) as sales_30d,
                   COALESCE(rs.sales_90d, 0) as sales_90d
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN (
                SELECT si.product_id, 
                    SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN si.quantity ELSE 0 END) as sales_7d,
                    SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN si.quantity ELSE 0 END) as sales_30d,
                    SUM(CASE WHEN s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN si.quantity ELSE 0 END) as sales_90d
                FROM sale_items si
                JOIN sales s ON si.sale_id = s.id
                WHERE s.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                GROUP BY si.product_id
            ) rs ON p.id = rs.product_id
            WHERE p.status = 'active'
        ");
        $allProducts = $stmt->fetchAll();
        
        $processed = [];
        $summary = [
            'high_risk_count' => 0,
            'total_reorder_value' => 0,
            'growing_products' => 0,
            'overstock_risks' => 0
        ];
        
        $leadTime = 7; // Assumed supplier lead time

        foreach ($allProducts as $p) {
            $avg7 = $p['sales_7d'] / 7;
            $avg30 = $p['sales_30d'] / 30;
            $avg90 = $p['sales_90d'] / 90;
            
            // Use 30 day avg primarily, fallback to 90
            $demandRate = $avg30 > 0 ? $avg30 : $avg90;
            
            $daysRemaining = $demandRate > 0 ? round($p['quantity'] / $demandRate, 1) : 999;
            $forecastDemand = ceil($demandRate * 30); // next 30 days
            
            $confidence = 'Low';
            if ($p['sales_90d'] > 20) $confidence = 'High';
            elseif ($p['sales_30d'] > 5) $confidence = 'Medium';
            
            $trend = 'Stable';
            if ($avg30 > 0) {
                if ($avg7 > $avg30 * 1.15) $trend = 'Growing';
                elseif ($avg7 < $avg30 * 0.85) $trend = 'Declining';
            }
            if ($trend === 'Growing') $summary['growing_products']++;
            
            if ($daysRemaining > 90 && $p['quantity'] > 20) $summary['overstock_risks']++;
            
            $riskLevel = 'Low';
            $sortScore = 0;
            if ($daysRemaining <= $leadTime) {
                $riskLevel = 'Critical';
                $summary['high_risk_count']++;
                $sortScore = 4;
            } elseif ($daysRemaining <= $leadTime + 7) {
                $riskLevel = 'High';
                $summary['high_risk_count']++;
                $sortScore = 3;
            } elseif ($daysRemaining <= 30) {
                $riskLevel = 'Medium';
                $sortScore = 2;
            } else {
                $sortScore = 1;
            }
            if ($daysRemaining == 999) $sortScore = 0;
            
            $suggestedReorder = 0;
            if ($riskLevel !== 'Low' && $demandRate > 0) {
                // Order enough to cover lead time + 30 days safety
                $targetStock = ceil($demandRate * ($leadTime + 30));
                $suggestedReorder = max(0, $targetStock - $p['quantity']);
            }
            
            $summary['total_reorder_value'] += ($suggestedReorder * $p['cost_price']);
            
            $reasoning = '';
            if ($riskLevel === 'Critical') $reasoning = "Stockout expected within lead time ({$leadTime} days). Immediate action required.";
            elseif ($riskLevel === 'High') $reasoning = "Stockout expected in {$daysRemaining} days. Order soon to cover {$leadTime}-day lead time.";
            elseif ($riskLevel === 'Medium') $reasoning = "Stock level adequate but requires monitoring.";
            elseif ($daysRemaining == 999) $reasoning = "No reorder required. No recent sales detected.";
            else $reasoning = "Sufficient stock for projected demand.";
            
            if ($trend === 'Growing') $reasoning .= " Demand is trending upward.";
            elseif ($trend === 'Declining') $reasoning .= " Demand is slowing down.";

            $processed[] = [
                'sku' => $p['sku'],
                'name' => $p['name'],
                'category' => $p['category'],
                'quantity' => $p['quantity'],
                'forecast_demand' => $forecastDemand,
                'days_remaining' => $daysRemaining,
                'confidence' => $confidence,
                'trend' => $trend,
                'risk_level' => $riskLevel,
                'suggested_reorder' => $suggestedReorder,
                'reasoning' => $reasoning,
                'sort_score' => $sortScore
            ];
        }
        
        // Sort by risk (High -> Low) then by days remaining
        usort($processed, function($a, $b) {
            if ($a['sort_score'] !== $b['sort_score']) return $b['sort_score'] <=> $a['sort_score'];
            return $a['days_remaining'] <=> $b['days_remaining'];
        });

        // Generate Chart Data: Historical 30 days aggregated
        $chartStmt = $db->query("
            SELECT DATE(created_at) as date, SUM((SELECT SUM(quantity) FROM sale_items WHERE sale_id = s.id)) as items
            FROM sales s
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at) ASC
        ");
        $chartHistory = $chartStmt->fetchAll();
        $chartLabels = [];
        $chartHistorical = [];
        $chartProjected = [];
        $chartConfidenceUpper = [];
        $chartConfidenceLower = [];
        
        $histMap = [];
        foreach ($chartHistory as $row) {
            $histMap[$row['date']] = (int)$row['items'];
        }
        
        $avgItemsPerDay = array_sum($histMap) / 30;
        $trendFactor = ($summary['growing_products'] > 10) ? 1.05 : 1.0;
        
        // Populate last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $chartLabels[] = date('M d', strtotime($d));
            $chartHistorical[] = $histMap[$d] ?? 0;
            $chartProjected[] = null;
            $chartConfidenceUpper[] = null;
            $chartConfidenceLower[] = null;
        }
        // Connect the lines
        $chartProjected[29] = $chartHistorical[29];
        $chartConfidenceUpper[29] = $chartHistorical[29];
        $chartConfidenceLower[29] = $chartHistorical[29];

        // Populate next 14 days projected
        $currentVal = $avgItemsPerDay;
        for ($i = 1; $i <= 14; $i++) {
            $d = date('Y-m-d', strtotime("+$i days"));
            $chartLabels[] = date('M d', strtotime($d));
            $chartHistorical[] = null;
            
            $currentVal = $currentVal * $trendFactor;
            $noise = rand(-10, 10) / 100; // +/- 10%
            $val = max(0, round($currentVal * (1 + $noise)));
            
            $chartProjected[] = $val;
            $chartConfidenceUpper[] = round($val * 1.2);
            $chartConfidenceLower[] = round($val * 0.8);
        }
        
        $chartData = [
            'labels' => $chartLabels,
            'historical' => $chartHistorical,
            'projected' => $chartProjected,
            'upper' => $chartConfidenceUpper,
            'lower' => $chartConfidenceLower
        ];

        if ($export === 'csv') {
            exportCSV('predictive_forecast_report', ['SKU', 'Product', 'Category', 'Current Stock', 'Risk Level', 'Forecast Demand (30d)', 'Confidence', 'Days Remaining', 'Suggested Reorder', 'Reasoning'], $processed,
                fn($r) => [$r['sku'], $r['name'], $r['category'], $r['quantity'], $r['risk_level'], $r['forecast_demand'], $r['confidence'], $r['days_remaining'] == 999 ? '999+' : $r['days_remaining'], $r['suggested_reorder'], $r['reasoning']]);
        } else {
            $total = count($processed);
            $totalPages = max(1, ceil($total / $perPage));
            $pagedData = array_slice($processed, $offset, $perPage);
            
            jsonResponse([
                'data' => $pagedData, 
                'summary' => $summary,
                'chart' => $chartData,
                'page' => $page, 
                'total_pages' => $totalPages, 
                'total' => $total
            ]);
        }
        break;
        
    case 'collection_efficiency':
        $stmt = $db->prepare("
            SELECT s.id, s.invoice_no, s.due_date, s.total, s.adjustment_amount, 
                   s.payment_method as sale_payment_method, DATE(s.created_at) as sale_date,
                   COALESCE(d.name, 'Walk-in Customer') as customer_name
            FROM sales s
            LEFT JOIN dealers d ON s.dealer_id = d.id
            WHERE s.due_date BETWEEN ? AND ?
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $maturities = $stmt->fetchAll();

        $summary = [
            'maturing_amount' => 0,
            'cash_purchase' => 0,
            'adjustments' => 0,
            'on_time_cash' => 0,
            'on_time_cm' => 0,
            'grace_cash' => 0,
            'grace_cm' => 0,
            'late_cash' => 0,
            'late_cm' => 0,
            'total_collected' => 0,
            'uncollected' => 0
        ];

        $detailed_rows = [];

        foreach ($maturities as $sale) {
            $maturingAmount = (float)$sale['total'] + (float)$sale['adjustment_amount'];
            $summary['maturing_amount'] += $maturingAmount;
            $summary['adjustments'] += (float)$sale['adjustment_amount'];

            $cStmt = $db->prepare("
                SELECT amount, payment_date, payment_method 
                FROM collections 
                WHERE sale_id = ? AND status = 'active'
            ");
            $cStmt->execute([$sale['id']]);
            $collections = $cStmt->fetchAll();

            $saleCollections = [
                'cash_purchase' => 0,
                'on_time_cash' => 0,
                'on_time_cm' => 0,
                'grace_cash' => 0,
                'grace_cm' => 0,
                'late_cash' => 0,
                'late_cm' => 0,
            ];

            foreach ($collections as $c) {
                $amount = (float)$c['amount'];
                $paymentDate = $c['payment_date'];
                $dueDate = $sale['due_date'] ?: $sale['sale_date'];
                $method = $c['payment_method'];
                
                $isCashPurchase = false;
                if ($sale['sale_payment_method'] === 'cash' && $paymentDate === $sale['sale_date']) {
                    $isCashPurchase = true;
                }

                if ($isCashPurchase) {
                    $saleCollections['cash_purchase'] += $amount;
                    $summary['cash_purchase'] += $amount;
                } else if ($paymentDate <= $dueDate) {
                    if ($method === 'credit_memo') {
                        $saleCollections['on_time_cm'] += $amount;
                        $summary['on_time_cm'] += $amount;
                    } else {
                        $saleCollections['on_time_cash'] += $amount;
                        $summary['on_time_cash'] += $amount;
                    }
                } else {
                    $datetime1 = new DateTime($dueDate);
                    $datetime2 = new DateTime($paymentDate);
                    $interval = $datetime1->diff($datetime2);
                    $daysLate = (int)$interval->format('%r%a');

                    if ($daysLate > 0 && $daysLate <= 7) {
                        if ($method === 'credit_memo') {
                            $saleCollections['grace_cm'] += $amount;
                            $summary['grace_cm'] += $amount;
                        } else {
                            $saleCollections['grace_cash'] += $amount;
                            $summary['grace_cash'] += $amount;
                        }
                    } else {
                        if ($method === 'credit_memo') {
                            $saleCollections['late_cm'] += $amount;
                            $summary['late_cm'] += $amount;
                        } else {
                            $saleCollections['late_cash'] += $amount;
                            $summary['late_cash'] += $amount;
                        }
                    }
                }
            }

            $saleTotalCollected = array_sum($saleCollections);
            $summary['total_collected'] += $saleTotalCollected;

            $rowOnTimeTotal = $saleCollections['cash_purchase'] + $saleCollections['on_time_cash'] + $saleCollections['on_time_cm'];
            $rowGraceTotal = $rowOnTimeTotal + $saleCollections['grace_cash'] + $saleCollections['grace_cm'];
            
            $detailed_rows[] = [
                'invoice_no' => $sale['invoice_no'],
                'customer' => $sale['customer_name'],
                'due_date' => $sale['due_date'],
                'maturing_amount' => $maturingAmount,
                'cash_purchase' => $saleCollections['cash_purchase'],
                'on_time' => $saleCollections['on_time_cash'] + $saleCollections['on_time_cm'],
                'grace_period' => $saleCollections['grace_cash'] + $saleCollections['grace_cm'],
                'after_grace' => $saleCollections['late_cash'] + $saleCollections['late_cm'],
                'total_collected' => $saleTotalCollected,
                'uncollected' => max(0, $maturingAmount - $saleTotalCollected),
                'on_time_efficiency' => $maturingAmount > 0 ? ($rowOnTimeTotal / $maturingAmount) * 100 : 0,
                'grace_efficiency' => $maturingAmount > 0 ? ($rowGraceTotal / $maturingAmount) * 100 : 0,
                'overall_efficiency' => $maturingAmount > 0 ? ($saleTotalCollected / $maturingAmount) * 100 : 0
            ];
        }

        $summary['uncollected'] = max(0, $summary['maturing_amount'] - $summary['total_collected']);
        
        $totalOnTime = $summary['cash_purchase'] + $summary['on_time_cash'] + $summary['on_time_cm'];
        $totalGrace = $totalOnTime + $summary['grace_cash'] + $summary['grace_cm'];
        
        $summary['on_time_efficiency'] = $summary['maturing_amount'] > 0 ? ($totalOnTime / $summary['maturing_amount']) * 100 : 0;
        $summary['grace_efficiency'] = $summary['maturing_amount'] > 0 ? ($totalGrace / $summary['maturing_amount']) * 100 : 0;
        $summary['overall_efficiency'] = $summary['maturing_amount'] > 0 ? ($summary['total_collected'] / $summary['maturing_amount']) * 100 : 0;

        if ($export === 'csv') {
            exportCSV('collection_efficiency_report', 
                ['Due Date', 'Invoice', 'Customer', 'Maturing Amount', 'Cash Purchase', 'On-Time', 'Grace Period', 'After 7 Days', 'Total Collected', 'Uncollected', 'On-Time %', 'Grace %', 'Overall %'], 
                $detailed_rows,
                fn($r) => [
                    $r['due_date'], $r['invoice_no'], $r['customer'],
                    number_format($r['maturing_amount'], 2, '.', ''),
                    number_format($r['cash_purchase'], 2, '.', ''),
                    number_format($r['on_time'], 2, '.', ''),
                    number_format($r['grace_period'], 2, '.', ''),
                    number_format($r['after_grace'], 2, '.', ''),
                    number_format($r['total_collected'], 2, '.', ''),
                    number_format($r['uncollected'], 2, '.', ''),
                    number_format($r['on_time_efficiency'], 2, '.', '') . '%',
                    number_format($r['grace_efficiency'], 2, '.', '') . '%',
                    number_format($r['overall_efficiency'], 2, '.', '') . '%'
                ]
            );
        }
        
        jsonResponse(['data' => $detailed_rows, 'summary' => $summary]);
        break;

    default:
        jsonResponse(['error' => 'Invalid report type'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
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
