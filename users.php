<?php
/**
 * User Management Page (Admin only)
 */
require_once __DIR__ . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN);

$pageTitle = 'User Management';
$currentPage = 'users';
include __DIR__ . '/includes/header.php';
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
                <label class="form-label">Full Name *</label>
                <input type="text" class="form-control" id="userFullName" required placeholder="Full name">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" class="form-control" id="userUsername" required placeholder="Username">
                </div>
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" class="form-control" id="userEmail" required placeholder="Email address">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" id="passwordLabel">Password *</label>
                    <input type="password" class="form-control" id="userPassword" placeholder="Min 6 characters" minlength="6">
                </div>
                <div class="form-group">
                    <label class="form-label">Role *</label>
                    <select class="form-control" id="userRole" required>
                        <option value="staff">Staff</option>
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

<script>
const currentUserId = <?= getCurrentUserId() ?>;

document.addEventListener('DOMContentLoaded', loadUsers);

async function loadUsers() {
    try {
        const data = await apiRequest('/api/users.php');
        renderUsers(data.data || []);
    } catch (e) {
        showToast('Failed to load users', 'error');
    }
}

function renderUsers(users) {
    const tbody = document.getElementById('usersBody');
    const roleBadge = { admin: 'badge-rose', manager: 'badge-violet', staff: 'badge-cyan' };
    
    tbody.innerHTML = users.map(u => `
        <tr>
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
                    <button class="btn btn-ghost btn-icon sm" title="Edit" onclick='editUser(${JSON.stringify(u)})'>
                        <i data-lucide="pencil" style="width:15px;height:15px;"></i>
                    </button>
                    ${u.id != currentUserId ? `
                    <button class="btn btn-ghost btn-icon sm" title="Toggle Status" onclick="toggleUserStatus(${u.id}, '${u.status}')">
                        <i data-lucide="${u.status === 'active' ? 'user-x' : 'user-check'}" style="width:15px;height:15px;color:${u.status === 'active' ? 'var(--accent-amber)' : 'var(--accent-emerald)'};"></i>
                    </button>
                    <button class="btn btn-ghost btn-icon sm" title="Delete" onclick="deleteUser(${u.id}, '${escapeHtml(u.full_name)}')">
                        <i data-lucide="trash-2" style="width:15px;height:15px;color:var(--accent-rose);"></i>
                    </button>` : ''}
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
            await apiRequest(`/api/users.php?id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
        } else {
            data.password = password;
            await apiRequest('/api/users.php', { method: 'POST', body: JSON.stringify(data) });
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
        await apiRequest(`/api/users.php?id=${id}`, { 
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
        await apiRequest(`/api/users.php?id=${id}`, { method: 'DELETE' });
        showToast('User deleted');
        loadUsers();
    } catch (e) {
        showToast(e.message || 'Failed to delete user', 'error');
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
