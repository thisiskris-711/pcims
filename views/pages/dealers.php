<?php
/**
 * Dealer Management Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER, ROLE_AUDITOR);

$canEdit = hasRole(ROLE_ADMIN, ROLE_MANAGER);
$canDelete = hasRole(ROLE_ADMIN);
$canRecordPayment = hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER);

$pageTitle = 'Dealers';
$currentPage = 'dealers';
$pageScripts = ['dealers.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-left">
        <div class="search-bar">
            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
            <input type="text" class="form-control" id="dealerSearch" placeholder="Search by name, code, or contact...">
        </div>
        <select class="form-control" id="statusFilter" style="width:auto;min-width:140px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="inactive">Inactive</option>
        </select>
    </div>
    <div class="toolbar-right">
        <?php if ($canEdit): ?>
        <button class="btn btn-primary" onclick="openDealerModal()">
            <i data-lucide="user-plus" style="width:18px;height:18px;"></i> Add Dealer
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Dealers Table -->
<div class="card">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Dealer</th>
                        <th>Contact</th>
                        <th>Phone</th>
                        <th>Credit Limit</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Sales</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="dealersBody">
                    <tr><td colspan="9" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="dealersPagination"></div>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Add/Edit Dealer Modal -->
<div class="modal modal-lg" id="dealerModal">
    <div class="modal-header">
        <h3 class="modal-title" id="dealerModalTitle">Add Dealer</h3>
        <button class="modal-close" onclick="closeModal('dealerModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="dealerForm" onsubmit="saveDealer(event)">
            <input type="hidden" id="dealerId" value="">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="dealerName">Dealer / Business Name *</label>
                    <input type="text" class="form-control" id="dealerName" required placeholder="e.g. Metro Electronics Hub">
                </div>
                <div class="form-group">
                    <label class="form-label" for="contactPerson">Contact Person</label>
                    <input type="text" class="form-control" id="contactPerson" placeholder="Primary contact name">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="dealerEmail">Email</label>
                    <input type="email" class="form-control" id="dealerEmail" placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label class="form-label" for="dealerPhone">Phone</label>
                    <input type="text" class="form-control" id="dealerPhone" placeholder="09xx xxx xxxx">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="dealerAddress">Address</label>
                <textarea class="form-control" id="dealerAddress" rows="2" placeholder="Full address"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="creditLimit">Credit Limit ($)</label>
                    <input type="number" class="form-control" id="creditLimit" value="0" min="0" step="0.01">
                </div>
                <div class="form-group" id="statusGroup" style="display:none;">
                    <label class="form-label" for="dealerStatus">Status</label>
                    <select class="form-control" id="dealerStatus">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label" for="dealerNotes">Notes</label>
                <textarea class="form-control" id="dealerNotes" rows="2" placeholder="Internal notes (optional)"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('dealerModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('dealerForm').requestSubmit()">
            <i data-lucide="save" style="width:16px;height:16px;"></i> Save
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($canRecordPayment): ?>
<!-- Record Payment Modal -->
<div class="modal" id="paymentModal">
    <div class="modal-header">
        <h3 class="modal-title">Record Payment</h3>
        <button class="modal-close" onclick="closeModal('paymentModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <div id="paymentDealerInfo" style="background:var(--bg-tertiary);border-radius:var(--border-radius-sm);padding:12px;margin-bottom:16px;"></div>
        <form id="paymentForm" onsubmit="processPayment(event)">
            <input type="hidden" id="paymentDealerId" value="">
            <div class="form-group">
                <label class="form-label" for="paymentAmount">Payment Amount ($) *</label>
                <input type="number" class="form-control" id="paymentAmount" required min="0.01" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
                <label class="form-label" for="paymentNotes">Notes</label>
                <textarea class="form-control" id="paymentNotes" rows="2" placeholder="Payment reference or notes"></textarea>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
        <button class="btn btn-success" onclick="document.getElementById('paymentForm').requestSubmit()">
            <i data-lucide="check-circle" style="width:16px;height:16px;"></i> Record Payment
        </button>
    </div>
</div>
<?php endif; ?>

<!-- Dealer Detail Modal -->
<div class="modal modal-xl" id="detailModal">
    <div class="modal-header">
        <h3 class="modal-title" id="detailModalTitle">Dealer Details</h3>
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
    window.CAN_RECORD_PAYMENT = <?= $canRecordPayment ? 'true' : 'false' ?>;
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
