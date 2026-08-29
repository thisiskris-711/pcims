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
            if (!sidebar.contains(e.target) && (!menuToggle || !menuToggle.contains(e.target))) {
                sidebar.classList.remove('open');
            }
        }
    });
    

    // Initialize notification system
    initNotifications();
    
    // Theme Toggle Logic
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    
    if (themeToggleBtn) {
        // Set initial icon based on current theme
        const currentTheme = document.documentElement.getAttribute('data-theme');
        if (currentTheme === 'dark') {
            themeToggleBtn.innerHTML = '<i data-lucide="sun" style="width:20px;height:20px;" id="themeToggleIcon"></i>';
            if (typeof lucide !== 'undefined') lucide.createIcons({ root: themeToggleBtn });
        }
        
        themeToggleBtn.addEventListener('click', () => {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            
            if (isDark) {
                document.documentElement.removeAttribute('data-theme');
                localStorage.setItem('theme', 'light');
                themeToggleBtn.innerHTML = '<i data-lucide="moon" style="width:20px;height:20px;" id="themeToggleIcon"></i>';
            } else {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                themeToggleBtn.innerHTML = '<i data-lucide="sun" style="width:20px;height:20px;" id="themeToggleIcon"></i>';
            }
            
            // Send async update to DB
            if (window.CSRF_TOKEN) {
                fetch(`${window.APP_URL}/api/account`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        theme: isDark ? 'light' : 'dark',
                        csrf_token: window.CSRF_TOKEN
                    })
                }).catch(e => console.error('Failed to sync theme to DB:', e));
            }
            
            // Re-render lucide icon for the button
            if (typeof lucide !== 'undefined') {
                lucide.createIcons({ root: themeToggleBtn });
            }
        });
    }
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
        config.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
        
        if (config.body instanceof FormData) {
            config.body.append('csrf_token', window.CSRF_TOKEN);
        } else if (config.headers['Content-Type'] === 'application/json' || (config.body && typeof config.body === 'string' && config.body.startsWith('{'))) {
            try {
                const body = JSON.parse(config.body || '{}');
                body.csrf_token = window.CSRF_TOKEN;
                config.body = JSON.stringify(body);
            } catch (e) {}
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

// ── Pagination Helper ──
function renderPagination(container, page, totalPages, callback) {
    if (!container) return;
    
    page = parseInt(page, 10) || 1;
    totalPages = parseInt(totalPages, 10) || 0;

    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }
    
    const cbName = callback.name || callback; // allow passing function name as string
    let html = '';
    html += `<a class="${page <= 1 ? 'disabled' : ''}" onclick="if(${page} > 1) { ${cbName}(${page - 1}); window.scrollTo({top:0, behavior:'smooth'}); }">&laquo;</a>`;
    
    for (let i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) {
        html += `<a class="${i === page ? 'active' : ''}" onclick="${cbName}(${i}); window.scrollTo({top:0, behavior:'smooth'});">${i}</a>`;
    }
    
    html += `<a class="${page >= totalPages ? 'disabled' : ''}" onclick="if(${page} < ${totalPages}) { ${cbName}(${page + 1}); window.scrollTo({top:0, behavior:'smooth'}); }">&raquo;</a>`;
    
    container.innerHTML = html;
}

// ── Inactivity & Session Timeout ──
let lastActivityTime = Date.now();
let lastKeepAliveTime = Date.now();
let sessionCheckInterval = null;

const IDLE_TIMEOUT = 15 * 60 * 1000; // 15 minutes
const WARNING_TIME = 14 * 60 * 1000; // 14 minutes
const KEEP_ALIVE_INTERVAL = 5 * 60 * 1000; // 5 minutes

function resetInactivityTimer() {
    lastActivityTime = Date.now();
    
    // Hide modal if it's active
    const modal = document.getElementById('sessionTimeoutModal');
    if (modal && modal.classList.contains('active')) {
        closeModal('sessionTimeoutModal');
    }
    
    // Ping backend if it's been more than 5 minutes since last ping
    if (Date.now() - lastKeepAliveTime > KEEP_ALIVE_INTERVAL) {
        lastKeepAliveTime = Date.now();
        apiRequest('/api/keep_alive', { method: 'POST' }).catch(err => console.error('Keep alive failed', err));
    }
}

function checkInactivity() {
    const elapsed = Date.now() - lastActivityTime;
    
    if (elapsed >= IDLE_TIMEOUT) {
        // Time is up, force logout
        window.location.href = window.APP_URL + '/logout?reason=timeout';
        return;
    }
    
    if (elapsed >= WARNING_TIME) {
        // Show warning modal and countdown
        const modal = document.getElementById('sessionTimeoutModal');
        const countdownEl = document.getElementById('sessionTimeoutCountdown');
        
        if (modal && !modal.classList.contains('active')) {
            openModal('sessionTimeoutModal');
        }
        
        if (countdownEl) {
            const secondsLeft = Math.ceil((IDLE_TIMEOUT - elapsed) / 1000);
            countdownEl.textContent = Math.max(0, secondsLeft);
        }
    }
}

// Initialize activity tracking if we're on a logged-in page
// (We assume window.CSRF_TOKEN implies a logged-in session for most pages, or we just run it always)
document.addEventListener('DOMContentLoaded', () => {
    // Only track if not on login page
    if (window.location.pathname.indexOf('/login') === -1) {
        // Throttle the reset to avoid processing every single tiny movement
        const throttledReset = debounce(resetInactivityTimer, 1000);
        
        // Listen to various activity events
        window.addEventListener('mousemove', throttledReset);
        window.addEventListener('keydown', throttledReset);
        window.addEventListener('click', throttledReset);
        window.addEventListener('scroll', throttledReset);
        
        // Check every second
        sessionCheckInterval = setInterval(checkInactivity, 1000);
    }
});
