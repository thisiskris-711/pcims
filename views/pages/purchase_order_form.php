<?php
/**
 * Create Purchase Order Form
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER);

$pageTitle = 'Create Purchase Order';
$currentPage = 'purchase_orders';
$pageScripts = ['purchase_order_form.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <a href="<?= APP_URL ?>/purchase-orders" class="btn btn-secondary">
            <i data-lucide="arrow-left" style="width:18px;height:18px;"></i> Back to List
        </a>
    </div>
    <div class="toolbar-right">
        <button class="btn btn-primary" onclick="submitPO()">
            <i data-lucide="save" style="width:18px;height:18px;"></i> Save Purchase Order
        </button>
    </div>
</div>

<div class="form-row" style="align-items:flex-start;">
    <!-- PO Details -->
    <div class="card" style="flex:1;">
        <div class="card-header">
            <h3>Order Details</h3>
        </div>
        <div class="card-body">
            <div class="form-group dropdown-wrapper" id="supplierSelectWrapper">
                <label class="form-label">Supplier *</label>
                <input type="hidden" id="selectedSupplierId" value="">
                <input type="text" class="form-control" id="supplierSearchInput" placeholder="Search for supplier..." autocomplete="off">
                <div class="notification-dropdown" id="supplierDropdown" style="width:100%;top:calc(100% + 4px);max-height:250px;overflow-y:auto;display:none;"></div>
            </div>
            <div id="selectedSupplierInfo" style="display:none;background:var(--bg-tertiary);padding:12px;border-radius:var(--border-radius-sm);margin-bottom:16px;"></div>
            
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="expectedDate">Expected Delivery Date</label>
                    <input type="date" class="form-control" id="expectedDate">
                </div>
                <div class="form-group">
                    <label class="form-label" for="poStatus">Status</label>
                    <select class="form-control" id="poStatus">
                        <option value="draft">Draft</option>
                        <option value="pending">Pending</option>
                        <option value="ordered">Ordered</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label" for="poNotes">Notes</label>
                <textarea class="form-control" id="poNotes" rows="3" placeholder="Additional instructions or notes..."></textarea>
            </div>
        </div>
    </div>
    
    <!-- Summary -->
    <div class="card" style="width:300px;">
        <div class="card-header">
            <h3>Summary</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;margin-bottom:12px;">
                <span class="text-muted">Total Items</span>
                <strong id="summaryTotalItems">0</strong>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:1.2rem;">
                <span class="text-muted">Total Amount</span>
                <strong style="color:var(--primary-color);" id="summaryTotalAmount">₱0.00</strong>
            </div>
        </div>
    </div>
</div>

<!-- Order Items -->
<div class="card" style="margin-top:20px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>Order Items</h3>
        <div class="dropdown-wrapper" id="productSelectWrapper" style="width:300px;">
            <div class="search-bar" style="width:100%;">
                <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
                <input type="text" class="form-control" id="productSearchInput" placeholder="Search product to add..." autocomplete="off">
            </div>
            <div class="notification-dropdown" id="productDropdown" style="width:100%;top:calc(100% + 4px);max-height:250px;overflow-y:auto;display:none;"></div>
        </div>
    </div>
    <div class="card-body" style="padding-top:0;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width:50px;"></th>
                        <th>Product</th>
                        <th style="width:150px;">Unit Cost (₱)</th>
                        <th style="width:120px;">Quantity</th>
                        <th style="width:150px;">Total (₱)</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="poItemsBody">
                    <tr id="emptyItemsRow"><td colspan="6" class="text-center text-muted" style="padding:40px;">No items added yet. Search and select products to add.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
