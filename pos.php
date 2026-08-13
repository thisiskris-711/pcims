<?php
/**
 * Point of Sale Page
 */
require_once __DIR__ . '/config/app.php';
requireLogin();

$db = getDB();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$taxRate = (float) getSetting('tax_rate', '12');

$pageTitle = 'Point of Sale';
$currentPage = 'pos';
$pageScripts = ['pos.js'];
include __DIR__ . '/includes/header.php';
?>

<div class="pos-layout">
    <!-- Products Panel -->
    <div class="pos-products">
        <div class="toolbar" style="margin-bottom:12px;">
            <div class="toolbar-left" style="flex:1;">
                <div class="search-bar" style="max-width:100%;">
                    <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
                    <input type="text" class="form-control" id="posSearch" placeholder="Search products by name, SKU, or barcode...">
                </div>
            </div>
        </div>
        
        <!-- Category Tabs -->
        <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;">
            <button class="btn btn-sm btn-secondary pos-cat-btn active" data-cat="" onclick="filterPOSCategory(this, '')">All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="btn btn-sm btn-ghost pos-cat-btn" data-cat="<?= $cat['id'] ?>" onclick="filterPOSCategory(this, '<?= $cat['id'] ?>')" 
                    style="border:1px solid <?= sanitize($cat['color']) ?>33;color:<?= sanitize($cat['color']) ?>;">
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
        
        <div class="pos-cart-items" id="cartItems">
            <div class="cart-empty">
                <i data-lucide="shopping-bag" style="width:48px;height:48px;"></i>
                <p style="margin-top:8px;">Your cart is empty</p>
                <p style="font-size:0.78rem;">Click products to add them</p>
            </div>
        </div>
        
        <div class="pos-cart-summary">
            <div class="summary-row">
                <span>Subtotal</span>
                <span id="cartSubtotal">$0.00</span>
            </div>
            <div class="summary-row">
                <span>Discount</span>
                <span id="cartDiscount">-$0.00</span>
            </div>
            <div class="summary-row">
                <span>Tax (<?= $taxRate ?>%)</span>
                <span id="cartTax">$0.00</span>
            </div>
            <div class="summary-row total">
                <span>Total</span>
                <span id="cartTotal">$0.00</span>
            </div>
        </div>
        
        <div class="pos-cart-actions">
            <button class="btn btn-secondary" onclick="clearCart()">
                <i data-lucide="trash" style="width:16px;height:16px;"></i> Clear
            </button>
            <button class="btn btn-primary" id="checkoutBtn" onclick="openCheckoutModal()" disabled>
                <i data-lucide="credit-card" style="width:16px;height:16px;"></i> Checkout
            </button>
        </div>
    </div>
</div>

<!-- Checkout Modal -->
<div class="modal" id="checkoutModal">
    <div class="modal-header">
        <h3 class="modal-title">Checkout</h3>
        <button class="modal-close" onclick="closeModal('checkoutModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <div class="form-group">
            <label class="form-label">Customer Name</label>
            <input type="text" class="form-control" id="customerName" value="Walk-in Customer" placeholder="Customer name">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <select class="form-control" id="paymentMethod">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="transfer">Transfer</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Order Discount ($)</label>
                <input type="number" class="form-control" id="orderDiscount" value="0" min="0" step="0.01" onchange="updateCartTotals()">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea class="form-control" id="checkoutNotes" rows="2" placeholder="Optional notes"></textarea>
        </div>
        
        <div style="background:var(--bg-tertiary);border-radius:var(--border-radius-sm);padding:16px;margin-top:16px;">
            <div class="summary-row total" style="border:none;margin:0;padding:0;">
                <span>Total to Pay</span>
                <span id="checkoutTotal" style="font-size:1.4rem;">$0.00</span>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('checkoutModal')">Cancel</button>
        <button class="btn btn-success" id="processPaymentBtn" onclick="processPayment()">
            <i data-lucide="check-circle" style="width:16px;height:16px;"></i> Process Payment
        </button>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal modal-lg" id="receiptModal">
    <div class="modal-header">
        <h3 class="modal-title">Sale Complete!</h3>
        <button class="modal-close" onclick="closeModal('receiptModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <div id="receiptContent"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('receiptModal')">Close</button>
        <button class="btn btn-primary" onclick="printReceipt()">
            <i data-lucide="printer" style="width:16px;height:16px;"></i> Print Receipt
        </button>
    </div>
</div>

<script>
    window.TAX_RATE = <?= $taxRate ?>;
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
