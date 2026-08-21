/**
 * Products Page JS — CRUD, search, filter, pagination
 */
let currentPage = 1;
let deleteProductId = null;

document.addEventListener('DOMContentLoaded', () => {
    loadProducts();
    
    // Search
    const searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => {
            currentPage = 1;
            loadProducts();
        }, 400));
    }
    
    // Filters
    ['categoryFilter', 'statusFilter', 'expiryFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => { currentPage = 1; loadProducts(); });
    });
    
    // Select all
    initSelectAll('selectAll', 'product-checkbox');
    
    // Check for filter=low_stock from URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('filter') === 'low_stock') {
        // Already loads all; could add special filter
    }
});

async function loadProducts() {
    const search = document.getElementById('productSearch')?.value || '';
    const category = document.getElementById('categoryFilter')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const expiryFilter = document.getElementById('expiryFilter')?.value || '';
    
    const params = new URLSearchParams({
        search, category, status, page: currentPage,
    });
    
    // Check for low_stock filter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('filter') === 'low_stock') {
        params.set('filter', 'low_stock');
    } else if (expiryFilter) {
        params.set('filter', expiryFilter);
    }
    
    try {
        const data = await apiRequest(`/api/products?${params}`);
        renderProducts(data);
    } catch (e) {
        showToast('Failed to load products', 'error');
    }
}

function renderProducts(response) {
    const tbody = document.getElementById('productsBody');
    const products = response.data || [];
    
    if (products.length === 0) {
        tbody.innerHTML = `
            <tr><td colspan="11">
                <div class="empty-state">
                    <i data-lucide="package-open" style="width:48px;height:48px;"></i>
                    <h3>No Products Found</h3>
                    <p>Try adjusting your search or filters, or add a new product.</p>
                </div>
            </td></tr>`;
        lucide.createIcons({ nodes: [tbody] });
        document.getElementById('productsPagination').innerHTML = '';
        return;
    }
    
    tbody.innerHTML = products.map(p => {
        const stockPercent = p.low_stock_threshold > 0 ? Math.min(100, (p.quantity / (p.low_stock_threshold * 3)) * 100) : 100;
        const stockLevel = p.quantity <= p.low_stock_threshold ? 'low' : (stockPercent < 50 ? 'medium' : 'high');
        const imgHtml = p.image 
            ? `<img src="${APP_URL}/uploads/products/${p.image}" alt="">`
            : `<i data-lucide="image" style="width:18px;height:18px;"></i>`;
            
        const bundleBadge = p.type === 'bundle' ? `<span class="status" style="padding:2px 6px; font-size:0.65rem; background:var(--accent-violet); color:white;">Bundle</span>` : '';
            
        let expiryHtml = '<span class="text-muted">—</span>';
        if (p.expiry_date) {
            const today = new Date();
            today.setHours(0,0,0,0);
            const expDate = new Date(p.expiry_date);
            const daysToExpiry = Math.ceil((expDate - today) / (1000 * 60 * 60 * 24));
            
            if (daysToExpiry < 0) {
                expiryHtml = `<div class="text-danger font-bold">Expired</div><div style="font-size:0.75rem">${p.expiry_date}</div>`;
            } else if (daysToExpiry <= 30) {
                expiryHtml = `<div class="text-warning font-bold">In ${daysToExpiry}d</div><div style="font-size:0.75rem">${p.expiry_date}</div>`;
            } else {
                expiryHtml = `<span>${p.expiry_date}</span>`;
            }
        }
        
        return `
        <tr ${window.CAN_EDIT ? `class="clickable-row" onclick="if(!event.target.closest('.product-checkbox') && !event.target.closest('.btn')) window.location.href='${APP_URL}/product_form?id=${p.id}'"` : ''}>
            <td><input type="checkbox" class="product-checkbox" value="${p.id}" onclick="event.stopPropagation()"></td>
            <td>
                <div class="d-flex align-center gap-1">
                    <div class="product-thumb">${imgHtml}</div>
                    <div>
                        <div class="font-bold d-flex align-center gap-1">${escapeHtml(p.name)} ${bundleBadge}</div>
                        <div class="text-muted" style="font-size:0.75rem;">${p.description ? escapeHtml(p.description).substring(0, 50) + '...' : ''}</div>
                    </div>
                </div>
            </td>
            <td><code style="font-size:0.8rem;color:var(--accent-cyan);">${escapeHtml(p.sku)}</code></td>
            <td>
                ${p.category_name ? `<span class="d-flex align-center gap-1"><span class="color-dot" style="background:${p.category_color}"></span>${escapeHtml(p.category_name)}</span>` : '<span class="text-muted">—</span>'}
            </td>
            <td class="text-muted">${formatCurrency(p.cost_price)}</td>
            <td class="font-bold">${formatCurrency(p.selling_price)}</td>
            <td>
                <div class="stock-indicator">
                    <span class="${stockLevel === 'low' ? 'text-danger' : (stockLevel === 'medium' ? 'text-warning' : 'text-success')} font-bold">${p.quantity}</span>
                    <div class="stock-bar">
                        <div class="stock-bar-fill ${stockLevel}" style="width:${stockPercent}%"></div>
                    </div>
                </div>
            </td>
            <td>${expiryHtml}</td>
            <td><span class="status status-${p.status}">${p.status}</span></td>
            <td class="text-muted">${p.creator_name ? escapeHtml(p.creator_name) : '—'}</td>
            ${window.CAN_EDIT ? `
            <td>
                <div class="d-flex gap-1">

                    <button class="btn btn-ghost btn-icon sm" title="Delete" onclick="promptDelete(${p.id}, '${escapeHtml(p.name).replace(/'/g, "\\'")}')">
                        <i data-lucide="trash-2" style="width:15px;height:15px;color:var(--accent-rose);"></i>
                    </button>
                </div>
            </td>
            ` : ''}
        </tr>`;
    }).join('');
    
    lucide.createIcons({ nodes: [tbody] });
    renderPagination(document.getElementById('productsPagination'), response.page, response.total_pages, goToPage);
}

