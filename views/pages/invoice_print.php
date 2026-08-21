<?php
/**
 * Sales Invoice Print View (Simple Layout)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('create_sales');
$saleId = (int)($_GET['id'] ?? 0);
if (!$saleId) die('Invalid Invoice ID');

$db = getDB();

// Fetch Sale
$stmt = $db->prepare("
    SELECT s.*, u.full_name as cashier, d.name as dealer_name, d.dealer_code, d.address as dealer_address
    FROM sales s 
    LEFT JOIN users u ON s.created_by = u.id 
    LEFT JOIN dealers d ON s.dealer_id = d.id
    WHERE s.id = ?
");
$stmt->execute([$saleId]);
$sale = $stmt->fetch();

if (!$sale) die('Invoice not found');

// Fetch Items
$itemStmt = $db->prepare("
    SELECT si.*, p.sku, p.name 
    FROM sale_items si 
    LEFT JOIN products p ON si.product_id = p.id 
    WHERE si.sale_id = ?
");
$itemStmt->execute([$saleId]);
$items = $itemStmt->fetchAll();

// Calculations
$totalGross = 0;
$totalDiscount = 0;

foreach ($items as $item) {
    $gross = $item['quantity'] * $item['unit_price'];
    $totalGross += $gross;
    $totalDiscount += $item['discount'];
}
$netAmount = $totalGross - $totalDiscount;

// For simple invoice, we might just show Subtotal and Total. 
// If VAT is needed, we can show it, but the simple invoice just had Sales Tax %. 
// We'll show Subtotal, Discount, and Total.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?= htmlspecialchars($sale['invoice_no']) ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 13px;
            color: #333;
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            padding: 10px;
        }
        .header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
        }
        .company-details {
            text-align: right;
            line-height: 1.5;
        }
        .company-name {
            font-size: 24px;
            font-weight: bold;
            color: #222;
            margin-bottom: 5px;
        }
        .invoice-title {
            font-size: 36px;
            font-weight: 300;
            color: #555;
            letter-spacing: 2px;
        }
        .meta-table {
            border-collapse: collapse;
            margin-top: 20px;
            width: 100%;
            max-width: 300px;
        }
        .meta-table td, .meta-table th {
            padding: 5px;
            border: 1px solid #ddd;
            text-align: left;
        }
        .meta-table th {
            background: #f8f8f8;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
        }
        
        .bill-ship-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .bill-box, .ship-box {
            width: 45%;
        }
        .box-title {
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            color: #666;
            border-bottom: 2px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 10px;
        }
        .address-text {
            line-height: 1.5;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            text-align: center;
        }
        .info-table th {
            background: #f8f8f8;
            padding: 8px;
            border: 1px solid #ddd;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
        }
        .info-table td {
            padding: 8px;
            border: 1px solid #ddd;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background: #f8f8f8;
            padding: 10px;
            border: 1px solid #ddd;
            font-size: 11px;
            text-transform: uppercase;
            color: #666;
            text-align: center;
        }
        .items-table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
        }
        .items-table td.desc-col {
            text-align: left;
        }
        .items-table td.right-col {
            text-align: right;
        }
        
        .totals-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 40px;
        }
        .totals-table {
            width: 300px;
            border-collapse: collapse;
        }
        .totals-table td {
            padding: 8px 10px;
            border: 1px solid #ddd;
        }
        .totals-table td:first-child {
            text-align: right;
            font-weight: bold;
            background: #f8f8f8;
            color: #555;
            width: 60%;
        }
        .totals-table td:last-child {
            text-align: right;
        }
        .totals-table tr.grand-total td {
            font-size: 16px;
            font-weight: bold;
            color: #000;
        }

        .footer-note {
            text-align: center;
            color: #666;
            margin-top: 50px;
            font-size: 14px;
        }

        .print-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 10px 20px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        @media print {
            .print-btn { display: none; }
            body { 
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
            }
        }
    </style>
</head>
<body>

<div class="invoice-box">
    
    <div class="header-row">
        <div>
            <div class="invoice-title">INVOICE</div>
            <table class="meta-table">
                <tr>
                    <th>Invoice No.</th>
                    <th>Date</th>
                    <th>Customer ID</th>
                </tr>
                <tr>
                    <td><?= htmlspecialchars($sale['invoice_no']) ?></td>
                    <td><?= date('Y-m-d', strtotime($sale['created_at'])) ?></td>
                    <td><?= htmlspecialchars($sale['dealer_code']) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="company-details">
            <div class="company-name">Personal Collection Direct Selling, Inc.</div>
            <div>GF BALI ARCADE MANDANGOA<br>
            9005 BALINGASAG MISAMIS ORIENTAL<br>
            PHILIPPINES<br>
            (02) 737-1717 / 0917-555-1717</div>
        </div>
    </div>

    <div class="bill-ship-row">
        <div class="bill-box">
            <div class="box-title">Bill To</div>
            <div class="address-text">
                <strong><?= htmlspecialchars($sale['dealer_name']) ?></strong><br>
                <?= nl2br(htmlspecialchars($sale['dealer_address'])) ?>
            </div>
        </div>
        <div class="ship-box">
            <div class="box-title">Ship To</div>
            <div class="address-text">
                <strong><?= htmlspecialchars($sale['dealer_name']) ?></strong><br>
                <?= nl2br(htmlspecialchars($sale['dealer_address'])) ?>
            </div>
        </div>
    </div>

    <table class="info-table">
        <tr>
            <th>Salesperson</th>
            <th>Payment Terms</th>
            <th>Due Date</th>
        </tr>
        <tr>
            <td><?= htmlspecialchars($sale['cashier']) ?></td>
            <td><?= $sale['payment_status'] === 'paid' ? 'Paid' : 'Due upon receipt' ?></td>
            <td><?= date('Y-m-d', strtotime($sale['created_at'])) ?></td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 8%;">QTY</th>
                <th style="width: 15%;">ITEM #</th>
                <th style="width: 35%; text-align: left;">DESCRIPTION</th>
                <th style="width: 12%;">UNIT PRICE</th>
                <th style="width: 12%;">DISCOUNT</th>
                <th style="width: 18%;">LINE TOTAL</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item): 
                $gross = $item['quantity'] * $item['unit_price'];
                $net = $gross - $item['discount'];
            ?>
            <tr>
                <td><?= $item['quantity'] ?></td>
                <td><?= htmlspecialchars($item['sku'] ?? '') ?></td>
                <td class="desc-col"><?= htmlspecialchars($item['name']) ?></td>
                <td class="right-col"><?= number_format($item['unit_price'], 2) ?></td>
                <td class="right-col"><?= number_format($item['discount'], 2) ?></td>
                <td class="right-col"><?= number_format($net, 2) ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Pad empty rows to maintain layout if needed -->
            <?php for($i = 0; $i < max(0, 10 - count($items)); $i++): ?>
            <tr>
                <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td>
            </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <div class="totals-row">
        <table class="totals-table">
            <tr>
                <td>TOTAL DISCOUNT</td>
                <td><?= number_format($totalDiscount, 2) ?></td>
            </tr>
            <tr>
                <td>SUBTOTAL</td>
                <td><?= number_format($totalGross - $totalDiscount, 2) ?></td>
            </tr>
            <!-- Adding Tax line if you want to be exactly like the template -->
            <tr>
                <td>SALES TAX %</td>
                <td>0.00</td>
            </tr>
            <tr class="grand-total">
                <td>TOTAL</td>
                <td><?= number_format($netAmount, 2) ?></td>
            </tr>
        </table>
    </div>

    <div class="footer-note">
        <strong>Make all checks payable to:</strong><br>
        Personal Collection Direct Selling, Inc.<br><br>
        <strong>THANK YOU FOR YOUR BUSINESS!</strong>
    </div>

</div>

<button class="print-btn" onclick="window.print()">Print Invoice</button>

<script>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    };
</script>

</body>
</html>

