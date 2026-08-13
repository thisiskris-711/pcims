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
    return '$' + parseFloat(amount).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
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
