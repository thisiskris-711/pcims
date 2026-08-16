/**
 * Suppliers — CRUD and Details
 */
let currentSupplierPage = 1;

document.addEventListener('DOMContentLoaded', () => {
    loadSuppliers();

    const searchInput = document.getElementById('supplierSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => { currentSupplierPage = 1; loadSuppliers(); }, 300));
    }

    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', () => { currentSupplierPage = 1; loadSuppliers(); });
    }
});

async function loadSuppliers(page = 1) {
    currentSupplierPage = page;
    const search = document.getElementById('supplierSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const params = new URLSearchParams({ search, status, page, per_page: 15 });

    try {
        const data = await apiRequest(`/api/suppliers?${params}`);
        renderSuppliers(data.data || []);
        renderPagination(document.getElementById('suppliersPagination'), data.page, data.total_pages, loadSuppliers);
    } catch (e) {
        showToast('Failed to load suppliers', 'error');
    }
}

function renderSuppliers(suppliers) {
    const tbody = document.getElementById('suppliersBody');

    if (suppliers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:40px;">No suppliers found</td></tr>';
        return;
    }

    tbody.innerHTML = suppliers.map(s => {
        const statusColor = s.status === 'active' ? 'var(--success-color)' : 'var(--text-muted)';

        let actions = `<button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); viewSupplier(${s.id})" title="View Details"><i data-lucide="eye" style="width:16px;height:16px;"></i></button>`;

        if (window.CAN_EDIT) {
            actions += ` <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); openSupplierModal(${s.id})" title="Edit"><i data-lucide="pencil" style="width:16px;height:16px;"></i></button>`;
        }
        if (window.CAN_DELETE && s.status !== 'inactive') {
            actions += ` <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); deleteSupplier(${s.id}, '${escapeHtml(s.name)}')" title="Deactivate" style="color:var(--error-color);"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>`;
        }

        return `<tr class="clickable-row" onclick="viewSupplier(${s.id})">
            <td><code style="font-size:0.8rem;color:var(--primary-color);">${escapeHtml(s.supplier_code)}</code></td>
            <td>
                <div style="font-weight:500;">${escapeHtml(s.name)}</div>
                ${s.email ? `<div style="font-size:0.78rem;color:var(--text-muted);">${escapeHtml(s.email)}</div>` : ''}
            </td>
            <td>${escapeHtml(s.contact_person || '—')}</td>
            <td>${escapeHtml(s.phone || '—')}</td>
            <td><span class="status-badge" style="color:${statusColor};background:${statusColor}15;">${s.status}</span></td>
            <td>${s.total_pos || 0}</td>
            <td>${actions}</td>
        </tr>`;
    }).join('');

    lucide.createIcons();
}

function openSupplierModal(id = null) {
    document.getElementById('supplierForm').reset();
    document.getElementById('supplierId').value = '';
    document.getElementById('supplierModalTitle').textContent = 'Add Supplier';
    document.getElementById('statusGroup').style.display = 'none';

    if (id) {
        document.getElementById('supplierModalTitle').textContent = 'Edit Supplier';
        document.getElementById('statusGroup').style.display = '';
        loadSupplierForEdit(id);
    }

    openModal('supplierModal');
    lucide.createIcons();
}

async function loadSupplierForEdit(id) {
    try {
        const s = await apiRequest(`/api/suppliers?action=detail&id=${id}`);
        document.getElementById('supplierId').value = s.id;
        document.getElementById('supplierName').value = s.name;
        document.getElementById('contactPerson').value = s.contact_person || '';
        document.getElementById('supplierEmail').value = s.email || '';
        document.getElementById('supplierPhone').value = s.phone || '';
        document.getElementById('supplierAddress').value = s.address || '';
        document.getElementById('supplierStatus').value = s.status;
        document.getElementById('supplierNotes').value = s.notes || '';
    } catch (e) {
        showToast('Failed to load supplier', 'error');
    }
}

async function saveSupplier(e) {
    e.preventDefault();
    const id = document.getElementById('supplierId').value;
    const body = JSON.stringify({
        name: document.getElementById('supplierName').value,
        contact_person: document.getElementById('contactPerson').value,
        email: document.getElementById('supplierEmail').value,
        phone: document.getElementById('supplierPhone').value,
        address: document.getElementById('supplierAddress').value,
        status: id ? document.getElementById('supplierStatus').value : 'active',
        notes: document.getElementById('supplierNotes').value,
    });

    try {
        const url = id ? `/api/suppliers?id=${id}` : '/api/suppliers';
        const method = id ? 'PUT' : 'POST';
        const result = await apiRequest(url, { method, body });
        showToast(result.message, 'success');
        closeModal('supplierModal');
        loadSuppliers(currentSupplierPage);
    } catch (e) {
        showToast(e.message || 'Failed to save supplier', 'error');
    }
}

async function deleteSupplier(id, name) {
    if (!confirm(`Deactivate supplier "${name}"?`)) return;

    try {
        const result = await apiRequest(`/api/suppliers?id=${id}`, { method: 'DELETE' });
        showToast(result.message, 'success');
        loadSuppliers(currentSupplierPage);
    } catch (e) {
        showToast(e.message || 'Failed to deactivate supplier', 'error');
    }
}

async function viewSupplier(id) {
    const body = document.getElementById('detailModalBody');
    body.innerHTML = '<div class="text-center text-muted" style="padding:40px;"><span class="spinner"></span> Loading...</div>';
    openModal('detailModal');

    try {
        const s = await apiRequest(`/api/suppliers?action=detail&id=${id}`);
        document.getElementById('detailModalTitle').textContent = `${s.name} (${s.supplier_code})`;

        const statusColor = s.status === 'active' ? 'var(--success-color)' : 'var(--text-muted)';

        let poHtml = '';
        if (s.recent_pos && s.recent_pos.length > 0) {
            poHtml = `
                <h4 style="margin:20px 0 10px;font-size:0.9rem;">Recent Purchase Orders</h4>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>PO Number</th><th>Date</th><th>Amount</th><th>Status</th><th>Expected</th></tr></thead>
                        <tbody>
                            ${s.recent_pos.map(po => {
                                let badgeColor = 'var(--text-muted)';
                                if (po.status === 'ordered') badgeColor = 'var(--primary-color)';
                                else if (po.status === 'received') badgeColor = 'var(--success-color)';
                                else if (po.status === 'pending') badgeColor = 'var(--warning-color)';
                                else if (po.status === 'cancelled') badgeColor = 'var(--error-color)';
                                
                                return `
                                <tr>
                                    <td><code style="font-size:0.78rem;">${po.po_number}</code></td>
                                    <td>${new Date(po.created_at).toLocaleDateString()}</td>
                                    <td style="font-weight:500;">${formatCurrency(po.total_amount)}</td>
                                    <td><span class="status-badge" style="color:${badgeColor};background:${badgeColor}15;">${po.status}</span></td>
                                    <td>${po.expected_date ? new Date(po.expected_date).toLocaleDateString() : '—'}</td>
                                </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            poHtml = '<p class="text-muted" style="margin-top:16px;">No recent purchase orders.</p>';
        }

        body.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:20px;">
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Status</div>
                    <span class="status-badge" style="color:${statusColor};background:${statusColor}15;">${s.status}</span>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:0.88rem;">
                <div><span style="color:var(--text-muted);">Contact:</span> ${escapeHtml(s.contact_person || '—')}</div>
                <div><span style="color:var(--text-muted);">Phone:</span> ${escapeHtml(s.phone || '—')}</div>
                <div><span style="color:var(--text-muted);">Email:</span> ${escapeHtml(s.email || '—')}</div>
                <div><span style="color:var(--text-muted);">Address:</span> ${escapeHtml(s.address || '—')}</div>
            </div>
            ${s.notes ? `<div style="margin-top:12px;font-size:0.85rem;color:var(--text-muted);"><strong>Notes:</strong> ${escapeHtml(s.notes)}</div>` : ''}
            ${poHtml}
        `;
        lucide.createIcons();
    } catch (e) {
        body.innerHTML = '<div class="text-center" style="color:var(--error-color);padding:40px;">Failed to load supplier details</div>';
    }
}
