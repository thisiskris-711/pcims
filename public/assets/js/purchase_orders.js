/**
 * Purchase Orders List & Receiving Logic
 */
let currentPOPage = 1;

document.addEventListener('DOMContentLoaded', () => {
    loadPOs();

    const searchInput = document.getElementById('poSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => { currentPOPage = 1; loadPOs(); }, 300));
    }

    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', () => { currentPOPage = 1; loadPOs(); });
    }
});

window.clearFilters = function() {
    if (document.getElementById('poSearch')) document.getElementById('poSearch').value = '';
    if (document.getElementById('statusFilter')) document.getElementById('statusFilter').value = '';
    loadPOs(1);
};

function updateClearFiltersBtn() {
    const search = document.getElementById('poSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const btn = document.getElementById('clearFiltersBtn');
    if (btn) {
        btn.style.display = (search || status) ? 'inline-flex' : 'none';
        btn.style.alignItems = 'center';
    }
}

async function loadPOs(page = 1) {
    currentPOPage = page;
    const search = document.getElementById('poSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const params = new URLSearchParams({ search, status, page, per_page: 15 });
    
    updateClearFiltersBtn();

    try {
        const data = await apiRequest(`/api/purchase_orders?${params}`);
        renderPOs(data.data || []);
        renderPagination(document.getElementById('poPagination'), data.page, data.total_pages, loadPOs);
    } catch (e) {
        showToast('Failed to load purchase orders', 'error');
    }
}

function getStatusColor(status) {
    switch (status) {
        case 'draft': return '#6b7280'; // gray
        case 'pending': return '#eab308'; // yellow/amber
        case 'ordered': return '#3b82f6'; // blue
        case 'partially_received': return '#f59e0b'; // orange
        case 'received': return '#10b981'; // green
        case 'cancelled': return '#ef4444'; // red
        default: return 'var(--text-muted)';
    }
}

function renderPOs(pos) {
    const tbody = document.getElementById('poBody');

    if (pos.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="7">
                    <div style="padding: 60px 20px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 12px;">
                        <div style="width: 48px; height: 48px; border-radius: 50%; background: var(--bg-tertiary); display: flex; align-items: center; justify-content: center; margin-bottom: 8px;">
                            <i data-lucide="file-search" style="width: 24px; height: 24px; color: var(--text-muted);"></i>
                        </div>
                        <h4 style="margin: 0; color: var(--text-main); font-weight: 500;">No purchase orders found</h4>
                        <p style="margin: 0; color: var(--text-muted); font-size: 0.9rem; max-width: 300px;">
                            Try adjusting your filters or create a new purchase order to get started.
                        </p>
                    </div>
                </td>
            </tr>
        `;
        lucide.createIcons();
        return;
    }

    const formatter = new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

    tbody.innerHTML = pos.map(po => {
        const statusColor = getStatusColor(po.status);
        const expected = po.expected_date ? formatter.format(new Date(po.expected_date)) : '—';
        const created = formatter.format(new Date(po.created_at));

        let actions = '';

        if (window.CAN_EDIT) {
            if (['ordered', 'partially_received'].includes(po.status)) {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="openReceiveModal(${po.id}, '${po.po_number}')" title="Receive Items" style="color:var(--success-color);"><i data-lucide="package-check" style="width:16px;height:16px;"></i></button>`;
            }
            if (po.status === 'pending') {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="updatePOStatus(${po.id}, 'ordered')" title="Approve" style="color:var(--primary-color);"><i data-lucide="check-circle" style="width:16px;height:16px;"></i></button>`;
            }
            if (['draft', 'pending', 'ordered'].includes(po.status)) {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="updatePOStatus(${po.id}, 'cancelled')" title="Cancel" style="color:var(--error-color);"><i data-lucide="ban" style="width:16px;height:16px;"></i></button>`;
            }
            if (po.status === 'draft') {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="deletePO(${po.id})" title="Delete"><i data-lucide="trash-2" style="width:16px;height:16px;color:var(--error-color);"></i></button>`;
            }
        }

        return `<tr style="cursor:pointer; transition:background 0.15s;" onmouseover="this.style.background='var(--bg-tertiary)'" onmouseout="this.style.background=''" onclick="if(!event.target.closest('.btn') && !event.target.closest('a')) viewPO(${po.id})">
            <td style="padding:16px;">
                <a href="#" onclick="viewPO(${po.id}); return false;" style="font-weight:600; color:var(--primary-color); text-decoration:none;">${po.po_number}</a>
            </td>
            <td style="padding:16px;"><div style="font-weight:500;">${escapeHtml(po.supplier_name || 'Unknown')}</div></td>
            <td style="padding:16px; color:var(--text-muted);">${created}</td>
            <td style="padding:16px; color:var(--text-muted);">${expected}</td>
            <td style="padding:16px; text-align:right; font-weight:600;">${formatCurrency(po.total_amount)}</td>
            <td style="padding:16px; text-align:center;">
                <span class="status-badge" style="color:${statusColor}; background:${statusColor}15; font-weight:600; padding:4px 10px; font-size:0.8rem; border-radius:var(--border-radius-lg); display:inline-block;">
                    ${po.status.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                </span>
            </td>
            <td style="padding:16px; text-align:right; white-space:nowrap;">
                ${actions}
            </td>
        </tr>`;
    }).join('');

    lucide.createIcons();
}

async function viewPO(id) {
    const body = document.getElementById('detailModalBody');
    body.innerHTML = '<div class="text-center text-muted" style="padding:40px;"><span class="spinner"></span> Loading...</div>';
    openModal('detailModal');

    try {
        const po = await apiRequest(`/api/purchase_orders?action=detail&id=${id}`);
        document.getElementById('detailModalTitle').textContent = `Purchase Order: ${po.po_number}`;

        const statusColor = getStatusColor(po.status);

        let itemsHtml = `
            <div class="table-wrapper" style="margin-top:20px;">
                <table>
                    <thead>
                        <tr><th>Product</th><th>Cost</th><th>Ordered</th><th>Received</th><th>Total</th></tr>
                    </thead>
                    <tbody>
                        ${po.items.map(item => `
                            <tr>
                                <td>
                                    <div style="font-weight:500;">${escapeHtml(item.product_name)}</div>
                                    <div style="font-size:0.75rem;color:var(--text-muted);">${escapeHtml(item.sku)}</div>
                                </td>
                                <td>${formatCurrency(item.unit_cost)}</td>
                                <td>${item.quantity_ordered}</td>
                                <td>
                                    <span style="color:${item.quantity_received < item.quantity_ordered ? 'var(--warning-color)' : 'var(--success-color)'};font-weight:500;">
                                        ${item.quantity_received}
                                    </span>
                                </td>
                                <td>${formatCurrency(item.total)}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;

        body.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;">
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Supplier</div>
                    <div style="font-weight:600;">${escapeHtml(po.supplier_name)}</div>
                    <div style="font-size:0.78rem;color:var(--text-muted);">${escapeHtml(po.supplier_code)}</div>
                </div>
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Status</div>
                    <span class="status-badge" style="color:${statusColor};background:${statusColor}15;">${po.status.replace('_', ' ')}</span>
                </div>
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Total Amount</div>
                    <div style="font-weight:700;font-size:1.2rem;color:var(--primary-color);">${formatCurrency(po.total_amount)}</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px;font-size:0.88rem;">
                <div><span style="color:var(--text-muted);">Date Created:</span> ${new Date(po.created_at).toLocaleDateString()}</div>
                <div><span style="color:var(--text-muted);">Expected:</span> ${po.expected_date ? new Date(po.expected_date).toLocaleDateString() : '—'}</div>
                <div><span style="color:var(--text-muted);">Created By:</span> ${escapeHtml(po.created_by_name || '—')}</div>
            </div>
            ${po.notes ? `<div style="margin-top:12px;font-size:0.85rem;color:var(--text-muted);"><strong>Notes:</strong><br>${escapeHtml(po.notes)}</div>` : ''}
            ${itemsHtml}
        `;
    } catch (e) {
        body.innerHTML = '<div class="text-center" style="color:var(--error-color);padding:40px;">Failed to load details</div>';
    }
}

async function updatePOStatus(id, newStatus) {
    if (!confirm(`Are you sure you want to mark this PO as ${newStatus}?`)) return;
    
    try {
        const result = await apiRequest(`/api/purchase_orders?action=status&id=${id}`, {
            method: 'PUT',
            body: JSON.stringify({ status: newStatus })
        });
        showToast(result.message, 'success');
        loadPOs(currentPOPage);
    } catch (e) {
        showToast(e.message || 'Failed to update status', 'error');
    }
}

async function deletePO(id) {
    if (!confirm('Are you sure you want to delete this draft PO?')) return;

    try {
        const result = await apiRequest(`/api/purchase_orders?id=${id}`, { method: 'DELETE' });
        showToast(result.message, 'success');
        loadPOs(currentPOPage);
    } catch (e) {
        showToast(e.message || 'Failed to delete PO', 'error');
    }
}

let receivingItems = [];

async function openReceiveModal(id, poNumber) {
    document.getElementById('receivePoId').value = id;
    document.getElementById('receivePoInfo').innerHTML = `Receiving items for PO: <strong>${poNumber}</strong>`;
    const tbody = document.getElementById('receiveItemsBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center">Loading...</td></tr>';
    
    openModal('receiveModal');
    
    try {
        const po = await apiRequest(`/api/purchase_orders?action=detail&id=${id}`);
        receivingItems = po.items;
        
        tbody.innerHTML = receivingItems.map(item => {
            const pending = item.quantity_ordered - item.quantity_received;
            if (pending <= 0) {
                return `<tr>
                    <td>${escapeHtml(item.product_name)}<br><small class="text-muted">${escapeHtml(item.sku)}</small></td>
                    <td>${item.quantity_ordered}</td>
                    <td><span style="color:var(--success-color);">${item.quantity_received}</span></td>
                    <td>0</td>
                    <td><span class="status-badge" style="background:var(--success-color)15;color:var(--success-color);">Done</span></td>
                </tr>`;
            }
            return `<tr>
                <td>${escapeHtml(item.product_name)}<br><small class="text-muted">${escapeHtml(item.sku)}</small></td>
                <td>${item.quantity_ordered}</td>
                <td>${item.quantity_received}</td>
                <td><span style="color:var(--warning-color);font-weight:600;">${pending}</span></td>
                <td>
                    <input type="number" class="form-control receive-qty-input" data-id="${item.id}" value="${pending}" min="0" max="${pending}" style="width:80px;">
                </td>
            </tr>`;
        }).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading items</td></tr>';
    }
}

async function processReceive(e) {
    e.preventDefault();
    const poId = document.getElementById('receivePoId').value;
    const inputs = document.querySelectorAll('.receive-qty-input');
    
    const itemsToReceive = Array.from(inputs).map(input => ({
        item_id: parseInt(input.getAttribute('data-id')),
        quantity: parseInt(input.value) || 0
    })).filter(item => item.quantity > 0);
    
    if (itemsToReceive.length === 0) {
        showToast('Please specify at least one item to receive', 'error');
        return;
    }
    
    try {
        const result = await apiRequest('/api/purchase_orders?action=receive', {
            method: 'POST',
            body: JSON.stringify({ po_id: parseInt(poId), items: itemsToReceive })
        });
        showToast(result.message, 'success');
        closeModal('receiveModal');
        loadPOs(currentPOPage);
    } catch (e) {
        showToast(e.message || 'Failed to receive items', 'error');
    }
}
