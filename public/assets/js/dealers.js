/**
 * Dealers — CRUD, Payment Recording, Credit History
 */
let currentDealerPage = 1;

document.addEventListener('DOMContentLoaded', () => {
    loadDealers();

    const searchInput = document.getElementById('dealerSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => { currentDealerPage = 1; loadDealers(); }, 300));
    }

    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', () => { currentDealerPage = 1; loadDealers(); });
    }
});

async function loadDealers(page = 1) {
    currentDealerPage = page;
    const search = document.getElementById('dealerSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const params = new URLSearchParams({ search, status, page, per_page: 15 });

    try {
        const data = await apiRequest(`/api/dealers?${params}`);
        renderDealers(data.data || []);
        renderPagination(document.getElementById('dealersPagination'), data.page, data.total_pages, loadDealers);
    } catch (e) {
        showToast('Failed to load dealers', 'error');
    }
}

function renderDealers(dealers) {
    const tbody = document.getElementById('dealersBody');

    if (dealers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted" style="padding:40px;">No dealers found</td></tr>';
        return;
    }

    tbody.innerHTML = dealers.map(d => {
        const statusColor = d.status === 'active' ? 'var(--success-color)' : (d.status === 'suspended' ? 'var(--warning-color)' : 'var(--text-muted)');
        const balance = parseFloat(d.credit_balance);
        const limit = parseFloat(d.credit_limit);
        const utilization = limit > 0 ? ((balance / limit) * 100).toFixed(0) : 0;
        const barColor = utilization > 80 ? 'var(--error-color)' : (utilization > 50 ? 'var(--warning-color)' : 'var(--success-color)');

        let actions = `<button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); viewDealer(${d.id})" title="View Details"><i data-lucide="eye" style="width:16px;height:16px;"></i></button>`;

        if (window.CAN_RECORD_PAYMENT && balance > 0) {
            actions += ` <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); openPaymentModal(${d.id}, '${escapeHtml(d.name)}', ${d.credit_balance})" title="Record Payment" style="color:var(--success-color);"><i data-lucide="banknote" style="width:16px;height:16px;"></i></button>`;
        }
        if (window.CAN_EDIT) {
            actions += ` <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); openDealerModal(${d.id})" title="Edit"><i data-lucide="pencil" style="width:16px;height:16px;"></i></button>`;
        }
        if (window.CAN_DELETE && d.status !== 'inactive') {
            actions += ` <button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); deleteDealer(${d.id}, '${escapeHtml(d.name)}')" title="Deactivate" style="color:var(--error-color);"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>`;
        }

        return `<tr class="clickable-row" onclick="viewDealer(${d.id})">
            <td><code style="font-size:0.8rem;color:var(--primary-color);">${escapeHtml(d.dealer_code)}</code></td>
            <td>
                <div style="font-weight:500;">${escapeHtml(d.name)}</div>
                ${d.email ? `<div style="font-size:0.78rem;color:var(--text-muted);">${escapeHtml(d.email)}</div>` : ''}
            </td>
            <td>${escapeHtml(d.contact_person || '—')}</td>
            <td>${escapeHtml(d.phone || '—')}</td>
            <td>${formatCurrency(d.credit_limit)}</td>
            <td>
                <div style="font-weight:500;color:${balance > 0 ? 'var(--warning-color)' : 'var(--text-muted)'};">${formatCurrency(d.credit_balance)}</div>
                ${limit > 0 ? `<div style="background:var(--bg-tertiary);border-radius:4px;height:4px;margin-top:4px;overflow:hidden;"><div style="width:${Math.min(utilization, 100)}%;height:100%;background:${barColor};border-radius:4px;"></div></div>` : ''}
            </td>
            <td><span class="status-badge" style="color:${statusColor};background:${statusColor}15;">${d.status}</span></td>
            <td>${d.total_sales || 0}</td>
            <td>${actions}</td>
        </tr>`;
    }).join('');

    lucide.createIcons();
}

function openDealerModal(id = null) {
    document.getElementById('dealerForm').reset();
    document.getElementById('dealerId').value = '';
    document.getElementById('dealerModalTitle').textContent = 'Add Dealer';
    document.getElementById('statusGroup').style.display = 'none';

    if (id) {
        document.getElementById('dealerModalTitle').textContent = 'Edit Dealer';
        document.getElementById('statusGroup').style.display = '';
        loadDealerForEdit(id);
    }

    openModal('dealerModal');
    lucide.createIcons();
}

async function loadDealerForEdit(id) {
    try {
        const d = await apiRequest(`/api/dealers?action=detail&id=${id}`);
        document.getElementById('dealerId').value = d.id;
        document.getElementById('dealerName').value = d.name;
        document.getElementById('contactPerson').value = d.contact_person || '';
        document.getElementById('dealerEmail').value = d.email || '';
        document.getElementById('dealerPhone').value = d.phone || '';
        document.getElementById('dealerAddress').value = d.address || '';
        document.getElementById('creditLimit').value = d.credit_limit;
        document.getElementById('dealerStatus').value = d.status;
        document.getElementById('dealerNotes').value = d.notes || '';
    } catch (e) {
        showToast('Failed to load dealer', 'error');
    }
}

async function saveDealer(e) {
    e.preventDefault();
    const id = document.getElementById('dealerId').value;
    const body = JSON.stringify({
        name: document.getElementById('dealerName').value,
        contact_person: document.getElementById('contactPerson').value,
        email: document.getElementById('dealerEmail').value,
        phone: document.getElementById('dealerPhone').value,
        address: document.getElementById('dealerAddress').value,
        credit_limit: parseFloat(document.getElementById('creditLimit').value) || 0,
        status: id ? document.getElementById('dealerStatus').value : 'active',
        notes: document.getElementById('dealerNotes').value,
    });

    try {
        const url = id ? `/api/dealers?id=${id}` : '/api/dealers';
        const method = id ? 'PUT' : 'POST';
        const result = await apiRequest(url, { method, body });
        showToast(result.message, 'success');
        closeModal('dealerModal');
        loadDealers(currentDealerPage);
    } catch (e) {
        showToast(e.message || 'Failed to save dealer', 'error');
    }
}

async function deleteDealer(id, name) {
    if (!confirm(`Deactivate dealer "${name}"? They will no longer appear in sales.`)) return;

    try {
        const result = await apiRequest(`/api/dealers?id=${id}`, { method: 'DELETE' });
        showToast(result.message, 'success');
        loadDealers(currentDealerPage);
    } catch (e) {
        showToast(e.message || 'Failed to deactivate dealer', 'error');
    }
}

function openPaymentModal(dealerId, dealerName, balance) {
    document.getElementById('paymentForm').reset();
    document.getElementById('paymentDealerId').value = dealerId;
    document.getElementById('paymentAmount').max = balance;
    document.getElementById('paymentDealerInfo').innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <div style="font-weight:600;">${escapeHtml(dealerName)}</div>
                <div style="font-size:0.82rem;color:var(--text-muted);">Outstanding Balance</div>
            </div>
            <div style="font-size:1.3rem;font-weight:700;color:var(--warning-color);">${formatCurrency(balance)}</div>
        </div>
    `;
    openModal('paymentModal');
    lucide.createIcons();
}

async function processPayment(e) {
    e.preventDefault();
    const dealerId = document.getElementById('paymentDealerId').value;
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const notes = document.getElementById('paymentNotes').value;

    if (!amount || amount <= 0) {
        showToast('Please enter a valid payment amount', 'error');
        return;
    }

    const body = JSON.stringify({ dealer_id: parseInt(dealerId), amount, notes });

    try {
        const result = await apiRequest('/api/credits?action=payment', { method: 'POST', body });
        showToast(result.message, 'success');
        closeModal('paymentModal');
        loadDealers(currentDealerPage);
    } catch (e) {
        showToast(e.message || 'Failed to record payment', 'error');
    }
}

async function viewDealer(id) {
    const body = document.getElementById('detailModalBody');
    body.innerHTML = '<div class="text-center text-muted" style="padding:40px;"><span class="spinner"></span> Loading...</div>';
    openModal('detailModal');

    try {
        const d = await apiRequest(`/api/dealers?action=detail&id=${id}`);
        document.getElementById('detailModalTitle').textContent = `${d.name} (${d.dealer_code})`;

        const statusColor = d.status === 'active' ? 'var(--success-color)' : (d.status === 'suspended' ? 'var(--warning-color)' : 'var(--text-muted)');
        const balance = parseFloat(d.credit_balance);
        const limit = parseFloat(d.credit_limit);
        const available = limit - balance;

        let creditHistoryHtml = '';
        if (d.credit_history && d.credit_history.length > 0) {
            creditHistoryHtml = `
                <h4 style="margin:20px 0 10px;font-size:0.9rem;">Credit History</h4>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance</th><th>Reference</th><th>Notes</th><th>By</th></tr></thead>
                        <tbody>
                            ${d.credit_history.map(tx => `
                                <tr>
                                    <td>${new Date(tx.created_at).toLocaleDateString()}</td>
                                    <td><span class="status-badge" style="color:${tx.type === 'charge' ? 'var(--error-color)' : 'var(--success-color)'};background:${tx.type === 'charge' ? 'var(--error-color)' : 'var(--success-color)'}15;">${tx.type}</span></td>
                                    <td style="font-weight:500;">${tx.type === 'charge' ? '+' : '−'}${formatCurrency(tx.amount)}</td>
                                    <td>${formatCurrency(tx.balance_after)}</td>
                                    <td><code style="font-size:0.78rem;">${escapeHtml(tx.reference_no || '—')}</code></td>
                                    <td style="font-size:0.82rem;">${escapeHtml(tx.notes || '—')}</td>
                                    <td>${escapeHtml(tx.processed_by || '—')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            creditHistoryHtml = '<p class="text-muted" style="margin-top:16px;">No credit transactions yet.</p>';
        }

        let salesHtml = '';
        if (d.recent_sales && d.recent_sales.length > 0) {
            salesHtml = `
                <h4 style="margin:20px 0 10px;font-size:0.9rem;">Recent Sales</h4>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Invoice</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            ${d.recent_sales.map(s => `
                                <tr>
                                    <td><code style="font-size:0.78rem;">${s.invoice_no}</code></td>
                                    <td style="font-weight:500;">${formatCurrency(s.total)}</td>
                                    <td>${s.payment_method}</td>
                                    <td><span class="status-badge" style="color:${s.payment_status === 'paid' ? 'var(--success-color)' : 'var(--warning-color)'};background:${s.payment_status === 'paid' ? 'var(--success-color)' : 'var(--warning-color)'}15;">${s.payment_status}</span></td>
                                    <td>${new Date(s.created_at).toLocaleDateString()}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }

        body.innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:20px;">
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Status</div>
                    <span class="status-badge" style="color:${statusColor};background:${statusColor}15;">${d.status}</span>
                </div>
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Credit Limit</div>
                    <div style="font-weight:600;font-size:1.1rem;">${formatCurrency(limit)}</div>
                </div>
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Outstanding Balance</div>
                    <div style="font-weight:600;font-size:1.1rem;color:${balance > 0 ? 'var(--warning-color)' : 'var(--text-muted)'};">${formatCurrency(balance)}</div>
                </div>
                <div style="background:var(--bg-tertiary);padding:16px;border-radius:var(--border-radius-sm);">
                    <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">Available Credit</div>
                    <div style="font-weight:600;font-size:1.1rem;color:var(--success-color);">${formatCurrency(available)}</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:0.88rem;">
                <div><span style="color:var(--text-muted);">Contact:</span> ${escapeHtml(d.contact_person || '—')}</div>
                <div><span style="color:var(--text-muted);">Phone:</span> ${escapeHtml(d.phone || '—')}</div>
                <div><span style="color:var(--text-muted);">Email:</span> ${escapeHtml(d.email || '—')}</div>
                <div><span style="color:var(--text-muted);">Address:</span> ${escapeHtml(d.address || '—')}</div>
            </div>
            ${d.notes ? `<div style="margin-top:12px;font-size:0.85rem;color:var(--text-muted);"><strong>Notes:</strong> ${escapeHtml(d.notes)}</div>` : ''}
            ${creditHistoryHtml}
            ${salesHtml}
        `;
        lucide.createIcons();
    } catch (e) {
        body.innerHTML = '<div class="text-center" style="color:var(--error-color);padding:40px;">Failed to load dealer details</div>';
    }
}
