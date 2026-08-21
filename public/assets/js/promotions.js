/**
 * Promotions Management JS
 */
document.addEventListener('DOMContentLoaded', () => {
    loadPromotions();
});

function generateProductCategorySelect(id, selectedValue = '') {
    let html = `<select class="form-control" id="${id}" onchange="updatePreview()">`;
    html += `<option value="">Select...</option>`;
    
    if (window.CATEGORIES && window.CATEGORIES.length > 0) {
        html += `<optgroup label="Categories">`;
        window.CATEGORIES.forEach(c => {
            html += `<option value="category_${c.id}" ${selectedValue === 'category_'+c.id ? 'selected' : ''}>${escapeHtml(c.name)} (Category)</option>`;
        });
        html += `</optgroup>`;
    }
    
    if (window.PRODUCTS && window.PRODUCTS.length > 0) {
        html += `<optgroup label="Specific Products">`;
        window.PRODUCTS.forEach(p => {
            html += `<option value="product_${p.id}" ${selectedValue === 'product_'+p.id ? 'selected' : ''}>${escapeHtml(p.name)}</option>`;
        });
        html += `</optgroup>`;
    }
    
    html += `</select>`;
    return html;
}

function getSelectedEntity(selectId) {
    const val = document.getElementById(selectId)?.value;
    if (!val) return null;
    const parts = val.split('_');
    if (parts[0] === 'category') {
        return { type: 'category', id: parseInt(parts[1]), name: window.CATEGORIES.find(c => c.id == parts[1])?.name || '' };
    } else if (parts[0] === 'product') {
        const p = window.PRODUCTS.find(p => p.id == parts[1]);
        return { type: 'product', id: parseInt(parts[1]), name: p?.name || '', image: p?.image || '' };
    }
    return null;
}

async function loadPromotions() {
    try {
        const data = await apiRequest('/api/promotions');
        renderPromotions(data.data || []);
    } catch (e) {
        showToast('Failed to load promotions', 'error');
    }
}

