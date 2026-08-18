/**
 * Point of Sale — Cart & Checkout Logic
 */
let cart = [];
let posProducts = [];
let posCategory = '';
let selectedDealer = null;

document.addEventListener('DOMContentLoaded', () => {
    loadPOSProducts();
    
    const searchInput = document.getElementById('posSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => loadPOSProducts(), 300));
    }

    // Dealer search in checkout modal
    const dealerSearch = document.getElementById('dealerSearchInput');
    if (dealerSearch) {
        dealerSearch.addEventListener('input', debounce(() => searchDealers(), 300));
        dealerSearch.addEventListener('focus', () => searchDealers());
        
        // Close dropdown on click outside
        document.addEventListener('click', (e) => {
            if (!document.getElementById('dealerSelectWrapper')?.contains(e.target)) {
                document.getElementById('dealerDropdown').style.display = 'none';
            }
        });
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
        const data = await apiRequest(`/api/products?${params}`);
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

    // Update credit warning if applicable
    onPaymentStatusChange();
}

// ── Dealer Search/Select ──
async function searchDealers() {
    const q = document.getElementById('dealerSearchInput')?.value || '';
    const dropdown = document.getElementById('dealerDropdown');

    try {
        const data = await apiRequest(`/api/dealers?action=search&q=${encodeURIComponent(q)}`);
        const dealers = data.data || [];

        if (dealers.length === 0) {
            dropdown.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:0.85rem;">No dealers found</div>';
        } else {
            dropdown.innerHTML = dealers.map(d => {
                const available = parseFloat(d.credit_limit) - parseFloat(d.credit_balance);
                return `<div class="dealer-option" onclick="selectDealer(${d.id}, '${escapeHtml(d.name).replace(/'/g, "\\'")}', '${d.dealer_code}', ${d.credit_limit}, ${d.credit_balance})" 
                         style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);transition:background 0.15s;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <div style="font-weight:500;">${escapeHtml(d.name)}</div>
                            <div style="font-size:0.78rem;color:var(--text-muted);">${d.dealer_code} · ${escapeHtml(d.contact_person || '')}</div>
                        </div>
                        <div style="text-align:right;font-size:0.82rem;">
                            <div style="color:var(--success-color);">Avail: ${formatCurrency(available)}</div>
                        </div>
                    </div>
                </div>`;
            }).join('');
        }
        dropdown.style.display = 'block';
    } catch (e) {
        dropdown.innerHTML = '<div style="padding:12px;color:var(--error-color);">Error loading dealers</div>';
        dropdown.style.display = 'block';
    }
}

function selectDealer(id, name, code, creditLimit, creditBalance) {
    selectedDealer = { id, name, code, credit_limit: creditLimit, credit_balance: creditBalance };
    document.getElementById('selectedDealerId').value = id;
    document.getElementById('dealerSearchInput').value = `${name} (${code})`;
    document.getElementById('dealerDropdown').style.display = 'none';

    const available = creditLimit - creditBalance;
    document.getElementById('selectedDealerInfo').style.display = 'block';
    document.getElementById('selectedDealerInfo').innerHTML = `
        <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
            <span style="color:var(--text-muted);">Credit Limit: <strong>${formatCurrency(creditLimit)}</strong></span>
            <span style="color:var(--text-muted);">Outstanding: <strong style="color:var(--warning-color);">${formatCurrency(creditBalance)}</strong></span>
            <span style="color:var(--success-color);">Available: <strong>${formatCurrency(available)}</strong></span>
        </div>
    `;

    onPaymentStatusChange();
}

function onPaymentStatusChange() {
    const paymentStatus = document.getElementById('paymentStatus')?.value || 'paid';
    const warning = document.getElementById('creditWarning');
    if (!warning) return;

    if (paymentStatus === 'credit' && selectedDealer) {
        const available = selectedDealer.credit_limit - selectedDealer.credit_balance;
        const subtotal = cart.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
        const discount = parseFloat(document.getElementById('orderDiscount')?.value || 0);
        const taxable = subtotal - discount;
        const tax = taxable * (window.TAX_RATE / 100);
        const total = taxable + tax;

        if (total > available) {
            warning.style.display = 'block';
            warning.innerHTML = `<i data-lucide="alert-triangle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Sale total (${formatCurrency(total)}) exceeds available credit (${formatCurrency(available)}). This sale will be <strong>blocked</strong>.`;
            lucide.createIcons({ nodes: [warning] });
        } else {
            warning.style.display = 'block';
            warning.style.color = 'var(--success-color)';
            warning.style.background = 'var(--success-color)15';
            warning.style.borderColor = 'var(--success-color)33';
            warning.innerHTML = `<i data-lucide="check-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Credit available: ${formatCurrency(available)}. This sale will be charged to the dealer's account.`;
            lucide.createIcons({ nodes: [warning] });
        }
    } else {
        warning.style.display = 'none';
    }
}

function openCheckoutModal() {
    if (cart.length === 0) return;

    // Reset dealer selection
    selectedDealer = null;
    document.getElementById('selectedDealerId').value = '';
    document.getElementById('dealerSearchInput').value = '';
    document.getElementById('selectedDealerInfo').style.display = 'none';
    document.getElementById('dealerDropdown').style.display = 'none';
    document.getElementById('paymentStatus').value = 'paid';
    document.getElementById('creditWarning').style.display = 'none';
    document.getElementById('orderDiscount').value = '0';
    document.getElementById('checkoutNotes').value = '';

    updateCartTotals();
    openModal('checkoutModal');
}

async function processPayment() {
    if (cart.length === 0) return;

    const dealerId = document.getElementById('selectedDealerId').value;
    if (!dealerId) {
        showToast('Please select a registered dealer', 'error');
        return;
    }
    
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
        dealer_id: parseInt(dealerId),
        discount: parseFloat(document.getElementById('orderDiscount').value || 0),
        payment_method: document.getElementById('paymentMethod').value,
        payment_status: document.getElementById('paymentStatus').value,
        notes: document.getElementById('checkoutNotes').value,
    });
    
    try {
        const result = await apiRequest('/api/sales', { method: 'POST', body });
        
        closeModal('checkoutModal');
        showReceipt(result);
        cart = [];
        selectedDealer = null;
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
        const sale = await apiRequest(`/api/sales?action=detail&id=${saleResult.sale_id}`);
        
        const itemsHtml = (sale.items || []).map(item => {
            let html = `
            <tr>
                <td style="text-align:left; font-weight: 500;">${item.product_name}</td>
                <td style="text-align:center;">${item.quantity}</td>
                <td style="text-align:right;">₱${parseFloat(item.unit_price).toFixed(2)}</td>
                <td style="text-align:right;">₱${parseFloat(item.total).toFixed(2)}</td>
            </tr>`;
            
            if (item.components && item.components.length > 0) {
                html += item.components.map(c => `
                <tr>
                    <td style="text-align:left; padding-left: 15px; font-size: 0.85em; color: #666; padding-top: 0; padding-bottom: 4px;">↳ ${c.quantity * item.quantity}x ${c.name}</td>
                    <td colspan="3"></td>
                </tr>
                `).join('');
            }
            return html;
        }).join('');

        const paymentStatusLabel = sale.payment_status === 'credit' ? '<span style="color:var(--warning-color);font-weight:600;">CREDIT</span>' : 'PAID';
        
        document.getElementById('receiptContent').innerHTML = `
            <div class="receipt" id="receiptPrint">
                <div class="receipt-header">
                    <h3>${window.APP_NAME || 'PCIMS'}</h3>
                    <p>Invoice: ${sale.invoice_no}</p>
                    <p>${new Date(sale.created_at).toLocaleString()}</p>
                </div>
                <p><strong>Dealer:</strong> ${escapeHtml(sale.dealer_name || 'N/A')} (${escapeHtml(sale.dealer_code || '')})</p>
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
                    <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>₱${parseFloat(sale.subtotal).toFixed(2)}</span></div>
                    ${parseFloat(sale.discount) > 0 ? `<div style="display:flex;justify-content:space-between;"><span>Discount</span><span>-₱${parseFloat(sale.discount).toFixed(2)}</span></div>` : ''}
                    <div style="display:flex;justify-content:space-between;"><span>Tax</span><span>₱${parseFloat(sale.tax).toFixed(2)}</span></div>
                    <div class="receipt-total" style="display:flex;justify-content:space-between;padding-top:8px;margin-top:8px;">
                        <span>TOTAL</span><span>₱${parseFloat(sale.total).toFixed(2)}</span>
                    </div>
                </div>
                <div class="receipt-footer">
                    <p>Payment: ${sale.payment_method.toUpperCase()} | Status: ${paymentStatusLabel}</p>
                    <p>Thank you for your business!</p>
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
