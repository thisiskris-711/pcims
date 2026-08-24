/**
 * Purchase Order Form Logic
 */
let poItems = [];

document.addEventListener('DOMContentLoaded', () => {
    // Supplier Search
    const supplierSearch = document.getElementById('supplierSearchInput');
    if (supplierSearch) {
        supplierSearch.addEventListener('input', debounce(() => searchSuppliers(), 300));
        supplierSearch.addEventListener('focus', () => searchSuppliers());
    }

    // Product Search
    const productSearch = document.getElementById('productSearchInput');
    if (productSearch) {
        productSearch.addEventListener('input', debounce(() => searchProducts(), 300));
        productSearch.addEventListener('focus', () => searchProducts());
    }

    // Close dropdowns on click outside
    document.addEventListener('click', (e) => {
        if (!document.getElementById('supplierSelectWrapper')?.contains(e.target)) {
            document.getElementById('supplierDropdown').style.display = 'none';
        }
        if (!document.getElementById('productSelectWrapper')?.contains(e.target)) {
            document.getElementById('productDropdown').style.display = 'none';
        }
    });
    
    // Auto-load product from URL (Predictive Analysis)
    const urlParams = new URLSearchParams(window.location.search);
    const sku = urlParams.get('sku');
    const qty = urlParams.get('qty');
    if (sku) {
        apiRequest(`/api/products?search=${encodeURIComponent(sku)}`).then(data => {
            if (data.data && data.data.length > 0) {
                const p = data.data[0];
                if (p.sku === sku) {
                    addProduct(p.id, p.name, p.sku, p.cost_price);
                    if (qty) updateItem(0, 'quantity', qty);
                }
            }
        }).catch(e => console.error("Failed to autoload product", e));
    }
});

// --- Suppliers ---

async function searchSuppliers() {
    const q = document.getElementById('supplierSearchInput')?.value || '';
    const dropdown = document.getElementById('supplierDropdown');

    try {
        const data = await apiRequest(`/api/suppliers?action=search&q=${encodeURIComponent(q)}`);
        const suppliers = data.data || [];

        if (suppliers.length === 0) {
            dropdown.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:0.85rem;">No active suppliers found</div>';
        } else {
            dropdown.innerHTML = suppliers.map(s => `
                <div onclick="selectSupplier(${s.id}, '${escapeHtml(s.name).replace(/'/g, "\\'")}', '${s.supplier_code}')" 
                     style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);transition:background 0.15s;">
                    <div style="font-weight:500;">${escapeHtml(s.name)}</div>
                    <div style="font-size:0.78rem;color:var(--text-muted);">${s.supplier_code} · ${escapeHtml(s.contact_person || 'No contact')}</div>
                </div>
            `).join('');
        }
        dropdown.style.display = 'block';
    } catch (e) {
        dropdown.innerHTML = '<div style="padding:12px;color:var(--error-color);">Error loading suppliers</div>';
        dropdown.style.display = 'block';
    }
}

function selectSupplier(id, name, code) {
    document.getElementById('selectedSupplierId').value = id;
    document.getElementById('supplierSearchInput').value = `${name} (${code})`;
    document.getElementById('supplierDropdown').style.display = 'none';

    document.getElementById('selectedSupplierInfo').style.display = 'block';
    document.getElementById('selectedSupplierInfo').innerHTML = `
        <div style="font-size:0.85rem;color:var(--primary-color);font-weight:600;"><i data-lucide="truck" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Selected: ${name}</div>
    `;
    lucide.createIcons();
}

// --- Products ---

async function searchProducts() {
    const q = document.getElementById('productSearchInput')?.value || '';
    const dropdown = document.getElementById('productDropdown');

    if (!q) {
        dropdown.style.display = 'none';
        return;
    }

    try {
        const data = await apiRequest(`/api/products?search=${encodeURIComponent(q)}&per_page=10`);
        const products = data.data || [];

        if (products.length === 0) {
            dropdown.innerHTML = '<div style="padding:12px;color:var(--text-muted);font-size:0.85rem;">No products found</div>';
        } else {
            dropdown.innerHTML = products.map(p => `
                <div onclick="addProduct(${p.id}, '${escapeHtml(p.name).replace(/'/g, "\\'")}', '${p.sku}', ${p.cost_price})" 
                     style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);display:flex;justify-content:space-between;">
                    <div>
                        <div style="font-weight:500;">${escapeHtml(p.name)}</div>
                        <div style="font-size:0.78rem;color:var(--text-muted);">${p.sku}</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:600;color:var(--primary-color);">${formatCurrency(p.cost_price)}</div>
                    </div>
                </div>
            `).join('');
        }
        dropdown.style.display = 'block';
    } catch (e) {
        dropdown.innerHTML = '<div style="padding:12px;color:var(--error-color);">Error loading products</div>';
        dropdown.style.display = 'block';
    }
}

