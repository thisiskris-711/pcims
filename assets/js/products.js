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
    ['categoryFilter', 'statusFilter'].forEach(id => {
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
    
    const params = new URLSearchParams({
        search, category, status, page: currentPage,
    });
    
    // Check for low_stock filter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('filter') === 'low_stock') {
        params.set('filter', 'low_stock');
    }
    
    try {
        const data = await apiRequest(`/api/products.php?${params}`);
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
            <tr><td colspan="9">
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
        
        return `
        <tr>
            <td><input type="checkbox" class="product-checkbox" value="${p.id}"></td>
            <td>
                <div class="d-flex align-center gap-1">
                    <div class="product-thumb">${imgHtml}</div>
                    <div>
                        <div class="font-bold">${escapeHtml(p.name)}</div>
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
            <td><span class="status status-${p.status}">${p.status}</span></td>
            <td>
                <div class="d-flex gap-1">
                    <a href="${APP_URL}/product_form.php?id=${p.id}" class="btn btn-ghost btn-icon sm" title="Edit">
                        <i data-lucide="pencil" style="width:15px;height:15px;"></i>
                    </a>
                    <button class="btn btn-ghost btn-icon sm" title="Delete" onclick="promptDelete(${p.id}, '${escapeHtml(p.name).replace(/'/g, "\\'")}')">
                        <i data-lucide="trash-2" style="width:15px;height:15px;color:var(--accent-rose);"></i>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
    
    lucide.createIcons({ nodes: [tbody] });
    renderPagination(response);
}

function renderPagination(response) {
    const container = document.getElementById('productsPagination');
    const { page, total_pages } = response;
    
    if (total_pages <= 1) { container.innerHTML = ''; return; }
    
    let html = '';
    
    html += `<a class="${page <= 1 ? 'disabled' : ''}" onclick="goToPage(${page - 1})">&laquo;</a>`;
    
    for (let i = Math.max(1, page - 2); i <= Math.min(total_pages, page + 2); i++) {
        html += `<a class="${i === page ? 'active' : ''}" onclick="goToPage(${i})">${i}</a>`;
    }
    
    html += `<a class="${page >= total_pages ? 'disabled' : ''}" onclick="goToPage(${page + 1})">&raquo;</a>`;
    
    container.innerHTML = html;
}

function goToPage(page) {
    currentPage = page;
    loadProducts();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function promptDelete(id, name) {
    deleteProductId = id;
    document.getElementById('deleteProductName').textContent = name;
    openModal('deleteModal');
}

async function confirmDelete() {
    if (!deleteProductId) return;
    
    try {
        await apiRequest(`/api/products.php?id=${deleteProductId}`, { method: 'DELETE' });
        showToast('Product deleted successfully', 'success');
        closeModal('deleteModal');
        loadProducts();
    } catch (e) {
        showToast(e.message || 'Failed to delete product', 'error');
    }
}
