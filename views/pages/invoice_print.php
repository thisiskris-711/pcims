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
    SELECT s.*, u.full_name as cashier, d.name as dealer_name, d.dealer_code, d.address as dealer_address, d.credit_limit, d.credit_balance
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
$totalGross = (float)$sale['subtotal'];
$totalDiscount = (float)$sale['discount'];
$netAmount = (float)$sale['total'];
$taxAmount = (float)$sale['tax'];
$netOfVat = $netAmount - $taxAmount;

$notesOriginal = $sale['notes'];
$discountBreakdown = [];
$actualNotes = $notesOriginal;

if (strpos($notesOriginal, '--- Discount Breakdown ---') !== false) {
  $parts = explode('--- Discount Breakdown ---', $notesOriginal);
  $actualNotes = trim($parts[0]);
  $breakdownText = trim($parts[1] ?? '');
  if ($breakdownText) {
    $discountBreakdown = explode("\n", $breakdownText);
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Invoice - <?= htmlspecialchars($sale['invoice_no']) ?></title>
  <style>
    :root {
      --ink: #1B2430;
      --ink-soft: #4A5568;
      --line: #D7DCE3;
      --line-strong: #AEB6C2;
      --paper: #FFFFFF;
      --wash: #F4F6F8;
      --accent: #1E5C4A;
      --accent-soft: #E4EEEA;
      --flag: #B3542A;
      --mono: "IBM Plex Mono", "Courier New", monospace;
      --serif: "Source Serif 4", Georgia, "Times New Roman", serif;
      --sans: "Inter", "Helvetica Neue", Arial, sans-serif;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--wash);
      font-family: var(--sans);
      color: var(--ink);
      padding: 40px 16px;
    }

    .sheet {
      max-width: 900px;
      margin: 0 auto;
      background: var(--paper);
      border: 1px solid var(--line);
      box-shadow: 0 1px 3px rgba(20, 30, 40, 0.06), 0 12px 32px rgba(20, 30, 40, 0.05);
    }

    .legend {
      background: var(--ink);
      color: #EDEFF2;
      font-size: 11.5px;
      padding: 10px 28px;
      display: flex;
      gap: 24px;
      align-items: center;
      letter-spacing: .02em;
    }

    .legend b {
      color: #fff;
    }

    .swatch {
      display: inline-block;
      width: 10px;
      height: 10px;
      border-radius: 2px;
      margin-right: 6px;
      vertical-align: -1px;
    }

    .sw-fill {
      background: #FCEBD6;
      border: 1px solid #E0B27A;
    }

    .sw-req {
      background: var(--flag);
    }

    .head {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 36px 28px 24px;
      border-bottom: 2px solid var(--ink);
    }

    .brand-mark {
      width: 52px;
      height: 52px;
      border: 2px solid var(--accent);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: var(--serif);
      font-weight: 700;
      font-size: 20px;
      color: var(--accent);
      margin-bottom: 14px;
    }

    .company-name {
      font-family: var(--serif);
      font-size: 24px;
      font-weight: 700;
      letter-spacing: .01em;
    }

    .company-meta {
      font-size: 12px;
      color: var(--ink-soft);
      margin-top: 6px;
      line-height: 1.6;
    }

    .doc-title {
      text-align: right;
    }

    .doc-title h1 {
      font-family: var(--serif);
      font-size: 30px;
      margin: 0 0 4px;
      letter-spacing: .04em;
      color: var(--accent);
    }

    .doc-title .sub {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .14em;
      color: var(--ink-soft);
    }

    .meta-strip {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      border-bottom: 1px solid var(--line);
    }

    .meta-cell {
      padding: 14px 20px;
      border-right: 1px solid var(--line);
    }

    .meta-cell:last-child {
      border-right: none;
    }

    .meta-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: var(--ink-soft);
      margin-bottom: 4px;
    }

    .meta-value {
      font-family: var(--mono);
      font-size: 13.5px;
    }

    .req::after {
      content: " *";
      color: var(--flag);
    }

    .bir {
      background: var(--accent-soft);
      padding: 12px 28px;
      display: flex;
      gap: 36px;
      flex-wrap: wrap;
      font-size: 11.5px;
      border-bottom: 1px solid var(--line);
    }

    .bir .item b {
      display: block;
      font-size: 9.5px;
      text-transform: uppercase;
      letter-spacing: .1em;
      color: var(--accent);
      margin-bottom: 2px;
      font-weight: 700;
    }

    .bir .item span {
      font-family: var(--mono);
    }

    .parties {
      display: grid;
      grid-template-columns: 1fr 1fr;
      padding: 24px 28px;
      gap: 32px;
      border-bottom: 1px solid var(--line);
    }

    .party-label {
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .14em;
      color: var(--accent);
      font-weight: 700;
      border-bottom: 1px solid var(--line-strong);
      padding-bottom: 6px;
      margin-bottom: 10px;
    }

    .party-row {
      display: flex;
      font-size: 13px;
      margin-bottom: 5px;
    }

    .party-row .k {
      width: 120px;
      color: var(--ink-soft);
      flex-shrink: 0;
    }

    .repstrip {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      border-bottom: 1px solid var(--line);
    }

    .repstrip .meta-cell {
      text-align: center;
    }

    table.items {
      width: 100%;
      border-collapse: collapse;
      margin: 0;
    }

    table.items thead th {
      background: var(--ink);
      color: #fff;
      font-size: 10.5px;
      text-transform: uppercase;
      letter-spacing: .08em;
      padding: 10px 12px;
      text-align: left;
      font-weight: 600;
    }

    table.items thead th.num {
      text-align: right;
    }

    table.items tbody td {
      padding: 10px 12px;
      font-size: 12.5px;
      border-bottom: 1px solid var(--line);
    }

    table.items tbody td.num {
      text-align: right;
      font-family: var(--mono);
    }

    table.items tbody tr:nth-child(even) {
      background: #FAFBFC;
    }

    .tag {
      display: inline-block;
      font-size: 9.5px;
      padding: 2px 7px;
      border-radius: 10px;
      background: var(--accent-soft);
      color: var(--accent);
      font-weight: 600;
      letter-spacing: .03em;
    }

    .bottom {
      display: flex;
      justify-content: space-between;
      padding: 24px 28px 8px;
      gap: 32px;
    }

    .signoff {
      flex: 1;
      font-size: 11.5px;
      color: var(--ink-soft);
    }

    .signoff .clause {
      line-height: 1.6;
      margin-bottom: 28px;
      max-width: 340px;
    }

    .sigline {
      border-top: 1px solid var(--ink);
      padding-top: 6px;
      width: 220px;
      margin-bottom: 22px;
      font-size: 10.5px;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: var(--ink-soft);
    }

    .totals {
      width: 320px;
      flex-shrink: 0;
    }

    .trow {
      display: flex;
      justify-content: space-between;
      padding: 7px 4px;
      font-size: 12.5px;
      border-bottom: 1px solid var(--line);
    }

    .trow .v {
      font-family: var(--mono);
    }

    .trow.sub {
      color: var(--ink-soft);
      font-size: 11.5px;
    }

    .trow.grand {
      background: var(--ink);
      color: #fff;
      font-size: 15px;
      font-weight: 700;
      padding: 12px 14px;
      margin-top: 6px;
      border: none;
    }

    .trow.grand .v {
      font-family: var(--mono);
    }

    .trow.change {
      font-weight: 700;
      color: var(--accent);
    }

    .credit {
      margin: 0 28px 24px;
      padding: 14px 18px;
      border: 1px dashed var(--line-strong);
      border-radius: 4px;
      font-size: 11.5px;
      color: var(--ink-soft);
    }

    .credit b {
      display: block;
      color: var(--ink);
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: .1em;
      margin-bottom: 8px;
    }

    .credit-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
    }

    .footer {
      text-align: center;
      font-size: 10px;
      color: var(--ink-soft);
      padding: 14px 28px 26px;
      letter-spacing: .03em;
    }

    @media print {
      body {
        background: #fff;
        padding: 0;
      }

      .sheet {
        box-shadow: none;
        border: none;
        max-width: none;
      }

      .legend {
        display: none;
      }

      .noprint {
        display: none;
      }
    }

    @media (max-width:720px) {
      .head {
        flex-direction: column;
        gap: 18px;
      }

      .doc-title {
        text-align: left;
      }

      .meta-strip,
      .repstrip,
      .credit-grid {
        grid-template-columns: 1fr 1fr;
      }

      .parties {
        grid-template-columns: 1fr;
      }

      .bottom {
        flex-direction: column;
      }

      .totals {
        width: 100%;
      }
    }
  </style>