function addProduct(id, name, sku, costPrice) {
    document.getElementById('productDropdown').style.display = 'none';
    document.getElementById('productSearchInput').value = '';

    const existing = poItems.find(item => item.product_id === id);
    if (existing) {
        existing.quantity++;
    } else {
        poItems.push({
            product_id: id,
            name: name,
            sku: sku,
            unit_cost: parseFloat(costPrice) || 0,
            quantity: 1
        });
    }

    renderPOItems();
}

function updateItem(index, field, value) {
    if (!poItems[index]) return;
    
    let parsed = parseFloat(value);
    if (isNaN(parsed) || parsed < 0) parsed = 0;
    
    poItems[index][field] = parsed;
    renderPOItems();
}

function removeItem(index) {
    poItems.splice(index, 1);
    renderPOItems();
}

function renderPOItems() {
    const tbody = document.getElementById('poItemsBody');
    
    if (poItems.length === 0) {
        tbody.innerHTML = '<tr id="emptyItemsRow"><td colspan="5" class="text-center text-muted" style="padding:40px;">No items added yet. Search and select products to add.</td></tr>';
        updateSummary();
        return;
    }

    tbody.innerHTML = poItems.map((item, idx) => {
        const total = item.quantity * item.unit_cost;
        return `
            <tr>
                <td>
                    <div style="font-weight:500;">${escapeHtml(item.name)}</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);">${item.sku}</div>
                </td>
                <td>
                    <div class="input-prefix" data-prefix="₱">
                        <input type="number" class="form-control" value="${item.unit_cost}" min="0" step="0.01" onchange="updateItem(${idx}, 'unit_cost', this.value)">
                    </div>
                </td>
                <td>
                    <input type="number" class="form-control" value="${item.quantity}" min="1" step="1" onchange="updateItem(${idx}, 'quantity', this.value)">
                </td>
                <td style="font-weight:600;">${formatCurrency(total)}</td>
                <td>
                    <button class="btn btn-sm btn-ghost" onclick="removeItem(${idx})" title="Remove">
                        <i data-lucide="x" style="width:16px;height:16px;color:var(--error-color);"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');

    lucide.createIcons();
    updateSummary();
}

function updateSummary() {
    const totalItems = poItems.length;
    const totalUnits = poItems.reduce((sum, item) => sum + (parseInt(item.quantity) || 0), 0);
    const totalAmount = poItems.reduce((sum, item) => sum + (item.quantity * item.unit_cost), 0);
    
    document.getElementById('summaryTotalItems').textContent = totalItems;
    
    const summaryTotalUnits = document.getElementById('summaryTotalUnits');
    if (summaryTotalUnits) summaryTotalUnits.textContent = totalUnits;
    
    const summarySubtotal = document.getElementById('summarySubtotal');
    if (summarySubtotal) summarySubtotal.textContent = formatCurrency(totalAmount);
    
    document.getElementById('summaryTotalAmount').textContent = formatCurrency(totalAmount);
}

// --- Submit ---

async function submitPO() {
    const supplierId = document.getElementById('selectedSupplierId').value;
    
    if (!supplierId) {
        showToast('Please select a supplier', 'error');
        return;
    }
    
    if (poItems.length === 0) {
        showToast('Please add at least one product to the order', 'error');
        return;
    }
    
    const body = JSON.stringify({
        supplier_id: parseInt(supplierId),
        expected_date: document.getElementById('expectedDate').value,
        status: document.getElementById('poStatus').value,
        notes: document.getElementById('poNotes').value,
        items: poItems.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            unit_cost: item.unit_cost
        }))
    });
    
    try {
        const result = await apiRequest('/api/purchase_orders', { method: 'POST', body });
        showToast(`PO ${result.po_number} created!`, 'success');
        
        setTimeout(() => {
            window.location.href = `${APP_URL}/purchase-orders`;
        }, 1000);
    } catch (e) {
        showToast(e.message || 'Failed to create PO', 'error');
    }
}
