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

async function loadPOs(page = 1) {
    currentPOPage = page;
    const search = document.getElementById('poSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const params = new URLSearchParams({ search, status, page, per_page: 15 });

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
        case 'draft': return 'var(--text-muted)';
        case 'pending': return 'var(--warning-color)';
        case 'ordered': return 'var(--primary-color)';
        case 'partially_received': return '#f59e0b'; // orange
        case 'received': return 'var(--success-color)';
        case 'cancelled': return 'var(--error-color)';
        default: return 'var(--text-muted)';
    }
}

function renderPOs(pos) {
    const tbody = document.getElementById('poBody');

    if (pos.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:40px;">No purchase orders found</td></tr>';
        return;
    }

    tbody.innerHTML = pos.map(po => {
        const statusColor = getStatusColor(po.status);
        const expected = po.expected_date ? new Date(po.expected_date).toLocaleDateString() : '—';
        const created = new Date(po.created_at).toLocaleDateString();

        let actions = `<button class="btn btn-sm btn-ghost" onclick="viewPO(${po.id})" title="View Details"><i data-lucide="eye" style="width:16px;height:16px;"></i></button>`;

        if (window.CAN_EDIT) {
            if (['ordered', 'partially_received'].includes(po.status)) {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="openReceiveModal(${po.id}, '${po.po_number}')" title="Receive Items" style="color:var(--success-color);"><i data-lucide="package-check" style="width:16px;height:16px;"></i></button>`;
            }
            if (po.status === 'pending') {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="updatePOStatus(${po.id}, 'ordered')" title="Mark as Ordered" style="color:var(--primary-color);"><i data-lucide="send" style="width:16px;height:16px;"></i></button>`;
            }
            if (['draft', 'pending', 'ordered'].includes(po.status)) {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="updatePOStatus(${po.id}, 'cancelled')" title="Cancel" style="color:var(--error-color);"><i data-lucide="x-circle" style="width:16px;height:16px;"></i></button>`;
            }
            if (po.status === 'draft') {
                actions += ` <button class="btn btn-sm btn-ghost" onclick="deletePO(${po.id})" title="Delete"><i data-lucide="trash-2" style="width:16px;height:16px;color:var(--error-color);"></i></button>`;
            }
        }

        return `<tr>
            <td><code style="color:var(--primary-color);font-size:0.8rem;">${po.po_number}</code></td>
            <td><div style="font-weight:500;">${escapeHtml(po.supplier_name || 'Unknown')}</div></td>
            <td>${created}</td>
            <td>${expected}</td>
            <td style="font-weight:600;">${formatCurrency(po.total_amount)}</td>
            <td><span class="status-badge" style="color:${statusColor};background:${statusColor}15;">${po.status.replace('_', ' ')}</span></td>
            <td>${actions}</td>
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
