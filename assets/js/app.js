/**
 * PCIMS - Personal Collection Inventory Management System
 * Main JavaScript Application
 */

// Global Variables
let currentUser = null;
let notificationCount = 0;
let stockUpdateInterval = null;

// Initialize Application
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

function initializeApp() {
    // Initialize tooltips
    initializeTooltips();
    
    // Initialize modals
    initializeModals();
    
    // Initialize form validations
    initializeFormValidations();
    
    // Initialize real-time updates
    initializeRealTimeUpdates();
    
    // Initialize keyboard shortcuts
    initializeKeyboardShortcuts();
    
    // Initialize search functionality
    initializeSearch();
    
    // Initialize data tables
    initializeDataTables();
    
    console.log('PCIMS Application initialized');
}

// Tooltips
function initializeTooltips() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Modals
function initializeModals() {
    // Auto-focus first input in modals
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('shown.bs.modal', function() {
            const firstInput = modal.querySelector('input:not([type="hidden"]), textarea, select');
            if (firstInput) {
                firstInput.focus();
            }
        });
    });
}

// Form Validations
function initializeFormValidations() {
    // Add custom validation methods
    const forms = document.querySelectorAll('form[novalidate]');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
    
    // Real-time validation
    document.querySelectorAll('.form-control, .form-select').forEach(input => {
        input.addEventListener('blur', function() {
            validateField(this);
        });
        
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid') || this.classList.contains('is-valid')) {
                validateField(this);
            }
        });
    });
}

function validateField(field) {
    const isValid = field.checkValidity();
    
    if (isValid) {
        field.classList.remove('is-invalid');
        field.classList.add('is-valid');
    } else {
        field.classList.remove('is-valid');
        field.classList.add('is-invalid');
    }
    
    return isValid;
}

// Real-time Updates
function initializeRealTimeUpdates() {
    // Update stock levels every 30 seconds
    stockUpdateInterval = setInterval(updateStockLevels, 30000);
    
    // Update notifications every 60 seconds
    setInterval(updateNotifications, 60000);
}

function updateStockLevels() {
    fetch('api/stock_levels.php')
        .then(response => response.json())
        .then(data => {
            data.forEach(item => {
                const element = document.getElementById(`stock-${item.product_id}`);
                if (element) {
                    const oldValue = parseInt(element.textContent);
                    const newValue = item.quantity_on_hand;
                    
                    element.textContent = newValue;
                    
                    // Add visual feedback for changes
                    if (oldValue !== newValue) {
                        element.classList.add('fade-in');
                        if (newValue <= item.reorder_level) {
                            element.classList.add('text-danger', 'fw-bold');
                            element.classList.remove('text-success');
                        } else {
                            element.classList.add('text-success');
                            element.classList.remove('text-danger', 'fw-bold');
                        }
                        
                        // Remove animation class after animation completes
                        setTimeout(() => {
                            element.classList.remove('fade-in');
                        }, 1000);
                    }
                }
            });
        })
        .catch(error => console.error('Error updating stock levels:', error));
}

function updateNotifications() {
    fetch('api/notifications.php')
        .then(response => response.json())
        .then(data => {
            const unreadCount = data.unread_count || 0;
            const notificationBadge = document.querySelector('.notification-badge');
            
            if (notificationBadge) {
                if (unreadCount > 0) {
                    notificationBadge.textContent = unreadCount;
                    notificationBadge.style.display = 'flex';
                } else {
                    notificationBadge.style.display = 'none';
                }
            }
        })
        .catch(error => console.error('Error updating notifications:', error));
}

// Keyboard Shortcuts
function initializeKeyboardShortcuts() {
    document.addEventListener('keydown', function(event) {
        // Ctrl+K for search
        if (event.ctrlKey && event.key === 'k') {
            event.preventDefault();
            const searchInput = document.querySelector('input[type="search"], input[placeholder*="search"]');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // Ctrl+N for new item (context-dependent)
        if (event.ctrlKey && event.key === 'n') {
            event.preventDefault();
            const addButton = document.querySelector('a[href*="action=add"]');
            if (addButton) {
                addButton.click();
            }
        }
        
        // Escape to close modals
        if (event.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                bootstrap.Modal.getInstance(openModal).hide();
            }
        }
    });
}

// Search Functionality
function initializeSearch() {
    const searchInputs = document.querySelectorAll('input[data-search-table]');
    
    searchInputs.forEach(input => {
        input.addEventListener('input', function() {
            const tableId = this.dataset.searchTable;
            const searchTerm = this.value.toLowerCase();
            const table = document.getElementById(tableId);
            
            if (table) {
                const rows = table.querySelectorAll('tbody tr');
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const shouldShow = text.includes(searchTerm);
                    row.style.display = shouldShow ? '' : 'none';
                });
            }
        });
    });
}

// Data Tables
function initializeDataTables() {
    // Add sorting functionality to all tables
    document.querySelectorAll('table[data-sortable="true"]').forEach(table => {
        makeTableSortable(table);
    });
}

function makeTableSortable(table) {
    const headers = table.querySelectorAll('th[data-sortable]');
    
    headers.forEach((header, index) => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            sortTable(table, index);
        });
    });
}

