<?php
/**
 * Supplier Management Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_suppliers');
$canEdit = hasRole(ROLE_ADMIN, ROLE_MANAGER);
$canDelete = hasRole(ROLE_ADMIN);

$pageTitle = 'Suppliers';
$currentPage = 'suppliers';
$pageScripts = ['suppliers.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-bar">
            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
            <input type="text" class="form-control" id="supplierSearch" placeholder="Search by name, code, or contact...">
        </div>
        <select class="form-control" id="statusFilter" style="width:auto;min-width:140px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div class="toolbar-right">
        <?php if ($canEdit): ?>
        <button class="btn btn-primary" onclick="openSupplierModal()">
            <i data-lucide="user-plus" style="width:18px;height:18px;"></i> Add Supplier
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Suppliers Table -->
<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Supplier</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Purchase Orders</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="suppliersBody">
                    <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="suppliersPagination"></div>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Add/Edit Supplier Modal -->
<div class="modal modal-lg" id="supplierModal">
    <div class="modal-header">
        <h3 class="modal-title" id="supplierModalTitle">Add Supplier</h3>
        <button class="modal-close" onclick="closeModal('supplierModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" style="padding-bottom: 8px;">
        <form id="supplierForm" onsubmit="saveSupplier(event)">
            <input type="hidden" id="supplierId" value="">
            <input type="hidden" id="supplierAddress" value="">
            
            <div class="form-row" style="margin-bottom: 12px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="supplierName" style="font-weight: 600;">Supplier / Company Name <span style="color:var(--error-color);">*</span></label>
                    <input type="text" class="form-control" id="supplierName" required placeholder="e.g. Global Supplies Inc.">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="contactPerson" style="font-weight: 600;">Contact Person <span style="font-weight: 400; color: var(--text-muted); font-size: 0.8rem;">(Optional)</span></label>
                    <input type="text" class="form-control" id="contactPerson" placeholder="Primary contact name">
                </div>
            </div>
            
            <div class="form-row" style="margin-bottom: 16px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="supplierEmail" style="font-weight: 600;">Email <span style="font-weight: 400; color: var(--text-muted); font-size: 0.8rem;">(Optional)</span></label>
                    <input type="email" class="form-control" id="supplierEmail" placeholder="email@example.com">
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="supplierPhone" style="font-weight: 600;">Phone <span style="font-weight: 400; color: var(--text-muted); font-size: 0.8rem;">(Optional)</span></label>
                    <input type="text" class="form-control" id="supplierPhone" placeholder="Contact number">
                </div>
            </div>

            <!-- Structured Address -->
            <div style="background: var(--bg-tertiary); padding: 16px; border-radius: var(--border-radius-md); margin-bottom: 16px; border: 1px solid var(--border-color);">
                <div class="form-label" style="font-weight: 600; margin-bottom: 12px; display: block;">Address <span style="font-weight: 400; color: var(--text-muted); font-size: 0.8rem;">(Optional)</span></div>
                
                <div class="form-group" style="margin-bottom: 12px;">
                    <input type="text" class="form-control" id="addrStreet" placeholder="Street / Building Name">
                </div>
                <div class="form-row" style="margin-bottom: 12px;">
                    <div class="form-group" style="margin-bottom: 0;"><input type="text" class="form-control" id="addrBrgy" placeholder="Barangay"></div>
                    <div class="form-group" style="margin-bottom: 0;"><input type="text" class="form-control" id="addrCity" placeholder="City / Municipality"></div>
                </div>
                <div class="form-row" style="margin-bottom: 0; grid-template-columns: 60% 1fr;">
                    <div class="form-group" style="margin-bottom: 0;"><input type="text" class="form-control" id="addrProv" placeholder="Province"></div>
                    <div class="form-group" style="margin-bottom: 0;"><input type="text" class="form-control" id="addrZip" placeholder="ZIP Code"></div>
                </div>
            </div>
            
            <div class="form-group" id="statusGroup" style="display:none; margin-bottom: 16px;">
                <label class="form-label" for="supplierStatus" style="font-weight: 600;">Status</label>
                <select class="form-control" id="supplierStatus">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            
            <div class="form-group" style="margin-bottom: 8px;">
                <label class="form-label" for="supplierNotes" style="font-weight: 600;">Notes <span style="font-weight: 400; color: var(--text-muted); font-size: 0.8rem;">(Optional)</span></label>
                <textarea class="form-control" id="supplierNotes" rows="2" placeholder="Internal notes..."></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer" style="padding-top: 16px;">
        <button class="btn btn-secondary" onclick="closeModal('supplierModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('supplierForm').requestSubmit()">
            <i data-lucide="save" style="width:16px;height:16px;"></i> Save Supplier
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Supplier Detail Modal -->
<div class="modal modal-xl" id="detailModal">
    <div class="modal-header">
        <h3 class="modal-title" id="detailModalTitle">Supplier Details</h3>
        <button class="modal-close" onclick="closeModal('detailModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" id="detailModalBody">
        <div class="text-center text-muted" style="padding:40px;">Loading...</div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('detailModal')">Close</button>
    </div>
</div>

<script>
    window.CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    window.CAN_DELETE = <?= $canDelete ? 'true' : 'false' ?>;
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
