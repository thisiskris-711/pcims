/**
 * Point of Sale — Cart & Checkout Logic
 */
let cart = [];
let posProducts = [];
let posCategory = '';

document.addEventListener('DOMContentLoaded', () => {
    loadPOSProducts();
    
    const searchInput = document.getElementById('posSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => loadPOSProducts(), 300));
    }
});

async function loadPOSProducts() {
    const search = document.getElementById('posSearch')?.value || '';
    const params = new URLSearchParams({
        search,
        category: posCategory,
        status: 'active',
        per_page: 100,
    });
    
    try {
        const data = await apiRequest(`/api/products.php?${params}`);
        posProducts = data.data || [];
        renderPOSProducts();
    } catch (e) {
        showToast('Failed to load products', 'error');
    }
}

function renderPOSProducts() {
    const grid = document.getElementById('posProductGrid');
    
    if (posProducts.length === 0) {
        grid.innerHTML = '<div class="text-center text-muted" style="grid-column:1/-1;padding:40px;"><i data-lucide="search-x" style="width:48px;height:48px;opacity:0.3;display:block;margin:0 auto 12px;"></i>No products found</div>';
        lucide.createIcons({ nodes: [grid] });
        return;
    }
    
    grid.innerHTML = posProducts.map(p => {
        const outOfStock = p.quantity <= 0;
        const imgHtml = p.image 
            ? `<img src="${APP_URL}/uploads/products/${p.image}" alt="">`
            : `<i data-lucide="package" style="width:28px;height:28px;"></i>`;
        
        return `
        <div class="pos-product-card ${outOfStock ? 'out-of-stock' : ''}" 
             onclick="${outOfStock ? '' : `addToCart(${p.id})`}" 
             style="${outOfStock ? 'opacity:0.5;cursor:not-allowed;' : ''}"
             title="${escapeHtml(p.name)}">
            <div class="product-img">${imgHtml}</div>
            <div class="product-name">${escapeHtml(p.name)}</div>
            <div class="product-price">${formatCurrency(p.selling_price)}</div>
            <div class="product-stock">${outOfStock ? 'Out of stock' : `Stock: ${p.quantity}`}</div>
        </div>`;
    }).join('');
    
    lucide.createIcons({ nodes: [grid] });
}

function filterPOSCategory(btn, catId) {
    document.querySelectorAll('.pos-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    posCategory = catId;
    loadPOSProducts();
}

function addToCart(productId) {
    const product = posProducts.find(p => p.id == productId);
    if (!product || product.quantity <= 0) return;
    
    const existing = cart.find(item => item.product_id == productId);
    
    if (existing) {
        if (existing.quantity >= product.quantity) {
            showToast(`Max stock reached for ${product.name}`, 'warning');
            return;
        }
        existing.quantity++;
    } else {
        cart.push({
            product_id: product.id,
            name: product.name,
            unit_price: parseFloat(product.selling_price),
            quantity: 1,
            max_stock: product.quantity,
            discount: 0,
        });
    }
    
    renderCart();
}

function renderCart() {
    const container = document.getElementById('cartItems');
    const countEl = document.getElementById('cartCount');
    const checkoutBtn = document.getElementById('checkoutBtn');
    
    const totalItems = cart.reduce((sum, item) => sum + item.quantity, 0);
    countEl.textContent = totalItems;
    checkoutBtn.disabled = cart.length === 0;
    
    if (cart.length === 0) {
        container.innerHTML = `
            <div class="cart-empty">
                <i data-lucide="shopping-bag" style="width:48px;height:48px;"></i>
                <p style="margin-top:8px;">Your cart is empty</p>
                <p style="font-size:0.78rem;">Click products to add them</p>
            </div>`;
        lucide.createIcons({ nodes: [container] });
        updateCartTotals();
        return;
    }
    
    container.innerHTML = cart.map((item, idx) => `
        <div class="cart-item">
            <div class="cart-item-info">
                <div class="cart-item-name">${escapeHtml(item.name)}</div>
                <div class="cart-item-price">${formatCurrency(item.unit_price)} each</div>
            </div>
            <div class="cart-item-qty">
                <button onclick="updateCartQty(${idx}, -1)">−</button>
                <span>${item.quantity}</span>
                <button onclick="updateCartQty(${idx}, 1)">+</button>
            </div>
            <div class="cart-item-total">${formatCurrency(item.unit_price * item.quantity)}</div>
            <button class="cart-item-remove" onclick="removeFromCart(${idx})">
                <i data-lucide="x" style="width:14px;height:14px;"></i>
            </button>
        </div>
    `).join('');
    
    lucide.createIcons({ nodes: [container] });
    updateCartTotals();
}

function updateCartQty(index, delta) {
    const item = cart[index];
    if (!item) return;
    
    const newQty = item.quantity + delta;
    
    if (newQty <= 0) {
        removeFromCart(index);
        return;
    }
    
    if (newQty > item.max_stock) {
        showToast('Maximum stock reached', 'warning');
        return;
    }
    
    item.quantity = newQty;
    renderCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    renderCart();
}

function clearCart() {
    if (cart.length === 0) return;
    if (!confirm('Clear all items from cart?')) return;
    cart = [];
    renderCart();
}

function updateCartTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
    const discount = parseFloat(document.getElementById('orderDiscount')?.value || 0);
    const taxable = subtotal - discount;
    const tax = taxable * (window.TAX_RATE / 100);
    const total = taxable + tax;
    
    document.getElementById('cartSubtotal').textContent = formatCurrency(subtotal);
    document.getElementById('cartDiscount').textContent = '-' + formatCurrency(discount);
    document.getElementById('cartTax').textContent = formatCurrency(tax);
    document.getElementById('cartTotal').textContent = formatCurrency(total);
    
    const checkoutTotal = document.getElementById('checkoutTotal');
    if (checkoutTotal) checkoutTotal.textContent = formatCurrency(total);
}