function renderPromotions(promotions) {
    const tbody = document.getElementById('promotionsBody');

    if (promotions.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:40px;"><i data-lucide="tag" style="width:48px;height:48px;opacity:0.3;display:block;margin:0 auto 12px;"></i>No promotions yet. Click "Add Promotion" to create one.</td></tr>';
        lucide.createIcons({ nodes: [tbody] });
        return;
    }

    const typeLabels = {
        'category_discount': 'Promo',
        'bundle_deal': 'Bundle',
    };

    const typeBadgeColors = {
        'category_discount': 'var(--accent-violet)',
        'spend_threshold': 'var(--accent-emerald)',
        'bundle_deal': 'var(--accent-amber)',
        'buy_x_get_y': 'var(--accent-sky)',
    };

    tbody.innerHTML = promotions.map(p => {
        const isActive = parseInt(p.is_active);
        const statusDot = isActive
            ? '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--accent-emerald);margin-right:6px;"></span>Active'
            : '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--text-muted);margin-right:6px;"></span>Inactive';

        const badgeColor = typeBadgeColors[p.type] || 'var(--text-muted)';
        const typeBadge = `<span style="display:inline-block;padding:3px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${badgeColor}20;color:${badgeColor};">${typeLabels[p.type] || p.type}</span>`;

        let dates = '—';
        if (p.start_date || p.end_date) {
            const start = p.start_date ? new Date(p.start_date).toLocaleDateString() : 'Any';
            const end = p.end_date ? new Date(p.end_date).toLocaleDateString() : 'Ongoing';
            dates = `${start} → ${end}`;
        }

        const config = typeof p.config === 'string' ? JSON.parse(p.config) : p.config;
        let configSummary = '';
        let imageHtml = '';
        if (p.type === 'category_discount') {
            configSummary = `Buy ${config.buy_qty || 4} Get ${config.get_qty || 1} at ₱${parseFloat(config.get_price || 0).toLocaleString()}`;
            if (config.product_id && window.PRODUCTS) {
                const targetProd = window.PRODUCTS.find(pr => parseInt(pr.id) === parseInt(config.product_id));
                if (targetProd && targetProd.image) {
                    imageHtml = `<img src="${window.APP_URL}/uploads/products/${escapeHtml(targetProd.image)}" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-right:12px;border:1px solid var(--border-color);" alt="">`;
                } else if (targetProd) {
                    imageHtml = `<img src="${window.APP_URL}/assets/images/placeholder.jpg" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-right:12px;border:1px solid var(--border-color);" alt="">`;
                }
            }
        } else if (p.type === 'bundle_deal') {
            configSummary = `Bundle at ₱${parseFloat(config.bundle_price).toLocaleString()}`;
            if (config.product_ids && Array.isArray(config.product_ids) && window.PRODUCTS) {
                const bundleImages = config.product_ids.map(id => {
                    const prod = window.PRODUCTS.find(pr => parseInt(pr.id) === parseInt(id));
                    if (prod && prod.image) {
                        return `<img src="${window.APP_URL}/uploads/products/${escapeHtml(prod.image)}" style="width:32px;height:32px;object-fit:cover;border-radius:4px;border:1px solid var(--border-color);margin-left:-12px;position:relative;" alt="" title="${escapeHtml(prod.name)}">`;
                    } else if (prod) {
                        return `<img src="${window.APP_URL}/assets/images/placeholder.jpg" style="width:32px;height:32px;object-fit:cover;border-radius:4px;border:1px solid var(--border-color);margin-left:-12px;position:relative;" alt="" title="${escapeHtml(prod.name)}">`;
                    }
                    return '';
                }).join('');
                if (bundleImages) {
                    imageHtml = `<div style="display:flex;padding-left:12px;margin-right:12px;align-items:center;">${bundleImages}</div>`;
                }
            }
        }

        const desc = p.description ? escapeHtml(p.description) : `<span class="text-muted">${configSummary}</span>`;

        return `<tr>
            <td style="font-size:0.85rem;">${statusDot}</td>
            <td>
                <div style="display:flex;align-items:center;">
                    ${imageHtml}
                    <strong>${escapeHtml(p.name)}</strong>
                </div>
            </td>
            <td>${typeBadge}</td>
            <td style="font-size:0.85rem;max-width:250px;">${desc}</td>
            <td style="font-size:0.82rem;color:var(--text-muted);white-space:nowrap;">${dates}</td>
            <td>
                <div style="display:flex;gap:4px;">
                    <button class="btn btn-sm btn-ghost" onclick="editPromotion(${p.id})" title="Edit">
                        <i data-lucide="pencil" style="width:16px;height:16px;"></i>
                    </button>
                    <button class="btn btn-sm btn-ghost" onclick="confirmDeletePromotion(${p.id})" title="Delete" style="color:var(--accent-rose);">
                        <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');

    lucide.createIcons({ nodes: [tbody] });
}


let bundleComponents = [];

function onPromoTypeChange() {
    const type = document.getElementById('promoType').value;
    document.getElementById('promoRuleGroup').style.display = type === 'category_discount' ? 'block' : 'none';
    document.getElementById('promoConfigContainer').style.display = type === 'category_discount' ? 'block' : 'none';
    document.getElementById('bundleConfigContainer').style.display = type === 'bundle_deal' ? 'block' : 'none';
    
    if (type === 'bundle_deal' && bundleComponents.length === 0) {
        addBundleComponent();
    }
    updatePreview();
}

function onPromoRuleChange() {
    const rule = document.getElementById('promoRule').value;
    const container = document.getElementById('promoConfigContainer');
    
    if (!rule) {
        container.innerHTML = '';
        updatePreview();
        return;
    }

    let html = '';
    if (rule === 'buy_x_get_y') {
        html = `
            <div style="display:flex;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Buy Quantity (X) *</label>
                    <input type="number" class="form-control" id="ruleBuyQty" min="1" value="2" oninput="updatePreview()">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Buy Product / Group *</label>
                    ${generateProductCategorySelect('ruleBuyTarget')}
                </div>
            </div>
            <div style="display:flex;gap:12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Get Quantity (Y) *</label>
                    <input type="number" class="form-control" id="ruleGetQty" min="1" value="1" oninput="updatePreview()">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Get Product / Group *</label>
                    ${generateProductCategorySelect('ruleGetTarget')}
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Promotional Price (₱) *</label>
                    <input type="number" class="form-control" id="rulePromoPrice" min="0" step="0.01" value="50" oninput="updatePreview()">
                </div>
            </div>
        `;
    } else if (rule === 'buy_any_x_get_any_y') {
        html = `
            <div style="display:flex;gap:12px;margin-bottom:12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Buy Quantity (X) *</label>
                    <input type="number" class="form-control" id="ruleBuyQty" min="1" value="2" oninput="updatePreview()">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Buy Category (X) *</label>
                    ${generateProductCategorySelect('ruleBuyTarget')}
                </div>
            </div>
            <div style="display:flex;gap:12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Get Quantity (Y) *</label>
                    <input type="number" class="form-control" id="ruleGetQty" min="1" value="1" oninput="updatePreview()">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Get Category (Y) *</label>
                    ${generateProductCategorySelect('ruleGetTarget')}
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Promotional Price (₱) *</label>
                    <input type="number" class="form-control" id="rulePromoPrice" min="0" step="0.01" value="50" oninput="updatePreview()">
                </div>
            </div>
        `;
    } else if (rule === 'buy_any_x_for_y') {
        html = `
            <div style="display:flex;gap:12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Quantity (X) *</label>
                    <input type="number" class="form-control" id="ruleBuyQty" min="1" value="3" oninput="updatePreview()">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="form-label">Product Group / Category *</label>
                    ${generateProductCategorySelect('ruleBuyTarget')}
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Total Price (₱) *</label>
                    <input type="number" class="form-control" id="rulePromoPrice" min="0" step="0.01" value="300" oninput="updatePreview()">
                </div>
            </div>
        `;
    }
    
    container.innerHTML = html;
    updatePreview();
}

function renderBundleComponents() {
    const tbody = document.getElementById('bundleComponentsBody');
    tbody.innerHTML = bundleComponents.map((c, index) => {
        return `
            <tr>
                <td><input type="number" class="form-control" min="1" value="${c.qty}" onchange="updateBundleComponent(${index}, 'qty', this.value)"></td>
                <td>
                    <select class="form-control" onchange="updateBundleComponent(${index}, 'selection', this.value)">
                        <option value="specific" ${c.selection === 'specific' ? 'selected' : ''}>Specific Product</option>
                        <option value="any" ${c.selection === 'any' ? 'selected' : ''}>Any in Group</option>
                    </select>
                </td>
                <td>
                    ${generateProductCategorySelect(`bundleTarget_${index}`, c.target)}
                </td>
                <td>
                    <button type="button" class="btn btn-sm btn-ghost" onclick="removeBundleComponent(${index})" style="color:var(--accent-rose);"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>
                </td>
            </tr>
        `;
    }).join('');
    
    // Attach change handlers for target dropdowns dynamically
    bundleComponents.forEach((c, index) => {
        const sel = document.getElementById(`bundleTarget_${index}`);
        if (sel) sel.onchange = (e) => {
            updateBundleComponent(index, 'target', e.target.value);
            updatePreview();
        };
    });
    
    lucide.createIcons({ nodes: [tbody] });
    updateBundleCalculations();
}

function addBundleComponent() {
    bundleComponents.push({ qty: 1, selection: 'specific', target: '' });
    renderBundleComponents();
}

function removeBundleComponent(index) {
    bundleComponents.splice(index, 1);
    renderBundleComponents();
}

function updateBundleComponent(index, field, value) {
    bundleComponents[index][field] = value;
    if (field === 'qty' || field === 'target') {
        updateBundleCalculations();
    }
}

function updateBundleCalculations() {
    let regularPrice = 0;
    bundleComponents.forEach(c => {
        const qty = parseInt(c.qty) || 0;
        if (c.target && c.target.startsWith('product_')) {
            const pId = parseInt(c.target.split('_')[1]);
            const p = window.PRODUCTS.find(pr => parseInt(pr.id) === pId);
            if (p) regularPrice += parseFloat(p.selling_price || 0) * qty;
        }
    });
    
    document.getElementById('bundleRegularPrice').textContent = '₱' + regularPrice.toFixed(2);
    
    const bundlePriceStr = document.getElementById('configBundlePrice')?.value;
    const bundlePrice = parseFloat(bundlePriceStr) || 0;
    
    let savings = 0;
    if (bundlePriceStr && bundlePrice > 0 && regularPrice > 0) {
        savings = regularPrice - bundlePrice;
    }
    
    const savingsEl = document.getElementById('bundleSavings');
    savingsEl.textContent = '₱' + Math.max(0, savings).toFixed(2);
    
    updatePreview();
}

function updatePreview() {
    const container = document.getElementById('posPreviewContainer');
    const type = document.getElementById('promoType').value;
    
    if (!type) {
        container.innerHTML = '<div class="text-muted text-center" style="font-size:0.9rem;">Select a promotion type and rule to generate a preview.</div>';
        return;
    }
    
    let html = '';
    const suggest = document.getElementById('promoSuggestPos').checked;
    
    if (type === 'category_discount') {
        const rule = document.getElementById('promoRule').value;
        const x = document.getElementById('ruleBuyQty')?.value || '?';
        const y = document.getElementById('ruleGetQty')?.value || '?';
        const z = document.getElementById('rulePromoPrice')?.value || '?';
        const tX = getSelectedEntity('ruleBuyTarget');
        const tY = getSelectedEntity('ruleGetTarget');
        
        let msg = '';
        if (rule === 'buy_x_get_y') {
            msg = `Buy ${x} ${tX?.name || 'Item'}, Get ${y} ${tY?.name || 'Item'} for ₱${z}`;
        } else if (rule === 'buy_any_x_get_any_y') {
            msg = `Buy any ${x} ${tX?.name || 'Category'} items, Get any ${y} ${tY?.name || 'Category'} item for ₱${z}`;
        } else if (rule === 'buy_any_x_for_y') {
            msg = `Buy any ${x} ${tX?.name || 'Category'} items for only ₱${z}`;
        }
        
        html = `
            <div class="pos-promo-badge qualified" style="display:flex;align-items:center;background:#fef2f2;border:1px dashed var(--accent-primary);padding:8px 12px;border-radius:6px;gap:12px;">
                <div style="flex-shrink:0;background:var(--accent-primary);color:#fff;width:32px;height:32px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-weight:700;">
                    <i data-lucide="tag" style="width:16px;height:16px;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.75rem;font-weight:700;color:var(--accent-primary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:2px;">🎉 Promotion Available</div>
                    <div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);line-height:1.2;">${msg}</div>
                </div>
                <button type="button" class="btn btn-sm" style="background:var(--accent-primary);color:#fff;font-size:0.8rem;white-space:nowrap;border:none;">Apply Promo</button>
            </div>
        `;
    } else if (type === 'bundle_deal') {
        const bp = document.getElementById('configBundlePrice')?.value || '?';
        html = `
            <div class="pos-bundle-suggestion" style="display:flex;align-items:center;background:#fffbf1;border:1px solid #fde68a;padding:12px;border-radius:6px;gap:12px;">
                <div style="flex-shrink:0;background:var(--accent-amber);color:#fff;width:32px;height:32px;border-radius:4px;display:flex;align-items:center;justify-content:center;">
                    <i data-lucide="package" style="width:16px;height:16px;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.85rem;font-weight:600;color:var(--text-primary);">Bundle & Save!</div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">Get this bundle for only ₱${bp}</div>
                </div>
                <button type="button" class="btn btn-sm" style="background:var(--accent-amber);color:#fff;font-size:0.8rem;border:none;">Add Bundle</button>
            </div>
        `;
    }
    
    if (!suggest) {
        html += `<div style="font-size:0.75rem;color:var(--text-muted);text-align:center;margin-top:8px;"><i data-lucide="eye-off" style="width:12px;height:12px;vertical-align:-2px;margin-right:4px;"></i> This promotion will not be suggested in POS.</div>`;
    }
    
    container.innerHTML = html;
    lucide.createIcons({ nodes: [container] });
}

function openPromoModal(data = null) {
    const isEdit = !!data;
    document.getElementById('promoModalTitle').textContent = isEdit ? 'Edit Promotion' : 'Add Promotion';
    document.getElementById('promoId').value = isEdit ? data.id : '';
    document.getElementById('promoName').value = isEdit ? data.name : '';
    document.getElementById('promoType').value = isEdit ? data.type : '';
    document.getElementById('promoDesc').value = isEdit ? (data.description || '') : '';
    document.getElementById('promoStartDate').value = isEdit ? (data.start_date || '') : '';
    document.getElementById('promoEndDate').value = isEdit ? (data.end_date || '') : '';
    document.getElementById('promoActive').value = isEdit ? data.is_active : '1';
    
    // Reset config fields
    document.getElementById('promoRule').value = '';
    bundleComponents = [];
    document.getElementById('promoSuggestPos').checked = true;
    document.getElementById('promoPriority').value = 'normal';
    document.getElementById('configBundlePrice').value = '';

    if (isEdit) {
        const config = typeof data.config === 'string' ? JSON.parse(data.config) : data.config;
        
        if (config.suggest_in_pos !== undefined) {
            document.getElementById('promoSuggestPos').checked = config.suggest_in_pos;
        }
        if (config.priority) {
            document.getElementById('promoPriority').value = config.priority;
        }

        if (data.type === 'category_discount') {
            const rule = config.rule || 'buy_x_get_y';
            document.getElementById('promoRule').value = rule;
            onPromoRuleChange(); // generate HTML
            
            setTimeout(() => {
                if (document.getElementById('ruleBuyQty')) document.getElementById('ruleBuyQty').value = config.buy_qty || '';
                if (document.getElementById('ruleGetQty')) document.getElementById('ruleGetQty').value = config.get_qty || '';
                if (document.getElementById('rulePromoPrice')) document.getElementById('rulePromoPrice').value = config.promo_price !== undefined ? config.promo_price : '';
                if (document.getElementById('ruleBuyTarget')) document.getElementById('ruleBuyTarget').value = config.buy_target || '';
                if (document.getElementById('ruleGetTarget')) document.getElementById('ruleGetTarget').value = config.get_target || '';
                updatePreview();
            }, 50);
        } else if (data.type === 'bundle_deal') {
            if (config.components) {
                bundleComponents = config.components;
            }
            document.getElementById('configBundlePrice').value = config.bundle_price || '';
            renderBundleComponents();
        }
    } else {
        onPromoRuleChange();
        renderBundleComponents();
    }

    onPromoTypeChange();
    openModal('promoModal');
}

async function editPromotion(id) {
    try {
        const data = await apiRequest('/api/promotions');
        const promo = (data.data || []).find(p => p.id == id);
        if (promo) openPromoModal(promo);
    } catch (e) {
        showToast('Failed to load promotion', 'error');
    }
}

async function savePromotion(e) {
    if (e) e.preventDefault();

    const id = document.getElementById('promoId').value;
    const type = document.getElementById('promoType').value;

    if (!type) {
        showToast('Please select a promotion type', 'warning');
        return;
    }

    let config = {
        suggest_in_pos: document.getElementById('promoSuggestPos').checked,
        priority: document.getElementById('promoPriority').value
    };

    if (type === 'category_discount') {
        const rule = document.getElementById('promoRule').value;
        if (!rule) {
            showToast('Please select a promo rule', 'warning');
            return;
        }
        config.rule = rule;
        config.buy_qty = parseInt(document.getElementById('ruleBuyQty')?.value) || 0;
        config.get_qty = parseInt(document.getElementById('ruleGetQty')?.value) || 0;
        config.promo_price = parseFloat(document.getElementById('rulePromoPrice')?.value) || 0;
        config.buy_target = document.getElementById('ruleBuyTarget')?.value || '';
        config.get_target = document.getElementById('ruleGetTarget')?.value || '';
        
        if (rule !== 'buy_any_x_for_y' && (!config.buy_target || !config.get_target)) {
            showToast('Please select all target products/categories', 'warning');
            return;
        }
        if (rule === 'buy_any_x_for_y' && !config.buy_target) {
            showToast('Please select a target product/category', 'warning');
            return;
        }

    } else if (type === 'bundle_deal') {
        config.components = bundleComponents;
        config.bundle_price = parseFloat(document.getElementById('configBundlePrice').value) || 0;
        
        if (bundleComponents.length === 0) {
            showToast('Bundle must have at least one component', 'warning');
            return;
        }
        if (!config.bundle_price) {
            showToast('Please enter a bundle price', 'warning');
            return;
        }
    }

    const body = JSON.stringify({
        name: document.getElementById('promoName').value,
        type: type,
        description: document.getElementById('promoDesc').value,
        config: config,
        is_active: parseInt(document.getElementById('promoActive').value),
        start_date: document.getElementById('promoStartDate').value || null,
        end_date: document.getElementById('promoEndDate').value || null,
    });

    try {
        if (id) {
            await apiRequest(`/api/promotions?id=${id}`, { method: 'PUT', body });
            showToast('Promotion updated', 'success');
        } else {
            await apiRequest('/api/promotions', { method: 'POST', body });
            showToast('Promotion created', 'success');
        }
        closeModal('promoModal');
        loadPromotions();
    } catch (e) {
        showToast(e.message || 'Failed to save promotion', 'error');
    }
}
let deletePromoId = null;

function confirmDeletePromotion(id) {
    deletePromoId = id;
    openModal('deletePromoModal');
    document.getElementById('confirmDeletePromoBtn').onclick = async () => {
        try {
            await apiRequest(`/api/promotions?id=${deletePromoId}`, { method: 'DELETE', body: '{}' });
            showToast('Promotion deleted', 'success');
            closeModal('deletePromoModal');
            loadPromotions();
        } catch (e) {
            showToast(e.message || 'Failed to delete', 'error');
        }
    };
}