function sortTable(table, columnIndex) {
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const isAscending = table.dataset.sortOrder === 'asc';
    
    rows.sort((a, b) => {
        const aValue = a.cells[columnIndex].textContent.trim();
        const bValue = b.cells[columnIndex].textContent.trim();
        
        // Try to parse as numbers
        const aNum = parseFloat(aValue);
        const bNum = parseFloat(bValue);
        
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return isAscending ? aNum - bNum : bNum - aNum;
        }
        
        // Compare as strings
        return isAscending ? 
            aValue.localeCompare(bValue) : 
            bValue.localeCompare(aValue);
    });
    
    // Reorder rows
    rows.forEach(row => tbody.appendChild(row));
    
    // Update sort order
    table.dataset.sortOrder = isAscending ? 'desc' : 'asc';
    
    // Update visual indicators
    updateSortIndicators(table, columnIndex, isAscending);
}

function updateSortIndicators(table, columnIndex, isAscending) {
    const headers = table.querySelectorAll('th');
    
    headers.forEach((header, index) => {
        const icon = header.querySelector('.sort-icon');
        if (icon) {
            icon.remove();
        }
        
        if (index === columnIndex) {
            const newIcon = document.createElement('i');
            newIcon.className = `sort-icon fas fa-sort-${isAscending ? 'up' : 'down'} ms-1`;
            header.appendChild(newIcon);
        }
    });
}

// Utility Functions
function showLoadingOverlay() {
    const overlay = document.createElement('div');
    overlay.className = 'loading-overlay';
    overlay.innerHTML = '<div class="spinner"></div>';
    document.body.appendChild(overlay);
}

function hideLoadingOverlay() {
    const overlay = document.querySelector('.loading-overlay');
    if (overlay) {
        overlay.remove();
    }
}

function showAlert(message, type = 'info', dismissible = true) {
    const alertContainer = document.querySelector('.container-fluid, .container');
    if (!alertContainer) return;
    
    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible fade show ${dismissible ? '' : 'alert-no-dismiss'}`;
    alert.innerHTML = `
        ${message}
        ${dismissible ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : ''}
    `;
    
    alertContainer.insertBefore(alert, alertContainer.firstChild);
    
    // Auto-dismiss after 5 seconds
    if (dismissible) {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            alert.classList.add('fade');
            setTimeout(() => bsAlert.close(), 1000);
        }, 5000);
    }
}

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

function formatCurrency(amount, currency = 'PHP') {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: currency
    }).format(amount);
}

function formatDate(date, options = {}) {
    const defaultOptions = {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    };
    
    const finalOptions = { ...defaultOptions, ...options };
    return new Date(date).toLocaleString('en-PH', finalOptions);
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// AJAX Helper Functions
function makeRequest(url, options = {}) {
    showLoadingOverlay();
    
    const defaultOptions = {
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    const finalOptions = { ...defaultOptions, ...options };
    
    return fetch(url, finalOptions)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .catch(error => {
            console.error('Request error:', error);
            showAlert('An error occurred while processing your request.', 'danger');
            throw error;
        })
        .finally(() => {
            hideLoadingOverlay();
        });
}

// File Upload Helper
function handleFileUpload(input, callback) {
    const file = input.files[0];
    if (!file) return;
    
    // Validate file size (5MB max)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        showAlert('File size exceeds 5MB limit.', 'danger');
        return;
    }
    
    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
    if (!allowedTypes.includes(file.type)) {
        showAlert('Invalid file type. Only images and PDFs are allowed.', 'danger');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    
    makeRequest('api/upload.php', {
        method: 'POST',
        body: formData
    })
    .then(data => {
        if (data.success) {
            callback(data);
        } else {
            showAlert(data.message || 'Upload failed.', 'danger');
        }
    });
}

// Print Function
function printElement(elementId, title = 'Print') {
    const element = document.getElementById(elementId);
    if (!element) return;
    
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
            <head>
                <title>${title}</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body { padding: 20px; }
                    @media print {
                        .no-print { display: none !important; }
                    }
                </style>
            </head>
            <body>
                <h1 class="text-center mb-4">${title}</h1>
                ${element.innerHTML}
                <script>
                    window.onload = function() {
                        window.print();
                        window.close();
                    }
                </script>
            </body>
        </html>
    `);
    
    printWindow.document.close();
}

// Export Functions
function exportToCSV(tableId, filename = 'export.csv') {
    const table = document.getElementById(tableId);
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        
        cols.forEach(col => {
            let text = col.textContent || col.innerText;
            text = text.replace(/"/g, '""'); // Escape quotes
            rowData.push(`"${text.trim()}"`);
        });
        
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    downloadFile(csvContent, filename, 'text/csv');
}

function downloadFile(content, filename, contentType) {
    const blob = new Blob([content], { type: contentType });
    const url = window.URL.createObjectURL(blob);
    
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}

// Chart Helper Functions
function createChart(canvasId, type, data, options = {}) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;
    
    const ctx = canvas.getContext('2d');
    
    const defaultOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    };
    
    const finalOptions = { ...defaultOptions, ...options };
    
    return new Chart(ctx, {
        type: type,
        data: data,
        options: finalOptions
    });
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (stockUpdateInterval) {
        clearInterval(stockUpdateInterval);
    }
});

// Export global functions for use in HTML
window.PCIMS = {
    showAlert,
    confirmAction,
    formatCurrency,
    formatDate,
    makeRequest,
    handleFileUpload,
    printElement,
    exportToCSV,
    createChart,
    showLoadingOverlay,
    hideLoadingOverlay,
    updateStockLevels,
    updateNotifications
};
