<?php
/**
 * Backup & Restore Page (Admin only)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN);

$pageTitle = 'Backup & Restore';
$currentPage = 'backup';
include dirname(__DIR__) . '/layouts/header.php';
?>

<style>
/* Summary Cards */
.summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.summary-card {
    background: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: var(--border-radius);
    padding: 16px;
    display: flex;
    align-items: center;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-md);
    border-color: var(--border-color-hover);
}
.summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    background: rgba(154, 0, 2, 0.08); /* PCIMS red tint */
    color: var(--accent-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 16px;
    flex-shrink: 0;
}
.summary-content {
    flex-grow: 1;
}
.summary-title {
    font-size: 0.85rem;
    color: var(--text-muted);
    margin-bottom: 4px;
    font-weight: 500;
}
.summary-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1.2;
}

/* Table Enhancements */
.backup-filename {
    font-weight: 500;
    color: var(--text-primary);
}
.backup-filename-meta {
    font-size: 0.8rem;
    color: var(--text-muted);
    margin-top: 2px;
}
.table-empty-state {
    padding: 60px 20px;
    text-align: center;
}
.empty-state-icon {
    width: 64px;
    height: 64px;
    color: var(--border-color-hover);
    margin: 0 auto 16px;
}

/* Tooltips */
[data-tooltip] {
    position: relative;
}
[data-tooltip]:hover::before {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    padding: 4px 8px;
    background: var(--text-primary);
    color: var(--text-white);
    font-size: 0.75rem;
    border-radius: 4px;
    white-space: nowrap;
    pointer-events: none;
    margin-bottom: 6px;
    z-index: 10;
}
[data-tooltip]:hover::after {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    border-width: 6px 6px 0;
    border-style: solid;
    border-color: var(--text-primary) transparent transparent transparent;
    pointer-events: none;
    margin-bottom: 0px;
    z-index: 10;
}

/* Modal Enhancements */
.modal-alert-box {
    background-color: rgba(225, 29, 72, 0.08);
    border-left: 4px solid var(--accent-primary);
    padding: 12px 16px;
    border-radius: 4px;
    margin-bottom: 16px;
}
.modal-alert-title {
    color: var(--accent-primary);
    font-weight: 600;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-alert-text {
    font-size: 0.9rem;
    color: var(--text-secondary);
}
.toggle-switch {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
}
.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.toggle-switch .slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: var(--border-color);
    transition: .4s;
    border-radius: 24px;
}
.toggle-switch .slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.toggle-switch input:checked + .slider {
    background-color: var(--accent-primary);
}
.toggle-switch input:checked + .slider:before {
    transform: translateX(20px);
}
.disabled-section {
    opacity: 0.5;
    pointer-events: none;
    transition: opacity 0.3s ease;
}
</style>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="font-size:1.25rem;font-weight:600;color:var(--text-primary);">Manage Database Backups</h2>
        <p class="text-muted" style="margin-top: 4px; font-size: 0.9rem;">Safely create, download, and restore system data snapshots.</p>
    </div>
    <div class="toolbar-right" style="display: flex; gap: 8px;">
        <button class="btn btn-secondary" onclick="openSettingsModal()">
            <i data-lucide="settings" style="width:18px;height:18px;"></i> Auto-Backup Settings
        </button>
        <button class="btn btn-primary" onclick="createBackup()" id="btnCreateBackup">
            <i data-lucide="database-backup" style="width:18px;height:18px;"></i> Create Backup Now
        </button>
    </div>
</div>