function openCheckoutModal() {
    if (cart.length === 0) return;
    updateCartTotals();
    openModal('checkoutModal');
}

async function processPayment() {
    if (cart.length === 0) return;
    
    const btn = document.getElementById('processPaymentBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px;"></span> Processing...';
    
    const body = JSON.stringify({
        items: cart.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            unit_price: item.unit_price,
            discount: item.discount,
        })),
        customer_name: document.getElementById('customerName').value || 'Walk-in Customer',
        discount: parseFloat(document.getElementById('orderDiscount').value || 0),
        payment_method: document.getElementById('paymentMethod').value,
        notes: document.getElementById('checkoutNotes').value,
    });
    
    try {
        const result = await apiRequest('/api/sales.php', { method: 'POST', body });
        
        closeModal('checkoutModal');
        showReceipt(result);
        cart = [];
        renderCart();
        loadPOSProducts(); // Refresh stock counts
        showToast(`Sale completed! Invoice: ${result.invoice_no}`, 'success');
    } catch (e) {
        showToast(e.message || 'Payment failed', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i data-lucide="check-circle" style="width:16px;height:16px;"></i> Process Payment';
        lucide.createIcons({ nodes: [btn] });
    }
}

async function showReceipt(saleResult) {
    try {
        const sale = await apiRequest(`/api/sales.php?action=detail&id=${saleResult.sale_id}`);
        
        const itemsHtml = (sale.items || []).map(item => `
            <tr>
                <td style="text-align:left;">${item.product_name}</td>
                <td style="text-align:center;">${item.quantity}</td>
                <td style="text-align:right;">$${parseFloat(item.unit_price).toFixed(2)}</td>
                <td style="text-align:right;">$${parseFloat(item.total).toFixed(2)}</td>
            </tr>
        `).join('');
        
        document.getElementById('receiptContent').innerHTML = `
            <div class="receipt" id="receiptPrint">
                <div class="receipt-header">
                    <h3>InventoryPro</h3>
                    <p>Invoice: ${sale.invoice_no}</p>
                    <p>${new Date(sale.created_at).toLocaleString()}</p>
                </div>
                <p><strong>Customer:</strong> ${escapeHtml(sale.customer_name)}</p>
                <p><strong>Cashier:</strong> ${escapeHtml(sale.cashier || 'N/A')}</p>
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:left;">Item</th>
                            <th style="text-align:center;">Qty</th>
                            <th style="text-align:right;">Price</th>
                            <th style="text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
                <div style="border-top:1px dashed #ccc;padding-top:8px;margin-top:8px;">
                    <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>$${parseFloat(sale.subtotal).toFixed(2)}</span></div>
                    ${parseFloat(sale.discount) > 0 ? `<div style="display:flex;justify-content:space-between;"><span>Discount</span><span>-$${parseFloat(sale.discount).toFixed(2)}</span></div>` : ''}
                    <div style="display:flex;justify-content:space-between;"><span>Tax</span><span>$${parseFloat(sale.tax).toFixed(2)}</span></div>
                    <div class="receipt-total" style="display:flex;justify-content:space-between;padding-top:8px;margin-top:8px;">
                        <span>TOTAL</span><span>$${parseFloat(sale.total).toFixed(2)}</span>
                    </div>
                </div>
                <div class="receipt-footer">
                    <p>Payment: ${sale.payment_method.toUpperCase()}</p>
                    <p>Thank you for your purchase!</p>
                </div>
            </div>
        `;
        
        openModal('receiptModal');
    } catch (e) {
        console.error('Failed to load receipt:', e);
    }
}

function printReceipt() {
    const content = document.getElementById('receiptPrint');
    if (!content) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html><head><title>Receipt</title>
        <style>
            body { font-family: 'Courier New', monospace; font-size: 13px; margin: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 4px 0; }
            .receipt-header { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px; }
            .receipt-total { border-top: 1px dashed #000; font-weight: bold; font-size: 15px; }
            .receipt-footer { text-align: center; border-top: 1px dashed #000; margin-top: 10px; padding-top: 10px; font-size: 11px; }
        </style>
        </head><body>${content.innerHTML}</body></html>
    `);
    printWindow.document.close();
    printWindow.print();
}
