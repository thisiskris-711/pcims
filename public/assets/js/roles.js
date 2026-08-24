/**
 * Roles Management JS
 */

let rolesData = [];

document.addEventListener('DOMContentLoaded', () => {
    loadRoles();
});

function loadRoles() {
    fetch(APP_URL + '/api/roles')
        .then(response => response.json())
        .then(res => {
            if (res.success) {
                rolesData = res.data;
                renderRolesTable();
            } else {
                alert('Failed to load roles: ' + res.message);
            }
        })
        .catch(err => {
            console.error(err);
            document.getElementById('rolesBody').innerHTML = '<tr><td colspan="5" class="text-danger text-center">Failed to load data</td></tr>';
        });
}

function renderRolesTable() {
    const tbody = document.getElementById('rolesBody');
    if (!rolesData || rolesData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No roles found.</td></tr>';
        return;
    }
    
    tbody.innerHTML = rolesData.map(role => {
        const isStandard = ['admin', 'manager', 'cashier', 'stocker', 'auditor', 'system_admin', 'inventory_manager', 'sales_associate', 'stock_associate'].includes(role.name);
        const isDeletable = role.name !== 'admin';
        const permsCount = Array.isArray(role.permissions) ? role.permissions.length : 0;
        
        return `
            <tr>
                <td>${role.id}</td>
                <td><strong>${role.name}</strong> ${isStandard ? '<span class="badge badge-primary">System</span>' : ''}</td>
                <td>${role.display_name}</td>
                <td>${permsCount} permission(s)</td>
                <td>
                    <button class="btn btn-ghost btn-icon sm" title="Edit" onclick="editRole(${role.id})">
                        <i data-lucide="edit-2" style="width:15px;height:15px;"></i>
                    </button>
                    ${isDeletable ? `
                    <button class="btn btn-ghost btn-icon sm text-danger" title="Delete" onclick="deleteRole(${role.id}, '${role.name}')">
                        <i data-lucide="trash-2" style="width:15px;height:15px;"></i>
                    </button>
                    ` : ''}
                </td>
            </tr>
        `;
    }).join('');
    
    if (window.lucide) {
        lucide.createIcons({ nodes: [tbody] });
    }
}

function openRoleModal() {
    document.getElementById('roleModalTitle').textContent = 'Create Role';
    document.getElementById('roleId').value = '';
    document.getElementById('roleName').value = '';
    document.getElementById('roleName').readOnly = false;
    document.getElementById('roleDisplayName').value = '';
    
    // Uncheck all permissions
    document.querySelectorAll('.role-permission-cb').forEach(cb => cb.checked = false);
    
    openModal('roleModal');
}

function editRole(id) {
    const role = rolesData.find(r => r.id === id);
    if (!role) return;
    
    document.getElementById('roleModalTitle').textContent = 'Edit Role';
    document.getElementById('roleId').value = role.id;
    document.getElementById('roleName').value = role.name;
    document.getElementById('roleName').readOnly = true; // Cannot change name after creation
    document.getElementById('roleDisplayName').value = role.display_name;
    
    // Check permissions
    const perms = Array.isArray(role.permissions) ? role.permissions : [];
    document.querySelectorAll('.role-permission-cb').forEach(cb => {
        cb.checked = perms.includes(cb.value);
    });
    
    openModal('roleModal');
}

function saveRole(e) {
    e.preventDefault();
    
    const id = document.getElementById('roleId').value;
    const name = document.getElementById('roleName').value;
    const displayName = document.getElementById('roleDisplayName').value;
    
    const perms = [];
    document.querySelectorAll('.role-permission-cb:checked').forEach(cb => {
        perms.push(cb.value);
    });
    
    const isEdit = !!id;
    const method = isEdit ? 'PUT' : 'POST';
    const payload = {
        name: name,
        display_name: displayName,
        permissions: perms
    };
    
    if (isEdit) {
        payload.id = id;
    }
    
    const btn = document.getElementById('btnSaveRole');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    
    fetch(APP_URL + '/api/roles', {
        method: method,
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            closeModal('roleModal');
            loadRoles();
        } else {
            alert(res.message || 'Failed to save role');
        }
    })
    .catch(err => {
        console.error(err);
        alert('A network error occurred.');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Save Role';
    });
}

function deleteRole(id, name) {
    if (!confirm(`Are you sure you want to delete the role "${name}"? This cannot be undone.`)) {
        return;
    }
    
    fetch(APP_URL + '/api/roles', {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({ id: id })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            loadRoles();
        } else {
            alert(res.message || 'Failed to delete role');
        }
    })
    .catch(err => {
        console.error(err);
        alert('A network error occurred.');
    });
}
