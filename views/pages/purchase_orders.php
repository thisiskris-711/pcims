<?php
/**
 * Purchase Orders List
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_suppliers');
$canEdit = hasRole(ROLE_ADMIN, ROLE_MANAGER);

$pageTitle = 'Purchase Orders';
$currentPage = 'purchase_orders';
$pageScripts = ['purchase_orders.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-bar">
            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
            <input type="text" class="form-control" id="poSearch" placeholder="Search PO Number or Supplier...">
        </div>
        <select class="form-control" id="statusFilter" style="width:auto;min-width:140px;">
            <option value="">All Status</option>
            <option value="draft">Draft</option>
            <option value="pending">Pending</option>
            <option value="ordered">Ordered</option>
            <option value="partially_received">Partially Received</option>
            <option value="received">Received</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>
    <div class="toolbar-right">
        <?php if ($canEdit): ?>
        <a href="<?= APP_URL ?>/purchase-order-form" class="btn btn-primary">
            <i data-lucide="plus" style="width:18px;height:18px;"></i> Create PO
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- PO Table -->
<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>PO Number</th>
                        <th>Supplier</th>
                        <th>Date</th>
                        <th>Expected</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="poBody">
                    <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="poPagination"></div>
    </div>
</div>

<!-- PO Detail Modal -->
<div class="modal modal-xl" id="detailModal">
    <div class="modal-header">
        <h3 class="modal-title" id="detailModalTitle">Purchase Order Details</h3>
        <button class="modal-close" onclick="closeModal('detailModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" id="detailModalBody">
        <div class="text-center text-muted" style="padding:40px;">Loading...</div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('detailModal')">Close</button>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Receive Stock Modal -->
<div class="modal modal-lg" id="receiveModal">
    <div class="modal-header">
        <h3 class="modal-title">Receive Items</h3>
        <button class="modal-close" onclick="closeModal('receiveModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <div id="receivePoInfo" style="margin-bottom:16px;background:var(--bg-tertiary);padding:12px;border-radius:var(--border-radius-sm);"></div>
        <form id="receiveForm" onsubmit="processReceive(event)">
            <input type="hidden" id="receivePoId" value="">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Ordered</th>
                            <th>Received</th>
                            <th>Pending</th>
                            <th style="width:120px;">Receive Now</th>
                        </tr>
                    </thead>
                    <tbody id="receiveItemsBody"></tbody>
                </table>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('receiveModal')">Cancel</button>
        <button class="btn btn-success" onclick="document.getElementById('receiveForm').requestSubmit()">
            <i data-lucide="check-square" style="width:16px;height:16px;"></i> Confirm Receipt
        </button>
    </div>
</div>
<?php endif; ?>

<script>
    window.CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
