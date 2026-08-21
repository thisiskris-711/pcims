<?php

/**
 * Promotions Management Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_products');
$pageTitle = 'Promotions';
$currentPage = 'promotions';
$pageScripts = ['promotions.js'];

$db = getDB();
$products = $db->query("SELECT id, name, image, selling_price FROM products WHERE status = 'active' ORDER BY name")->fetchAll();
$categories = $db->query("SELECT id, name, color, icon FROM categories ORDER BY name")->fetchAll();

include dirname(__DIR__) . '/layouts/header.php';
?>

<script>
    window.PRODUCTS = <?= json_encode($products) ?>;
    window.CATEGORIES = <?= json_encode($categories) ?>;
</script>

<style>
.custom-dropdown-item:hover {
    background-color: var(--background-color, #f8fafc) !important;
}
</style>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="font-size:1rem;font-weight:600;">Manage promotions and deals</h2>
    </div>
    <div class="toolbar-right">
        <button class="btn btn-primary" onclick="openPromoModal()">
            <i data-lucide="plus" style="width:18px;height:18px;"></i> Add Promotion
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Dates</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="promotionsBody">
                    <tr>
                        <td colspan="6" class="text-center text-muted" style="padding:40px;">Loading...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Promotion Modal -->
<div class="modal" id="promoModal" style="max-width:600px;">
    <div class="modal-header">
        <h3 class="modal-title" id="promoModalTitle">Add Promotion</h3>
        <button class="modal-close" onclick="closeModal('promoModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="promoForm" onsubmit="savePromotion(event)">
            <input type="hidden" id="promoId" value="">
            <div class="form-group">
                <label class="form-label" for="promoName">Name *</label>
                <input type="text" class="form-control" id="promoName" required placeholder="e.g., Buy 2 Same Category - 10% Off">
            </div>
            <div class="form-group">
                <label class="form-label" for="promoType">Type *</label>
                <select class="form-control" id="promoType" required onchange="onPromoTypeChange()">
                    <option value="">Select type...</option>
                    <option value="category_discount">Promo</option>
                    <option value="bundle_deal">Bundle</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="promoDesc">Description</label>
                <textarea class="form-control" id="promoDesc" rows="2" placeholder="Describe the promotion..."></textarea>
            </div>

            <div class="form-group" id="promoRuleGroup" style="display:none;">
                <label class="form-label" for="promoRule">Promo Rule *</label>
                <select class="form-control" id="promoRule" onchange="onPromoRuleChange()">
                    <option value="">Select a rule...</option>
                    <option value="buy_x_get_y">Buy X, Get Y at Price Z</option>
                    <option value="buy_any_x_get_any_y">Buy any X, Get any Y at Price Z</option>
                    <option value="buy_any_x_for_y">Buy any X for only Y</option>
                </select>
            </div>

            <!-- Promo Config Container -->
            <div id="promoConfigContainer" style="display:none; padding:16px; background:var(--bg-tertiary, #f9fafb); border:1px solid var(--border-color); border-radius:6px; margin-bottom:16px;">
                <!-- Filled dynamically by JS -->
            </div>

            <!-- Bundle Config Container -->
            <div id="bundleConfigContainer" style="display:none; padding:16px; background:var(--bg-tertiary, #f9fafb); border:1px solid var(--border-color); border-radius:6px; margin-bottom:16px;">
                <label class="form-label">Bundle Components *</label>
                <table class="table" style="margin-bottom:12px; font-size:0.9rem;">
                    <thead>
                        <tr>
                            <th width="80">Qty</th>
                            <th width="140">Selection</th>
                            <th>Product / Group</th>
                            <th width="40"></th>
                        </tr>
                    </thead>
                    <tbody id="bundleComponentsBody">
                        <!-- Dynamic rows -->
                    </tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline" onclick="addBundleComponent()" style="margin-bottom:16px; width:100%; justify-content:center;">
                    <i data-lucide="plus" style="width:16px;height:16px;margin-right:6px;"></i> Add Bundle Item
                </button>
                <div style="display:flex;gap:12px;align-items:flex-end;">
                    <div class="form-group" style="flex:1;margin-bottom:0;">
                        <label class="form-label" for="configBundlePrice">Bundle Price (₱) *</label>
                        <input type="number" class="form-control" id="configBundlePrice" min="0" step="0.01" oninput="updateBundleCalculations()" placeholder="e.g., 500.00">
                    </div>
                    <div style="flex:2; padding:12px; background:var(--bg-card, #fff); border-radius:4px; border:1px solid var(--border-color); display:flex; justify-content:space-around; align-items:center;">
                        <div><span class="text-muted" style="font-size:0.85rem;">Regular Price:</span> <br><strong id="bundleRegularPrice" style="font-size:1.1rem;">₱0.00</strong></div>
                        <div><span class="text-muted" style="font-size:0.85rem;">Customer Saves:</span> <br><strong id="bundleSavings" style="color:var(--accent-emerald); font-size:1.1rem;">₱0.00</strong></div>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:12px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label" for="promoStartDate">Start Date</label>
                    <input type="date" class="form-control" id="promoStartDate">
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label" for="promoEndDate">End Date</label>
                    <input type="date" class="form-control" id="promoEndDate">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="promoActive">Status</label>
                <select class="form-control" id="promoActive">
                    <option value="1">Active</option>
                    <option value="0">Inactive</option>
                </select>
            </div>

            <div style="display:flex;gap:24px;margin-bottom:16px;">
                <div class="form-group" style="flex:1;">
                    <div style="display:flex;align-items:center;justify-content:space-between;height:38px;">
                        <label class="form-label" style="margin-bottom:0;cursor:pointer;" for="promoSuggestPos">Suggest in POS</label>
                        <label class="toggle-switch">
                            <input type="checkbox" id="promoSuggestPos" checked onchange="updatePreview()">
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label" for="promoPriority">Recommendation Priority</label>
                    <select class="form-control" id="promoPriority">
                        <option value="high">High</option>
                        <option value="normal" selected>Normal</option>
                        <option value="low">Low</option>
                    </select>
                </div>
            </div>

            <!-- POS Preview Section -->
            <div style="margin-top:24px; margin-bottom:24px;">
                <label class="form-label">POS Preview</label>
                <div id="posPreviewContainer" style="background:var(--bg-tertiary, #f9fafb); border:1px solid var(--border-color); border-radius:6px; padding:16px; display:flex; flex-direction:column; gap:12px; min-height:100px; justify-content:center;">
                    <div class="text-muted text-center" style="font-size:0.9rem;">Select a promotion type and rule to generate a preview.</div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('promoModal')">Cancel</button>
        <button class="btn btn-primary" onclick="savePromotion(event)">
            <i data-lucide="check" style="width:16px;height:16px;"></i> Save Promotion
        </button>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deletePromoModal">
    <div class="modal-header">
        <h3 class="modal-title">Delete Promotion</h3>
        <button class="modal-close" onclick="closeModal('deletePromoModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <p>Are you sure you want to delete this promotion? This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('deletePromoModal')">Cancel</button>
        <button class="btn btn-danger" id="confirmDeletePromoBtn">
            <i data-lucide="trash-2" style="width:16px;height:16px;"></i> Delete
        </button>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>