<!-- Summary Section -->
<div class="summary-grid" id="summaryGrid">
    <div class="summary-card">
        <div class="summary-icon">
            <i data-lucide="files"></i>
        </div>
        <div class="summary-content">
            <div class="summary-title">Total Backups</div>
            <div class="summary-value" id="statTotalBackups">-</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon">
            <i data-lucide="hard-drive"></i>
        </div>
        <div class="summary-content">
            <div class="summary-title">Storage Used</div>
            <div class="summary-value" id="statStorageUsed">-</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon">
            <i data-lucide="clock"></i>
        </div>
        <div class="summary-content">
            <div class="summary-title">Last Backup</div>
            <div class="summary-value" id="statLastBackup">-</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon" style="color: var(--accent-emerald); background: rgba(16, 185, 129, 0.1);">
            <i data-lucide="check-circle"></i>
        </div>
        <div class="summary-content">
            <div class="summary-title">Backup Status</div>
            <div class="summary-value" id="statStatus">Ready</div>
        </div>
    </div>
</div>

<div class="card" style="border: 1px solid var(--border-color); box-shadow: var(--shadow-sm);">
    <div class="card-body" style="padding:0;">
        <div class="table-wrapper">
            <table style="margin-bottom: 0;">
                <thead>
                    <tr>
                        <th style="padding-left: 24px;">Backup Details</th>
                        <th>Created At</th>
                        <th>Size</th>
                        <th style="width:180px; text-align: right; padding-right: 24px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="backupsBody">
                    <tr>
                        <td colspan="4" class="text-center text-muted" style="padding:40px;">
                            <i data-lucide="loader-2" class="spin" style="width:24px;height:24px;margin-bottom:8px;"></i>
                            <br>Loading backups...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Restore Confirmation Modal -->
<div class="modal" id="restoreModal">
    <div class="modal-header">
        <h3 class="modal-title" style="color: var(--accent-primary);">
            <i data-lucide="alert-triangle" style="width:20px;height:20px;margin-right:8px;vertical-align:text-bottom;"></i>
            Restore Database Backup?
        </h3>
        <button class="modal-close" onclick="closeModal('restoreModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <div class="modal-alert-box" style="margin-bottom: 20px;">
            <div class="modal-alert-text" style="color: var(--text-primary); font-size: 0.95rem;">
                This will replace the current database with the selected backup. A safety backup will be created automatically before restoring.
            </div>
        </div>
        <p style="color: var(--text-secondary); margin-bottom: 8px; font-size: 0.9rem;">Selected backup file:</p>
        <p style="background: var(--bg-tertiary); padding: 8px 12px; border-radius: 4px; font-family: monospace; color: var(--text-primary); border: 1px solid var(--border-color); margin-bottom: 8px;"><strong id="restoreFilename"></strong></p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('restoreModal')">Cancel</button>
        <button class="btn" style="background-color: var(--accent-amber); color: white;" onclick="executeRestore()" id="btnExecuteRestore">
            <i data-lucide="refresh-cw" style="width:16px;height:16px;"></i> Restore Backup
        </button>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-header">
        <h3 class="modal-title">Delete Backup?</h3>
        <button class="modal-close" onclick="closeModal('deleteModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <p style="margin-bottom: 12px;">This backup file will be permanently deleted. This action cannot be undone.</p>
        <p style="background: var(--bg-tertiary); padding: 8px 12px; border-radius: 4px; font-family: monospace; color: var(--text-secondary); border: 1px solid var(--border-color); margin-bottom: 16px;"><strong id="deleteFilename"></strong></p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
        <button class="btn" style="background-color: var(--accent-rose); color: white;" onclick="executeDelete()" id="btnExecuteDelete">
            <i data-lucide="trash-2" style="width:16px;height:16px;"></i> Delete Backup
        </button>
    </div>
