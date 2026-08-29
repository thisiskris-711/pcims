<?php
/**
 * Dealer Management Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requirePermission('manage_dealers');
$canEdit = hasRole(ROLE_ADMIN, ROLE_MANAGER);
$canDelete = hasRole(ROLE_ADMIN);
$canRecordPayment = hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER);

$pageTitle = 'Dealers';
$currentPage = 'dealers';
$pageScripts = ['dealers.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<!-- Tabs -->
<div class="tabs-container" style="margin-bottom: 24px; border-bottom: 1px solid #e5e7eb; display:flex; gap: 8px;">
    <button class="btn tab-btn active" id="tabDealers" onclick="switchTab('dealers')" style="background: none; border: none; padding: 12px 24px; border-bottom: 2px solid var(--primary-color); font-weight: 600; font-size: 0.95rem; color: var(--primary-color);">Dealers</button>
    <button class="btn tab-btn" id="tabApplications" onclick="switchTab('applications')" style="background: none; border: none; padding: 12px 24px; color: var(--text-muted); font-weight: 500; font-size: 0.95rem;">Pending Applications <span id="pendingAppCount" style="margin-left: 6px; background: var(--bg-tertiary); padding: 2px 8px; border-radius: 12px; font-size: 0.8rem;">0</span></button>
</div>

<div class="toolbar" id="toolbarDealers" style="margin-bottom: 16px;">
    <div class="toolbar-left" style="gap: 12px; flex-wrap: wrap;">
        <div class="search-bar" style="min-width: 250px;">
            <span class="search-icon"><i data-lucide="search" style="width:18px;height:18px;"></i></span>
            <input type="text" class="form-control" id="dealerSearch" placeholder="Search dealers...">
        </div>
        <select class="form-control" id="statusFilter" style="width:auto;min-width:140px;">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="suspended">Suspended</option>
            <option value="inactive">Inactive</option>
        </select>
        <select class="form-control" id="creditStatusFilter" style="width:auto;min-width:160px;">
            <option value="">All Credit Status</option>
            <option value="no_outstanding">No Outstanding</option>
            <option value="outstanding">Has Outstanding</option>
            <option value="near_limit">Near Credit Limit</option>
            <option value="over_limit">Over Credit Limit</option>
        </select>
        <select class="form-control" id="sortFilter" style="width:auto;min-width:140px;">
            <option value="name">Sort by Name</option>
            <option value="outstanding">Sort by Outstanding</option>
            <option value="utilization">Sort by Utilization</option>
            <option value="sales">Sort by Sales</option>
        </select>
    </div>
    <div class="toolbar-right">
        <?php if ($canEdit): ?>
        <button class="btn btn-primary" onclick="openAddDealerModal()">
            <i data-lucide="user-plus" style="width:18px;height:18px;"></i> Add Dealer
        </button>
        <?php endif; ?>
    </div>
</div>


<!-- Dealers Table -->
<div id="cardDealers" style="background:#fff; border: 1px solid #e5e7eb; border-radius: var(--border-radius-sm); box-shadow: 0 1px 2px rgba(0,0,0,0.05); margin-bottom: 24px;">
    <div style="padding: 0;">
        <div class="table-wrapper" style="border:none; border-radius: var(--border-radius-sm);">
            <table style="margin:0;">
                <thead style="background: #f9fafb;">
                    <tr>
                        <th style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">Dealer</th>
                        <th style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; width: 140px;">Phone</th>
                        <th style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; width: 220px;">Credit Status</th>
                        <th style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; width: 120px;">Status</th>
                        <th style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; width: 100px;">Sales</th>
                        <th style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb; width: 140px; text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="dealersBody">
                    <tr><td colspan="6" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="dealersPagination"></div>
    </div>
</div>

<!-- Applications Table -->
<div class="card" id="cardApplications" style="display:none;">
    <div class="card-body" style="padding-top:16px;">
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Applicant Name</th>
                        <th>Phone</th>
                        <th>Branch</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th style="width:140px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="applicationsBody">
                    <tr><td colspan="7" class="text-center text-muted" style="padding:40px;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pagination" id="applicationsPagination"></div>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- Add Dealer Modal (Detailed) -->
<div class="modal modal-lg" id="addDealerModal">
    <div class="modal-header">
        <h3 class="modal-title">Add Dealer (Application)</h3>
        <button class="modal-close" onclick="closeModal('addDealerModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" style="background:#f9fafb;">
        <form id="addDealerForm" onsubmit="submitAddDealer(event)">
            <h4 style="margin-top:0;margin-bottom:12px;font-size:0.95rem;color:var(--text-secondary);border-bottom:1px solid #e5e7eb;padding-bottom:8px;">Personal Information</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_first_name">First Name *</label>
                    <input type="text" class="form-control" id="add_first_name" name="first_name" autocomplete="given-name" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_middle_name">Middle Name</label>
                    <input type="text" class="form-control" id="add_middle_name" name="middle_name" autocomplete="additional-name">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_last_name">Last Name *</label>
                    <input type="text" class="form-control" id="add_last_name" name="last_name" autocomplete="family-name" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_phone">Mobile Number *</label>
                    <input type="tel" class="form-control" id="add_phone" name="phone" autocomplete="tel" required maxlength="11">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_email">Email Address</label>
                    <input type="email" class="form-control" id="add_email" name="email" autocomplete="email">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="add_address1">House No. / Street</label>
                <input type="text" class="form-control" id="add_address1" name="address1" autocomplete="street-address">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_region">Region</label>
                    <input type="text" class="form-control" id="add_region" name="region" autocomplete="address-level1">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_province">Province</label>
                    <input type="text" class="form-control" id="add_province" name="province" autocomplete="address-level2">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_city">City / Municipality</label>
                    <input type="text" class="form-control" id="add_city" name="city" autocomplete="address-level3">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_barangay">Barangay</label>
                    <input type="text" class="form-control" id="add_barangay" name="barangay" autocomplete="off">
                </div>
            </div>

            <h4 style="margin-top:20px;margin-bottom:12px;font-size:0.95rem;color:var(--text-secondary);border-bottom:1px solid #e5e7eb;padding-bottom:8px;">Other Details</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_preferred_branch">Preferred Branch</label>
                    <input type="text" class="form-control" id="add_preferred_branch" name="preferred_branch" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_source">Source</label>
                    <select class="form-control" id="add_source" name="source" autocomplete="off">
                        <option value="">Select Source...</option>
                        <option value="Facebook">Facebook</option>
                        <option value="Friend/Family">Friend/Family</option>
                        <option value="Branch Walk-in">Branch Walk-in</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <h4 style="margin-top:20px;margin-bottom:12px;font-size:0.95rem;color:var(--text-secondary);border-bottom:1px solid #e5e7eb;padding-bottom:8px;">Recruiter Details</h4>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_recruiter_id">Recruiter's ID</label>
                    <input type="text" class="form-control" id="add_recruiter_id" name="recruiter_id" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_recruiter_name">Recruiter's Name</label>
                    <input type="text" class="form-control" id="add_recruiter_name" name="recruiter_name" autocomplete="off">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" for="add_recruiter_phone">Recruiter's Mobile No.</label>
                    <input type="tel" class="form-control" id="add_recruiter_phone" name="recruiter_phone" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="add_recruiter_fb">Recruiter's FB Profile</label>
                    <input type="text" class="form-control" id="add_recruiter_fb" name="recruiter_fb" autocomplete="off">
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('addDealerModal')">Cancel</button>
        <button class="btn btn-success" style="background:var(--accent-primary);color:white;border:none;" onclick="document.getElementById('addDealerForm').requestSubmit()">
            <i data-lucide="check" style="width:16px;height:16px;"></i> Add Dealer
        </button>
    </div>
</div>

<!-- Edit Dealer Modal -->
<div class="modal modal-lg" id="dealerModal">
    <div class="modal-header">
        <h3 class="modal-title" id="dealerModalTitle">Add Dealer</h3>
        <button class="modal-close" onclick="closeModal('dealerModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <form id="dealerForm" onsubmit="saveDealer(event)">
            <input type="hidden" id="dealerId" value="">
            <div class="form-group">
                <label class="form-label" for="dealerName">Dealer Name *</label>
                <input type="text" class="form-control" id="dealerName" required placeholder="Full Name">
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
                    <label class="form-label" for="creditLimit">Credit Limit (₱)</label>
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
<div class="modal modal-lg" id="paymentModal">
    <div class="modal-header">
        <h3 class="modal-title">Record Payment</h3>
        <button class="modal-close" onclick="closeModal('paymentModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" style="background:#f9fafb;">
        <div id="paymentDealerInfo" style="background:#fff;border:1px solid #e5e7eb;border-radius:var(--border-radius-sm);padding:16px;margin-bottom:16px;"></div>
        <form id="paymentForm" onsubmit="processPayment(event)">
            <input type="hidden" id="paymentDealerId" value="">
            
            <h4 style="margin-top:0;margin-bottom:12px;font-size:0.95rem;color:var(--text-secondary);border-bottom:1px solid #e5e7eb;padding-bottom:8px;">Unpaid Invoices</h4>
            <div id="paymentInvoicesContainer" style="max-height: 250px; overflow-y: auto; margin-bottom: 20px; background:#fff; border:1px solid #e5e7eb; border-radius:var(--border-radius-sm);">
                <table style="margin:0; border:none; box-shadow:none;">
                    <thead style="position: sticky; top: 0; background: #f3f4f6; z-index: 1;">
                        <tr>
                            <th style="padding: 10px 12px; border-bottom:1px solid #e5e7eb;">Invoice</th>
                            <th style="padding: 10px 12px; border-bottom:1px solid #e5e7eb;">Due Date</th>
                            <th style="padding: 10px 12px; border-bottom:1px solid #e5e7eb;">Status</th>
                            <th style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; text-align:right;">Balance</th>
                            <th style="padding: 10px 12px; border-bottom:1px solid #e5e7eb; width: 140px;">Payment</th>
                        </tr>
                    </thead>
                    <tbody id="paymentInvoicesBody">
                        <tr><td colspan="5" class="text-center text-muted" style="padding:20px;">Loading invoices...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="form-row" style="align-items: flex-end;">
                <div class="form-group" style="flex:2">
                    <label class="form-label" for="paymentReference">Payment Reference</label>
                    <input type="text" class="form-control" id="paymentReference" placeholder="e.g. OR-000123 / bank reference">
                </div>
                <div class="form-group" style="flex:1; text-align:right;">
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:4px;">Total Payment</div>
                    <div style="font-size:1.4rem; font-weight:700; color:var(--primary-color);">
                        ₱<span id="paymentAmountDisplay">0.00</span>
                    </div>
                    <input type="hidden" id="paymentAmount" value="0">
                </div>
                <div class="form-group" style="flex:1; text-align:right;">
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:4px;">Remaining Balance</div>
                    <div style="font-size:1.4rem; font-weight:700; color:var(--warning-color);" id="remainingBalanceDisplay">
                        ₱0.00
                    </div>
                </div>
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
        <button class="btn btn-success" id="btnSubmitPayment" disabled onclick="document.getElementById('paymentForm').requestSubmit()">
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

<!-- Application Detail Modal -->
<div class="modal modal-lg" id="applicationModal">
    <div class="modal-header">
        <h3 class="modal-title">Application Details</h3>
        <button class="modal-close" onclick="closeModal('applicationModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body" id="applicationModalBody">
        <div class="text-center text-muted" style="padding:40px;">Loading...</div>
    </div>
    <div class="modal-footer" id="applicationModalFooter">
        <button class="btn btn-secondary" onclick="closeModal('applicationModal')">Close</button>
    </div>
</div>

<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER)): ?>
<!-- Create Credit Memo Modal -->
<div class="modal" id="memoModal">
    <div class="modal-header">
        <h3 class="modal-title">Create Credit Memo</h3>
        <button class="modal-close" onclick="closeModal('memoModal')"><i data-lucide="x" style="width:20px;height:20px;"></i></button>
    </div>
    <div class="modal-body">
        <div id="memoDealerInfo" style="background:var(--bg-tertiary);border-radius:var(--border-radius-sm);padding:12px;margin-bottom:16px;"></div>
        <form id="memoForm" onsubmit="submitMemo(event)">
            <input type="hidden" id="memoDealerId" value="">
            <div class="form-group">
                <label class="form-label" for="memoAmount">Amount (₱) *</label>
                <input type="number" class="form-control" id="memoAmount" required min="0.01" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
                <label class="form-label" for="memoReason">Reason *</label>
                <input type="text" class="form-control" id="memoReason" required placeholder="e.g., Promotional rebate">
            </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="closeModal('memoModal')">Cancel</button>
        <button class="btn btn-primary" onclick="document.getElementById('memoForm').requestSubmit()">
            <i data-lucide="check" style="width:16px;height:16px;"></i> Create Memo
        </button>
    </div>
</div>
<?php endif; ?>

<script>
    window.CAN_EDIT = <?= $canEdit ? 'true' : 'false' ?>;
    window.CAN_DELETE = <?= $canDelete ? 'true' : 'false' ?>;
    window.CAN_RECORD_PAYMENT = <?= $canRecordPayment ? 'true' : 'false' ?>;
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
