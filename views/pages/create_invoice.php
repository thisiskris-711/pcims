<?php

/**
 * Create Invoice Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('create_sales');
$db = getDB();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$taxRate = (float) getSetting('tax_rate', '12');

$pageTitle = 'Create Invoice';
$currentPage = 'create_invoice';
$pageScripts = ['create_invoice.js'];

// Load active promotions for sync frontend cart calculation
$activePromos = $db->query("
    SELECT * FROM promotions 
    WHERE is_active = 1 
      AND (start_date IS NULL OR start_date <= CURDATE())
      AND (end_date IS NULL OR end_date >= CURDATE())
")->fetchAll();

// Load active bundles
$bundlesStmt = $db->query("
    SELECT p.id as bundle_id, p.name as bundle_name, p.selling_price as bundle_price, p.image,
           pbi.product_id, pbi.quantity as required_qty
    FROM products p
    JOIN product_bundle_items pbi ON p.id = pbi.bundle_id
    WHERE p.type = 'bundle' AND p.status = 'active'
");
$bundleRows = $bundlesStmt->fetchAll();
$activeBundles = [];
foreach ($bundleRows as $row) {
    if (!isset($activeBundles[$row['bundle_id']])) {
        $activeBundles[$row['bundle_id']] = [
            'bundle_id' => $row['bundle_id'],
            'name' => $row['bundle_name'],
            'bundle_price' => (float)$row['bundle_price'],
            'image' => $row['image'],
            'items' => []
        ];
    }
    $activeBundles[$row['bundle_id']]['items'][] = [
        'product_id' => (int)$row['product_id'],
        'required_qty' => (int)$row['required_qty']
    ];
}
$activeBundlesList = array_values($activeBundles);

// Also inject 'bundle_deal' promotions as ACTIVE_BUNDLES so they share the same logic
foreach ($activePromos as $promo) {
    if ($promo['type'] === 'bundle_deal') {
        $config = json_decode($promo['config'], true);
        if ($config && !empty($config['components'])) {
            $items = [];
            foreach ($config['components'] as $comp) {
                if (str_starts_with($comp['target'], 'product_')) {
                    $items[] = [
                        'product_id' => (int)str_replace('product_', '', $comp['target']),
                        'required_qty' => (int)$comp['qty']
                    ];
                }
            }
            if (!empty($items)) {
                $activeBundlesList[] = [
                    'bundle_id' => 'promo_' . $promo['id'],
                    'name' => $promo['name'],
                    'bundle_price' => (float)$config['bundle_price'],
                    'image' => null,
                    'items' => $items
                ];
            }
        }
    }
}

include dirname(__DIR__) . '/layouts/header.php';
?>

<script>
    window.ACTIVE_PROMOS = <?= json_encode($activePromos) ?>;
    window.ACTIVE_BUNDLES = <?= json_encode($activeBundlesList) ?>;
</script>

<div class="pos-layout">
    <!-- Products Panel -->
    <div class="pos-products">
        <div class="toolbar" style="margin-bottom:12px;">
            <div class="toolbar-left" style="flex:1;">
                <div class="search-bar" style="max-width:100%;">
                    <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
                    <label for="posSearch" style="display:none;">Search products</label>
                    <input type="text" class="form-control" id="posSearch" placeholder="Search products by name, SKU, or barcode...">
                </div>
            </div>
        </div>

        <!-- Category Tabs -->
        <div class="pos-categories-container">
            <button class="btn btn-sm btn-ghost pos-cat-btn active" data-cat="" onclick="filterPOSCategory(this, '')">All</button>
            <?php foreach ($categories as $cat): ?>
                <button class="btn btn-sm btn-ghost pos-cat-btn" data-cat="<?= $cat['id'] ?>" onclick="filterPOSCategory(this, '<?= $cat['id'] ?>')">
                    <?= sanitize($cat['name']) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="pos-product-grid" id="posProductGrid">
            <div class="text-center text-muted" style="grid-column:1/-1;padding:40px;">Loading products...</div>
        </div>
    </div>

    <!-- Cart Panel -->
    <div class="pos-cart">
        <div class="pos-cart-header">
            <h3><i data-lucide="shopping-cart" style="width:20px;height:20px;vertical-align:middle;margin-right:6px;"></i> Cart</h3>
            <span class="cart-count" id="cartCount">0</span>
        </div>

        <div class="pos-cart-scrollable" style="flex: 1; overflow-y: auto;">
            <div class="pos-cart-items" id="cartItems" style="flex: none; overflow: visible;">
                <div class="cart-empty">
                    <i data-lucide="shopping-bag" style="width:48px;height:48px;"></i>
                    <p style="margin-top:8px;">Your cart is empty</p>
                    <p style="font-size:0.78rem;">Select a product to add it to the invoice.</p>
                </div>
            </div>

            <!-- Smart Recommendations -->
            <div class="pos-recommendations" id="posRecommendations" style="display:none;"></div>
        </div>

        <div class="pos-cart-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="cartSubtotal">₱0.00</span>
            </div>
            <div class="summary-row discount">
                <span>Dealer Discount (25%)</span>
                <span id="cartDiscount">-₱0.00</span>
            </div>

            <div class="summary-row total">
                <span>Total</span>
                <span id="cartTotal">₱0.00</span>
            </div>
        </div>

        <div class="pos-cart-actions">
            <button class="btn btn-secondary" onclick="clearCart()">
                <i data-lucide="trash" style="width:16px;height:16px;"></i> Clear
            </button>
            <button class="btn btn-primary" id="checkoutBtn" onclick="openCheckoutModal()" disabled>
                <i data-lucide="file-plus" style="width:16px;height:16px;"></i> Create Invoice
            </button>
        </div>
    </div>
</div>

<!-- Checkout Modal (Invoice Form) -->
<div class="modal invoice-modal" id="checkoutModal" style="max-width: 900px; width: 95%; display: flex; flex-direction: column; max-height: 95vh; overflow: hidden;">
    <div class="modal-header" style="border-bottom:none; padding: 15px 15px 0; flex-shrink: 0;">
        <button class="modal-close" onclick="closeModal('checkoutModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" style="padding: 15px 30px 30px; overflow-y: auto; flex: 1;">
        <!-- Invoice Header -->
        <div style="display: flex; justify-content: space-between; margin-bottom: 20px; border-bottom: 2px solid var(--border-color); padding-bottom: 20px; flex-wrap: wrap; gap: 20px;">
            <div>
                <h2 style="color:var(--primary-color); margin:0 0 5px 0;">Personal Collection Direct Selling, Inc.</h2>
                <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">GF BALI ARCADE MANDANGOA 9005 BALINGASAG MISAMIS ORIENTAL PHILIPPINES</p>
            </div>
            <div style="text-align: right; min-width: 300px;">
                <h1 style="color:var(--text-muted); font-size: 2.2rem; margin:0 0 15px 0; text-transform:uppercase; letter-spacing: 2px; opacity:0.5;">Invoice</h1>
                <div style="display:flex; gap:10px; align-items:center; justify-content:flex-end; margin-bottom:8px;">
                    <span style="color:var(--text-muted);font-size:0.9rem; font-weight: 500; width: 60px;">Date:</span>
                    <input type="date" id="invoiceDate" name="invoiceDate" class="form-control" style="width:180px; padding:6px 10px; font-size:0.9rem;" value="<?= date('Y-m-d') ?>" readonly>
                </div>
                <div style="display:flex; gap:10px; align-items:center; justify-content:flex-end;">
                    <span style="color:var(--text-muted);font-size:0.9rem; font-weight: 500; width: 60px;">Method:</span>
                    <select class="form-control" id="paymentMethod" onchange="onPaymentMethodChange()" style="width:180px; padding:6px 10px; font-size:0.9rem;">
                        <option value="cash">Cash</option>
                        <option value="credit">Credit</option>
                        <option value="cash&credit">Cash & Credit</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Bill To Section -->
        <div style="background: var(--bg-secondary); padding: 15px 20px; border-radius: 8px; margin-bottom: 25px; border: 1px solid var(--border-color);">
            <h4 style="margin:0 0 10px 0; font-size:0.9rem; text-transform:uppercase; font-weight:600; letter-spacing: 1px;">Bill To</h4>
            <div class="form-group" style="margin-bottom: 0;">
                <div style="position:relative;" id="dealerSelectWrapper">
                    <input type="text" class="form-control" id="dealerSearchInput" placeholder="Search dealer by name or code..." autocomplete="off" style="padding: 8px 12px;">
                    <input type="hidden" id="selectedDealerId" value="">
                    <div class="dealer-dropdown" id="dealerDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:var(--border-radius-sm);max-height:200px;overflow-y:auto;z-index:100;margin-top:4px;"></div>
                </div>
                <div id="selectedDealerInfo" style="display:none; margin-top:12px; font-size:0.95rem; line-height: 1.5; background: var(--bg-card); padding: 10px 15px; border-radius: 6px; border: 1px solid var(--border-color);">
                </div>
                <div id="creditWarning" style="display:none;background:var(--warning-color)15;border:1px solid var(--warning-color)33;color:var(--warning-color);padding:10px 14px;border-radius:var(--border-radius-sm);font-size:0.85rem;margin-top:12px;">
                </div>
            </div>
        </div>

        <!-- Items Table -->
        <div style="border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden; margin-bottom: 25px;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.95rem;">
                <thead style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
                    <tr>
                        <th style="padding: 12px 15px; font-weight: 600; text-transform: uppercase; font-size: 0.85rem;">Item Description</th>
                        <th style="padding: 12px 15px; font-weight: 600; text-align: center; width: 80px; text-transform: uppercase; font-size: 0.85rem;">Qty</th>
                        <th style="padding: 12px 15px; font-weight: 600; text-align: right; width: 120px; text-transform: uppercase; font-size: 0.85rem;">Unit Price</th>
                        <th style="padding: 12px 15px; font-weight: 600; text-align: right; width: 140px; text-transform: uppercase; font-size: 0.85rem;">Line Total</th>
                    </tr>
                </thead>
                <tbody id="invoiceModalItemsBody">
                    <!-- JS fills this -->
                </tbody>
            </table>
        </div>

        <div style="display:flex; justify-content:space-between; gap:30px; align-items: flex-start; flex-wrap: wrap;">
            <!-- Notes & Cash -->
            <div style="flex:1; min-width: 300px;">
                <h4 style="margin:0 0 10px 0; font-size:0.9rem; text-transform:uppercase; font-weight:600; letter-spacing: 1px;">Notes / Terms</h4>
                <textarea class="form-control" id="checkoutNotes" rows="3" placeholder="Thank you for your business!"></textarea>

                <div id="cashInputRow" style="margin-top:20px; display:flex; gap:15px; align-items:center; background:var(--bg-secondary); padding:15px 20px; border-radius:8px; border: 1px solid var(--border-color);">
                    <div style="flex:1;">
                        <label for="cashReceived" class="form-label" style="font-size:0.85rem; font-weight:600; margin-bottom: 5px; display: block;">Cash Received</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="cashReceived" placeholder="0.00" oninput="calculateChange()" style="padding: 8px 12px;">
                    </div>
                    <div style="flex:1;">
                        <label for="changeAmount" class="form-label" style="font-size:0.85rem; font-weight:600; margin-bottom: 5px; display: block;" id="changeLabel">Change</label>
                        <input type="text" class="form-control" id="changeAmount" value="₱0.00" readonly style="font-weight:bold; color:var(--success-color); padding: 8px 12px;">
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div style="width: 340px; background: var(--bg-secondary); padding: 25px; border-radius: 8px; border: 1px solid var(--border-color);">
                <div style="display:flex; justify-content:space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color); font-size: 1rem;">
                    <span>Subtotal</span>
                    <span id="invoiceModalSubtotal" style="font-weight:500;">₱0.00</span>
                </div>
                <div style="display:flex; justify-content:space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color); font-size: 1rem;">
                    <span>Dealer Discount (25%)</span>
                    <span id="invoiceModalDiscount" style="color:var(--danger-color); font-weight:500;">-₱0.00</span>
                    <input type="hidden" id="orderDiscount" value="0.00">
                </div>
                <div style="display:flex; justify-content:space-between; padding-top: 20px; font-size: 1.5rem; font-weight: bold; color: var(--primary-color);">
                    <span>Total</span>
                    <span id="checkoutTotal">₱0.00</span>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer" style="background:var(--bg-card); border-top:1px solid var(--border-color); padding: 15px 25px; flex-shrink: 0; z-index: 10;">
        <button class="btn btn-secondary" onclick="closeModal('checkoutModal')">Cancel</button>
        <button class="btn btn-success" id="processPaymentBtn" onclick="processPayment()">
            <i data-lucide="file-check" style="width:16px;height:16px;"></i> Generate Invoice
        </button>
    </div>
</div>

<!-- POS Exit Confirmation Modal -->
<div class="modal" id="posExitModal" data-backdrop="static">
    <div class="modal-header">
        <h3 class="modal-title" style="color: var(--danger-color);"><i data-lucide="alert-triangle" style="width:20px;height:20px;margin-right:8px;vertical-align:text-bottom;"></i> Unsaved Transaction</h3>
        <button class="modal-close" onclick="closeModal('posExitModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <p>You have items in your cart. Are you sure you want to navigate away?</p>
        <p class="text-muted" style="margin-top: 10px; font-size: 0.9rem;">Leaving this page will clear your cart.</p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('posExitModal')">Continue Editing</button>
        <button class="btn btn-danger" id="confirmPosExitBtn">Cancel Transaction</button>
    </div>
</div>

<script>
    window.TAX_RATE = <?= $taxRate ?>;
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>