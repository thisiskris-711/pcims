<?php
/**
 * Sales History Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER, ROLE_AUDITOR);

$pageTitle = 'Sales History';
$currentPage = 'sales';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <input type="date" class="form-control" id="salesDateFrom" style="width:auto;">
        <input type="date" class="form-control" id="salesDateTo" style="width:auto;">
        <select class="form-control" id="salesPayment" style="width:auto;min-width:130px;">
            <option value="">All Methods</option>
            <option value="cash">Cash</option>
            <option value="card">Card</option>
            <option value="transfer">Transfer</option>
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
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="salesBody">
                    <tr><td colspan="11" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
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
    ['salesDateFrom', 'salesDateTo', 'salesPayment'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => { salesPage = 1; loadSales(); });
    });
});

async function loadSales() {
    const params = new URLSearchParams({
        date_from: document.getElementById('salesDateFrom')?.value || '',
        date_to: document.getElementById('salesDateTo')?.value || '',
        payment_method: document.getElementById('salesPayment')?.value || '',
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
        tbody.innerHTML = '<tr><td colspan="11"><div class="empty-state"><i data-lucide="receipt" style="width:48px;height:48px;"></i><h3>No Sales Found</h3><p>Sales will appear here after checkout.</p></div></td></tr>';
        lucide.createIcons({ nodes: [tbody] });
        document.getElementById('salesPagination').innerHTML = '';
        return;
    }
    
    const paymentBadge = { cash: 'badge-emerald', card: 'badge-blue', transfer: 'badge-violet', other: 'badge-gray' };
    
    tbody.innerHTML = sales.map(s => `
        <tr class="clickable-row" onclick="viewSaleDetail(${s.id})">
            <td><code style="font-size:0.8rem;color:var(--accent-cyan);">${escapeHtml(s.invoice_no)}</code></td>
            <td>${escapeHtml(s.dealer_name || '—')}${s.dealer_code ? ` <code style="font-size:0.72rem;color:var(--text-muted);">${s.dealer_code}</code>` : ''}</td>
            <td><span class="badge badge-violet">${s.item_count}</span></td>
            <td>${formatCurrency(s.subtotal)}</td>
            <td class="text-muted">${parseFloat(s.discount) > 0 ? '-' + formatCurrency(s.discount) : '—'}</td>
            <td class="text-muted">${formatCurrency(s.tax)}</td>
            <td class="font-bold text-success">${formatCurrency(s.total)}</td>
            <td><span class="badge ${paymentBadge[s.payment_method] || 'badge-gray'}">${s.payment_method}</span></td>
            <td><span class="status status-${s.payment_status}">${s.payment_status}</span></td>
            <td class="text-muted">${new Date(s.created_at).toLocaleDateString()}</td>
            <td>
                <button class="btn btn-ghost btn-icon sm" title="View Details" onclick="event.stopPropagation(); viewSaleDetail(${s.id})">
                    <i data-lucide="eye" style="width:15px;height:15px;"></i>
                </button>
            </td>
        </tr>
    `).join('');
    
    lucide.createIcons({ nodes: [tbody] });
    
    // Pagination
    const { page, total_pages } = response;
    const pagEl = document.getElementById('salesPagination');
    if (total_pages <= 1) { pagEl.innerHTML = ''; return; }
    let html = `<a class="${page<=1?'disabled':''}" onclick="salesGoPage(${page-1})">&laquo;</a>`;
    for (let i = Math.max(1,page-2); i <= Math.min(total_pages,page+2); i++) {
        html += `<a class="${i===page?'active':''}" onclick="salesGoPage(${i})">${i}</a>`;
    }
    html += `<a class="${page>=total_pages?'disabled':''}" onclick="salesGoPage(${page+1})">&raquo;</a>`;
    pagEl.innerHTML = html;
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
                <td class="text-muted">${parseFloat(item.discount) > 0 ? formatCurrency(item.discount) : '—'}</td>
                <td class="font-bold">${formatCurrency(item.total)}</td>
            </tr>
        `).join('');
        
        document.getElementById('saleDetailContent').innerHTML = `
            <div class="grid-2 mb-2">
                <div>
                    <p><strong>Invoice:</strong> ${sale.invoice_no}</p>
                    <p><strong>Dealer:</strong> ${escapeHtml(sale.dealer_name || 'N/A')} ${sale.dealer_code ? `<code style="font-size:0.78rem;">${sale.dealer_code}</code>` : ''}</p>
                    <p><strong>Cashier:</strong> ${escapeHtml(sale.cashier || 'N/A')}</p>
                </div>
                <div>
                    <p><strong>Date:</strong> ${new Date(sale.created_at).toLocaleString()}</p>
                    <p><strong>Payment:</strong> <span class="badge badge-blue">${sale.payment_method}</span></p>
                    <p><strong>Status:</strong> <span class="status status-${sale.payment_status}">${sale.payment_status}</span></p>
                </div>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>Product</th><th>SKU</th><th class="text-center">Qty</th><th>Price</th><th>Discount</th><th>Total</th></tr></thead>
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
        `;
        
        openModal('saleDetailModal');
    } catch (e) {
        showToast('Failed to load sale details', 'error');
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
