<?php
/**
 * User Management Page (Admin only)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN);

$pageTitle = 'User Management';
$currentPage = 'users';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="font-size:1rem;font-weight:600;">Manage system users and roles</h2>
    </div>
    <div class="toolbar-right">
        <button class="btn btn-primary" onclick="openUserModal()">
            <i data-lucide="user-plus" style="width:18px;height:18px;"></i> Add User
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Joined</th>
                        <th style="width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersBody">
                    <tr><td colspan="8" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="usersPagination" class="pagination"></div>
    </div>
</div>

<!-- User Modal -->
<div class="modal" id="userModal">
    <div class="modal-header">
        <h3 class="modal-title" id="userModalTitle">Add User</h3>
        <button class="modal-close" onclick="closeModal('userModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="userForm" onsubmit="saveUser(event)">
            <input type="hidden" id="userId" value="">
            <div class="form-group">
                <label class="form-label" for="userFullName">Full Name *</label>
                <input type="text" class="form-control" id="userFullName" required placeholder="Full name">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="userUsername">Username *</label>
                    <input type="text" class="form-control" id="userUsername" required placeholder="Username">
                </div>
                <div class="form-group">
                    <label class="form-label" for="userEmail">Email *</label>
                    <input type="email" class="form-control" id="userEmail" required placeholder="Email address">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" id="passwordLabel" for="userPassword">Password *</label>
                    <input type="password" class="form-control" id="userPassword" placeholder="Min 6 characters" minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label" for="userRole">Role *</label>
                    <select class="form-control" id="userRole" required>
                        <option value="cashier">Cashier</option>
                        <option value="stocker">Stocker</option>
                        <option value="auditor">Auditor</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('userModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('userForm').requestSubmit()">
            <i data-lucide="save" style="width:16px;height:16px;"></i> Save
        </button>
    </div>
</div>

<!-- Permissions Modal -->
<div class="modal" id="permissionsModal">
    <div class="modal-header">
        <h3 class="modal-title">Manage Permissions: <span id="permUserName"></span></h3>
        <button class="modal-close" onclick="closeModal('permissionsModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" id="permissionsBody" style="padding: 0;">
        <!-- Checkboxes populated here -->
    </div>
    <div class="modal-footer">
        <button class="btn btn-primary" onclick="closeModal('permissionsModal')">Done</button>
    </div>
</div>

<script>
const currentUserId = <?= getCurrentUserId() ?>;
let usersPage = 1;

const availablePermissions = [
    { id: 'manage_users', label: 'Manage Users' },
    { id: 'manage_products', label: 'Manage Products' },
    { id: 'manage_stock', label: 'Manage Stock' },
    { id: 'manage_sales', label: 'Manage Sales' },
    { id: 'view_reports', label: 'View Reports' }
];

document.addEventListener('DOMContentLoaded', loadUsers);

async function loadUsers() {
    try {
        const data = await apiRequest(`/api/users?page=${usersPage}`);
        renderUsers(data.data || []);
        if (typeof renderPagination === 'function') {
            renderPagination(document.getElementById('usersPagination'), data.page, data.total_pages, usersGoPage);
        }
    } catch (e) {
        showToast('Failed to load users', 'error');
    }
}

function usersGoPage(p) {
    usersPage = p;
    loadUsers();
}

function renderUsers(users) {
    const tbody = document.getElementById('usersBody');
    const roleBadge = { admin: 'badge-rose', manager: 'badge-violet', staff: 'badge-cyan' };
    
    tbody.innerHTML = users.map(u => `
        <tr class="clickable-row" onclick='editUser(${JSON.stringify(u)})'>
            <td>
                <div class="d-flex align-center gap-1">
                    <div class="user-avatar" style="width:32px;height:32px;font-size:0.75rem;">${u.full_name.charAt(0).toUpperCase()}</div>
                    <span class="font-bold">${escapeHtml(u.full_name)}</span>
                </div>
            </td>
            <td><code>${escapeHtml(u.username)}</code></td>
            <td class="text-muted">${escapeHtml(u.email)}</td>
            <td><span class="badge ${roleBadge[u.role] || 'badge-gray'}">${u.role}</span></td>
            <td><span class="status status-${u.status}">${u.status}</span></td>
            <td class="text-muted">${u.last_login ? new Date(u.last_login).toLocaleDateString() : 'Never'}</td>
            <td class="text-muted">${new Date(u.created_at).toLocaleDateString()}</td>
            <td>
                <div class="d-flex gap-1">

                    ${u.id != currentUserId ? `
                    <button class="btn btn-ghost btn-icon sm" title="Permissions" onclick="event.stopPropagation(); openPermissionsModal(${u.id}, '${escapeHtml(u.full_name)}', '${escapeHtml(u.role)}', ${u.permissions ? `'${escapeHtml(u.permissions)}'` : 'null'})">
                        <i data-lucide="key" style="width:15px;height:15px;color:var(--accent-violet);"></i>
                    </button>
                    <button class="btn btn-ghost btn-icon sm" title="Toggle Status" onclick="event.stopPropagation(); toggleUserStatus(${u.id}, '${u.status}')">
                        <i data-lucide="${u.status === 'active' ? 'user-x' : 'user-check'}" style="width:15px;height:15px;color:${u.status === 'active' ? 'var(--accent-amber)' : 'var(--accent-emerald)'};"></i>
                    </button>
                    <button class="btn btn-ghost btn-icon sm" title="Delete" onclick="event.stopPropagation(); deleteUser(${u.id}, '${escapeHtml(u.full_name)}')">
                        <i data-lucide="trash-2" style="width:15px;height:15px;color:var(--accent-rose);"></i>
                    </button>
                    ` : ''}
                </div>
            </td>
        </tr>
    `).join('');
    
    lucide.createIcons({ nodes: [tbody] });
}

function openUserModal(user = null) {
    document.getElementById('userModalTitle').textContent = user ? 'Edit User' : 'Add User';
    document.getElementById('userId').value = user?.id || '';
    document.getElementById('userFullName').value = user?.full_name || '';
    document.getElementById('userUsername').value = user?.username || '';
    document.getElementById('userEmail').value = user?.email || '';
    document.getElementById('userPassword').value = '';
    document.getElementById('userRole').value = user?.role || 'staff';
    
    const pwInput = document.getElementById('userPassword');
    const pwLabel = document.getElementById('passwordLabel');
    if (user) {
        pwInput.removeAttribute('required');
        pwLabel.textContent = 'Password (leave blank to keep)';
    } else {
        pwInput.setAttribute('required', 'required');
        pwLabel.textContent = 'Password *';
    }
    
    document.getElementById('userUsername').readOnly = !!user;
    openModal('userModal');
}

function editUser(user) { openUserModal(user); }

async function saveUser(e) {
    e.preventDefault();
    const id = document.getElementById('userId').value;
    
    const data = {
        full_name: document.getElementById('userFullName').value,
        username: document.getElementById('userUsername').value,
        email: document.getElementById('userEmail').value,
        role: document.getElementById('userRole').value,
    };
    
    const password = document.getElementById('userPassword').value;
    if (password) data.password = password;
    
    try {
        if (id) {
            await apiRequest(`/api/users?id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
        } else {
            data.password = password;
            await apiRequest('/api/users', { method: 'POST', body: JSON.stringify(data) });
        }
        showToast(`User ${id ? 'updated' : 'created'} successfully`);
        closeModal('userModal');
        loadUsers();
    } catch (e) {
        showToast(e.message || 'Failed to save user', 'error');
    }
}

async function toggleUserStatus(id, currentStatus) {
    const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
    try {
        await apiRequest(`/api/users?id=${id}`, { 
            method: 'PUT', 
            body: JSON.stringify({ status: newStatus }) 
        });
        showToast(`User ${newStatus === 'active' ? 'activated' : 'deactivated'}`);
        loadUsers();
    } catch (e) {
        showToast(e.message || 'Failed to update status', 'error');
    }
}

async function deleteUser(id, name) {
    if (!confirm(`Delete user "${name}"? This cannot be undone.`)) return;
    try {
        await apiRequest(`/api/users?id=${id}`, { method: 'DELETE' });
        showToast('User deleted');
        loadUsers();
    } catch (e) {
        showToast(e.message || 'Failed to delete user', 'error');
    }
}

function openPermissionsModal(id, name, role, permsString) {
    document.getElementById('permUserName').textContent = name;
    const container = document.getElementById('permissionsBody');
    
    if (role === 'admin') {
        container.innerHTML = '<div style="padding:20px; text-align:center; color:var(--text-muted);">Admin users have all permissions by default.</div>';
        openModal('permissionsModal');
        return;
    }
    
    let perms = [];
    try {
        perms = permsString ? JSON.parse(permsString) : [];
        if (!Array.isArray(perms)) perms = [];
    } catch (e) {
        perms = [];
    }
    
    container.innerHTML = availablePermissions.map(p => `
        <label class="d-flex align-center gap-2" style="padding:15px 20px; border-bottom:1px solid var(--border-color); cursor:pointer;">
            <input type="checkbox" value="${p.id}" onchange="updatePermissions(${id})" ${perms.includes(p.id) ? 'checked' : ''} style="width:18px;height:18px;">
            <span style="font-weight:500;">${p.label}</span>
        </label>
    `).join('');
    
    openModal('permissionsModal');
}

async function updatePermissions(userId) {
    const checkboxes = document.querySelectorAll('#permissionsBody input[type="checkbox"]:checked');
    const newPerms = Array.from(checkboxes).map(cb => cb.value);
    
    try {
        await apiRequest(`/api/users?id=${userId}`, {
            method: 'PUT',
            body: JSON.stringify({ permissions: newPerms })
        });
        showToast('Permissions updated');
        // Refresh the underlying table so the modal gets the updated permissions next time it's opened
        loadUsers();
    } catch (e) {
        showToast(e.message || 'Failed to update permissions', 'error');
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
