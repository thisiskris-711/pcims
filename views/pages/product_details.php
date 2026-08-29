<?php

/**
 * Product Details Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_products');

$db = getDB();

if (!isset($_GET['id'])) {
    redirect(APP_URL . '/products');
}

$stmt = $db->prepare("
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.id = ?
");
$stmt->execute([$_GET['id']]);
$product = $stmt->fetch();

if (!$product) {
    flashMessage('Product not found.', 'error');
    redirect(APP_URL . '/products');
}

// Fetch stock history
$stockStmt = $db->prepare("
    SELECT st.*, u.full_name as user_name 
    FROM stock_transactions st
    LEFT JOIN users u ON st.created_by = u.id
    WHERE st.product_id = ?
    ORDER BY st.created_at DESC
    LIMIT 50
");
$stockStmt->execute([$product['id']]);
$stockHistory = $stockStmt->fetchAll();

$pageTitle = 'Product Details: ' . sanitize($product['name']);
$currentPage = 'products';
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Import model-viewer for 3D rendering -->
<script type="module" src="https://ajax.googleapis.com/ajax/libs/model-viewer/4.0.0/model-viewer.min.js"></script>

<style>
    .details-container {
        display: grid;
        grid-template-columns: 1fr;
        gap: 24px;
    }

    @media (min-width: 768px) {
        .details-container {
            grid-template-columns: 1fr 1fr;
        }
    }

    .media-viewer {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        position: relative;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .media-viewer model-viewer {
        width: 100%;
        height: 500px;
        background-color: #f8f9fa;
    }

    .media-viewer img {
        max-width: 100%;
        max-height: 500px;
        object-fit: contain;
    }

    .info-card {
        padding: 24px;
    }

    .stat-box {
        background: var(--bg-body);
        padding: 16px;
        border-radius: 8px;
        text-align: center;
        border: 1px solid var(--border-color);
    }

    .stat-label {
        font-size: 0.85rem;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-color);
    }

    #lightbox {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.85);
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
    }
</style>

<div style="max-width:1200px;">
    <div class="d-flex" style="justify-content: space-between; align-items: center; margin-bottom: 24px;">
        <div class="d-flex gap-1" style="align-items: center;">
            <a href="<?= APP_URL ?>/products" class="btn btn-ghost btn-sm" title="Back to Products">
                <i data-lucide="arrow-left" style="width:16px;height:16px;"></i>
            </a>
            <h2 style="margin:0; font-size: 1.5rem;"><?= sanitize($product['name']) ?></h2>
            <span class="status status-<?= $product['status'] ?>" style="margin-left: 8px;"><?= ucfirst($product['status']) ?></span>
        </div>
        <?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER)): ?>
            <a href="<?= APP_URL ?>/product_form?id=<?= $product['id'] ?>" class="btn btn-primary btn-sm">
                <i data-lucide="edit" style="width:16px;height:16px;"></i> Edit Product
            </a>
        <?php endif; ?>
    </div>

    <div class="details-container">
        <!-- Media Section -->
        <div class="media-viewer">
            <?php if (!empty($product['model_3d']) || !empty($product['image'])): ?>

                <?php if (!empty($product['model_3d'])): ?>
                    <model-viewer id="product-model-viewer"
                        src="<?= UPLOAD_URL . sanitize($product['model_3d']) ?>"
                        alt="A 3D model of <?= sanitize($product['name']) ?>"
                        camera-controls
                        shadow-intensity="1"
                        environment-image="neutral"
                        exposure="1.0"
                        style="display: <?= !empty($product['model_3d']) ? 'block' : 'none' ?>;">
                    </model-viewer>
                <?php endif; ?>

                <?php if (!empty($product['image'])): ?>
                    <img id="product-image-viewer"
                        src="<?= UPLOAD_URL . sanitize($product['image']) ?>"
                        alt="<?= sanitize($product['name']) ?>"
                        style="cursor: zoom-in; max-height: 100%; max-width: 100%; height: auto; width: auto; display: <?= empty($product['model_3d']) ? 'block' : 'none' ?>;"
                        onclick="openLightbox(this.src)">
                <?php endif; ?>

                <!-- Floating Toolbar -->
                <div style="position: absolute; bottom: 16px; display: flex; gap: 8px; background: rgba(255,255,255,0.9); padding: 8px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); border: 1px solid var(--border-color); backdrop-filter: blur(4px);">
                    <?php if (!empty($product['image'])): ?>
                        <button class="btn btn-sm <?= empty($product['model_3d']) ? 'btn-primary' : 'btn-ghost' ?>" id="btn-view-2d" onclick="switchMediaView('2d')" style="padding: 4px 12px; font-weight: 500;">2D</button>
                    <?php endif; ?>

                    <?php if (!empty($product['model_3d'])): ?>
                        <button class="btn btn-sm <?= !empty($product['model_3d']) ? 'btn-primary' : 'btn-ghost' ?>" id="btn-view-3d" onclick="switchMediaView('3d')" style="padding: 4px 12px; font-weight: 500;">3D</button>
                        <button class="btn btn-sm btn-ghost" id="btn-play-3d" onclick="toggle3DRotation()" style="padding: 4px 8px; display: <?= !empty($product['model_3d']) ? 'flex' : 'none' ?>;" title="Auto-Rotate">
                            <i id="icon-play-3d" data-lucide="play" style="width:16px;height:16px;"></i>
                        </button>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- Placeholder -->
                <div class="text-muted" style="text-align:center; padding: 40px;">
                    <i data-lucide="image" style="width:64px;height:64px; opacity: 0.2; margin-bottom: 16px;"></i>
                    <p>No image or 3D model available.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Information Section -->
        <div>
            <div class="card info-card">
                <h3 style="margin-top:0; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 24px;">Product Details</h3>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                    <div class="stat-box">
                        <div class="stat-label">Selling Price</div>
                        <div class="stat-value" style="color: var(--accent-emerald);"><?= formatCurrency($product['selling_price']) ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Current Stock</div>
                        <div class="stat-value <?= $product['quantity'] <= $product['low_stock_threshold'] ? 'text-danger' : '' ?>">
                            <?= $product['quantity'] ?>
                        </div>
                    </div>
                </div>

                <table class="table" style="background: transparent; box-shadow: none;">
                    <tbody>
                        <tr>
                            <td style="width: 150px; color: var(--text-muted);">SKU</td>
                            <td class="font-bold"><code style="color:var(--accent-cyan);"><?= sanitize($product['sku']) ?></code></td>
                        </tr>
                        <tr>
                            <td style="color: var(--text-muted);">Category</td>
                            <td><?= $product['category_name'] ? sanitize($product['category_name']) : '<span style="opacity: 0.3;">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color: var(--text-muted);">Barcode</td>
                            <td><?= $product['barcode'] ? sanitize($product['barcode']) : '<span style="opacity: 0.3;">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td style="color: var(--text-muted);">Expiry Date</td>
                            <td><?= $product['expiry_date'] ? date('M j, Y', strtotime($product['expiry_date'])) : '<span style="opacity: 0.3;">—</span>' ?></td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top: 24px;">
                    <h4 style="font-size: 1rem; margin-bottom: 8px;">Description</h4>
                    <p style="color: var(--text-color); line-height: 1.6; white-space: pre-wrap;"><?= $product['description'] ? sanitize($product['description']) : '<span class="text-muted">No description provided.</span>' ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock History Section -->
    <div class="card" style="margin-top: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="card-title">Recent Stock History</h3>
            <a href="<?= APP_URL ?>/stock?search=<?= urlencode($product['sku']) ?>" class="btn btn-ghost btn-sm text-primary" style="padding: 4px 12px;">
                View All <i data-lucide="arrow-right" style="width:14px;height:14px;margin-left:4px;"></i>
            </a>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($stockHistory)): ?>
                <div class="empty-state" style="padding: 40px; text-align: center;">
                    <i data-lucide="history" style="width:48px;height:48px; opacity:0.2;"></i>
                    <h4 style="margin-top: 16px;">No stock movements yet</h4>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Reference</th>
                                <th>Type</th>
                                <th style="text-align: right;">Quantity</th>
                                <th style="text-align: right;">Balance After</th>
                                <th>Notes</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stockHistory as $st): ?>
                                <?php
                                $typeColor = 'var(--text-color)';
                                $typeLabel = ucfirst($st['type']);
                                $sign = '';
                                if ($st['type'] === 'in') {
                                    $typeColor = 'var(--accent-emerald)';
                                    $sign = '+';
                                } elseif ($st['type'] === 'out') {
                                    $typeColor = 'var(--accent-rose)';
                                    $sign = '-';
                                } elseif ($st['type'] === 'adj') {
                                    $typeColor = 'var(--accent-amber)';
                                    $typeLabel = 'Adjustment';
                                    $sign = $st['quantity'] >= 0 ? '+' : '';
                                }

                                $ref = sanitize($st['reference_no']);
                                $refLink = $ref;
                                if (strpos($ref, 'SO-') === 0) {
                                    $refLink = '<a href="' . APP_URL . '/sales?search=' . urlencode($ref) . '" class="text-primary hover-underline" style="text-decoration:none;">' . $ref . '</a>';
                                } elseif (strpos($ref, 'PO-') === 0) {
                                    $refLink = '<a href="' . APP_URL . '/purchase-orders?search=' . urlencode($ref) . '" class="text-primary hover-underline" style="text-decoration:none;">' . $ref . '</a>';
                                }
                                ?>
                                <tr>
                                    <td class="text-muted"><?= date('M j, Y h:i A', strtotime($st['created_at'])) ?></td>
                                    <td><code style="color:var(--accent-cyan); font-size: 0.85rem; background:transparent; padding:0;"><?= $refLink ?></code></td>
                                    <td><span style="color: <?= $typeColor ?>; font-weight: 500;"><?= $typeLabel ?></span></td>
                                    <td style="text-align: right; font-weight: 600; color: <?= $typeColor ?>;">
                                        <?= $sign . $st['quantity'] ?>
                                    </td>
                                    <td style="text-align: right; font-weight: bold;"><?= $st['balance_after'] ?></td>
                                    <td class="text-muted"><?= $st['notes'] ? sanitize($st['notes']) : '—' ?></td>
                                    <td><?= $st['user_name'] ? sanitize($st['user_name']) : '<span class="text-muted">System</span>' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="lightbox" onclick="this.style.display='none'">
    <button style="position: absolute; top: 20px; right: 20px; background: transparent; border: none; color: white; cursor: pointer;">
        <i data-lucide="x" style="width: 32px; height: 32px;"></i>
    </button>
    <img id="lightbox-img" src="" style="max-width: 90%; max-height: 90%; border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,0.5);">
</div>

<script>
    function openLightbox(src) {
        document.getElementById('lightbox-img').src = src;
        document.getElementById('lightbox').style.display = 'flex';
    }

    let isRotating = false;

    function switchMediaView(view) {
        const viewer2D = document.getElementById('product-image-viewer');
        const viewer3D = document.getElementById('product-model-viewer');
        const btn2D = document.getElementById('btn-view-2d');
        const btn3D = document.getElementById('btn-view-3d');
        const btnPlay = document.getElementById('btn-play-3d');

        if (view === '2d') {
            if (viewer2D) viewer2D.style.display = 'block';
            if (viewer3D) viewer3D.style.display = 'none';
            if (btnPlay) btnPlay.style.display = 'none';

            if (btn2D) {
                btn2D.classList.remove('btn-ghost');
                btn2D.classList.add('btn-primary');
            }
            if (btn3D) {
                btn3D.classList.remove('btn-primary');
                btn3D.classList.add('btn-ghost');
            }
        } else if (view === '3d') {
            if (viewer2D) viewer2D.style.display = 'none';
            if (viewer3D) viewer3D.style.display = 'block';
            if (btnPlay) btnPlay.style.display = 'flex';

            if (btn3D) {
                btn3D.classList.remove('btn-ghost');
                btn3D.classList.add('btn-primary');
            }
            if (btn2D) {
                btn2D.classList.remove('btn-primary');
                btn2D.classList.add('btn-ghost');
            }
        }
    }

    function toggle3DRotation() {
        const viewer3D = document.getElementById('product-model-viewer');
        const iconPlay = document.getElementById('icon-play-3d');

        if (!viewer3D) return;

        isRotating = !isRotating;
        if (isRotating) {
            viewer3D.setAttribute('auto-rotate', 'true');
            iconPlay.setAttribute('data-lucide', 'pause');
        } else {
            viewer3D.removeAttribute('auto-rotate');
            iconPlay.setAttribute('data-lucide', 'play');
        }
        lucide.createIcons();
    }
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>