function goToPage(page) {
    currentPage = page;
    loadProducts();
}

function promptDelete(id, name) {
    deleteProductId = id;
    document.getElementById('deleteProductName').textContent = name;
    openModal('deleteModal');
}

async function confirmDelete() {
    if (!deleteProductId) return;
    
    try {
        await apiRequest(`/api/products?id=${deleteProductId}`, { method: 'DELETE' });
        showToast('Product deleted successfully', 'success');
        closeModal('deleteModal');
        loadProducts();
    } catch (e) {
        showToast(e.message || 'Failed to delete product', 'error');
    }
}

// Bulk Actions
document.addEventListener('change', (e) => {
    if (e.target.matches('.product-checkbox') || e.target.id === 'selectAll') {
        updateBulkActions();
    }
});

function getSelectedIds() {
    const checkboxes = document.querySelectorAll('.product-checkbox:checked');
    return Array.from(checkboxes).map(cb => cb.value);
}

function updateBulkActions() {
    const selectedIds = getSelectedIds();
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    
    if (selectedIds.length > 0) {
        bulkActions.style.display = 'flex';
        selectedCount.textContent = selectedIds.length;
    } else {
        bulkActions.style.display = 'none';
    }
}

function promptBulkDelete() {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) return;
    
    if (confirm(`Are you sure you want to delete ${selectedIds.length} selected products? This cannot be undone.`)) {
        confirmBulkDelete(selectedIds);
    }
}

async function confirmBulkDelete(ids) {
    try {
        const res = await apiRequest('/api/products?action=bulk_delete', { 
            method: 'POST', 
            body: JSON.stringify({ ids }) 
        });
        if (res.success) {
            showToast(res.message, 'success');
            document.getElementById('selectAll').checked = false;
            updateBulkActions();
            loadProducts();
        }
    } catch (e) {
        showToast(e.message || 'Failed to delete products', 'error');
    }
}

async function applyBulkCategory() {
    const selectedIds = getSelectedIds();
    if (selectedIds.length === 0) return;
    
    const categoryId = document.getElementById('bulkCategorySelect').value;
    if (categoryId === '') {
        showToast('Please select a category first', 'warning');
        return;
    }
    
    try {
        const res = await apiRequest('/api/products?action=bulk_category', {
            method: 'POST', 
            body: JSON.stringify({ 
                ids: selectedIds, 
                category_id: categoryId 
            })
        });
        if (res.success) {
            showToast(res.message, 'success');
            document.getElementById('selectAll').checked = false;
            document.getElementById('bulkCategorySelect').value = '';
            updateBulkActions();
            loadProducts();
        }
    } catch (e) {
        showToast(e.message || 'Failed to update category', 'error');
    }
}
