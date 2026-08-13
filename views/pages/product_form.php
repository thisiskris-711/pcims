<?php
/**
 * Product Add/Edit Form
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER);

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
    
    if (empty($name)) {
        flashMessage('Product name is required.', 'error');
    } else {
        // Handle image
        $image = $product['image'] ?? null;
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Delete old image
            if ($image) deleteUploadedFile($image);
            $image = handleImageUpload($_FILES['image']);
        }
        
        if ($isEdit) {
            $stmt = $db->prepare("
                UPDATE products SET name=?, description=?, category_id=?, cost_price=?, selling_price=?, 
                low_stock_threshold=?, image=?, barcode=?, status=?, updated_at=NOW() WHERE id=?
            ");
            $stmt->execute([$name, $description, $categoryId, $costPrice, $sellingPrice, $lowStockThreshold, $image, $barcode, $status, $product['id']]);
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
                INSERT INTO products (sku, name, description, category_id, cost_price, selling_price, quantity, low_stock_threshold, image, barcode, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$sku, $name, $description, $categoryId, $costPrice, $sellingPrice, $quantity, $lowStockThreshold, $image, $barcode, $status, getCurrentUserId()]);
            
            $newId = $db->lastInsertId();
            
            // Log initial stock
            if ($quantity > 0) {
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
            <a href="<?= APP_URL ?>/products.php" class="btn btn-ghost btn-sm">
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
                        <label class="form-label" for="cost_price">Cost Price ($)</label>
                        <input type="number" class="form-control" id="cost_price" name="cost_price" step="0.01" min="0"
                               value="<?= $product['cost_price'] ?? '0.00' ?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="selling_price">Selling Price ($)</label>
                        <input type="number" class="form-control" id="selling_price" name="selling_price" step="0.01" min="0"
                               value="<?= $product['selling_price'] ?? '0.00' ?>" placeholder="0.00">
                    </div>
                </div>
                
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
                        <div class="image-upload <?= !empty($product['image']) ? 'has-image' : '' ?>" onclick="document.getElementById('imageInput').click()">
                            <?php if (!empty($product['image'])): ?>
                                <img src="<?= UPLOAD_URL . sanitize($product['image']) ?>" id="imagePreview" alt="Product image">
                            <?php else: ?>
                                <img src="" id="imagePreview" alt="Preview" style="display:none;">
                                <div class="image-upload-text" id="uploadText">
                                    <i data-lucide="upload" style="width:32px;height:32px;"></i>
                                    Click to upload image
                                </div>
                            <?php endif; ?>
                            <input type="file" id="imageInput" name="image" accept="image/*" 
                                   onchange="previewImage(this, '#imagePreview'); document.getElementById('uploadText')?.remove();">
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
                    <a href="<?= APP_URL ?>/products.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
