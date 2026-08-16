/**
 * InventoryPro — Core Application JavaScript
 * Sidebar, modals, toasts, AJAX helpers
 */

// ── Sidebar Toggle ──
document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebar');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarClose = document.getElementById('sidebarClose');
    const modalOverlay = document.getElementById('modalOverlay');
    
    // Move all modals to the body to prevent stacking context issues with the overlay
    document.querySelectorAll('.modal').forEach(m => document.body.appendChild(m));
    
    if (menuToggle) {
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
    }
    
    if (sidebarClose) {
        sidebarClose.addEventListener('click', () => {
            sidebar.classList.remove('open');
        });
    }
    
    // Close sidebar on overlay click (mobile)
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && e.target !== menuToggle) {
                sidebar.classList.remove('open');
            }
        }
    });
    
    // Auto-dismiss flash alerts
    const flashAlert = document.getElementById('flashAlert');
    if (flashAlert) {
        setTimeout(() => {
            flashAlert.style.opacity = '0';
            flashAlert.style.transform = 'translateY(-10px)';
            setTimeout(() => flashAlert.remove(), 300);
        }, 4000);
    }
    
    // Initialize notification system
    initNotifications();
});

// ── Toast Notifications ──
function showToast(message, type = 'success', duration = 4000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const iconMap = {
        success: 'check-circle',
        error: 'alert-circle',
        warning: 'alert-triangle',
        info: 'info',
    };
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <span class="toast-icon"><i data-lucide="${iconMap[type] || 'info'}" style="width:20px;height:20px;"></i></span>
        <span class="toast-message">${escapeHtml(message)}</span>
        <button class="toast-close" onclick="removeToast(this.parentElement)">
            <i data-lucide="x" style="width:16px;height:16px;"></i>
        </button>
    `;
    
    container.appendChild(toast);
    lucide.createIcons({ nodes: [toast] });
    
    // Auto remove
    setTimeout(() => removeToast(toast), duration);
}

function removeToast(toast) {
    if (!toast || toast.classList.contains('removing')) return;
    toast.classList.add('removing');
    setTimeout(() => toast.remove(), 300);
}

// ── Modal System ──
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    const overlay = document.getElementById('modalOverlay');
    if (modal) {
        modal.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    const overlay = document.getElementById('modalOverlay');
    if (modal) {
        modal.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function closeAllModals() {
    document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
    const overlay = document.getElementById('modalOverlay');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
}

// Close modal on overlay click
document.addEventListener('click', (e) => {
    if (e.target.id === 'modalOverlay') {
        closeAllModals();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeAllModals();
});

// ── AJAX Helper ──
async function apiRequest(url, options = {}) {
    const defaults = {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    };
    
    const config = { ...defaults, ...options };
    
    // Add CSRF token for non-GET requests
    if (config.method !== 'GET' && window.CSRF_TOKEN) {
        if (config.body instanceof FormData) {
            config.body.append('csrf_token', window.CSRF_TOKEN);
        } else if (config.headers['Content-Type'] === 'application/json') {
            const body = JSON.parse(config.body || '{}');
            body.csrf_token = window.CSRF_TOKEN;
            config.body = JSON.stringify(body);
        }
    }
    
    // Add JSON content type for non-FormData
    if (config.body && !(config.body instanceof FormData)) {
        config.headers['Content-Type'] = config.headers['Content-Type'] || 'application/json';
    }
    
    try {
        const response = await fetch(window.APP_URL + url, config);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.error || data.message || `HTTP ${response.status}`);
        }
        
        return data;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

// ── Utility Functions ──
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

function confirmAction(message = 'Are you sure?') {
    return confirm(message);
}

// ── Tabs ──
function initTabs(containerSelector) {
    const container = document.querySelector(containerSelector);
    if (!container) return;
    
    const buttons = container.querySelectorAll('.tab-btn');
    const contents = container.querySelectorAll('.tab-content');
    
    buttons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.tab;
            
            buttons.forEach(b => b.classList.remove('active'));
            contents.forEach(c => c.classList.remove('active'));
            
            btn.classList.add('active');
            const targetContent = document.getElementById(target);
            if (targetContent) targetContent.classList.add('active');
        });
    });
}

// ── Image Preview ──
function previewImage(input, previewEl) {
    const preview = typeof previewEl === 'string' ? document.querySelector(previewEl) : previewEl;
    if (!preview || !input.files || !input.files[0]) return;
    
    const reader = new FileReader();
    reader.onload = (e) => {
        preview.src = e.target.result;
        preview.style.display = 'block';
        const uploadEl = input.closest('.image-upload');
        if (uploadEl) uploadEl.classList.add('has-image');
    };
    reader.readAsDataURL(input.files[0]);
}

// ── Table Select All ──
function initSelectAll(checkboxId, itemClass) {
    const selectAll = document.getElementById(checkboxId);
    if (!selectAll) return;
    
    selectAll.addEventListener('change', () => {
        document.querySelectorAll(`.${itemClass}`).forEach(cb => {
            cb.checked = selectAll.checked;
        });
    });
}

// ── Notifications System ──
function initNotifications() {
    const btn = document.getElementById('notificationBtn');
    const dropdown = document.getElementById('notificationDropdown');
    const wrapper = document.getElementById('notificationDropdownWrapper');
    const badge = document.getElementById('notificationBadge');
    const markAllReadBtn = document.getElementById('markAllReadBtn');
    
    if (!btn || !dropdown) return;
    
    // Toggle dropdown
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        dropdown.classList.toggle('active');
        if (dropdown.classList.contains('active')) {
            fetchNotifications();
        }
    });
    
    // Close on outside click
    document.addEventListener('click', (e) => {
        if (wrapper && !wrapper.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });
    
    // Mark all as read
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', async (e) => {
            e.stopPropagation();
            try {
                await apiRequest('/api/notifications', {
                    method: 'POST',
                    body: JSON.stringify({ action: 'mark_read' })
                });
                badge.style.display = 'none';
                fetchNotifications();
            } catch (err) {
                console.error(err);
            }
        });
    }
    
    // Initial fetch and polling
    fetchNotifications();
    setInterval(fetchNotifications, 60000); // Poll every minute
}

async function fetchNotifications() {
    const list = document.getElementById('notificationList');
    const badge = document.getElementById('notificationBadge');
    
    if (!list) return;
    
    try {
        const data = await apiRequest('/api/notifications?action=list');
        
        // Update badge
        if (data.unread_count > 0) {
            badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
        
        // Render list
        if (!data.notifications || data.notifications.length === 0) {
            list.innerHTML = `<div class="p-3 text-center text-muted" style="padding:20px;">No notifications</div>`;
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
                        <i data-lucide="${iconName}" style="width:16px;height:16px;"></i>
                    </div>
                    <div class="notification-content">
                        <div class="notification-title">${escapeHtml(n.title)}</div>
                        <div class="notification-message">${escapeHtml(n.message)}</div>
                        <div class="notification-time">${timeAgoFormat(n.created_at)}</div>
                    </div>
                </div>
            `;
        }).join('');
        
        lucide.createIcons({ nodes: [list] });
        
        // Add click listeners to items
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
                        fetchNotifications();
                    } catch (e) { console.error(e); }
                }
                
                if (link && link !== 'null') {
                    window.location.href = window.APP_URL + link;
                }
            });
        });
        
    } catch (err) {
        console.error('Failed to fetch notifications', err);
    }
}

function timeAgoFormat(datetime) {
    const date = new Date(datetime);
    const now = new Date();
    // Adjusting for simple client side relative time (approximation)
    const diffSeconds = Math.floor((now - date) / 1000);
    
    if (diffSeconds < 60) return 'just now';
    if (diffSeconds < 3600) return Math.floor(diffSeconds / 60) + 'm ago';
    if (diffSeconds < 86400) return Math.floor(diffSeconds / 3600) + 'h ago';
    if (diffSeconds < 604800) return Math.floor(diffSeconds / 86400) + 'd ago';
    
    return date.toLocaleDateString();
}
