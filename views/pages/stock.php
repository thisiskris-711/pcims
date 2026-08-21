<?php
/**
 * Stock Movement Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_inventory');
$db = getDB();
$products = $db->query("SELECT id, name, sku, quantity, image FROM products WHERE status='active' AND type != 'bundle' ORDER BY name")->fetchAll();

$pageTitle = 'Stock Movement';
$currentPage = 'stock';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <select class="form-control" id="stockType" style="width:auto;min-width:140px;">
            <option value="">All Types</option>
            <option value="in">Stock In</option>
            <option value="out">Stock Out</option>
            <option value="adjustment">Adjustment</option>
        </select>
        <input type="date" class="form-control" id="dateFrom" style="width:auto;">
        <input type="date" class="form-control" id="dateTo" style="width:auto;">
    </div>
    <div class="toolbar-right">
        <button class="btn btn-success" onclick="openStockModal('in')">
            <i data-lucide="arrow-down-left" style="width:18px;height:18px;"></i> Stock In
        </button>
        <button class="btn btn-danger" onclick="openStockModal('out')">
            <i data-lucide="arrow-up-right" style="width:18px;height:18px;"></i> Stock Out
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Balance</th>
                        <th>Notes</th>
                        <th>By</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody id="stockBody">
                    <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="stockPagination" class="pagination"></div>
    </div>
</div>

<!-- Stock Transaction Modal -->
<div class="modal" id="stockModal">
    <div class="modal-header">
        <h3 class="modal-title" id="stockModalTitle">Record Stock In</h3>
        <button class="modal-close" onclick="closeModal('stockModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="stockForm" onsubmit="submitStock(event)">
            <input type="hidden" id="stockTypeInput" value="in">
            <div class="form-group">
                <label class="form-label" for="stockProduct">Product *</label>
                <select class="form-control" id="stockProduct" required>
                    <option value="">Select product</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" data-qty="<?= $p['quantity'] ?>" data-image="<?= sanitize($p['image'] ?? '') ?>" data-name="<?= sanitize($p['name']) ?>" data-sku="<?= sanitize($p['sku']) ?>">
                        <?= sanitize($p['name']) ?> (<?= sanitize($p['sku']) ?>) — Stock: <?= $p['quantity'] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="stockQty">Quantity *</label>
                <input type="number" class="form-control" id="stockQty" min="1" required placeholder="Enter quantity">
            </div>
            <div class="form-group">
                <label class="form-label" for="stockNotes">Notes</label>
                <textarea class="form-control" id="stockNotes" rows="3" placeholder="Reason or reference details"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('stockModal')">Cancel</button>
        <button class="btn btn-primary" id="stockSubmitBtn" onclick="document.getElementById('stockForm').requestSubmit()">
            <i data-lucide="check" style="width:16px;height:16px;"></i> Record
        </button>
    </div>
</div>

<!-- Tom Select Library -->
<link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>

<script>
let stockPage = 1;
let stockProductSelect;

document.addEventListener('DOMContentLoaded', () => {
    loadStockHistory();
    
    // Initialize Tom Select for Product dropdown
    stockProductSelect = new TomSelect('#stockProduct', {
        searchField: ['text', 'sku'],
        render: {
            option: function(data, escape) {
                if (data.value === '') return `<div>Select product</div>`;
                const img = data.image ? `<img src="${window.APP_URL}/uploads/products/${escape(data.image)}" style="width:32px;height:32px;object-fit:cover;border-radius:4px;margin-right:12px;flex-shrink:0;">` : `<div style="width:32px;height:32px;border-radius:4px;background:var(--bg-hover);margin-right:12px;flex-shrink:0;display:flex;align-items:center;justify-content:center;"><i data-lucide="image" style="width:16px;height:16px;color:var(--text-muted);"></i></div>`;
                
                return `<div style="display:flex;align-items:center;padding:6px 12px;">
                            ${img}
                            <div>
                                <div style="font-weight:500;color:var(--text-main);">${escape(data.name)}</div>
                                <div style="font-size:0.8rem;color:var(--text-muted);">${escape(data.sku)} &mdash; Stock: ${escape(data.qty)}</div>
                            </div>
                        </div>`;
            },
            item: function(data, escape) {
                if (data.value === '') return `<div>Select product</div>`;
                const img = data.image ? `<img src="${window.APP_URL}/uploads/products/${escape(data.image)}" style="width:20px;height:20px;object-fit:cover;border-radius:4px;margin-right:8px;vertical-align:middle;">` : '';
                return `<div style="display:flex;align-items:center;">
                            ${img}
                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escape(data.name)} (${escape(data.sku)})</span>
                        </div>`;
            }
        },
        onDropdownOpen: function() {
            lucide.createIcons();
        }
    });
    
    ['stockType', 'dateFrom', 'dateTo'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => { stockPage = 1; loadStockHistory(); });
    });
});

async function loadStockHistory() {
    const type = document.getElementById('stockType')?.value || '';
    const dateFrom = document.getElementById('dateFrom')?.value || '';
    const dateTo = document.getElementById('dateTo')?.value || '';
    
    const params = new URLSearchParams({ type, date_from: dateFrom, date_to: dateTo, page: stockPage });
    
    try {
        const data = await apiRequest(`/api/stock?${params}`);
        renderStock(data);
    } catch (e) {
        showToast('Failed to load stock history', 'error');
    }
}

function renderStock(response) {
    const tbody = document.getElementById('stockBody');
    const items = response.data || [];
    
    if (items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8"><div class="empty-state"><i data-lucide="arrow-left-right" style="width:48px;height:48px;"></i><h3>No Transactions</h3><p>Record your first stock movement.</p></div></td></tr>';
        lucide.createIcons({ nodes: [tbody] });
        document.getElementById('stockPagination').innerHTML = '';
        return;
    }
    
    const typeMap = { 'in': ['Stock In', 'badge-emerald'], 'out': ['Stock Out', 'badge-rose'], 'adjustment': ['Adjustment', 'badge-amber'] };
    
    tbody.innerHTML = items.map(s => {
        const [label, cls] = typeMap[s.type] || ['Unknown', 'badge-gray'];
        return `
        <tr>
            <td><code style="font-size:0.8rem;color:var(--accent-cyan);">${escapeHtml(s.reference_no || '—')}</code></td>
            <td><span class="badge ${cls}">${label}</span></td>
            <td>
                <div class="font-bold" style="font-size:0.85rem;">${escapeHtml(s.product_name)}</div>
                <div class="text-muted" style="font-size:0.75rem;">${escapeHtml(s.sku)}</div>
            </td>
            <td class="font-bold ${s.type === 'in' ? 'text-success' : 'text-danger'}">
                ${s.type === 'in' ? '+' : '-'}${s.quantity}
            </td>
            <td>${s.balance_after}</td>
            <td class="text-muted" style="max-width:200px;" title="${escapeHtml(s.notes || '')}">${s.notes ? escapeHtml(s.notes).substring(0, 40) : '—'}</td>
            <td class="text-muted">${escapeHtml(s.user_name || '—')}</td>
            <td class="text-muted">${new Date(s.created_at).toLocaleDateString()}</td>
        </tr>`;
    }).join('');
    
    lucide.createIcons({ nodes: [tbody] });
    
    renderPagination(document.getElementById('stockPagination'), response.page, response.total_pages, stockGoPage);
}

function stockGoPage(p) { stockPage = p; loadStockHistory(); }

function openStockModal(type) {
    document.getElementById('stockTypeInput').value = type;
    document.getElementById('stockModalTitle').textContent = type === 'in' ? 'Record Stock In' : 'Record Stock Out';
    document.getElementById('stockSubmitBtn').className = `btn ${type === 'in' ? 'btn-success' : 'btn-danger'}`;
    if (stockProductSelect) stockProductSelect.clear();
    document.getElementById('stockQty').value = '';
    document.getElementById('stockNotes').value = '';
    openModal('stockModal');
}

async function submitStock(e) {
    e.preventDefault();
    
    const body = JSON.stringify({
        product_id: document.getElementById('stockProduct').value,
        type: document.getElementById('stockTypeInput').value,
        quantity: document.getElementById('stockQty').value,
        notes: document.getElementById('stockNotes').value,
    });
    
    try {
        const result = await apiRequest('/api/stock', { method: 'POST', body });
        showToast(result.message || 'Stock recorded');
        closeModal('stockModal');
        loadStockHistory();
    } catch (e) {
        showToast(e.message || 'Failed to record stock', 'error');
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