<!-- Auto-Backup Settings Modal -->
<div class="modal" id="settingsModal">
    <div class="modal-header">
        <h3 class="modal-title">Auto-Backup Settings</h3>
        <button class="modal-close" onclick="closeModal('settingsModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="settingsForm">
            <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 16px; margin-bottom: 24px; background: var(--bg-card); display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <h4 style="margin: 0; font-size: 1rem; color: var(--text-primary);">Daily Auto-Backup</h4>
                    <p class="text-muted" style="margin: 4px 0 0 0; font-size: 0.85rem;">Automatically create a database backup once every day.</p>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="auto_backup_enabled" name="auto_backup_enabled" value="1" onchange="toggleAutoBackupSettings()">
                    <span class="slider"></span>
                </label>
            </div>
            
            <div id="autoBackupOptions">
                <h4 style="font-size: 0.9rem; color: var(--text-primary); margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Schedule</h4>
                <div class="form-group" style="margin-bottom: 24px;">
                    <label for="auto_backup_time" style="font-weight: 500;">Time of Day</label>
                    <input type="time" class="form-control" id="auto_backup_time" name="auto_backup_time" required>
                    <div class="text-muted" style="font-size: 0.8rem; margin-top: 4px;">Runs once daily within 1 hour of the selected time.</div>
                </div>

                <h4 style="font-size: 0.9rem; color: var(--text-primary); margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">Retention</h4>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label for="auto_backup_retention_days" style="font-weight: 500;">Keep backups for</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="number" class="form-control" id="auto_backup_retention_days" name="auto_backup_retention_days" min="1" max="365" style="width: 100px;" required>
                        <span style="color: var(--text-primary); font-size: 0.9rem;">days</span>
                    </div>
                    <div class="text-muted" style="font-size: 0.8rem; margin-top: 4px;">Backups older than this period will be automatically deleted.</div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('settingsModal')">Cancel</button>
        <button class="btn btn-primary" onclick="saveSettings()" id="btnSaveSettings">
            Save Settings
        </button>
    </div>
</div>

<script>
let currentRestoreFile = '';
let currentDeleteFile = '';

document.addEventListener('DOMContentLoaded', () => {
    loadBackups();
});

function formatBytes(bytes, decimals = 2) {
    if (!+bytes) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
}

function timeSince(dateString) {
    const date = new Date(dateString);
    const seconds = Math.floor((new Date() - date) / 1000);
    
    let interval = seconds / 31536000;
    if (interval > 1) return Math.floor(interval) + " years ago";
    interval = seconds / 2592000;
    if (interval > 1) return Math.floor(interval) + " months ago";
    interval = seconds / 86400;
    if (interval > 1) return Math.floor(interval) + " days ago";
    interval = seconds / 3600;
    if (interval > 1) return Math.floor(interval) + " hours ago";
    interval = seconds / 60;
    if (interval > 1) return Math.floor(interval) + " minutes ago";
    return Math.floor(seconds) + " seconds ago";
}

function renderEmptyState() {
    return `
        <tr>
            <td colspan="4">
                <div class="table-empty-state">
                    <div class="empty-state-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect width="18" height="18" x="3" y="3" rx="2" ry="2"/>
                            <path d="M12 11v6"/>
                            <path d="M9.5 14.5L12 17l2.5-2.5"/>
                            <path d="M12 7h.01"/>
                        </svg>
                    </div>
                    <h3 style="font-size: 1.1rem; color: var(--text-primary); margin-bottom: 8px;">No database backups available</h3>
                    <p style="color: var(--text-muted); margin-bottom: 24px; max-width: 400px; margin-left: auto; margin-right: auto;">Create a backup to protect your system data.</p>
                    <button class="btn btn-primary" onclick="createBackup()">
                        <i data-lucide="database-backup" style="width:16px;height:16px;"></i> Create Backup Now
                    </button>
                </div>
            </td>
        </tr>
    `;
}