</head>

<body onload="window.print()">

  <div class="sheet">

    <div class="legend">
      <span><span class="swatch sw-req"></span><b>Asterisk (*)</b> — required for BIR compliance</span>
      <span class="noprint" style="margin-left:auto; cursor:pointer;" onclick="window.print()"><b style="color:var(--accent-soft);">🖨️ Print</b></span>
    </div>

    <div class="head">
      <div>
        <div class="brand-mark">PC</div>
        <div class="company-name"><?= sanitize(getSetting('company_name') ?: 'PCIMS') ?></div>
        <div class="company-meta">
          <span><?= sanitize(getSetting('company_address') ?: 'Company Address') ?></span><br>
          <span><?= sanitize(getSetting('company_phone') ?: 'Phone') ?></span> &nbsp;·&nbsp; <span><?= sanitize(getSetting('company_email') ?: 'Email') ?></span><br>
          VAT Reg. TIN: <span><?= sanitize(getSetting('company_tin') ?: '000-000-000-00000') ?></span>
        </div>
      </div>
      <div class="doc-title">
        <h1>Sales Invoice</h1>
        <div class="sub">Original — Customer's Copy</div>
      </div>
    </div>

    <div class="meta-strip">
      <div class="meta-cell">
        <div class="meta-label req">Invoice No.</div>
        <div class="meta-value"><?= htmlspecialchars($sale['invoice_no']) ?></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label req">Date</div>
        <div class="meta-value"><?= date('Y-m-d', strtotime($sale['created_at'])) ?></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Due Date</div>
        <div class="meta-value"><?= $sale['due_date'] ? date('Y-m-d', strtotime($sale['due_date'])) : 'N/A' ?></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Payment Terms</div>
        <div class="meta-value"><?= htmlspecialchars(ucfirst($sale['payment_method'])) ?></div>
      </div>
    </div>

    <div class="bir">
      <div class="item"><b>ATP / Acknowledgement Cert. No.</b><span>AC_000_000000_000000</span></div>
      <div class="item"><b>Date Issued</b><span><?= date('Y-m-d', strtotime($sale['created_at'])) ?></span></div>
      <div class="item"><b>Series Range</b><span>SI0000000001 – SI0000999999</span></div>
    </div>

    <div class="parties">
      <div>
        <div class="party-label">Sold To</div>
        <div class="party-row"><span class="k">Name</span><span><?= htmlspecialchars($sale['dealer_name'] ?? 'Walk-in') ?></span></div>
        <div class="party-row"><span class="k">Dealer ID</span><span><?= htmlspecialchars($sale['dealer_code'] ?? 'N/A') ?></span></div>
        <div class="party-row"><span class="k">Address</span><span><?= htmlspecialchars($sale['dealer_address'] ?? 'N/A') ?></span></div>
      </div>
    </div>

    <div class="repstrip">
      <div class="meta-cell">
        <div class="meta-label">Salesperson</div>
        <div class="meta-value"><?= htmlspecialchars($sale['cashier'] ?? 'Unknown') ?></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Encoded By</div>
        <div class="meta-value"><?= htmlspecialchars($sale['cashier'] ?? 'Unknown') ?></div>
      </div>
      <div class="meta-cell">
        <div class="meta-label">Timestamp</div>
        <div class="meta-value"><?= date('Y-m-d H:i', strtotime($sale['created_at'])) ?></div>
      </div>
    </div>

    <table class="items">
      <thead>
        <tr>
          <th style="width:12%">Code</th>
          <th>Description</th>
          <th style="width:6%">Qty</th>
          <th class="num" style="width:10%">Price</th>
          <th class="num" style="width:10%">Gross</th>
          <th class="num" style="width:9%">Discount</th>
          <th class="num" style="width:10%">NBD</th>
          <th class="num" style="width:10%">Other Disc.</th>
          <th class="num" style="width:11%">Net Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $sumQty = 0;
        $sumGross = 0;
        $sumDiscount = 0;
        $sumNBD = 0;
        $sumOtherDisc = 0;
        $sumNet = 0;
        foreach ($items as $item):
          $gross = $item['quantity'] * $item['unit_price'];
          $discount = $gross * 0.25;
          $nbd = $gross - $discount;
          $otherDisc = (float)$item['discount']; 
          $net = $nbd - $otherDisc;

          $sumQty += $item['quantity'];
          $sumGross += $gross;
          $sumDiscount += $discount;
          $sumNBD += $nbd;
          $sumOtherDisc += $otherDisc;
          $sumNet += $net;
        ?>
          <tr>
            <td><?= htmlspecialchars($item['sku'] ?? '') ?></td>
            <td><?= htmlspecialchars($item['product_name']) ?></td>
            <td><?= $item['quantity'] ?></td>
            <td class="num"><?= number_format($item['unit_price'], 2) ?></td>
            <td class="num"><?= number_format($gross, 2) ?></td>
            <td class="num"><?= number_format($discount, 2) ?></td>
            <td class="num"><?= number_format($nbd, 2) ?></td>
            <td class="num"><?= number_format($otherDisc, 2) ?></td>
            <td class="num"><?= number_format($net, 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php for ($i = 0; $i < max(0, 5 - count($items)); $i++): ?>
          <tr>
            <td>&nbsp;</td>
            <td></td>
            <td></td>
            <td class="num"></td>
            <td class="num"></td>
            <td class="num"></td>
            <td class="num"></td>
            <td class="num"></td>
            <td class="num"></td>
          </tr>
        <?php endfor; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight: 700; background: var(--wash); border-top: 1px solid var(--ink); font-size: 12.5px;">
          <td colspan="2" style="text-align: right; text-transform: uppercase; font-size: 10.5px; letter-spacing: .08em; padding: 10px 12px;">Totals</td>
          <td style="padding: 10px 12px; border-bottom: 1px solid var(--line);"><?= $sumQty ?></td>
          <td class="num" style="padding: 10px 12px; border-bottom: 1px solid var(--line);"></td>
          <td class="num" style="padding: 10px 12px; border-bottom: 1px solid var(--line);"><?= number_format($sumGross, 2) ?></td>
          <td class="num" style="padding: 10px 12px; border-bottom: 1px solid var(--line);"><?= number_format($sumDiscount, 2) ?></td>
          <td class="num" style="padding: 10px 12px; border-bottom: 1px solid var(--line);"><?= number_format($sumNBD, 2) ?></td>
          <td class="num" style="padding: 10px 12px; border-bottom: 1px solid var(--line);"><?= number_format($sumOtherDisc, 2) ?></td>
          <td class="num" style="padding: 10px 12px; border-bottom: 1px solid var(--line);"><?= number_format($sumNet, 2) ?></td>
        </tr>
      </tfoot>
    </table>

    <div class="bottom">
      <div class="signoff">
        <div class="clause">
          By signing below, the customer acknowledges receipt of the above goods/services in good order and condition, and agrees to the company's terms and conditions.
        </div>
        <div class="sigline">Customer / Dealer Signature</div>
        <div class="sigline">Checked &amp; Released By</div>
        <div class="sigline">Approved By</div>
        <?php if ($actualNotes): ?>
          <div style="margin-top: 20px; padding: 10px; border: 1px dashed var(--line); font-size: 11px;">
            <strong>Notes:</strong><br>
            <?= nl2br(htmlspecialchars($actualNotes)) ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="totals">
        <div class="trow sub"><span>Subtotal</span><span class="v"><?= number_format($totalGross, 2) ?></span></div>
        <div class="trow sub"><span>Total Discount</span><span class="v">-<?= number_format($totalDiscount, 2) ?></span></div>

        <?php foreach ($discountBreakdown as $line): ?>
          <div class="trow sub" style="padding-left:10px; font-size:10px; color:#888;">
            <span><?= htmlspecialchars(trim($line)) ?></span><span class="v"></span>
          </div>
        <?php endforeach; ?>

        <div class="trow sub"><span>Vatable Sales</span><span class="v"><?= number_format($netOfVat, 2) ?></span></div>
        <div class="trow sub"><span>Zero-Rated Sales</span><span class="v">0.00</span></div>
        <div class="trow sub"><span>VAT-Exempt Sales</span><span class="v">0.00</span></div>
        <div class="trow sub"><span>Amount Net of VAT</span><span class="v"><?= number_format($netOfVat, 2) ?></span></div>
        <div class="trow sub"><span>VAT Amount (12%)</span><span class="v"><?= number_format($taxAmount, 2) ?></span></div>
        <div class="trow grand"><span>Total Amount Due</span><span class="v">₱ <?= number_format($netAmount, 2) ?></span></div>
        <?php if ($sale['payment_method'] === 'cash&credit' || $sale['payment_method'] === 'cash'): ?>
          <div class="trow sub" style="margin-top:8px"><span>Cash Received</span><span class="v"><?= number_format($sale['cash_received'], 2) ?></span></div>
          <div class="trow change"><span>Change</span><span class="v"><?= number_format(max(0, $sale['cash_received'] - $netAmount), 2) ?></span></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (in_array($sale['payment_method'], ['credit', 'cash&credit'])):
      $creditUsed = $sale['total'] - $sale['cash_received'];
      $prevBalance = max(0, $sale['credit_balance'] - $creditUsed);
      $availBalance = max(0, $sale['credit_limit'] - $sale['credit_balance']);
    ?>
      <div class="credit">
        <b>Credit tracking</b>
        <div class="credit-grid">
          <div>Total Credit Limit<br><span><?= number_format($sale['credit_limit'] ?? 0, 2) ?></span></div>
          <div>Previous Balance<br><span><?= number_format($prevBalance, 2) ?></span></div>
          <div>This Invoice Balance<br><span><?= number_format($creditUsed, 2) ?></span></div>
          <div>Available Balance<br><span><?= number_format($availBalance, 2) ?></span></div>
        </div>
      </div>
    <?php endif; ?>



  </div>

</body>

</html>