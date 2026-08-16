/**
 * Notifications Page Script
 */

let currentPage = 1;
const perPage = 20;

document.addEventListener('DOMContentLoaded', () => {
    loadPageNotifications(currentPage);

    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    const markAllBtn = document.getElementById('pageMarkAllReadBtn');

    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                loadPageNotifications(currentPage);
            }
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            currentPage++;
            loadPageNotifications(currentPage);
        });
    }
    
    if (markAllBtn) {
        markAllBtn.addEventListener('click', async () => {
            try {
                await apiRequest('/api/notifications', {
                    method: 'POST',
                    body: JSON.stringify({ action: 'mark_read' })
                });
                showToast('All notifications marked as read', 'success');
                
                // Update topbar dropdown if initialized
                if (typeof fetchNotifications === 'function') {
                    fetchNotifications();
                }
                
                loadPageNotifications(currentPage);
            } catch (err) {
                showToast(err.message || 'Failed to mark notifications as read', 'error');
            }
        });
    }
});

async function loadPageNotifications(page) {
    const list = document.getElementById('fullNotificationList');
    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    const info = document.getElementById('paginationInfo');
    
    if (!list) return;
    
    list.innerHTML = `
        <div class="text-center text-muted" style="padding: 40px;">
            <i data-lucide="loader-2" class="spin" style="width: 24px; height: 24px;"></i>
            <div class="mt-2">Loading...</div>
        </div>
    `;
    lucide.createIcons({ nodes: [list] });
    
    try {
        const data = await apiRequest(`/api/notifications?action=list&page=${page}&per_page=${perPage}`);
        
        // Update pagination UI
        if (info) {
            const start = data.total > 0 ? (data.page - 1) * perPage + 1 : 0;
            const end = Math.min(data.page * perPage, data.total);
            info.textContent = `Showing ${start} to ${end} of ${data.total} notifications`;
        }
        
        if (prevBtn) prevBtn.disabled = data.page <= 1;
        if (nextBtn) nextBtn.disabled = data.page >= data.total_pages;
        
        // Render list
        if (!data.notifications || data.notifications.length === 0) {
            list.innerHTML = `
                <div class="empty-state" style="padding:60px 20px;">
                    <i data-lucide="bell-off" style="width:48px;height:48px;color:var(--text-muted);margin-bottom:16px;"></i>
                    <h3 style="margin-bottom:8px;">No notifications</h3>
                    <p class="text-muted">You're all caught up!</p>
                </div>
            `;
            lucide.createIcons({ nodes: [list] });
            return;
        }
        
        list.innerHTML = data.notifications.map(n => {
            const isUnread = n.is_read == 0 ? 'unread' : '';
            const iconMap = {
                info: 'info',
                warning: 'alert-triangle',
                success: 'check-circle',
                error: 'alert-circle'
            };
            const iconName = iconMap[n.type] || 'info';
            
            return `
                <div class="notification-item ${isUnread}" data-id="${n.id}" data-link="${n.link || ''}">
                    <div class="notification-icon ${n.type}">
                        <i data-lucide="${iconName}" style="width:20px;height:20px;"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title">${escapeHtml(n.title)}</div>
                        <div class="notification-message">${escapeHtml(n.message)}</div>
                        <div class="notification-time">${new Date(n.created_at).toLocaleString()}</div>
                    </div>
                    ${n.is_read == 0 ? `
                    <div style="display:flex;align-items:center;">
                        <div style="width:8px;height:8px;border-radius:50%;background:var(--accent-violet);"></div>
                    </div>
                    ` : ''}
                </div>
            `;
        }).join('');
        
        lucide.createIcons({ nodes: [list] });
        
        // Click handlers
        list.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', async () => {
                const id = item.dataset.id;
                const link = item.dataset.link;
                
                if (item.classList.contains('unread')) {
                    try {
                        await apiRequest('/api/notifications', {
                            method: 'POST',
                            body: JSON.stringify({ action: 'mark_read', id: id })
                        });
                        
                        // Update topbar dropdown if initialized
                        if (typeof fetchNotifications === 'function') {
                            fetchNotifications();
                        }
                    } catch (e) { console.error(e); }
                }
                
                if (link && link !== 'null') {
                    window.location.href = window.APP_URL + link;
                } else {
                    // Just reload list to reflect read status if no link
                    loadPageNotifications(currentPage);
                }
            });
        });
        
    } catch (err) {
        list.innerHTML = `
            <div class="text-center text-danger" style="padding: 40px;">
                <i data-lucide="alert-circle" style="width:24px;height:24px;margin-bottom:8px;"></i>
                <div>Failed to load notifications. Please try again.</div>
            </div>
        `;
        lucide.createIcons({ nodes: [list] });
        console.error(err);
    }
}
