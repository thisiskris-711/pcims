<?php
require 'config/app.php';
$db = getDB();
$sales = $db->query('SELECT * FROM sales WHERE payment_status = "paid" OR payment_status = "credit"')->fetchAll();
foreach ($sales as $sale) {
    if ($sale['payment_method'] === 'cash') {
        $exists = $db->query('SELECT id FROM collections WHERE sale_id = ' . $sale['id'])->fetchColumn();
        if (!$exists) {
            $stmt = $db->prepare('INSERT INTO collections (sale_id, dealer_id, amount, payment_date, payment_method, reference_number, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $sale['id'],
                $sale['dealer_id'],
                $sale['total'],
                date('Y-m-d', strtotime($sale['created_at'])),
                'cash_check',
                'POS-' . $sale['invoice_no'],
                $sale['created_by']
            ]);
            echo 'Migrated cash sale ' . $sale['invoice_no'] . "\n";
        }
    }
}
echo "Done.\n";