function loadBackups() {
    fetch(`${APP_URL}/api/backup?action=list`)
        .then(res => res.json())
        .then(res => {
            const tbody = document.getElementById('backupsBody');
            if (res.success) {
                // Update Summary Cards
                document.getElementById('statTotalBackups').textContent = res.summary.total_backups;
                document.getElementById('statStorageUsed').textContent = formatBytes(res.summary.total_size_bytes);
                
                if (res.summary.last_backup) {
                    document.getElementById('statLastBackup').textContent = timeSince(res.summary.last_backup);
                    document.getElementById('statLastBackup').setAttribute('title', res.summary.last_backup);
                    document.getElementById('statStatus').textContent = 'Ready / Successful';
                } else {
                    document.getElementById('statLastBackup').textContent = 'Never';
                    document.getElementById('statStatus').textContent = 'No Backup';
                }

                // Render Table
                if (res.data.length === 0) {
                    tbody.innerHTML = renderEmptyState();
                    lucide.createIcons();
                    return;
                }
                
                let html = '';
                res.data.forEach(file => {
                    const formattedSize = formatBytes(file.size);
                    
                    // Create a cleaner display name (e.g. "Backup: 2026-08-22 10:00:00")
                    let displayName = "Manual Backup";
                    if(file.filename.startsWith('safety_prerestore_')) {
                         displayName = "Safety Pre-Restore";
                    }
                    
                    html += `
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding-left: 24px;">
                                <div style="display:flex; align-items:flex-start; gap:12px;">
                                    <div style="background: var(--bg-tertiary); padding: 8px; border-radius: 8px; border: 1px solid var(--border-color);">
                                        <i data-lucide="${file.filename.startsWith('safety') ? 'shield-check' : 'database'}" style="width:20px;height:20px;color:var(--text-muted);"></i>
                                    </div>
                                    <div>
                                        <div class="backup-filename">${displayName}</div>
                                        <div class="backup-filename-meta" style="font-family: monospace;">${file.filename}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="color: var(--text-primary); font-weight: 500;">${file.created_at}</div>
                                <div style="color: var(--text-muted); font-size: 0.8rem; margin-top: 2px;">${timeSince(file.created_at)}</div>
                            </td>
                            <td style="color: var(--text-secondary); font-weight: 500;">${formattedSize}</td>
                            <td style="text-align: right; padding-right: 24px;">
                                <div class="action-buttons" style="display: flex; justify-content: flex-end; gap: 4px;">
                                    <a href="${APP_URL}/api/backup?action=download&file=${encodeURIComponent(file.filename)}" class="btn btn-ghost btn-icon sm" data-tooltip="Download Backup" style="color: var(--accent-blue);">
                                        <i data-lucide="download" style="width:16px;height:16px;"></i>
                                    </a>
                                    <button class="btn btn-ghost btn-icon sm" data-tooltip="Restore Backup" onclick="confirmRestore('${file.filename}')" style="color: var(--accent-amber);">
                                        <i data-lucide="refresh-cw" style="width:16px;height:16px;"></i>
                                    </button>
                                    <button class="btn btn-ghost btn-icon sm" data-tooltip="Delete Backup" onclick="confirmDelete('${file.filename}')" style="color: var(--accent-rose);">
                                        <i data-lucide="trash-2" style="width:16px;height:16px;"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
                lucide.createIcons();
            } else {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger" style="padding:20px;">Error loading backups.</td></tr>`;
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('backupsBody').innerHTML = `<tr><td colspan="4" class="text-center text-danger" style="padding:20px;">Failed to load backups.</td></tr>`;
        });
}

function createBackup() {
    const btn = document.getElementById('btnCreateBackup');
    if (btn.disabled) return;
    
    const originalText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:18px;height:18px;"></i> Creating Backup...`;
    btn.disabled = true;
    lucide.createIcons();
    
    fetch(`${APP_URL}/api/backup`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=create&csrf_token=${encodeURIComponent(window.CSRF_TOKEN)}`
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            loadBackups();
        } else {
            showToast(res.error || 'Failed to create backup', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error occurred', 'error');
    })
    .finally(() => {
        // Only reset button state if it hasn't been completely replaced by empty state re-render
        if(document.body.contains(btn)) {
            btn.innerHTML = originalText;
            btn.disabled = false;
            lucide.createIcons();
        }
    });
}

function confirmRestore(filename) {
    currentRestoreFile = filename;
    document.getElementById('restoreFilename').textContent = filename;
    openModal('restoreModal');
}

function executeRestore() {
    if (!currentRestoreFile) return;
    
    const btn = document.getElementById('btnExecuteRestore');
    const originalText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:16px;height:16px;"></i> Restoring...`;
    btn.disabled = true;
    lucide.createIcons();
    
    fetch(`${APP_URL}/api/backup`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=restore&file=${encodeURIComponent(currentRestoreFile)}&csrf_token=${encodeURIComponent(window.CSRF_TOKEN)}`
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            closeModal('restoreModal');
            loadBackups(); // Will show the newly created safety backup
        } else {
            showToast(res.error || 'Failed to restore backup', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error occurred', 'error');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        currentRestoreFile = '';
        lucide.createIcons();
    });
}

function confirmDelete(filename) {
    currentDeleteFile = filename;
    document.getElementById('deleteFilename').textContent = filename;
    openModal('deleteModal');
}

function executeDelete() {
    if (!currentDeleteFile) return;
    
    const btn = document.getElementById('btnExecuteDelete');
    const originalText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:16px;height:16px;"></i> Deleting...`;
    btn.disabled = true;
    lucide.createIcons();
    
    fetch(`${APP_URL}/api/backup`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=delete&file=${encodeURIComponent(currentDeleteFile)}&csrf_token=${encodeURIComponent(window.CSRF_TOKEN)}`
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            closeModal('deleteModal');
            loadBackups();
        } else {
            showToast(res.error || 'Failed to delete backup', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error occurred', 'error');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        currentDeleteFile = '';
        lucide.createIcons();
    });
}

function toggleAutoBackupSettings() {
    const isEnabled = document.getElementById('auto_backup_enabled').checked;
    const optionsDiv = document.getElementById('autoBackupOptions');
    const inputs = optionsDiv.querySelectorAll('input');
    
    if (isEnabled) {
        optionsDiv.classList.remove('disabled-section');
        inputs.forEach(input => input.disabled = false);
    } else {
        optionsDiv.classList.add('disabled-section');
        inputs.forEach(input => input.disabled = true);
    }
}

function openSettingsModal() {
    fetch(`${APP_URL}/api/backup?action=get_settings`)
        .then(res => res.json())
        .then(res => {
            if (res.success) {
                document.getElementById('auto_backup_enabled').checked = res.data.auto_backup_enabled === '1';
                document.getElementById('auto_backup_time').value = res.data.auto_backup_time || '02:00';
                document.getElementById('auto_backup_retention_days').value = res.data.auto_backup_retention_days || 7;
                toggleAutoBackupSettings();
                openModal('settingsModal');
            } else {
                showToast('Failed to load settings', 'error');
            }
        })
        .catch(err => {
            console.error(err);
            showToast('Network error occurred', 'error');
        });
}

function saveSettings() {
    const btn = document.getElementById('btnSaveSettings');
    const originalText = btn.innerHTML;
    btn.innerHTML = `<i data-lucide="loader-2" class="spin" style="width:16px;height:16px;"></i> Saving...`;
    btn.disabled = true;
    lucide.createIcons();
    
    const formData = new FormData(document.getElementById('settingsForm'));
    // Handle unchecked checkbox
    if (!formData.has('auto_backup_enabled')) {
        formData.append('auto_backup_enabled', '0');
    }
    formData.append('action', 'save_settings');
    formData.append('csrf_token', window.CSRF_TOKEN);
    
    fetch(`${APP_URL}/api/backup`, {
        method: 'POST',
        body: new URLSearchParams(formData)
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            showToast(res.message, 'success');
            closeModal('settingsModal');
        } else {
            showToast(res.error || 'Failed to save settings', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network error occurred', 'error');
    })
    .finally(() => {
        btn.innerHTML = originalText;
        btn.disabled = false;
        lucide.createIcons();
    });
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
