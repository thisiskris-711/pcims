/**
 * Dealers — CRUD, Payment Recording, Credit History
 */
let currentDealerPage = 1;

document.addEventListener('DOMContentLoaded', () => {
    loadDealers();
    fetchPendingCount();

    const searchInput = document.getElementById('dealerSearch');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(() => { currentDealerPage = 1; loadDealers(); }, 300));
    }

    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', () => { currentDealerPage = 1; loadDealers(); });
    }
    
    const creditStatusFilter = document.getElementById('creditStatusFilter');
    if (creditStatusFilter) {
        creditStatusFilter.addEventListener('change', () => { currentDealerPage = 1; loadDealers(); });
    }
    
    const sortFilter = document.getElementById('sortFilter');
    if (sortFilter) {
        sortFilter.addEventListener('change', () => { currentDealerPage = 1; loadDealers(); });
    }
});

async function fetchPendingCount() {
    try {
        const res = await apiRequest('/api/dealer_applications?status=pending&per_page=1');
        const count = res.total || 0;
        const countSpan = document.getElementById('pendingAppCount');
        if (countSpan) {
            countSpan.textContent = count;
            countSpan.style.display = count > 0 ? 'inline-block' : 'none';
        }
    } catch (e) {
        // fail silently
    }
}

async function loadDealers(page = 1) {
    currentDealerPage = page;
    const search = document.getElementById('dealerSearch')?.value || '';
    const status = document.getElementById('statusFilter')?.value || '';
    const credit_status = document.getElementById('creditStatusFilter')?.value || '';
    const sort = document.getElementById('sortFilter')?.value || 'name';
    const params = new URLSearchParams({ search, status, credit_status, sort, page, per_page: 15 });

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
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted" style="padding:40px;">No dealers found</td></tr>';
        return;
    }

    tbody.innerHTML = dealers.map(d => {
        let displayStatus = d.status;
        let statusColor = d.status === 'active' ? 'var(--success-color)' : (d.status === 'suspended' ? 'var(--warning-color)' : 'var(--text-muted)');
        
        const balance = parseFloat(d.credit_balance);
        const limit = parseFloat(d.credit_limit);
        const utilization = limit > 0 ? (balance / limit) : 0;
        const utilPercent = (utilization * 100).toFixed(1);
        
        let warningIcon = '';
        if (d.status === 'active' && utilization > 1.0) {
            displayStatus = 'Over Limit';
            statusColor = 'var(--error-color)';
            warningIcon = `<i data-lucide="alert-triangle" style="width:14px;height:14px;color:var(--error-color);vertical-align:middle;margin-left:4px;" title="Credit Over Limit"></i>`;
        }
        
        // Warning treatment for high outstanding
        let creditTextClass = '';
        if (utilization > 1.0) creditTextClass = 'color:var(--error-color);font-weight:700;';
        else if (utilization >= 0.7) creditTextClass = 'color:var(--warning-color);font-weight:600;';
        else if (balance > 0) creditTextClass = 'font-weight:500;';
        else creditTextClass = 'color:var(--success-color);font-weight:500;';

        const barColor = utilization > 1.0 ? 'var(--error-color)' : (utilization >= 0.7 ? 'var(--warning-color)' : 'var(--success-color)');

        let actions = '';

        if (window.CAN_EDIT) {
            actions += `<button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); openDealerModal(${d.id})" title="Edit Dealer" style="color:var(--primary-color);padding:4px;"><i data-lucide="edit" style="width:16px;height:16px;"></i></button>`;
        }

        if (window.CAN_RECORD_PAYMENT && balance > 0) {
            actions += `<button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); openPaymentModal(${d.id}, '${escapeHtml(d.name)}', ${d.credit_balance})" title="Account / Credit Transactions" style="color:var(--success-color);padding:4px;"><i data-lucide="banknote" style="width:16px;height:16px;"></i></button>`;
        }

        if (window.CAN_DELETE && d.status !== 'inactive') {
            actions += `<button class="btn btn-sm btn-ghost" onclick="event.stopPropagation(); deleteDealer(${d.id}, '${escapeHtml(d.name)}')" title="Delete" style="color:var(--error-color);padding:4px;"><i data-lucide="trash-2" style="width:16px;height:16px;"></i></button>`;
        }

        return `<tr class="clickable-row" onclick="viewDealer(${d.id})">
            <td style="padding: 12px 16px;">
                <div style="font-weight:600; font-size:1rem; margin-bottom:2px;">${escapeHtml(d.name)}</div>
                <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:2px;"><code>${escapeHtml(d.dealer_code)}</code></div>
                ${d.email ? `<div style="font-size:0.8rem; color:var(--text-muted);">${escapeHtml(d.email)}</div>` : ''}
            </td>
            <td style="padding: 12px 16px; color:var(--text-secondary);">${escapeHtml(d.phone || '—')}</td>
            <td style="padding: 12px 16px;">
                <div style="font-size:0.95rem; margin-bottom:4px;">
                    <span style="font-weight:700; ${creditTextClass}">${formatCurrency(balance)}</span> 
                    <span style="color:var(--text-muted);font-size:0.85rem;">/ ${formatCurrency(limit)}</span>
                    ${warningIcon}
                </div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:6px;">${utilPercent}% used</div>
                <div style="background:var(--bg-tertiary);border-radius:2px;height:4px;width:100%;overflow:hidden;">
                    <div style="width:${Math.min(utilization * 100, 100)}%;height:100%;background:${barColor};border-radius:2px;"></div>
                </div>
            </td>
            <td style="padding: 12px 16px;">
                <span class="status-badge" style="color:${statusColor};background:${statusColor}15; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500; display:inline-block; text-transform:capitalize;">${displayStatus}</span>
            </td>
            <td style="padding: 12px 16px;">
                <div style="font-weight:700; font-size:1.1rem; line-height:1;">${d.total_sales || 0}</div>
                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">Sales</div>
            </td>
            <td style="padding: 12px 16px; text-align:right;">
                <div style="display:flex; justify-content:flex-end; gap:4px;">
                    ${actions}
                </div>
            </td>
        </tr>`;
    }).join('');

    lucide.createIcons();
}

