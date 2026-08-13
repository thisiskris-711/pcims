<?php
/**
 * Reports Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER);

$pageTitle = 'Reports';
$currentPage = 'reports';
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Report Tabs -->
<div class="tabs" id="reportTabs">
    <button class="tab-btn active" data-tab="salesReport" onclick="switchReportTab(this, 'sales')">
        <i data-lucide="trending-up" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Sales Report
    </button>
    <button class="tab-btn" data-tab="inventoryReport" onclick="switchReportTab(this, 'inventory')">
        <i data-lucide="warehouse" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Inventory Valuation
    </button>
    <button class="tab-btn" data-tab="stockReport" onclick="switchReportTab(this, 'stock_movement')">
        <i data-lucide="arrow-left-right" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Stock Movement
    </button>
    <button class="tab-btn" data-tab="lowStockReport" onclick="switchReportTab(this, 'low_stock')">
        <i data-lucide="alert-triangle" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Low Stock
    </button>
</div>

<!-- Filters -->
<div class="report-filters" id="reportFilters">
    <div class="form-group">
        <label class="form-label">From</label>
        <input type="date" class="form-control" id="reportFrom" value="<?= date('Y-m-01') ?>">
    </div>
    <div class="form-group">
        <label class="form-label">To</label>
        <input type="date" class="form-control" id="reportTo" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="form-group" style="display:flex;align-items:flex-end;gap:8px;">
        <button class="btn btn-primary" onclick="loadReport()">
            <i data-lucide="bar-chart-3" style="width:16px;height:16px;"></i> Generate
        </button>
        <button class="btn btn-secondary" onclick="exportReport()">
            <i data-lucide="download" style="width:16px;height:16px;"></i> Export CSV
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid mb-3" id="reportSummary" style="display:none;"></div>

<!-- Report Table -->
<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead id="reportHead"><tr><th>Generate a report to see data</th></tr></thead>
                <tbody id="reportBody">
                    <tr><td class="text-center text-muted" style="padding:40px;">Select a report type and click Generate</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let currentReport = 'sales';

function switchReportTab(btn, type) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentReport = type;
    
    // Show/hide date filters for low_stock
    document.getElementById('reportFilters').style.display = type === 'low_stock' ? 'none' : '';
    
    // Clear
    document.getElementById('reportSummary').style.display = 'none';
    document.getElementById('reportHead').innerHTML = '<tr><th>Click Generate</th></tr>';
    document.getElementById('reportBody').innerHTML = '<tr><td class="text-center text-muted" style="padding:40px;">Click Generate to load this report</td></tr>';
    
    if (type === 'low_stock') loadReport();
}

async function loadReport() {
    const dateFrom = document.getElementById('reportFrom')?.value || '';
    const dateTo = document.getElementById('reportTo')?.value || '';
    
    const params = new URLSearchParams({
        action: currentReport,
        date_from: dateFrom,
        date_to: dateTo,
    });
    
    try {
        const data = await apiRequest(`/api/reports.php?${params}`);
        renderReport(data);
    } catch (e) {
        showToast('Failed to generate report', 'error');
    }
}

function exportReport() {
    const dateFrom = document.getElementById('reportFrom')?.value || '';
    const dateTo = document.getElementById('reportTo')?.value || '';
    window.open(`${APP_URL}/api/reports.php?action=${currentReport}&date_from=${dateFrom}&date_to=${dateTo}&export=csv`);
}

function renderReport(data) {
    const thead = document.getElementById('reportHead');
    const tbody = document.getElementById('reportBody');
    const summaryEl = document.getElementById('reportSummary');
    const rows = data.data || [];
    
    // Summary cards
    if (data.summary) {
        summaryEl.style.display = 'grid';
        const s = data.summary;
        
        switch (currentReport) {
            case 'sales':
                summaryEl.innerHTML = `
                    <div class="stat-card violet"><div class="stat-value">${s.count}</div><div class="stat-label">Total Orders</div></div>
                    <div class="stat-card emerald"><div class="stat-value">$${parseFloat(s.revenue).toFixed(2)}</div><div class="stat-label">Total Revenue</div></div>
                    <div class="stat-card amber"><div class="stat-value">$${parseFloat(s.discounts).toFixed(2)}</div><div class="stat-label">Total Discounts</div></div>
                    <div class="stat-card cyan"><div class="stat-value">$${parseFloat(s.taxes).toFixed(2)}</div><div class="stat-label">Total Tax</div></div>
                `;
                break;
            case 'inventory':
                summaryEl.innerHTML = `
                    <div class="stat-card violet"><div class="stat-value">${s.total_items.toLocaleString()}</div><div class="stat-label">Total Units</div></div>
                    <div class="stat-card amber"><div class="stat-value">$${parseFloat(s.total_cost).toFixed(2)}</div><div class="stat-label">Cost Value</div></div>
                    <div class="stat-card emerald"><div class="stat-value">$${parseFloat(s.total_retail).toFixed(2)}</div><div class="stat-label">Retail Value</div></div>
                `;
                break;
            case 'stock_movement':
                summaryEl.innerHTML = `
                    <div class="stat-card emerald"><div class="stat-value">${s.in_count}</div><div class="stat-label">Stock In (${s.in_qty} units)</div></div>
                    <div class="stat-card rose"><div class="stat-value">${s.out_count}</div><div class="stat-label">Stock Out (${s.out_qty} units)</div></div>
                `;
                break;
            default:
                summaryEl.style.display = 'none';
        }
    } else {
        summaryEl.style.display = 'none';
    }
    
    if (rows.length === 0) {
        thead.innerHTML = '<tr><th>No data</th></tr>';
        tbody.innerHTML = '<tr><td class="text-center text-muted" style="padding:40px;">No records found for this period</td></tr>';
        return;
    }
    
    // Table headers and rows based on report type
    switch (currentReport) {
        case 'sales':
            thead.innerHTML = '<tr><th>Invoice</th><th>Customer</th><th>Items</th><th>Subtotal</th><th>Discount</th><th>Tax</th><th>Total</th><th>Payment</th><th>Date</th></tr>';
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td><code style="color:var(--accent-cyan);">${r.invoice_no}</code></td>
                    <td>${escapeHtml(r.customer_name)}</td>
                    <td>${r.item_count}</td>
                    <td>${formatCurrency(r.subtotal)}</td>
                    <td class="text-muted">${formatCurrency(r.discount)}</td>
                    <td class="text-muted">${formatCurrency(r.tax)}</td>
                    <td class="font-bold text-success">${formatCurrency(r.total)}</td>
                    <td><span class="badge badge-blue">${r.payment_method}</span></td>
                    <td class="text-muted">${new Date(r.created_at).toLocaleDateString()}</td>
                </tr>`).join('');
            break;
            
        case 'inventory':
            thead.innerHTML = '<tr><th>SKU</th><th>Product</th><th>Category</th><th>Cost</th><th>Price</th><th>Stock</th><th>Cost Value</th><th>Retail Value</th><th>Status</th></tr>';
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td><code style="color:var(--accent-cyan);">${r.sku}</code></td>
                    <td class="font-bold">${escapeHtml(r.name)}</td>
                    <td class="text-muted">${r.category || '—'}</td>
                    <td>${formatCurrency(r.cost_price)}</td>
                    <td>${formatCurrency(r.selling_price)}</td>
                    <td class="${r.quantity <= r.low_stock_threshold ? 'text-danger' : ''} font-bold">${r.quantity}</td>
                    <td class="text-muted">${formatCurrency(r.cost_value)}</td>
                    <td class="text-success font-bold">${formatCurrency(r.retail_value)}</td>
                    <td><span class="status status-${r.status}">${r.status}</span></td>
                </tr>`).join('');
            break;
            
        case 'stock_movement':
            thead.innerHTML = '<tr><th>Reference</th><th>Type</th><th>Product</th><th>SKU</th><th>Qty</th><th>Balance</th><th>Notes</th><th>User</th><th>Date</th></tr>';
            const typeClass = { 'in': 'badge-emerald', 'out': 'badge-rose', 'adjustment': 'badge-amber' };
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td><code style="color:var(--accent-cyan);">${r.reference_no || '—'}</code></td>
                    <td><span class="badge ${typeClass[r.type] || 'badge-gray'}">${r.type}</span></td>
                    <td class="font-bold">${escapeHtml(r.product_name)}</td>
                    <td class="text-muted">${r.sku}</td>
                    <td class="${r.type === 'in' ? 'text-success' : 'text-danger'} font-bold">${r.type === 'in' ? '+' : '-'}${r.quantity}</td>
                    <td>${r.balance_after}</td>
                    <td class="text-muted truncate" style="max-width:150px;">${r.notes || '—'}</td>
                    <td class="text-muted">${r.user_name || '—'}</td>
                    <td class="text-muted">${new Date(r.created_at).toLocaleDateString()}</td>
                </tr>`).join('');
            break;
            
        case 'low_stock':
            thead.innerHTML = '<tr><th>SKU</th><th>Product</th><th>Category</th><th>Stock</th><th>Threshold</th><th>Deficit</th><th>Cost</th><th>Price</th></tr>';
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td><code style="color:var(--accent-cyan);">${r.sku}</code></td>
                    <td class="font-bold">${escapeHtml(r.name)}</td>
                    <td class="text-muted">${r.category || '—'}</td>
                    <td class="text-danger font-bold">${r.quantity}</td>
                    <td class="text-muted">${r.low_stock_threshold}</td>
                    <td class="text-warning font-bold">${r.deficit}</td>
                    <td>${formatCurrency(r.cost_price)}</td>
                    <td>${formatCurrency(r.selling_price)}</td>
                </tr>`).join('');
            break;
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
