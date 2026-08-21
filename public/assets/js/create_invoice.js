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

    // Intercept navigation links
    let intendedUrl = null;
    
    document.body.addEventListener('click', (e) => {
        // Find closest anchor tag
        const link = e.target.closest('a');
        if (!link) return;
        
        const href = link.getAttribute('href');
        
        // Ignore links that don't navigate or navigate in new tab
        if (!href || href === '#' || href.startsWith('javascript:') || link.target === '_blank') return;
        
        // Ignore links within the POS itself if any
        if (href === window.location.pathname || href === window.location.href) return;

        if (cart.length > 0) {
            e.preventDefault();
            intendedUrl = href;
            openModal('posExitModal');
        }
    });

    const confirmPosExitBtn = document.getElementById('confirmPosExitBtn');
    if (confirmPosExitBtn) {
        confirmPosExitBtn.addEventListener('click', () => {
            cart = [];
            if (intendedUrl) {
                window.location.href = intendedUrl;
            }
        });
    }

    // Browser fallback for closing tab/refreshing
    window.addEventListener('beforeunload', (e) => {
        if (cart.length > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
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
        const lowStock = p.quantity > 0 && p.quantity <= 5;
        const imgHtml = p.image 
            ? `<img src="${APP_URL}/uploads/products/${p.image}" alt="">`
            : `<i data-lucide="package" style="width:28px;height:28px;"></i>`;
        
        return `
        <div class="pos-product-card ${outOfStock ? 'out-of-stock' : ''} ${lowStock ? 'low-stock' : ''}" 
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
                <p style="font-size:0.78rem;">Select a product to add it to the invoice.</p>
            </div>`;
        lucide.createIcons({ nodes: [container] });
        updateCartTotals();
        hideRecommendations();
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
    updateRecommendations();
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
    let subtotal = 0;
    let promoDiscount = 0;

    cart.forEach(item => {
        subtotal += item.unit_price * item.quantity;
        
        
        
        // Calculate dynamic promotions
        if (window.ACTIVE_PROMOS) {
            // Group cart by product ID
            const cartProductQty = {};
            cart.forEach(cItem => {
                cartProductQty[cItem.product_id] = (cartProductQty[cItem.product_id] || 0) + cItem.quantity;
            });

            window.ACTIVE_PROMOS.forEach(promo => {
                if (promo.type === 'category_discount') {
                    const config = typeof promo.config === 'string' ? JSON.parse(promo.config) : promo.config;
                    if (!config) return;

                    const rule = config.rule;
                    const buyQty = parseInt(config.buy_qty) || 0;
                    const getQty = parseInt(config.get_qty) || 0;
                    const promoPrice = parseFloat(config.promo_price) || 0;
                    const buyTarget = config.buy_target || '';
                    
                    if (rule === 'buy_x_get_y' && buyTarget.startsWith('product_')) {
                        const pId = parseInt(buyTarget.split('_')[1]);
                        const cartQty = cartProductQty[pId] || 0;
                        if (cartQty > 0 && buyQty + getQty > 0) {
                            const sets = Math.floor(cartQty / (buyQty + getQty));
                            if (sets > 0) {
                                // Find unit price
                                const cartItem = cart.find(i => parseInt(i.product_id) === pId);
                                if (cartItem) {
                                    const regularPriceForGetItems = getQty * cartItem.unit_price;
                                    const promoPriceForGetItems = getQty * promoPrice;
                                    const savings = regularPriceForGetItems - promoPriceForGetItems;
                                    if (savings > 0) promoDiscount += savings * sets;
                                }
                            }
                        }
                    } else if (rule === 'buy_any_x_for_y' && buyTarget.startsWith('category_')) {
                        // Complex category discount. Estimate simplified for frontend or leave to backend
                    }
                }
            });
        }
    });

    const discountedSubtotal = subtotal - promoDiscount;
    const dealerDiscount = discountedSubtotal * 0.25;
    const totalDiscount = promoDiscount + dealerDiscount;
    const total = subtotal - totalDiscount;
    
    // Update checkout modal input
    const discountInput = document.getElementById('orderDiscount');
    if (discountInput) {
        discountInput.value = totalDiscount.toFixed(2);
    }
    
    document.getElementById('cartSubtotal').textContent = formatCurrency(subtotal);
    
    // Show promo breakdown if applicable
    const discountEl = document.getElementById('cartDiscount');
    if (promoDiscount > 0) {
        discountEl.innerHTML = `-<br><span style="font-size:0.7rem">Promo: ${formatCurrency(promoDiscount)}<br>Dealer: ${formatCurrency(dealerDiscount)}</span>`;
    } else {
        discountEl.textContent = '-' + formatCurrency(dealerDiscount);
    }
    document.getElementById('cartTotal').textContent = formatCurrency(total);
    
    const checkoutTotal = document.getElementById('checkoutTotal');
    if (checkoutTotal) checkoutTotal.textContent = formatCurrency(total);
    
    const invoiceModalSubtotal = document.getElementById('invoiceModalSubtotal');
    if (invoiceModalSubtotal) invoiceModalSubtotal.textContent = formatCurrency(subtotal);

    const invoiceModalDiscount = document.getElementById('invoiceModalDiscount');
    if (invoiceModalDiscount) invoiceModalDiscount.textContent = '-' + formatCurrency(totalDiscount);
    
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
    const paymentMethod = document.getElementById('paymentMethod')?.value || 'cash';
    const warning = document.getElementById('creditWarning');
    if (!warning) return;

    if ((paymentStatus === 'credit' || paymentMethod === 'credit' || paymentMethod === 'cash&credit') && selectedDealer) {
        const available = selectedDealer.credit_limit - selectedDealer.credit_balance;
        const subtotal = cart.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
        const discount = parseFloat(document.getElementById('orderDiscount')?.value || 0);
        const taxable = subtotal - discount;
        const tax = taxable * (window.TAX_RATE / 100);
        const total = taxable + tax;
        
        let amountToCharge = total;
        if (paymentMethod === 'cash&credit') {
            const cashReceived = parseFloat(document.getElementById('cashReceived')?.value || 0);
            amountToCharge = Math.max(0, total - cashReceived);
        }

        if (amountToCharge > 0 && amountToCharge > available) {
            warning.style.display = 'block';
            warning.innerHTML = `<i data-lucide="alert-triangle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Credit portion (${formatCurrency(amountToCharge)}) exceeds available credit (${formatCurrency(available)}). This sale will be <strong>blocked</strong>.`;
            lucide.createIcons({ nodes: [warning] });
        } else if (amountToCharge > 0) {
            warning.style.display = 'block';
            warning.style.color = 'var(--success-color)';
            warning.style.background = 'var(--success-color)15';
            warning.style.borderColor = 'var(--success-color)33';
            warning.innerHTML = `<i data-lucide="check-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Credit available: ${formatCurrency(available)}. ${formatCurrency(amountToCharge)} will be charged to the dealer's account.`;
            lucide.createIcons({ nodes: [warning] });
        } else {
            warning.style.display = 'none';
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
    document.getElementById('paymentMethod').value = 'cash';
    const statusEl = document.getElementById('paymentStatus');
    if (statusEl) statusEl.value = 'paid';
    document.getElementById('cashReceived').value = '';
    document.getElementById('creditWarning').style.display = 'none';
    document.getElementById('orderDiscount').value = '0';
    document.getElementById('checkoutNotes').value = '';
    
    if(typeof onPaymentMethodChange === 'function') onPaymentMethodChange();

    // Populate the invoice items table
    const tbody = document.getElementById('invoiceModalItemsBody');
    if (tbody) {
        tbody.innerHTML = cart.map(item => `
            <tr>
                <td style="padding: 12px 15px; border-bottom: 1px solid var(--border-color);">
                    <div style="font-weight: 500;">${escapeHtml(item.name)}</div>
                </td>
                <td style="padding: 12px 15px; border-bottom: 1px solid var(--border-color); text-align: center;">${item.quantity}</td>
                <td style="padding: 12px 15px; border-bottom: 1px solid var(--border-color); text-align: right;">${formatCurrency(item.unit_price)}</td>
                <td style="padding: 12px 15px; border-bottom: 1px solid var(--border-color); text-align: right; font-weight: 500;">${formatCurrency(item.unit_price * item.quantity)}</td>
            </tr>
        `).join('');
    }

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
        cash_received: parseFloat(document.getElementById('cashReceived').value || 0),
        payment_status: document.getElementById('paymentStatus')?.value || 'paid',
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

function showReceipt(saleResult) {
    // Open the A4 invoice in a new printable tab
    window.open(`${APP_URL}/invoice_print?id=${saleResult.sale_id}`, '_blank');
}

function printReceipt() {
    // Legacy function, replaced by auto-print in the new invoice tab
}

function onPaymentMethodChange() {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const cashInputRow = document.getElementById('cashInputRow');
    const changeLabel = document.getElementById('changeLabel');
    const cashReceived = document.getElementById('cashReceived');
    
    if (paymentMethod === 'cash') {
        cashInputRow.style.display = 'flex';
        changeLabel.textContent = 'Change';
        cashReceived.disabled = false;
    } else if (paymentMethod === 'credit') {
        cashInputRow.style.display = 'none';
        cashReceived.value = '';
    } else if (paymentMethod === 'cash&credit') {
        cashInputRow.style.display = 'flex';
        changeLabel.textContent = 'Credit Portion';
        cashReceived.disabled = false;
    }
    
    calculateChange();
}

function calculateChange() {
    const paymentMethod = document.getElementById('paymentMethod').value;
    const cashReceivedInput = document.getElementById('cashReceived').value;
    const cashReceived = parseFloat(cashReceivedInput) || 0;
    const changeAmountField = document.getElementById('changeAmount');
    
    const subtotal = cart.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
    const discount = parseFloat(document.getElementById('orderDiscount')?.value || 0);
    const taxable = subtotal - discount;
    const tax = taxable * (window.TAX_RATE / 100) || 0;
    const total = taxable + tax;
    
    if (paymentMethod === 'cash') {
        const change = Math.max(0, cashReceived - total);
        changeAmountField.value = formatCurrency(change);
        changeAmountField.style.color = 'var(--success-color)';
    } else if (paymentMethod === 'cash&credit') {
        const remaining = Math.max(0, total - cashReceived);
        changeAmountField.value = formatCurrency(remaining);
        changeAmountField.style.color = 'var(--warning-color)';
    } else {
        changeAmountField.value = formatCurrency(0);
    }
    
    onPaymentStatusChange();
}

// ── Smart Recommendations Engine ──
let recDebounceTimer = null;

function hideRecommendations() {
    const container = document.getElementById('posRecommendations');
    if (container) container.style.display = 'none';
}

function updateRecommendations() {
    // Debounce to avoid hammering the API during rapid cart changes
    if (recDebounceTimer) clearTimeout(recDebounceTimer);
    recDebounceTimer = setTimeout(async () => {
        if (cart.length === 0) {
            hideRecommendations();
            return;
        }

        const subtotal = cart.reduce((sum, item) => sum + (item.unit_price * item.quantity), 0);
        const params = new URLSearchParams({
            action: 'recommendations',
            cart_subtotal: subtotal.toFixed(2),
        });
        
        cart.forEach(item => {
            params.append('cart_product_ids[]', item.product_id);
            params.append(`cart_qty[${item.product_id}]`, item.quantity);
        });

        try {
            const data = await apiRequest(`/api/products?${params}`);
            renderRecommendations(data);
        } catch (e) {
            // Silently fail — recommendations are non-critical
            console.warn('Recommendations failed:', e);
        }
    }, 500);
}

function renderRecommendations(data) {
    const container = document.getElementById('posRecommendations');
    if (!container) return;

    const { recommendations = [], bundles = [], promotions = [] } = data;
    const hasContent = recommendations.length > 0 || bundles.length > 0 || promotions.length > 0;

    if (!hasContent) {
        container.style.display = 'none';
        return;
    }

    let html = '';

    // 1. Active Promotions
    if (promotions.length > 0) {
        html += `<div class="pos-rec-header"><i data-lucide="tag" style="width:14px;height:14px;"></i> Promotions</div>`;
        promotions.forEach(promo => {
            const qualifiedClass = promo.qualified ? 'qualified' : '';
            let progressHtml = '';
            if (promo.progress !== undefined) {
                progressHtml = `<div class="pos-promo-progress"><div class="pos-promo-progress-bar" style="width:${(promo.progress * 100).toFixed(0)}%"></div></div>`;
            }
            
            let imageHtml = '';
            if (promo.image_url) {
                imageHtml = `<img src="${window.APP_URL}/uploads/products/${escapeHtml(promo.image_url)}" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-right:8px;border:1px solid var(--border-color);" alt="">`;
            } else if (promo.product_id) {
                imageHtml = `<img src="${window.APP_URL}/assets/images/placeholder.jpg" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-right:8px;border:1px solid var(--border-color);" alt="">`;
            }
            
            html += `<div class="pos-promo-badge ${qualifiedClass}" style="display:flex;align-items:center;">
                ${imageHtml}
                <div style="flex:1;">
                    <span class="pos-promo-label">${escapeHtml(promo.label)}</span>
                    <div class="pos-promo-desc">
                        ${escapeHtml(promo.description)}
                        ${progressHtml}
                    </div>
                </div>
            </div>`;
        });
    }

    // 2. Bundle Suggestions
    if (bundles.length > 0) {
        html += `<div class="pos-rec-header" style="margin-top:8px;"><i data-lucide="package" style="width:14px;height:14px;"></i> Bundle & Save</div>`;
        bundles.forEach(bundle => {
            const itemNames = bundle.products.map(p => escapeHtml(p.name)).join(', ');
            const missingIds = bundle.missing_products.map(p => p.product_id);
            html += `<div class="pos-bundle-card">
                <div class="pos-bundle-card-header">
                    <span class="pos-bundle-card-title">${escapeHtml(bundle.name)}</span>
                    <span class="pos-bundle-card-savings">Save ${formatCurrency(bundle.savings)}</span>
                </div>
                <div class="pos-bundle-card-items">${itemNames}</div>
                <div class="pos-bundle-card-prices">
                    <span class="regular">${formatCurrency(bundle.regular_price)}</span>
                    <span class="bundle">${formatCurrency(bundle.bundle_price)}</span>
                </div>
                <button class="pos-rec-add-btn" onclick="addBundleMissing([${missingIds.join(',')}])">
                    <i data-lucide="plus" style="width:12px;height:12px;"></i> Complete Bundle
                </button>
            </div>`;
        });
    }

    // 3. Product Recommendations
    if (recommendations.length > 0) {
        html += `<div class="pos-rec-header" style="margin-top:8px;"><i data-lucide="sparkles" style="width:14px;height:14px;"></i> Recommended for this Sale</div>`;
        recommendations.forEach(rec => {
            const imgHtml = rec.image
                ? `<img src="${APP_URL}/uploads/products/${rec.image}" alt="">`
                : `<i data-lucide="package" style="width:18px;height:18px;"></i>`;
            html += `<div class="pos-rec-card">
                <div class="pos-rec-card-img">${imgHtml}</div>
                <div class="pos-rec-card-info">
                    <div class="pos-rec-card-name" title="${escapeHtml(rec.name)}">${escapeHtml(rec.name)}</div>
                    <div class="pos-rec-card-reason">${escapeHtml(rec.reason || '')}</div>
                </div>
                <div class="pos-rec-card-price">${formatCurrency(rec.selling_price)}</div>
                <button class="pos-rec-add-btn" onclick="addToCart(${rec.product_id})">+ Add</button>
            </div>`;
        });
    }

    container.innerHTML = html;
    container.style.display = 'block';
    lucide.createIcons({ nodes: [container] });
}

function addBundleMissing(productIds) {
    productIds.forEach(id => addToCart(id));
}
