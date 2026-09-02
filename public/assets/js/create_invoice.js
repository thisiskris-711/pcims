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
        const isBundle = p.type === 'bundle';
        const imgHtml = p.image 
            ? `<img src="${APP_URL}/uploads/products/${p.image}" alt="">`
            : `<i data-lucide="${isBundle ? 'layers' : 'package'}" style="width:28px;height:28px;"></i>`;
        
        let bundleHtml = '';
        if (isBundle && p.components && p.components.length > 0) {
            const items = p.components.map(c => `${c.required_qty}x ${escapeHtml(c.product_name)}`).join(', ');
            bundleHtml = `<div class="bundle-components" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px; line-height: 1.2;">Includes: ${items}</div>`;
        }

        return `
        <div class="pos-product-card ${outOfStock ? 'out-of-stock' : ''} ${lowStock ? 'low-stock' : ''} ${isBundle ? 'is-bundle' : ''}" 
             onclick="${outOfStock ? '' : `addToCart(${p.id})`}" 
             style="${outOfStock ? 'opacity:0.5;cursor:not-allowed;' : ''}; position: relative; ${isBundle ? 'border: 1px solid var(--accent-primary); background: #f8faff;' : ''}"
             title="${escapeHtml(p.name)}">
            ${isBundle ? `<div class="pos-product-bundle-badge" style="position:absolute;top:8px;left:8px;background:var(--accent-primary);color:white;padding:2px 6px;border-radius:4px;font-size:0.7rem;font-weight:600;z-index:2;"><i data-lucide="layers" style="width:10px;height:10px;margin-right:2px;display:inline-block;"></i> Bundle</div>` : ''}
            ${p.active_promo ? `<div class="pos-product-promo-badge" title="${escapeHtml(p.promo_desc)}" style="position:absolute;top:8px;right:8px;z-index:2;"><i data-lucide="tag" style="width:10px;height:10px;margin-right:3px;"></i> Promo</div>` : ''}
            <div class="product-img">${imgHtml}</div>
            <div class="product-name">${escapeHtml(p.name)}</div>
            ${bundleHtml}
            <div class="product-price" style="margin-top:auto;">${formatCurrency(p.selling_price)}</div>
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
    let product = posProducts.find(p => p.id == productId);
    if (!product && window.RECOMMENDED_PRODUCTS) {
        const rec = window.RECOMMENDED_PRODUCTS.find(p => (p.id || p.product_id) == productId);
        if (rec) {
            product = {
                id: rec.id || rec.product_id,
                name: rec.name,
                selling_price: rec.selling_price,
                quantity: rec.stock !== undefined ? rec.stock : rec.quantity,
                active_promo: rec.active_promo,
                promo_desc: rec.promo_desc
            };
        }
    }
    
    if (!product || product.quantity <= 0) {
        if (product && product.quantity <= 0) {
            if(typeof showToast === 'function') showToast('This item is out of stock', 'error');
        }
        return;
    }
    
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
            active_promo: product.active_promo || null,
            promo_desc: product.promo_desc || null,
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
            <div class="cart-item-top">
                <div class="cart-item-name" title="${escapeHtml(item.name)}">
                    ${escapeHtml(item.name)}
                    ${item.active_promo ? `<span style="background:var(--accent-rose);color:white;font-size:0.6rem;padding:2px 4px;border-radius:4px;margin-left:4px;vertical-align:middle;cursor:help;" title="${escapeHtml(item.promo_desc || item.active_promo)}">Promo</span>` : ''}
                </div>
                <button class="cart-item-remove" onclick="removeFromCart(${idx})" title="Remove item">
                    <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                </button>
            </div>
            <div class="cart-item-bottom">
                <div class="cart-item-price">${formatCurrency(item.unit_price)}</div>
                <div class="cart-item-qty">
                    <button onclick="updateCartQty(${idx}, -1)">−</button>
                    <input type="number" id="cart-qty-${idx}" name="cart-qty-${idx}" value="${item.quantity}" min="1" max="${item.max_stock}" onchange="setCartQty(${idx}, this.value)" style="width: 45px; text-align: center; border: 1px solid var(--border-color); border-radius: 4px; padding: 2px; -moz-appearance: textfield;">
                    <button onclick="updateCartQty(${idx}, 1)">+</button>
                </div>
                <div class="cart-item-total">${formatCurrency(item.unit_price * item.quantity)}</div>
            </div>
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

function setCartQty(index, value) {
    const item = cart[index];
    if (!item) return;
    
    let newQty = parseInt(value, 10);
    
    if (isNaN(newQty) || newQty <= 0) {
        removeFromCart(index);
        return;
    }
    
    if (newQty > item.max_stock) {
        showToast('Maximum stock reached', 'warning');
        newQty = item.max_stock;
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
    });
        
    // Group cart by product ID for promo and bundle evaluations
    const cartProductQty = {};
    cart.forEach(cItem => {
        cartProductQty[cItem.product_id] = (cartProductQty[cItem.product_id] || 0) + cItem.quantity;
    });

    // Calculate bundle discounts first (highest priority)
    if (window.ACTIVE_BUNDLES) {
        window.ACTIVE_BUNDLES.forEach(bundle => {
            // Find how many full sets of this bundle we can make
            let possibleSets = Infinity;
            
            bundle.items.forEach(comp => {
                const avail = cartProductQty[comp.product_id] || 0;
                const sets = Math.floor(avail / comp.required_qty);
                if (sets < possibleSets) {
                    possibleSets = sets;
                }
            });
            
            if (possibleSets > 0 && possibleSets !== Infinity) {
                // We have complete bundle(s)!
                let regularComponentTotal = 0;
                bundle.items.forEach(comp => {
                    const cartItem = cart.find(i => parseInt(i.product_id) === comp.product_id);
                    if (cartItem) {
                        regularComponentTotal += cartItem.unit_price * comp.required_qty;
                    }
                    // Deduct used quantities from the pool so they aren't double-discounted
                    cartProductQty[comp.product_id] -= comp.required_qty * possibleSets;
                });
                
                const bundleSavings = regularComponentTotal - bundle.bundle_price;
                if (bundleSavings >= 0) {
                    promoDiscount += bundleSavings * possibleSets;
                }
            }
        });
    }

    // Calculate dynamic promotions with remaining quantities
    if (window.ACTIVE_PROMOS) {
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

    const discountedSubtotal = subtotal - promoDiscount;
    const dealerDiscount = selectedDealer ? (discountedSubtotal * 0.25) : 0;
    const totalDiscount = promoDiscount + dealerDiscount;
    const total = subtotal - totalDiscount;

    const netOfVat = total / 1.12;
    const tax = total - netOfVat;
    
    // Update checkout modal input
    const discountInput = document.getElementById('orderDiscount');
    if (discountInput) {
        discountInput.value = totalDiscount.toFixed(2);
    }
    const taxInput = document.getElementById('orderTax');
    if (taxInput) {
        taxInput.value = tax.toFixed(2);
    }
    const invoiceModalTax = document.getElementById('invoiceModalTax');
    if (invoiceModalTax) {
        invoiceModalTax.textContent = formatCurrency(tax);
    }
    
    document.getElementById('cartSubtotal').textContent = formatCurrency(subtotal);
    
    // Show promo breakdown if applicable
    const promoDiscountRow = document.getElementById('promoDiscountRow');
    if (promoDiscountRow) {
        if (promoDiscount > 0) {
            promoDiscountRow.style.display = 'flex';
            document.getElementById('cartPromoDiscount').textContent = '-' + formatCurrency(promoDiscount);
        } else {
            promoDiscountRow.style.display = 'none';
        }
    }
    
    const cartDealerDiscount = document.getElementById('cartDealerDiscount');
    if (cartDealerDiscount) {
        cartDealerDiscount.textContent = '-' + formatCurrency(dealerDiscount);
    }
    document.getElementById('cartTotal').textContent = formatCurrency(total);
    
    const checkoutTotal = document.getElementById('checkoutTotal');
    if (checkoutTotal) checkoutTotal.textContent = formatCurrency(total);
    
    const invoiceModalSubtotal = document.getElementById('invoiceModalSubtotal');
    if (invoiceModalSubtotal) invoiceModalSubtotal.textContent = formatCurrency(subtotal);

    const invoiceModalPromoRow = document.getElementById('invoiceModalPromoRow');
    if (invoiceModalPromoRow) {
        if (promoDiscount > 0) {
            invoiceModalPromoRow.style.display = 'flex';
            document.getElementById('invoiceModalPromoDiscount').textContent = '-' + formatCurrency(promoDiscount);
        } else {
            invoiceModalPromoRow.style.display = 'none';
        }
    }

    const invoiceModalDiscount = document.getElementById('invoiceModalDiscount');
    if (invoiceModalDiscount) invoiceModalDiscount.textContent = '-' + formatCurrency(dealerDiscount);
    
    const cartDiscount = document.getElementById('cartDiscount');
    if (cartDiscount) {
        cartDiscount.textContent = '-' + formatCurrency(dealerDiscount);
    }
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
        <div style="font-weight:600; font-size:1rem; margin-bottom:8px; color:var(--text-primary);">
            ${escapeHtml(name)} <span style="color:var(--text-muted); font-size:0.85rem; font-weight:normal; margin-left:6px;">Code: ${escapeHtml(code)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.85rem;">
            <span style="color:var(--text-muted);">Credit Limit: <strong>${formatCurrency(creditLimit)}</strong></span>
            <span style="color:var(--text-muted);">Outstanding: <strong style="color:var(--warning-color);">${formatCurrency(creditBalance)}</strong></span>
            <span style="color:var(--success-color);">Available: <strong>${formatCurrency(available)}</strong></span>
        </div>
    `;

    onPaymentStatusChange();
    updateCartTotals();
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
    document.getElementById('invoiceNo').value = 'Auto-generated';
    
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
        due_date: document.getElementById('dueDate')?.value || '',
        invoice_date: document.getElementById('invoiceDate')?.value || '',
    });
    
    try {
        const result = await apiRequest('/api/sales', { method: 'POST', body });
        
        closeModal('checkoutModal');
        cart = [];
        selectedDealer = null;
        renderCart();
        loadPOSProducts(); // Refresh stock counts
        showToast(`Sale submitted for approval! Ref: ${result.invoice_no}`, 'success');
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
    const dueDateWrapper = document.getElementById('dueDateWrapper');
    
    if (paymentMethod === 'cash') {
        cashInputRow.style.display = 'flex';
        changeLabel.textContent = 'Change';
        cashReceived.disabled = false;
        if (dueDateWrapper) dueDateWrapper.style.display = 'none';
    } else if (paymentMethod === 'credit') {
        cashInputRow.style.display = 'none';
        cashReceived.value = '';
        if (dueDateWrapper) dueDateWrapper.style.display = 'flex';
    } else if (paymentMethod === 'cash&credit') {
        cashInputRow.style.display = 'flex';
        changeLabel.textContent = 'Credit Portion';
        cashReceived.disabled = false;
        if (dueDateWrapper) dueDateWrapper.style.display = 'flex';
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
    window.RECOMMENDED_PRODUCTS = [...recommendations, ...bundles];
    
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

            let buttonHtml = '';
            if (!promo.qualified && promo.missing_products && promo.missing_products.length > 0) {
                const missingJson = JSON.stringify(promo.missing_products).replace(/'/g, "&#39;").replace(/"/g, "&quot;");
                buttonHtml = `<button class="pos-rec-add-btn" style="border-color:var(--accent-primary); color:var(--accent-primary); margin-left: 8px; flex-shrink: 0;" onclick="addBundleMissing(${missingJson})">+ Add Promo</button>`;
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
                ${buttonHtml}
            </div>`;
        });
    }

    // 2. Bundle Suggestions
    if (bundles.length > 0) {
        bundles.forEach(bundle => {
            let itemsListHtml = '';
            bundle.products.forEach(p => {
                if (p.missing_qty > 0) {
                    if (p.cart_qty > 0) {
                        itemsListHtml += `<div style="color:var(--accent-emerald);font-size:0.75rem;margin-bottom:2px;display:flex;align-items:center;gap:4px;"><i data-lucide="check" style="width:10px;height:10px;"></i> ${p.cart_qty}x ${escapeHtml(p.name)}</div>`;
                    }
                    itemsListHtml += `<div style="color:var(--text-muted);font-size:0.75rem;margin-bottom:2px;display:flex;align-items:center;gap:4px;"><i data-lucide="plus" style="width:10px;height:10px;"></i> ${p.missing_qty}x ${escapeHtml(p.name)}</div>`;
                } else {
                    itemsListHtml += `<div style="color:var(--accent-emerald);font-size:0.75rem;margin-bottom:2px;display:flex;align-items:center;gap:4px;"><i data-lucide="check" style="width:10px;height:10px;"></i> ${p.required_qty}x ${escapeHtml(p.name)}</div>`;
                }
            });

            if (!bundle.qualified) {
                const missingJson = JSON.stringify(bundle.missing_products.map(p => ({
                    id: p.product_id,
                    qty: p.missing_qty,
                    price: p.selling_price,
                    stock: p.stock,
                    name: p.name
                }))).replace(/'/g, "&#39;").replace(/"/g, "&quot;");

                html += `<div class="pos-promo-badge" style="display:flex; flex-direction:column; gap:6px; border-left:3px solid var(--accent-cyan); padding:8px 10px; background:var(--bg-tertiary); margin-bottom:6px; border-radius:4px;">
                    <div>
                        <span class="pos-promo-label" style="background:var(--accent-cyan); color:white;">BUNDLE AVAILABLE</span>
                        <div class="pos-promo-desc" style="margin-top:6px; font-weight:600; font-size:0.8rem; color:var(--text-primary);">Complete this bundle and save ${formatCurrency(bundle.savings)}</div>
                    </div>
                    <div style="padding-left:2px; margin: 4px 0;">
                        ${itemsListHtml}
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; border-top:1px dashed var(--border-color); padding-top:6px;">
                        <div style="font-size:0.75rem; display:flex; flex-direction:column;">
                            <span style="color:var(--accent-emerald); font-weight:bold; font-size:0.85rem;">Bundle Price: ${formatCurrency(bundle.bundle_price)}</span>
                            <span style="text-decoration:line-through; color:var(--text-muted);">Regular: ${formatCurrency(bundle.regular_price)}</span>
                        </div>
                        <button class="pos-rec-add-btn" style="border-color:var(--accent-cyan); color:var(--accent-cyan);" onclick="addBundleMissing(${missingJson})">+ Add Bundle</button>
                    </div>
                </div>`;
            } else {
                html += `<div class="pos-promo-badge qualified" style="display:flex; flex-direction:column; gap:6px; border-left:3px solid var(--accent-emerald); padding:8px 10px; background:rgba(16,185,129,0.05); margin-bottom:6px; border-radius:4px;">
                    <div>
                        <span class="pos-promo-label" style="background:var(--accent-emerald); color:white;">BUNDLE APPLIED</span>
                        <div class="pos-promo-desc" style="margin-top:6px; font-weight:600; font-size:0.8rem; color:var(--text-primary);"><i data-lucide="layers" style="width:12px;height:12px;margin-right:4px;"></i> ${escapeHtml(bundle.name)}</div>
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px;">
                        <span style="font-size:0.85rem; font-weight:bold; color:var(--text-primary);">Bundle Price: ${formatCurrency(bundle.bundle_price)}</span>
                        <span style="font-size:0.85rem; font-weight:bold; color:var(--accent-emerald);">You Save: ${formatCurrency(bundle.savings)}</span>
                    </div>
                </div>`;
            }
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
                    <div class="pos-rec-card-price" style="font-size:0.75rem;color:var(--text-muted);">${formatCurrency(rec.selling_price)}</div>
                </div>
                <button class="pos-rec-add-btn" onclick="addToCart(${rec.product_id})">+ Add</button>
            </div>`;
        });
    }

    container.innerHTML = html;
    container.style.display = 'block';
    lucide.createIcons({ nodes: [container] });
}

function addBundleMissing(items) {
    if (!items || items.length === 0) return;
    
    let added = false;
    items.forEach(item => {
        const id = item.id;
        const qtyToAdd = item.qty;
        const sellingPrice = item.price;
        const maxStock = item.stock;
        const name = item.name;
        
        const existing = cart.find(c => c.product_id == id);
        if (existing) {
            if (existing.quantity + qtyToAdd <= maxStock) {
                existing.quantity += qtyToAdd;
                added = true;
            } else if (existing.quantity < maxStock) {
                existing.quantity = maxStock;
                added = true;
            }
        } else {
            const addQty = Math.min(qtyToAdd, maxStock);
            if (addQty > 0) {
                cart.push({
                    product_id: id,
                    name: name,
                    unit_price: sellingPrice,
                    quantity: addQty,
                    max_stock: maxStock,
                    discount: 0,
                    active_promo: null,
                    promo_desc: null,
                });
                added = true;
            }
        }
    });
    
    if (added) {
        renderCart();
        showToast('Bundle components added to cart', 'success');
    }
}


