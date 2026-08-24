<?php
/**
 * Reports Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('view_reports');
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
    <button class="tab-btn" data-tab="forecastReport" onclick="switchReportTab(this, 'forecast')">
        <i data-lucide="line-chart" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Predictive Analysis
    </button>
    <button class="tab-btn" data-tab="collectionEfficiency" onclick="switchReportTab(this, 'collection_efficiency')">
        <i data-lucide="percent" style="width:16px;height:16px;vertical-align:middle;margin-right:4px;"></i> Collection Efficiency
    </button>
</div>

<!-- Filters -->
<div class="report-filters" id="reportFilters">
    <div class="form-group">
        <label class="form-label" for="reportFrom">From</label>
        <input type="date" class="form-control" id="reportFrom" value="<?= date('Y-m-01') ?>">
    </div>
    <div class="form-group">
        <label class="form-label" for="reportTo">To</label>
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

<!-- Forecast Chart Container -->
<div class="card" id="forecastChartContainer" style="display:none; margin-bottom:16px;">
    <div class="card-body">
        <h4 style="margin:0 0 16px; font-weight: 500; font-size: 1.1rem; color: var(--text-main);">Demand Forecast (Aggr. Next 14 Days)</h4>
        <div style="height: 250px;">
            <canvas id="forecastChart"></canvas>
        </div>
    </div>
</div>

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
        <div id="reportsPagination" class="pagination"></div>
    </div>
</div>

<script>
let currentReport = 'sales';
let reportPage = 1;

function switchReportTab(btn, type) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentReport = type;
    reportPage = 1;
    
    // Show/hide date filters
    if (type === 'low_stock' || type === 'forecast') {
        document.getElementById('reportFilters').style.display = 'none';
    } else {
        document.getElementById('reportFilters').style.display = '';
    }
    
    if (type === 'forecast') {
        document.getElementById('forecastChartContainer').style.display = 'block';
    } else {
        document.getElementById('forecastChartContainer').style.display = 'none';
    }
    
    // Clear
    document.getElementById('reportSummary').style.display = 'none';
    document.getElementById('reportHead').innerHTML = '<tr><th>Click Generate</th></tr>';
    document.getElementById('reportBody').innerHTML = '<tr><td class="text-center text-muted" style="padding:40px;">Click Generate to load this report</td></tr>';
    document.getElementById('reportsPagination').innerHTML = '';
    
    if (type === 'low_stock' || type === 'forecast') loadReport();
}

async function loadReport() {
    // Reset to page 1 if this was triggered by clicking the Generate button (not a pagination click)
    // Actually we can leave reportPage alone since the Generate button would just re-fetch the current page.
    const dateFrom = document.getElementById('reportFrom')?.value || '';
    const dateTo = document.getElementById('reportTo')?.value || '';
    
    const params = new URLSearchParams({
        action: currentReport,
        date_from: dateFrom,
        date_to: dateTo,
        page: reportPage
    });
    
    try {
        const data = await apiRequest(`/api/reports?${params}`);
        renderReport(data);
    } catch (e) {
        showToast('Failed to generate report', 'error');
    }
}

function reportsGoPage(p) {
    reportPage = p;
    loadReport();
}

function exportReport() {
    const dateFrom = document.getElementById('reportFrom')?.value || '';
    const dateTo = document.getElementById('reportTo')?.value || '';
    window.open(`${APP_URL}/api/reports?action=${currentReport}&date_from=${dateFrom}&date_to=${dateTo}&export=csv`);
}

function createDraftPO(sku, qty) {
    window.location.href = `${APP_URL}/purchase-order-form?sku=${sku}&qty=${qty}`;
}

let forecastChartInstance = null;
function renderForecastChart(chartData) {
    if (!chartData) return;
    const ctx = document.getElementById('forecastChart').getContext('2d');
    
    if (forecastChartInstance) {
        forecastChartInstance.destroy();
    }
    
    forecastChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Historical Demand',
                    data: chartData.historical,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Projected Demand',
                    data: chartData.projected,
                    borderColor: '#f59e0b',
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.raw === null) return null;
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            label += context.parsed.y + ' units';
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true },
                x: { grid: { display: false } }
            }
        }
    });
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
                    <div class="stat-card emerald"><div class="stat-value">${formatCurrency(s.revenue)}</div><div class="stat-label">Total Revenue</div></div>
                    <div class="stat-card amber"><div class="stat-value">${formatCurrency(s.discounts)}</div><div class="stat-label">Total Discounts</div></div>
                    <div class="stat-card cyan"><div class="stat-value">${formatCurrency(s.taxes)}</div><div class="stat-label">Total Tax</div></div>
                `;
                break;
            case 'inventory':
                summaryEl.innerHTML = `
                    <div class="stat-card violet"><div class="stat-value">${s.total_items.toLocaleString()}</div><div class="stat-label">Total Units</div></div>
                    <div class="stat-card amber"><div class="stat-value">${formatCurrency(s.total_cost)}</div><div class="stat-label">Cost Value</div></div>
                    <div class="stat-card emerald"><div class="stat-value">${formatCurrency(s.total_retail)}</div><div class="stat-label">Retail Value</div></div>
                `;
                break;
            case 'stock_movement':
                summaryEl.innerHTML = `
                    <div class="stat-card emerald"><div class="stat-value">${s.in_count}</div><div class="stat-label">Stock In (${s.in_qty} units)</div></div>
                    <div class="stat-card rose"><div class="stat-value">${s.out_count}</div><div class="stat-label">Stock Out (${s.out_qty} units)</div></div>
                `;
                break;
            case 'forecast':
                summaryEl.innerHTML = `
                    <div class="stat-card rose"><div class="stat-value">${s.high_risk_count}</div><div class="stat-label">High Risk Stockouts</div></div>
                    <div class="stat-card violet"><div class="stat-value">${formatCurrency(s.total_reorder_value)}</div><div class="stat-label">Recommended Reorder Value</div></div>
                    <div class="stat-card emerald"><div class="stat-value">${s.growing_products}</div><div class="stat-label">Products Growing</div></div>
                    <div class="stat-card amber"><div class="stat-value">${s.overstock_risks}</div><div class="stat-label">Overstock Risks</div></div>
                `;
                break;
            case 'collection_efficiency':
                summaryEl.innerHTML = `
                    <div class="stat-card violet"><div class="stat-value">${formatCurrency(s.maturing_amount)}</div><div class="stat-label">Maturing</div></div>
                    <div class="stat-card emerald"><div class="stat-value">${formatCurrency(s.total_collected)}</div><div class="stat-label">Collected</div></div>
                    <div class="stat-card rose"><div class="stat-value">${formatCurrency(s.uncollected)}</div><div class="stat-label">Uncollected</div></div>
                    <div class="stat-card cyan"><div class="stat-value">${s.on_time_efficiency.toFixed(2)}%</div><div class="stat-label">On-Time %</div></div>
                    <div class="stat-card amber"><div class="stat-value">${s.grace_efficiency.toFixed(2)}%</div><div class="stat-label">7-Day %</div></div>
                    <div class="stat-card blue"><div class="stat-value">${s.overall_efficiency.toFixed(2)}%</div><div class="stat-label">Overall %</div></div>
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
            
        case 'forecast':
            thead.innerHTML = '<tr><th>SKU / Product</th><th>Risk Level</th><th>Forecast Demand (30d)</th><th>Confidence</th><th>Suggested Reorder</th><th>Action</th></tr>';
            tbody.innerHTML = rows.map(r => {
                let riskBadge = '';
                if (r.risk_level === 'Critical') riskBadge = '<span class="badge badge-rose">Critical</span>';
                else if (r.risk_level === 'High') riskBadge = '<span class="badge badge-amber">High</span>';
                else if (r.risk_level === 'Medium') riskBadge = '<span class="badge" style="background:#f59e0b15;color:#f59e0b">Medium</span>';
                else riskBadge = '<span class="badge badge-emerald">Low</span>';
                
                let confBadge = '';
                if (r.confidence === 'High') confBadge = '<span class="badge badge-emerald">High</span>';
                else if (r.confidence === 'Medium') confBadge = '<span class="badge badge-amber">Medium</span>';
                else confBadge = '<span class="badge badge-gray">Low</span>';
                
                let actionBtn = r.suggested_reorder > 0 ? 
                    `<button class="btn btn-sm btn-primary" onclick="createDraftPO('${r.sku}', ${r.suggested_reorder})" title="Create Draft PO"><i data-lucide="shopping-cart" style="width:14px;height:14px;vertical-align:middle;margin-right:4px;"></i> Draft PO</button>` : 
                    '<span class="text-muted" style="font-size:0.85rem;">No reorder required</span>';
                
                return `
                <tr>
                    <td>
                        <div class="font-bold">${escapeHtml(r.name)}</div>
                        <code style="color:var(--accent-cyan);font-size:0.8rem;">${r.sku}</code>
                    </td>
                    <td>${riskBadge}</td>
                    <td class="font-bold">${r.forecast_demand} units</td>
                    <td>${confBadge}</td>
                    <td>
                        <div class="font-bold text-violet" style="font-size:1.1rem;">${r.suggested_reorder > 0 ? r.suggested_reorder : '—'}</div>
                        <div style="font-size:0.75rem;color:var(--text-muted);max-width:200px;line-height:1.2;margin-top:4px;">${r.reasoning}</div>
                    </td>
                    <td style="vertical-align:middle;">${actionBtn}</td>
                </tr>`;
            }).join('');
            
            if (data.chart) renderForecastChart(data.chart);
            if (window.lucide) setTimeout(() => lucide.createIcons(), 0);
            break;
            
        case 'collection_efficiency':
            thead.innerHTML = '<tr><th>Due Date</th><th>Invoice</th><th>Customer</th><th>Maturing</th><th>Cash Purch.</th><th>On-Time</th><th>Grace</th><th>After 7</th><th>Total Col.</th><th>Uncollected</th><th>On-Time %</th><th>Grace %</th><th>Overall %</th></tr>';
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td class="text-muted">${r.due_date}</td>
                    <td><code style="color:var(--accent-cyan);">${r.invoice_no}</code></td>
                    <td class="font-bold truncate" style="max-width:120px;" title="${escapeHtml(r.customer)}">${escapeHtml(r.customer)}</td>
                    <td class="font-bold">${formatCurrency(r.maturing_amount)}</td>
                    <td class="text-muted">${formatCurrency(r.cash_purchase)}</td>
                    <td class="text-success">${formatCurrency(r.on_time)}</td>
                    <td class="text-warning">${formatCurrency(r.grace_period)}</td>
                    <td class="text-danger">${formatCurrency(r.after_grace)}</td>
                    <td class="font-bold text-success">${formatCurrency(r.total_collected)}</td>
                    <td class="font-bold text-danger">${formatCurrency(r.uncollected)}</td>
                    <td class="font-bold">${r.on_time_efficiency.toFixed(2)}%</td>
                    <td class="font-bold">${r.grace_efficiency.toFixed(2)}%</td>
                    <td class="font-bold">${r.overall_efficiency.toFixed(2)}%</td>
                </tr>`).join('');
            break;
    }
    
    if (['low_stock', 'forecast'].includes(currentReport) && typeof renderPagination === 'function') {
        renderPagination(document.getElementById('reportsPagination'), data.page, data.total_pages, reportsGoPage);
    } else {
        document.getElementById('reportsPagination').innerHTML = '';
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
