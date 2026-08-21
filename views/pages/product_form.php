<?php

/**
 * Product Add/Edit Form
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_products');
$db = getDB();
$categories = $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();

$isEdit = false;
$product = null;

if (isset($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $product = $stmt->fetch();
    if ($product) $isEdit = true;
}

$bundleItems = [];
if ($isEdit && ($product['type'] ?? 'standard') === 'bundle') {
    $stmt = $db->prepare("SELECT pbi.*, p.name as product_name, p.sku FROM product_bundle_items pbi JOIN products p ON pbi.product_id = p.id WHERE pbi.bundle_id = ?");
    $stmt->execute([$product['id']]);
    $bundleItems = $stmt->fetchAll();
}
$allProducts = $db->query("SELECT id, name, sku FROM products WHERE status = 'active' AND type = 'standard' ORDER BY name")->fetchAll();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId = $_POST['category_id'] ?: null;
    $costPrice = (float)($_POST['cost_price'] ?? 0);
    $sellingPrice = (float)($_POST['selling_price'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);
    $lowStockThreshold = (int)($_POST['low_stock_threshold'] ?? 10);
    $barcode = trim($_POST['barcode'] ?? '');
    $status = $_POST['status'] ?? 'active';
    $type = $_POST['type'] ?? 'standard';
    $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

    if (empty($name)) {
        flashMessage('Product name is required.', 'error');
    } else {
        // Handle image
        $image = $product['image'] ?? null;
        if (isset($_POST['remove_image']) && $_POST['remove_image'] === '1') {
            if ($image) deleteUploadedFile($image);
            $image = null;
        } elseif (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Delete old image
            if ($image) deleteUploadedFile($image);
            $image = handleImageUpload($_FILES['image']);
        }

        if ($isEdit) {
            $stmt = $db->prepare("
                UPDATE products SET name=?, description=?, category_id=?, cost_price=?, selling_price=?, 
                low_stock_threshold=?, image=?, barcode=?, expiry_date=?, status=?, type=?, updated_at=NOW() WHERE id=?
            ");
            $stmt->execute([$name, $description, $categoryId, $costPrice, $sellingPrice, $lowStockThreshold, $image, $barcode, $expiryDate, $status, $type, $product['id']]);

            $db->prepare("DELETE FROM product_bundle_items WHERE bundle_id = ?")->execute([$product['id']]);
            if ($type === 'bundle' && !empty($_POST['bundle_product_id'])) {
                $bStmt = $db->prepare("INSERT INTO product_bundle_items (bundle_id, product_id, quantity) VALUES (?, ?, ?)");
                foreach ($_POST['bundle_product_id'] as $idx => $compId) {
                    $cQty = (int)($_POST['bundle_quantity'][$idx] ?? 1);
                    if ($cQty > 0) {
                        $bStmt->execute([$product['id'], $compId, $cQty]);
                    }
                }
            }

            flashMessage('Product updated successfully!');
        } else {
            $prefix = 'GN';
            if ($categoryId) {
                $catStmt = $db->prepare("SELECT name FROM categories WHERE id = ?");
                $catStmt->execute([$categoryId]);
                $catName = $catStmt->fetchColumn();
                if ($catName) $prefix = getCategoryPrefix($catName);
            }
            $sku = generateSKU($prefix);

            $stmt = $db->prepare("
                INSERT INTO products (sku, name, description, category_id, cost_price, selling_price, quantity, low_stock_threshold, image, barcode, expiry_date, status, type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sku, $name, $description, $categoryId, $costPrice, $sellingPrice, $type === 'bundle' ? 0 : $quantity, $lowStockThreshold, $image, $barcode, $expiryDate, $status, $type, getCurrentUserId()]);

            $newId = $db->lastInsertId();

            if ($type === 'bundle' && !empty($_POST['bundle_product_id'])) {
                $bStmt = $db->prepare("INSERT INTO product_bundle_items (bundle_id, product_id, quantity) VALUES (?, ?, ?)");
                foreach ($_POST['bundle_product_id'] as $idx => $compId) {
                    $cQty = (int)($_POST['bundle_quantity'][$idx] ?? 1);
                    if ($cQty > 0) {
                        $bStmt->execute([$newId, $compId, $cQty]);
                    }
                }
            }

            // Log initial stock
            if ($type === 'standard' && $quantity > 0) {
                $ref = generateReferenceNo('in');
                $db->prepare("INSERT INTO stock_transactions (product_id, type, quantity, balance_after, reference_no, notes, created_by) VALUES (?, 'in', ?, ?, ?, 'Initial stock', ?)")
                    ->execute([$newId, $quantity, $quantity, $ref, getCurrentUserId()]);
            }

            flashMessage('Product created successfully!');
        }

        redirect(APP_URL . '/products');
    }
}

$pageTitle = $isEdit ? 'Edit Product' : 'Add Product';
$currentPage = 'products';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div style="max-width:800px;">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= $isEdit ? 'Edit' : 'New' ?> Product</h3>
            <a href="<?= APP_URL ?>/products" class="btn btn-ghost btn-sm">
                <i data-lucide="arrow-left" style="width:16px;height:16px;"></i> Back
            </a>
        </div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="productForm">
                <div class="form-group">
                    <label class="form-label" for="name">Product Name *</label>
                    <input type="text" class="form-control" id="name" name="name" required
                        value="<?= sanitize($product['name'] ?? '') ?>" placeholder="Enter product name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3" placeholder="Product description"><?= sanitize($product['description'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="type">Product Type</label>
                        <select class="form-control" id="type" name="type" onchange="toggleBundleFields()">
                            <option value="standard" <?= ($product['type'] ?? 'standard') === 'standard' ? 'selected' : '' ?>>Standard Product</option>
                            <option value="bundle" <?= ($product['type'] ?? '') === 'bundle' ? 'selected' : '' ?>>Product Bundle</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-control" id="category_id" name="category_id">
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= ($product['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($cat['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="barcode">Barcode</label>
                        <input type="text" class="form-control" id="barcode" name="barcode"
                            value="<?= sanitize($product['barcode'] ?? '') ?>" placeholder="Optional barcode">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="cost_price">Cost Price (₱)</label>
                        <input type="number" class="form-control" id="cost_price" name="cost_price" step="0.01" min="0"
                            value="<?= $product['cost_price'] ?? '0.00' ?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="selling_price">Selling Price (₱)</label>
                        <input type="number" class="form-control" id="selling_price" name="selling_price" step="0.01" min="0"
                            value="<?= $product['selling_price'] ?? '0.00' ?>" placeholder="0.00">
                    </div>
                </div>

                <div id="standardFields" style="<?= ($product['type'] ?? 'standard') === 'bundle' ? 'display:none;' : '' ?>">
                    <?php if (!$isEdit): ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="quantity">Initial Quantity</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" min="0"
                                    value="0" placeholder="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="low_stock_threshold">Low Stock Threshold</label>
                                <input type="number" class="form-control" id="low_stock_threshold" name="low_stock_threshold" min="0"
                                    value="<?= $product['low_stock_threshold'] ?? '10' ?>" placeholder="10">
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label class="form-label" for="low_stock_threshold">Low Stock Threshold</label>
                            <input type="number" class="form-control" id="low_stock_threshold" name="low_stock_threshold" min="0"
                                value="<?= $product['low_stock_threshold'] ?? '10' ?>" placeholder="10">
                            <div class="form-hint">Current stock: <strong><?= $product['quantity'] ?></strong> — Adjust stock via Stock Movement page.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="bundleFields" style="<?= ($product['type'] ?? 'standard') === 'standard' ? 'display:none;' : '' ?>">
                    <h4 style="margin-top: 10px; margin-bottom: 10px; font-size: 1rem;">Bundle Components</h4>
                    <div id="bundleItemsContainer">
                        <?php foreach ($bundleItems as $idx => $bItem): ?>
                            <div class="d-flex bundle-item-row" style="margin-bottom: 10px; align-items: flex-end; gap: 16px;">
                                <div class="form-group" style="flex: 3; margin-bottom: 0;">
                                    <label class="form-label">Product</label>
                                    <select class="form-control" name="bundle_product_id[]" required>
                                        <option value="">Select a product</option>
                                        <?php foreach ($allProducts as $ap): ?>
                                            <option value="<?= $ap['id'] ?>" <?= $ap['id'] == $bItem['product_id'] ? 'selected' : '' ?>><?= sanitize($ap['name']) ?> (<?= sanitize($ap['sku']) ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                    <label class="form-label" for="bundle_quantity[]">Quantity</label>
                                    <input type="number" class="form-control" name="bundle_quantity[]" min="1" value="<?= $bItem['quantity'] ?>" placeholder="Qty" required>
                                </div>
                                <button type="button" class="btn btn-ghost btn-sm text-danger" onclick="this.closest('.bundle-item-row').remove()" style="height: 42px; padding: 0 12px;">
                                    <i data-lucide="trash-2" style="width:18px;height:18px;"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="addBundleItem()">
                        <i data-lucide="plus" style="width:16px;height:16px;"></i> Add Component
                    </button>
                    <div class="form-hint mt-2">Bundle available stock will be calculated automatically from its components.</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="expiry_date">Expiry Date</label>
                        <input type="date" class="form-control" id="expiry_date" name="expiry_date"
                            value="<?= sanitize($product['expiry_date'] ?? '') ?>">
                        <div class="form-hint">Leave blank if not applicable.</div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="active" <?= ($product['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($product['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Product Image</label>
                        <input type="hidden" name="remove_image" id="removeImageFlag" value="0">
                        <div style="position: relative; display: inline-block; width: 100%;">
                            <div class="image-upload <?= !empty($product['image']) ? 'has-image' : '' ?>" id="imageUploadContainer" onclick="document.getElementById('imageInput').click()">
                                <?php if (!empty($product['image'])): ?>
                                    <img src="<?= UPLOAD_URL . sanitize($product['image']) ?>" id="imagePreview" alt="Product image">
                                    <div class="image-upload-text" id="uploadText" style="display:none;">
                                        <i data-lucide="upload" style="width:32px;height:32px;"></i>
                                        Click to upload image
                                    </div>
                                <?php else: ?>
                                    <img src="" id="imagePreview" alt="Preview" style="display:none;">
                                    <div class="image-upload-text" id="uploadText">
                                        <i data-lucide="upload" style="width:32px;height:32px;"></i>
                                        Click to upload image
                                    </div>
                                <?php endif; ?>
                                <input type="file" id="imageInput" name="image" accept="image/*"
                                    onchange="handleImageSelect(this)">
                            </div>
                            <button type="button" id="removeImageBtn" class="btn btn-sm btn-danger"
                                style="position: absolute; top: 10px; right: 10px; padding: 4px; display: <?= !empty($product['image']) ? 'block' : 'none' ?>; z-index: 10;"
                                onclick="clearImage(event)" title="Remove Image">
                                <i data-lucide="x" style="width:14px;height:14px;"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                    <div class="form-hint mb-2">
                        SKU: <code style="color:var(--accent-cyan);"><?= sanitize($product['sku']) ?></code>
                        | Created: <?= date('M j, Y', strtotime($product['created_at'])) ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-1" style="margin-top:24px;">
                    <button type="submit" class="btn btn-primary">
                        <i data-lucide="<?= $isEdit ? 'save' : 'plus' ?>" style="width:18px;height:18px;"></i>
                        <?= $isEdit ? 'Save Changes' : 'Create Product' ?>
                    </button>
                    <a href="<?= APP_URL ?>/products" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>

<script>
    function toggleBundleFields() {
        const type = document.getElementById('type').value;
        document.getElementById('standardFields').style.display = type === 'standard' ? 'block' : 'none';
        document.getElementById('bundleFields').style.display = type === 'bundle' ? 'block' : 'none';
    }

    function addBundleItem() {
        const container = document.getElementById('bundleItemsContainer');
        const row = document.createElement('div');
        row.className = 'd-flex bundle-item-row';
        row.style.marginBottom = '10px';
        row.style.alignItems = 'flex-end';
        row.style.gap = '16px';
        row.innerHTML = `
            <div class="form-group" style="flex: 3; margin-bottom: 0;">
                <label class="form-label">Product</label>
                <select class="form-control" name="bundle_product_id[]" required>
                    <option value="">Select a product</option>
                    <?php foreach ($allProducts as $ap): ?>
                    <option value="<?= $ap['id'] ?>"><?= addslashes(sanitize($ap['name'])) ?> (<?= addslashes(sanitize($ap['sku'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                <label class="form-label">Quantity</label>
                <input type="number" class="form-control" name="bundle_quantity[]" min="1" value="1" placeholder="Qty" required>
            </div>
            <button type="button" class="btn btn-ghost btn-sm text-danger" onclick="this.closest('.bundle-item-row').remove()" style="height: 42px; padding: 0 12px;">
                <i data-lucide="trash-2" style="width:18px;height:18px;"></i>
            </button>
        `;
        container.appendChild(row);
        if (typeof lucide !== 'undefined') lucide.createIcons({
            root: row
        });
        
        row.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('wheel', (e) => e.preventDefault());
        });
    }

    function handleImageSelect(input) {
        previewImage(input, '#imagePreview');
        const uploadText = document.getElementById('uploadText');
        if (uploadText) uploadText.style.display = 'none';
        document.getElementById('removeImageBtn').style.display = 'block';
        document.getElementById('removeImageFlag').value = '0';
    }

    function clearImage(event) {
        event.stopPropagation();
        const fileInput = document.getElementById('imageInput');
        fileInput.value = '';

        const preview = document.getElementById('imagePreview');
        preview.src = '';
        preview.style.display = 'none';

        const uploadText = document.getElementById('uploadText');
        if (uploadText) uploadText.style.display = 'flex';

        const container = document.getElementById('imageUploadContainer');
        if (container) container.classList.remove('has-image');

        document.getElementById('removeImageBtn').style.display = 'none';
        document.getElementById('removeImageFlag').value = '1';
    }

    document.querySelectorAll('input[type="number"]').forEach(input => {
        input.addEventListener('wheel', (e) => e.preventDefault());
    });
</script>