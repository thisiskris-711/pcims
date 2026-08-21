<?php
/**
 * Collection Efficiency API
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('view_reports');

$db = getDB();
$action = $_GET['action'] ?? '';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t'); // default to end of current month
$export = $_GET['export'] ?? '';

try {
    if ($action === 'report') {
        // Find all sales whose due_date falls in the period
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

            // Fetch collections for this sale
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
                $dueDate = $sale['due_date'];
                $method = $c['payment_method']; // 'cash_check' or 'credit_memo'
                
                $isCashPurchase = false;
                // Automatically identify Cash Purchase: payment method is cash, fully paid at time of sale.
                // Or simply, if sale's payment method is 'cash' and it was paid on the sale date.
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
                    } else { // After 7 Days
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
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="collection_efficiency_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Due Date', 'Invoice', 'Customer', 'Maturing Amount', 'Cash Purchase', 'On-Time', 'Grace Period', 'After 7 Days', 'Total Collected', 'Uncollected', 'On-Time %', 'Grace %', 'Overall %']);
            
            foreach ($detailed_rows as $r) {
                fputcsv($output, [
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
                ]);
            }
            fclose($output);
            exit;
        }

        jsonResponse(['data' => $detailed_rows, 'summary' => $summary]);
    } else {
        jsonResponse(['error' => 'Invalid action'], 400);
    }

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
}
