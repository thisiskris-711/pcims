<?php
/**
 * Products Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_AUDITOR);

$db = getDB();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$pageTitle = 'Products';
$currentPage = 'products';
$pageScripts = ['products.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-bar">
            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
            <input type="text" class="form-control" id="productSearch" placeholder="Search products by name, SKU, or barcode...">
        </div>
        <select class="form-control" id="categoryFilter" style="width:auto;min-width:160px;">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"><?= sanitize($cat['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="form-control" id="statusFilter" style="width:auto;min-width:120px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
        <select class="form-control" id="expiryFilter" style="width:auto;min-width:140px;">
            <option value="">Expiry Filter</option>
            <option value="expiring_soon">Expiring Soon (30 days)</option>
            <option value="expired">Expired</option>
        </select>
    </div>
    <div class="toolbar-right">
        <?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER)): ?>
        <a href="<?= APP_URL ?>/product_form" class="btn btn-primary" id="addProductBtn">
            <i data-lucide="plus" style="width:18px;height:18px;"></i> Add Product
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Products Table -->
<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table id="productsTable">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
                        <th style="min-width:250px;">Product</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th>Cost</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Expiry</th>
                        <th>Status</th>
                        <th>Added By</th>
                        <?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER)): ?>
                        <th style="width:100px;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="productsBody">
                    <tr><td colspan="11" class="text-center text-muted" style="padding:40px;">Loading products...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="productsPagination" class="pagination"></div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteModal">
    <div class="modal-header">
        <h3 class="modal-title">Delete Product</h3>
        <button class="modal-close" onclick="closeModal('deleteModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <p>Are you sure you want to delete <strong id="deleteProductName"></strong>? This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
        <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()">
            <i data-lucide="trash-2" style="width:16px;height:16px;"></i> Delete
        </button>
    </div>
</div>

<script>
    window.CAN_EDIT = <?= hasRole(ROLE_ADMIN, ROLE_MANAGER) ? 'true' : 'false' ?>;
</script>
<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
