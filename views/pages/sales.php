<?php
/**
 * Sales History Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('view_sales');
$pageTitle = 'Sales Invoices';
$currentPage = 'sales';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left" style="gap: 12px; flex-wrap: wrap;">
        <div class="search-bar" style="min-width: 200px;">
            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
            <input type="text" class="form-control" id="salesSearch" placeholder="Search invoices...">
        </div>
        <input type="date" class="form-control" id="salesDateFrom" style="width:auto;">
        <input type="date" class="form-control" id="salesDateTo" style="width:auto;">
        <select class="form-control" id="salesPayment" style="width:auto;min-width:130px;">
            <option value="">All Methods</option>
            <option value="cash">Cash</option>
            <option value="credit">Credit</option>
            <option value="cash&credit">Cash & Credit</option>
        </select>
        <select class="form-control" id="salesStatus" style="width:auto;min-width:130px;">
            <option value="">All Status</option>
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
            <option value="pending_approval">Pending Approval</option>
            <option value="refunded">Refunded</option>
            <option value="voided">Voided</option>
        </select>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Dealer</th>
                        <th>Items</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Tax</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="salesBody">
                    <tr><td colspan="10" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="salesPagination" class="pagination"></div>
    </div>
</div>

<!-- Sale Detail Modal -->
<div class="modal modal-lg" id="saleDetailModal">
    <div class="modal-header">
        <h3 class="modal-title">Sale Details</h3>
        <button class="modal-close" onclick="closeModal('saleDetailModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" id="saleDetailContent">
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('saleDetailModal')">Close</button>
    </div>
</div>

<script>
let salesPage = 1;

document.addEventListener('DOMContentLoaded', () => {
    loadSales();
    ['salesDateFrom', 'salesDateTo', 'salesPayment', 'salesStatus'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => { salesPage = 1; loadSales(); });
    });
    const searchInput = document.getElementById('salesSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => { salesPage = 1; loadSales(); }, 300));
    }
});

async function loadSales() {
    const urlParams = new URLSearchParams(window.location.search);
    const dealerId = urlParams.get('dealer_id') || '';

    const params = new URLSearchParams({
        search: document.getElementById('salesSearch')?.value || '',
        date_from: document.getElementById('salesDateFrom')?.value || '',
        date_to: document.getElementById('salesDateTo')?.value || '',
        payment_method: document.getElementById('salesPayment')?.value || '',
        status: document.getElementById('salesStatus')?.value || '',
        dealer_id: dealerId,
        page: salesPage,
    });
    
    try {
        const data = await apiRequest(`/api/sales?${params}`);
        renderSales(data);
    } catch (e) {
        showToast('Failed to load sales', 'error');
    }
}

function renderSales(response) {
    const tbody = document.getElementById('salesBody');
    const sales = response.data || [];
    
    if (sales.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><i data-lucide="receipt" style="width:48px;height:48px;"></i><h3>No Sales Found</h3><p>Sales will appear here after checkout.</p></div></td></tr>';
        lucide.createIcons({ nodes: [tbody] });
        document.getElementById('salesPagination').innerHTML = '';
        return;
    }
    
    const paymentBadge = { cash: 'badge-emerald', credit: 'badge-blue', 'cash&credit': 'badge-violet' };
    
    tbody.innerHTML = sales.map(s => `
        <tr class="clickable-row" onclick="viewSaleDetail(${s.id})">
            <td><code style="font-size:0.8rem;color:var(--accent-cyan);">${escapeHtml(s.invoice_no)}</code></td>
            <td>${escapeHtml(s.dealer_name || '—')}${s.dealer_code ? ` <code style="font-size:0.72rem;color:var(--text-muted);">${s.dealer_code}</code>` : ''}</td>
            <td><span class="badge badge-violet">${s.item_count}</span></td>
            <td>${formatCurrency(s.subtotal)}</td>
            <td class="text-muted">${parseFloat(s.discount) > 0 ? '-' + formatCurrency(s.discount) : '—'}</td>
            <td class="text-muted">${formatCurrency(s.tax)}</td>
            <td class="font-bold text-success">${formatCurrency(s.total)}</td>
            <td><span class="badge ${paymentBadge[s.payment_method] || 'badge-gray'}">${s.payment_method.toUpperCase()}</span></td>
            <td><span class="status status-${s.payment_status}">${s.payment_status}</span></td>
            <td class="text-muted">${new Date(s.created_at).toLocaleDateString()}</td>
        </tr>
    `).join('');
    
    lucide.createIcons({ nodes: [tbody] });
    
    renderPagination(document.getElementById('salesPagination'), response.page, response.total_pages, salesGoPage);
}

function salesGoPage(p) { salesPage = p; loadSales(); }

async function viewSaleDetail(id) {
    try {
        const sale = await apiRequest(`/api/sales?action=detail&id=${id}`);
        
        const itemsHtml = (sale.items || []).map(item => `
            <tr>
                <td>${escapeHtml(item.product_name)}</td>
                <td><code>${escapeHtml(item.sku || '—')}</code></td>
                <td class="text-center">${item.quantity}</td>
                <td>${formatCurrency(item.unit_price)}</td>
                <td class="font-bold">${formatCurrency(item.total)}</td>
            </tr>
        `).join('');
        
        document.getElementById('saleDetailContent').innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom: 16px;">
                <div class="grid-2" style="flex:1; margin-bottom:0;">
                    <div>
                        <p><strong>Invoice:</strong> ${sale.invoice_no}</p>
                        <p><strong>Dealer:</strong> ${escapeHtml(sale.dealer_name || 'N/A')} ${sale.dealer_code ? `<code style="font-size:0.78rem;">${sale.dealer_code}</code>` : ''}</p>
                        <p><strong>Cashier:</strong> ${escapeHtml(sale.cashier || 'N/A')}</p>
                    </div>
                    <div>
                        <p><strong>Date:</strong> ${new Date(sale.created_at).toLocaleString()}</p>
                        <p><strong>Payment:</strong> <span class="badge badge-blue">${sale.payment_method.toUpperCase()}</span></p>
                        <p><strong>Status:</strong> <span class="status status-${sale.payment_status}">${sale.payment_status}</span></p>
                    </div>
                </div>
                <a href="${window.APP_URL || ''}/invoice_print?id=${sale.id}" target="_blank" class="btn btn-primary" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                    <i data-lucide="printer" style="width:16px;height:16px;"></i> Print Invoice
                </a>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Product</th><th>SKU</th><th class="text-center">Qty</th><th>Price</th><th>Total</th></tr></thead>
                    <tbody>${itemsHtml}</tbody>
                </table>
            </div>
            <div style="margin-top:16px;padding:16px;background:var(--bg-tertiary);border-radius:var(--border-radius-sm);">
                <div class="summary-row"><span>Subtotal</span><span>${formatCurrency(sale.subtotal)}</span></div>
                ${parseFloat(sale.discount) > 0 ? `<div class="summary-row"><span>Discount</span><span>-${formatCurrency(sale.discount)}</span></div>` : ''}
                <div class="summary-row"><span>Tax</span><span>${formatCurrency(sale.tax)}</span></div>
                <div class="summary-row total"><span>Total</span><span>${formatCurrency(sale.total)}</span></div>
            </div>
            ${sale.notes ? `<p class="text-muted mt-2"><strong>Notes:</strong> ${escapeHtml(sale.notes)}</p>` : ''}
            ${sale.payment_status === 'pending_approval' ? `
                <div style="margin-top:16px; display:flex; gap:10px; justify-content: flex-end;">
                    <button class="btn btn-danger" onclick="rejectSale(${sale.id})"><i data-lucide="x-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Reject</button>
                    <button class="btn btn-success" onclick="approveSale(${sale.id})"><i data-lucide="check-circle" style="width:16px;height:16px;vertical-align:middle;margin-right:6px;"></i> Approve</button>
                </div>
            ` : ''}
        `;
        
        lucide.createIcons({ nodes: [document.getElementById('saleDetailContent')] });
        openModal('saleDetailModal');
    } catch (e) {
        showToast('Failed to load sale details', 'error');
    }
}

async function approveSale(id) {
    if (!confirm('Are you sure you want to approve this invoice?')) return;
    try {
        const result = await apiRequest('/api/sales?action=approve', {
            method: 'POST',
            body: JSON.stringify({ sale_id: id })
        });
        showToast(result.message || 'Invoice approved', 'success');
        closeModal('saleDetailModal');
        loadSales();
    } catch (e) {
        showToast(e.message || 'Failed to approve invoice', 'error');
    }
}

async function rejectSale(id) {
    if (!confirm('Are you sure you want to reject this invoice?')) return;
    try {
        const result = await apiRequest('/api/sales?action=reject', {
            method: 'POST',
            body: JSON.stringify({ sale_id: id })
        });
        showToast(result.message || 'Invoice rejected', 'success');
        closeModal('saleDetailModal');
        loadSales();
    } catch (e) {
        showToast(e.message || 'Failed to reject invoice', 'error');
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