function openAddDealerModal() {
    document.getElementById('addDealerForm').reset();
    openModal('addDealerModal');
    lucide.createIcons();
}

async function submitAddDealer(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    try {
        const res = await apiRequest('/api/dealer_applications?action=add_and_approve', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        showToast(res.message, 'success');
        closeModal('addDealerModal');
        loadDealers(currentDealersPage);
    } catch (e) {
        showToast(e.message || 'Failed to add dealer', 'error');
    }
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
        contact_person: '',
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

async function openPaymentModal(dealerId, dealerName, balance) {
    currentPaymentDealerBalance = parseFloat(balance);
    document.getElementById('paymentForm').reset();
    document.getElementById('paymentDealerId').value = dealerId;
    document.getElementById('paymentAmount').value = 0;
    document.getElementById('paymentAmountDisplay').textContent = '0.00';
    document.getElementById('remainingBalanceDisplay').textContent = formatCurrency(currentPaymentDealerBalance);
    document.getElementById('btnSubmitPayment').disabled = true;

    document.getElementById('paymentDealerInfo').innerHTML = `
        <div style="font-weight:600; font-size:1.1rem; margin-bottom:4px;">${escapeHtml(dealerName)}</div>
        <div style="font-size:0.9rem; color:var(--text-muted);">
            Outstanding Balance: <span style="font-weight:700; color:var(--warning-color);">${formatCurrency(currentPaymentDealerBalance)}</span>
        </div>
    `;
    
    // Load unpaid invoices
    const tbody = document.getElementById('paymentInvoicesBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding:20px;">Loading invoices...</td></tr>';
    
    openModal('paymentModal');
    lucide.createIcons();
    
    try {
        const res = await apiRequest(`/api/credits?action=unpaid_invoices&dealer_id=${dealerId}`);
        const invoices = res.data || [];
        
        if (invoices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding:20px;">No unpaid invoices found.</td></tr>';
            return;
        }
        
        const now = new Date();
        now.setHours(0,0,0,0);

        tbody.innerHTML = invoices.map((inv, idx) => {
            const dueDate = new Date(inv.due_date);
            const isOverdue = dueDate < now;
            const statusHtml = isOverdue ? 
                `<span style="color:var(--error-color); font-weight:600; font-size:0.8rem;">Overdue</span>` : 
                `<span style="color:var(--text-muted); font-weight:500; font-size:0.8rem;">Due</span>`;

            return `
            <tr>
                <td style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                    <input type="hidden" class="alloc-sale-id" value="${inv.sale_id}">
                    <input type="hidden" class="alloc-max-balance" value="${inv.balance}">
                    <code style="color:var(--primary-color); font-size:0.85rem;">${inv.invoice_no}</code>
                </td>
                <td style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top; font-size: 0.85rem;">
                    ${dueDate.toLocaleDateString()}
                </td>
                <td style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                    ${statusHtml}
                </td>
                <td style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; text-align:right; font-weight:500; vertical-align:top;">
                    ${formatCurrency(inv.balance)}
                </td>
                <td style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; vertical-align:top;">
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <input type="number" class="form-control alloc-amount" style="padding:6px; font-size:0.9rem;" min="0" max="${inv.balance}" step="0.01" value="" placeholder="0.00" oninput="calculateTotalPayment(this)">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <span class="alloc-error" style="color:var(--error-color); font-size:0.75rem; display:none;">Exceeds balance</span>
                            <button type="button" class="btn btn-sm btn-ghost" style="padding:0; height:auto; color:var(--primary-color); font-size:0.75rem; text-decoration:underline; border:none; margin-left:auto;" onclick="setFullPayment(this, ${inv.balance})">Pay Full</button>
                        </div>
                    </div>
                </td>
            </tr>
        `}).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center" style="color:var(--error-color);padding:20px;">Failed to load invoices</td></tr>';
    }
}

function setFullPayment(btn, balance) {
    const row = btn.closest('tr');
    const input = row.querySelector('.alloc-amount');
    if (input) {
        input.value = balance;
        calculateTotalPayment(input);
    }
}

function calculateTotalPayment(triggerInput = null) {
    let total = 0;
    let hasError = false;

    document.querySelectorAll('#paymentInvoicesBody tr').forEach(row => {
        const input = row.querySelector('.alloc-amount');
        const errorSpan = row.querySelector('.alloc-error');
        if (!input || !errorSpan) return;

        const val = parseFloat(input.value) || 0;
        const maxVal = parseFloat(row.querySelector('.alloc-max-balance').value) || 0;

        if (val < 0) {
            errorSpan.textContent = 'Invalid amount';
            errorSpan.style.display = 'block';
            hasError = true;
        } else if (val > maxVal) {
            errorSpan.textContent = `Exceeds ${formatCurrency(maxVal)}`;
            errorSpan.style.display = 'block';
            hasError = true;
        } else {
            errorSpan.style.display = 'none';
            total += val;
        }
    });

    // Update display values
    document.getElementById('paymentAmount').value = total > 0 ? total.toFixed(2) : 0;
    
    // The formatCurrency helper prepends the currency symbol, but our template has a hardcoded ₱
    // We'll strip the hardcoded one in HTML later, or just remove the ₱ from formatCurrency output.
    // For now, formatCurrency(total).substring(1) to drop the ₱ since the HTML already has it.
    document.getElementById('paymentAmountDisplay').textContent = total > 0 ? formatCurrency(total).replace('₱', '') : '0.00';

    let remaining = currentPaymentDealerBalance - total;
    if (remaining < 0) remaining = 0; // Cap at 0 based on invoices

    document.getElementById('remainingBalanceDisplay').textContent = formatCurrency(remaining);

    // Disable button if errors exist or no total payment
    const btnSubmit = document.getElementById('btnSubmitPayment');
    if (hasError || total <= 0) {
        btnSubmit.disabled = true;
    } else {
        btnSubmit.disabled = false;
    }
}

async function processPayment(e) {
    e.preventDefault();
    const dealerId = document.getElementById('paymentDealerId').value;
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const notes = document.getElementById('paymentReference').value; // Sending payment_reference mapped to notes variable for DB

    if (!amount || amount <= 0 || document.getElementById('btnSubmitPayment').disabled) {
        showToast('Please allocate valid payment amounts', 'error');
        return;
    }
    
    const allocations = [];
    document.querySelectorAll('#paymentInvoicesBody tr').forEach(row => {
        const saleIdInput = row.querySelector('.alloc-sale-id');
        const amountInput = row.querySelector('.alloc-amount');
        if (saleIdInput && amountInput) {
            const allocAmt = parseFloat(amountInput.value);
            if (!isNaN(allocAmt) && allocAmt > 0) {
                allocations.push({
                    sale_id: parseInt(saleIdInput.value),
                    amount: allocAmt
                });
            }
        }
    });

    const body = JSON.stringify({ dealer_id: parseInt(dealerId), amount, notes, allocations });

    try {
        const result = await apiRequest('/api/credits?action=payment', { method: 'POST', body });
        showToast(result.message, 'success');
        closeModal('paymentModal');
        
        // Refresh detail view if open
        const detailModalOpen = document.getElementById('detailModal').style.display === 'flex';
        if (detailModalOpen) {
            viewDealer(dealerId);
        }
        
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
        document.getElementById('detailModalTitle').innerHTML = `${escapeHtml(d.name)} (${escapeHtml(d.dealer_code)})` + 
            (window.CAN_EDIT ? ` <button class="btn btn-sm btn-ghost" onclick="openDealerModal(${d.id})" title="Edit Dealer" style="color:var(--primary-color);padding:2px 6px;margin-left:8px;vertical-align:middle;border:1px solid #e2e8f0;border-radius:4px;"><i data-lucide="edit" style="width:14px;height:14px;"></i> Edit</button>` : '');

        const statusColor = d.status === 'active' ? 'var(--success-color)' : (d.status === 'suspended' ? 'var(--warning-color)' : 'var(--text-muted)');
        const balance = parseFloat(d.credit_balance);
        const limit = parseFloat(d.credit_limit);
        const available = limit - balance;
        
        let overdueBadge = '';
        try {
            const overdueRes = await apiRequest(`/api/credits?action=overdue`);
            const overdueDealers = overdueRes.data || [];
            const od = overdueDealers.find(od => od.dealer_id === id);
            if (od) {
                overdueBadge = `<span style="background:var(--error-color);color:white;padding:2px 6px;border-radius:4px;font-size:0.75rem;margin-left:8px;vertical-align:middle;">${od.days_overdue} Days Overdue (₱${formatCurrency(od.overdue_amount)})</span>`;
            }
        } catch (e) {
            // ignore
        }

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
        
        let creditMemosHtml = '';
        try {
            const cmRes = await apiRequest(`/api/credits?action=list_memos&dealer_id=${id}`);
            const memos = cmRes.data || [];
            
            let memosList = '';
            if (memos.length > 0) {
                memosList = `
                    <div class="table-wrapper">
                        <table>
                            <thead><tr><th>Date</th><th>Reference</th><th>Amount</th><th>Balance</th><th>Reason</th><th>Status</th><th>By</th><th>Actions</th></tr></thead>
                            <tbody>
                                ${memos.map(m => {
                                    const mStatusColor = m.status === 'approved' ? 'var(--success-color)' : (m.status === 'used' ? 'var(--text-muted)' : 'var(--error-color)');
                                    let mActions = '';
                                    if (m.status === 'approved') {
                                        if (window.CAN_EDIT) {
                                            mActions += `<button class="btn btn-sm btn-ghost" onclick="voidCreditMemo(${m.id})" title="Void Memo" style="color:var(--error-color);padding:2px;"><i data-lucide="x-circle" style="width:14px;height:14px;"></i></button>`;
                                        }
                                    }
                                    return `
                                    <tr>
                                        <td>${new Date(m.created_at).toLocaleDateString()}</td>
                                        <td><code style="font-size:0.78rem;">${escapeHtml(m.reference_no)}</code></td>
                                        <td>${formatCurrency(m.amount)}</td>
                                        <td style="font-weight:500;">${formatCurrency(m.balance)}</td>
                                        <td style="font-size:0.82rem;">${escapeHtml(m.reason)}</td>
                                        <td><span class="status-badge" style="color:${mStatusColor};background:${mStatusColor}15;">${m.status}</span></td>
                                        <td>${escapeHtml(m.created_by_name || '—')}</td>
                                        <td>${mActions}</td>
                                    </tr>`;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                memosList = '<p class="text-muted">No credit memos.</p>';
            }
            
            const createBtn = window.CAN_EDIT ? `<button class="btn btn-sm btn-primary" onclick="openMemoModal(${id}, '${escapeHtml(d.name)}')"><i data-lucide="plus" style="width:14px;height:14px;"></i> Create Memo</button>` : '';
            
            creditMemosHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin:20px 0 10px;">
                    <h4 style="margin:0;font-size:0.9rem;">Credit Memos</h4>
                    ${createBtn}
                </div>
                ${memosList}
            `;
            
        } catch (e) {
            // ignore
        }

        let salesHtml = '';
        if (d.recent_sales && d.recent_sales.length > 0) {
            salesHtml = `
                <h4 style="margin:20px 0 10px;font-size:0.9rem;">Recent Sales</h4>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Invoice</th><th>Total</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            ${d.recent_sales.map(s => {
                                const st = s.payment_status || 'unknown';
                                const color = st === 'paid' ? 'var(--success-color)' : (st === 'pending' ? 'var(--warning-color)' : 'var(--text-muted)');
                                return `
                                <tr>
                                    <td><a href="${window.APP_URL}/invoice_print?id=${s.id}" target="_blank" style="text-decoration:none; color:var(--primary-color);" title="View Invoice"><code style="font-size:0.78rem; cursor:pointer;">${s.invoice_no}</code></a></td>
                                    <td style="font-weight:500;">${formatCurrency(s.total)}</td>
                                    <td><span class="badge" style="background:#f1f5f9;color:#475569;font-size:0.75rem;">${s.payment_method}</span></td>
                                    <td><span class="status-badge" style="color:${color};background:${color}15; display:inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 500;">${st}</span></td>
                                    <td>${new Date(s.created_at).toLocaleDateString()}</td>
                                </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
                <div style="text-align:center; margin-top:12px;">
                    <a href="${window.APP_URL}/sales?dealer_id=${id}" class="btn btn-sm btn-secondary" style="text-decoration:none; display:inline-flex; align-items:center;">
                        View all sales <i data-lucide="arrow-right" style="width:14px;height:14px;margin-left:4px;"></i>
                    </a>
                </div>
            `;
        }

        body.innerHTML = `
            <div style="display:flex;align-items:center;margin-bottom:12px;">
                ${overdueBadge}
            </div>
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
                <div><span style="color:var(--text-muted);">Phone:</span> ${escapeHtml(d.phone || '—')}</div>
                <div><span style="color:var(--text-muted);">Email:</span> ${escapeHtml(d.email || '—')}</div>
                <div><span style="color:var(--text-muted);">Address:</span> ${escapeHtml(d.address || '—')}</div>
            </div>
            ${d.notes ? `<div style="margin-top:12px;font-size:0.85rem;color:var(--text-muted);"><strong>Notes:</strong> ${escapeHtml(d.notes)}</div>` : ''}
            ${creditMemosHtml}
            ${creditHistoryHtml}
            ${salesHtml}
        `;
        lucide.createIcons();
    } catch (e) {
        body.innerHTML = '<div class="text-center" style="color:var(--error-color);padding:40px;">Failed to load dealer details</div>';
    }
}

// ============================================
// Credit Memos Logic
// ============================================
function openMemoModal(dealerId, dealerName) {
    document.getElementById('memoForm').reset();
    document.getElementById('memoDealerId').value = dealerId;
    document.getElementById('memoDealerInfo').innerHTML = `
        <div style="font-weight:600;">${escapeHtml(dealerName)}</div>
        <div style="font-size:0.82rem;color:var(--text-muted);">Create Credit Memo</div>
    `;
    openModal('memoModal');
    lucide.createIcons();
}

async function submitMemo(e) {
    e.preventDefault();
    const dealerId = document.getElementById('memoDealerId').value;
    const amount = parseFloat(document.getElementById('memoAmount').value);
    const reason = document.getElementById('memoReason').value;
    
    if (!amount || amount <= 0) {
        showToast('Valid amount required', 'error');
        return;
    }
    
    try {
        const body = JSON.stringify({ dealer_id: parseInt(dealerId), amount, reason });
        const res = await apiRequest('/api/credits?action=create_memo', { method: 'POST', body });
        showToast(res.message, 'success');
        closeModal('memoModal');
        viewDealer(dealerId); // reload detail
    } catch (e) {
        showToast(e.message || 'Failed to create memo', 'error');
    }
}

async function voidCreditMemo(memoId) {
    if (!confirm('Are you sure you want to void this credit memo?')) return;
    try {
        const res = await apiRequest('/api/credits?action=void_memo', { method: 'POST', body: JSON.stringify({ memo_id: memoId }) });
        showToast(res.message, 'success');
        const dealerId = document.getElementById('dealerId')?.value || document.querySelector('#detailModalTitle').textContent.match(/\((.*?)\)/)?.[0]; 
        // Just reload the current open detail modal by ID if we can grab it, else we need to figure it out
        const titleText = document.getElementById('detailModalTitle').textContent;
        // Hacky way to reload, better to just rely on user clicking again if we can't find ID, but we can usually find it
        closeModal('detailModal');
    } catch (e) {
        showToast(e.message || 'Failed to void memo', 'error');
    }
}

// ============================================
// Dealer Applications Management
// ============================================
let currentApplicationPage = 1;

function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active', 'border-primary', 'font-semibold'));
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.style.borderBottom = 'none';
        btn.style.color = 'var(--text-muted)';
        btn.style.fontWeight = '500';
    });

    if (tab === 'dealers') {
        const btn = document.getElementById('tabDealers');
        btn.classList.add('active');
        btn.style.borderBottom = '2px solid var(--primary-color)';
        btn.style.color = 'inherit';
        btn.style.fontWeight = '600';
        
        document.getElementById('cardDealers').style.display = 'block';
        document.getElementById('toolbarDealers').style.display = 'flex';
        document.getElementById('cardApplications').style.display = 'none';
        loadDealers(1);
    } else {
        const btn = document.getElementById('tabApplications');
        btn.classList.add('active');
        btn.style.borderBottom = '2px solid var(--primary-color)';
        btn.style.color = 'inherit';
        btn.style.fontWeight = '600';

        document.getElementById('cardDealers').style.display = 'none';
        document.getElementById('toolbarDealers').style.display = 'none';
        document.getElementById('cardApplications').style.display = 'block';
        loadApplications(1);
    }
}

async function loadApplications(page = 1) {
    currentApplicationPage = page;
    const params = new URLSearchParams({ status: 'pending', page, per_page: 15 });

    try {
        const data = await apiRequest(`/api/dealer_applications?${params}`);
        renderApplications(data.data || []);
        renderPagination(document.getElementById('applicationsPagination'), data.page, data.total_pages, loadApplications);
    } catch (e) {
        showToast('Failed to load applications', 'error');
    }
}

function renderApplications(apps) {
    const tbody = document.getElementById('applicationsBody');

    if (apps.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:40px;">No pending applications</td></tr>';
        return;
    }

    tbody.innerHTML = apps.map(a => {
        const name = `${a.first_name} ${a.last_name}`;
        let actions = `<button class="btn btn-sm btn-primary" onclick="event.stopPropagation(); viewApplication(${a.id})" title="View Details">Review</button>`;
        
        return `<tr class="clickable-row" onclick="viewApplication(${a.id})">
            <td>${new Date(a.created_at).toLocaleDateString()}</td>
            <td style="font-weight:500;">${escapeHtml(name)}</td>
            <td>${escapeHtml(a.phone)}</td>
            <td>${escapeHtml(a.preferred_branch || '—')}</td>
            <td>${escapeHtml(a.source || '—')}</td>
            <td><span class="status-badge" style="color:var(--warning-color);background:var(--warning-color)15;">Pending</span></td>
            <td>${actions}</td>
        </tr>`;
    }).join('');

    lucide.createIcons();
}

async function viewApplication(id) {
    const body = document.getElementById('applicationModalBody');
    const footer = document.getElementById('applicationModalFooter');
    
    body.innerHTML = '<div class="text-center text-muted" style="padding:40px;"><span class="spinner"></span> Loading...</div>';
    footer.innerHTML = '<button class="btn btn-secondary" onclick="closeModal(\'applicationModal\')">Close</button>';
    openModal('applicationModal');

    try {
        const data = await apiRequest(`/api/dealer_applications?status=pending`);
        const app = data.data.find(a => a.id === id);
        
        if (!app) throw new Error('Application not found');

        body.innerHTML = `
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
                <div>
                    <h4 style="margin:0 0 10px 0;border-bottom:1px solid #eee;padding-bottom:5px;">Personal Info</h4>
                    <p><strong>Name:</strong> ${escapeHtml(app.first_name)} ${escapeHtml(app.middle_name || '')} ${escapeHtml(app.last_name)}</p>
                    <p><strong>Phone:</strong> ${escapeHtml(app.phone)}</p>
                    <p><strong>Email:</strong> ${escapeHtml(app.email || '—')}</p>
                    <p><strong>Address:</strong> ${escapeHtml(app.address1 || '')}, ${escapeHtml(app.barangay || '')}, ${escapeHtml(app.city || '')}, ${escapeHtml(app.province || '')}</p>
                </div>
                <div>
                    <h4 style="margin:0 0 10px 0;border-bottom:1px solid #eee;padding-bottom:5px;">Application Details</h4>
                    <p><strong>Date:</strong> ${new Date(app.created_at).toLocaleString()}</p>
                    <p><strong>Branch:</strong> ${escapeHtml(app.preferred_branch || '—')}</p>
                    <p><strong>Source:</strong> ${escapeHtml(app.source || '—')}</p>
                </div>
            </div>
            <div>
                <h4 style="margin:0 0 10px 0;border-bottom:1px solid #eee;padding-bottom:5px;">Recruiter Info</h4>
                <p><strong>Recruiter ID:</strong> ${escapeHtml(app.recruiter_id || '—')}</p>
                <p><strong>Name:</strong> ${escapeHtml(app.recruiter_name || '—')}</p>
                <p><strong>Phone:</strong> ${escapeHtml(app.recruiter_phone || '—')}</p>
                <p><strong>Facebook:</strong> ${escapeHtml(app.recruiter_fb || '—')}</p>
            </div>
        `;
        
        footer.innerHTML = `
            <button class="btn btn-danger" style="margin-right:auto;background:var(--accent-rose);color:white;border:none;" onclick="rejectApplication(${app.id})">Reject</button>
            <button class="btn btn-secondary" onclick="closeModal('applicationModal')">Cancel</button>
            <button class="btn btn-success" style="background:var(--accent-emerald);color:white;border:none;" onclick="approveApplication(${app.id})">Approve as Dealer</button>
        `;
    } catch (e) {
        body.innerHTML = '<div class="text-center" style="color:var(--error-color);padding:40px;">Failed to load application</div>';
    }
}

async function approveApplication(id) {
    if (!confirm('Approve this application and create a new dealer?')) return;
    
    try {
        const res = await apiRequest('/api/dealer_applications?action=approve', {
            method: 'POST',
            body: JSON.stringify({ id })
        });
        showToast(res.message, 'success');
        closeModal('applicationModal');
        loadApplications(currentApplicationPage);
    } catch (e) {
        showToast(e.message || 'Failed to approve', 'error');
    }
}

async function rejectApplication(id) {
    if (!confirm('Are you sure you want to reject this application?')) return;
    
    try {
        const res = await apiRequest('/api/dealer_applications?action=reject', {
            method: 'POST',
            body: JSON.stringify({ id })
        });
        showToast(res.message, 'success');
        closeModal('applicationModal');
        loadApplications(currentApplicationPage);
    } catch (e) {
        showToast(e.message || 'Failed to reject', 'error');
    }
}
