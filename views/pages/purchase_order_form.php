<?php
/**
 * Create Purchase Order Form
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_suppliers');
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

<!-- Unified PO Form Area -->
<div class="card" style="overflow:visible;">
    <div class="card-body">
        <!-- Order Details Header -->
        <h3 style="margin-bottom: 24px;">Order Details</h3>
        
        <!-- Supplier -->
        <div class="form-group dropdown-wrapper" id="supplierSelectWrapper" style="max-width: 600px;">
            <label class="form-label">Supplier *</label>
            <input type="hidden" id="selectedSupplierId" value="">
            <input type="text" class="form-control" id="supplierSearchInput" placeholder="Search for supplier..." autocomplete="off">
            <div class="notification-dropdown" id="supplierDropdown" style="width:100%;top:calc(100% + 4px);max-height:250px;overflow-y:auto;display:none; opacity:1; visibility:visible; transform:none;"></div>
        </div>
        <div id="selectedSupplierInfo" style="display:none;background:var(--bg-tertiary);padding:12px;border-radius:var(--border-radius-sm);margin-bottom:16px;max-width: 600px;"></div>
        
        <!-- Date and Status -->
        <div class="form-row" style="max-width: 600px;">
            <div class="form-group">
                <label class="form-label" for="expectedDate">Expected Delivery Date</label>
                <input type="date" class="form-control" id="expectedDate">
            </div>
            <div class="form-group">
                <label class="form-label" for="poStatus">Status</label>
                <select class="form-control" id="poStatus" style="background-color: var(--bg-tertiary); color: var(--text-muted); pointer-events: none;" tabindex="-1">
                    <option value="draft" selected>Draft</option>
                    <option value="pending">Pending</option>
                    <option value="ordered">Ordered</option>
                </select>
            </div>
        </div>
        
        <!-- Notes -->
        <div class="form-group" style="max-width: 600px;">
            <label class="form-label" for="poNotes">Notes</label>
            <textarea class="form-control" id="poNotes" rows="2" placeholder="Additional instructions or notes..."></textarea>
        </div>

        <!-- Divider -->
        <hr style="margin: 32px 0; border: 0; border-top: 1px solid var(--border-color);">

        <!-- Order Items and Summary Area -->
        <div style="display: flex; gap: 32px; flex-wrap: wrap;">
            
            <!-- Left Column: Order Items -->
            <div style="flex: 1; min-width: 500px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3>Order Items</h3>
                    <div class="dropdown-wrapper" id="productSelectWrapper" style="width:300px;">
                        <div class="search-bar" style="width:100%;">
                            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
                            <input type="text" class="form-control" id="productSearchInput" placeholder="Search product to add..." autocomplete="off">
                        </div>
                        <div class="notification-dropdown" id="productDropdown" style="width:100%;top:calc(100% + 4px);max-height:250px;overflow-y:auto;display:none; opacity:1; visibility:visible; transform:none;"></div>
                    </div>
                </div>
                
                <div class="table-wrapper" style="border: 1px solid var(--border-color); border-radius: var(--border-radius-md);">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="width:120px;">Unit Cost</th>
                                <th style="width:120px;">Quantity</th>
                                <th style="width:120px;">Total</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody id="poItemsBody">
                            <tr id="emptyItemsRow"><td colspan="5" class="text-center text-muted" style="padding:40px;">No items added yet. Search and select products to add.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column: Summary -->
            <div style="width: 300px;">
                <div style="background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--border-radius-lg); padding: 24px;">
                    <h4 style="font-size: 0.85rem; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-muted); margin-bottom: 24px; font-weight: 600;">Order Summary</h4>
                    
                    <div style="display:flex; justify-content:space-between; margin-bottom: 12px; font-size: 0.95rem;">
                        <span class="text-muted">Total Items</span>
                        <strong id="summaryTotalItems">0</strong>
                    </div>
                    
                    <div style="display:flex; justify-content:space-between; margin-bottom: 12px; font-size: 0.95rem;">
                        <span class="text-muted">Total Units</span>
                        <strong id="summaryTotalUnits">0</strong>
                    </div>

                    <div style="display:flex; justify-content:space-between; margin-bottom: 24px; font-size: 0.95rem;">
                        <span class="text-muted">Subtotal</span>
                        <strong id="summarySubtotal">₱0.00</strong>
                    </div>

                    <div style="border-top: 1px dashed var(--border-color); padding-top: 20px; display:flex; justify-content:space-between; align-items: center;">
                        <span style="font-weight: 600; font-size: 1.1rem;">TOTAL</span>
                        <strong style="color:var(--primary-color); font-size: 1.4rem;" id="summaryTotalAmount">₱0.00</strong>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
