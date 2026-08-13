<?php
/**
 * Categories Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER);

$pageTitle = 'Categories';
$currentPage = 'categories';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="font-size:1rem;font-weight:600;">Manage product categories</h2>
    </div>
    <div class="toolbar-right">
        <button class="btn btn-primary" onclick="openCategoryModal()">
            <i data-lucide="plus" style="width:18px;height:18px;"></i> Add Category
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Color</th>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Products</th>
                        <th>Created</th>
                        <th style="width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="categoriesBody">
                    <tr><td colspan="6" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal" id="categoryModal">
    <div class="modal-header">
        <h3 class="modal-title" id="categoryModalTitle">Add Category</h3>
        <button class="modal-close" onclick="closeModal('categoryModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="categoryForm" onsubmit="saveCategory(event)">
            <input type="hidden" id="categoryId" value="">
            <div class="form-group">
                <label class="form-label" for="catName">Name *</label>
                <input type="text" class="form-control" id="catName" required placeholder="Category name">
            </div>
            <div class="form-group">
                <label class="form-label" for="catDesc">Description</label>
                <textarea class="form-control" id="catDesc" rows="3" placeholder="Optional description"></textarea>
            </div>
            <div class="form-group">
                <label class="form-label" for="catColor">Color</label>
                <div class="d-flex align-center gap-1">
                    <input type="color" id="catColor" value="#8b5cf6" style="width:50px;height:36px;border:none;background:none;cursor:pointer;">
                    <span id="catColorHex" class="text-muted" style="font-size:0.85rem;">#8b5cf6</span>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('categoryModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('categoryForm').requestSubmit()">
            <i data-lucide="save" style="width:16px;height:16px;"></i> Save
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', loadCategories);

document.getElementById('catColor').addEventListener('input', (e) => {
    document.getElementById('catColorHex').textContent = e.target.value;
});

async function loadCategories() {
    try {
        const data = await apiRequest('/api/categories.php');
        renderCategories(data.data || []);
    } catch (e) {
        showToast('Failed to load categories', 'error');
    }
}

function renderCategories(cats) {
    const tbody = document.getElementById('categoriesBody');
    
    if (cats.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i data-lucide="tags" style="width:48px;height:48px;"></i><h3>No Categories</h3><p>Create your first category to organize products.</p></div></td></tr>';
        lucide.createIcons({ nodes: [tbody] });
        return;
    }
    
    tbody.innerHTML = cats.map(c => `
        <tr>
            <td><span class="color-dot" style="background:${c.color};width:16px;height:16px;"></span></td>
            <td class="font-bold">${escapeHtml(c.name)}</td>
            <td class="text-muted">${c.description ? escapeHtml(c.description) : '—'}</td>
            <td><span class="badge badge-violet">${c.product_count}</span></td>
            <td class="text-muted">${new Date(c.created_at).toLocaleDateString()}</td>
            <td>
                <div class="d-flex gap-1">
                    <button class="btn btn-ghost btn-icon sm" title="Edit" onclick='editCategory(${JSON.stringify(c)})'>
                        <i data-lucide="pencil" style="width:15px;height:15px;"></i>
                    </button>
                    <button class="btn btn-ghost btn-icon sm" title="Delete" onclick="deleteCategory(${c.id}, '${escapeHtml(c.name)}')">
                        <i data-lucide="trash-2" style="width:15px;height:15px;color:var(--accent-rose);"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
    
    lucide.createIcons({ nodes: [tbody] });
}

function openCategoryModal(cat = null) {
    document.getElementById('categoryModalTitle').textContent = cat ? 'Edit Category' : 'Add Category';
    document.getElementById('categoryId').value = cat?.id || '';
    document.getElementById('catName').value = cat?.name || '';
    document.getElementById('catDesc').value = cat?.description || '';
    document.getElementById('catColor').value = cat?.color || '#8b5cf6';
    document.getElementById('catColorHex').textContent = cat?.color || '#8b5cf6';
    openModal('categoryModal');
}

function editCategory(cat) {
    openCategoryModal(cat);
}

async function saveCategory(e) {
    e.preventDefault();
    const id = document.getElementById('categoryId').value;
    const body = JSON.stringify({
        name: document.getElementById('catName').value,
        description: document.getElementById('catDesc').value,
        color: document.getElementById('catColor').value,
    });
    
    try {
        if (id) {
            await apiRequest(`/api/categories.php?id=${id}`, { method: 'PUT', body });
        } else {
            await apiRequest('/api/categories.php', { method: 'POST', body });
        }
        showToast(`Category ${id ? 'updated' : 'created'} successfully`);
        closeModal('categoryModal');
        loadCategories();
    } catch (e) {
        showToast(e.message || 'Failed to save category', 'error');
    }
}

async function deleteCategory(id, name) {
    if (!confirm(`Delete category "${name}"? Products will become uncategorized.`)) return;
    try {
        await apiRequest(`/api/categories.php?id=${id}`, { method: 'DELETE' });
        showToast('Category deleted');
        loadCategories();
    } catch (e) {
        showToast(e.message || 'Failed to delete', 'error');
    }
}
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
