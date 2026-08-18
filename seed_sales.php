<?php
require_once __DIR__ . '/config/app.php';

$db = getDB();

// 1. Get cashier ID
$stmt = $db->query("SELECT id FROM users WHERE role = 'cashier' LIMIT 1");
$cashierId = $stmt->fetchColumn();

if (!$cashierId) {
    echo "No cashier found. Creating one...\n";
    $db->query("INSERT INTO users (username, password, full_name, role) VALUES ('cashier_seed', 'pass', 'Seed Cashier', 'cashier')");
    $cashierId = $db->lastInsertId();
}

// 2. Get some products
$stmt = $db->query("SELECT id, name, selling_price as price FROM products WHERE status = 'active' LIMIT 50");
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($products)) {
    die("No active products found to sell.\n");
}

// 2.5 Get some dealers
$stmt = $db->query("SELECT id FROM dealers LIMIT 50");
$dealers = $stmt->fetchAll(PDO::FETCH_ASSOC);
$hasDealers = !empty($dealers);

// 3. Generate 100 random sales
$startDate = strtotime('-6 months');
$endDate = time();

$paymentMethods = ['cash', 'credit', 'cash&credit'];

$db->beginTransaction();

try {
    for ($i = 1; $i <= 100; $i++) {
        // Random date
        $randomTimestamp = mt_rand($startDate, $endDate);
        $createdAt = date('Y-m-d H:i:s', $randomTimestamp);

        // Generate Invoice No
        $invoiceNo = 'INV-' . date('Ymd', $randomTimestamp) . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT) . '-' . $i;

        // Choose 1 to 5 random products
        $numItems = mt_rand(1, 5);
        $saleItems = [];
        $subtotal = 0;

        for ($j = 0; $j < $numItems; $j++) {
            $product = $products[array_rand($products)];
            $quantity = mt_rand(1, 10);
            $itemTotal = $product['price'] * $quantity;
            $subtotal += $itemTotal;

            $saleItems[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'quantity' => $quantity,
                'unit_price' => $product['price'],
                'total' => $itemTotal
            ];
        }

        // Maybe some discount
        $discount = (mt_rand(0, 10) > 8) ? mt_rand(10, 100) : 0;
        if ($discount > $subtotal) $discount = 0;
        
        $total = $subtotal - $discount;
        $paymentMethod = $paymentMethods[array_rand($paymentMethods)];
        
        $dealerId = $hasDealers ? $dealers[array_rand($dealers)]['id'] : null;

        // Insert Sale
        $stmt = $db->prepare("
            INSERT INTO sales (invoice_no, dealer_id, subtotal, discount, tax, total, payment_method, payment_status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoiceNo,
            $dealerId,
            $subtotal,
            $discount,
            0, // tax
            $total,
            $paymentMethod,
            'paid',
            $cashierId,
            $createdAt
        ]);

        $saleId = $db->lastInsertId();

        // Insert Items
        $itemStmt = $db->prepare("
            INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, discount, total)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($saleItems as $item) {
            $itemStmt->execute([
                $saleId,
                $item['product_id'],
                $item['product_name'],
                $item['quantity'],
                $item['unit_price'],
                0,
                $item['total']
            ]);
        }
    }
    
    $db->commit();
    echo "100 sales successfully inserted.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "Error inserting sales: " . $e->getMessage() . "\n";
}
