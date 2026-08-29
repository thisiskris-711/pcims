<?php
/**
 * System Settings Page (Admin only)
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();
requireRole(ROLE_ADMIN);

$pageTitle = 'System Settings';
$currentPage = 'settings';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="font-size:1rem;font-weight:600;">Configure global application parameters</h2>
    </div>
    <div class="toolbar-right">
        <button type="submit" form="settingsForm" class="btn btn-primary">
            <i data-lucide="save" style="width:18px;height:18px;"></i> Save Settings
        </button>
    </div>
</div>

<div class="card" style="max-width: 800px;">
    <div class="card-body">
        <form id="settingsForm">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <h3 style="margin-top:0; margin-bottom:15px; font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:8px;">Company Profile</h3>
            
            <div class="form-group">
                <label for="company_name">Company Name</label>
                <input type="text" id="company_name" name="company_name" class="form-control" value="<?= sanitize(getSetting('company_name')) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="company_address">Company Address</label>
                <textarea id="company_address" name="company_address" class="form-control" rows="3"><?= sanitize(getSetting('company_address')) ?></textarea>
            </div>
            
            <div class="form-group">
                <label for="company_tin">Tax Identification Number (TIN)</label>
                <input type="text" id="company_tin" name="company_tin" class="form-control" value="<?= sanitize(getSetting('company_tin')) ?>">
            </div>

            <h3 style="margin-top:30px; margin-bottom:15px; font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:8px;">Tax & Invoice Settings</h3>
            
            <div class="form-group" style="display:flex; gap:20px;">
                <div style="flex:1;">
                    <label for="vat_rate">VAT Rate (%)</label>
                    <input type="number" id="vat_rate" name="vat_rate" class="form-control" min="0" max="100" step="0.01" value="<?= sanitize(getSetting('vat_rate', '0.00')) ?>" required>
                </div>
                <div style="flex:1;">
                    <label for="invoice_prefix">Invoice Number Prefix</label>
                    <input type="text" id="invoice_prefix" name="invoice_prefix" class="form-control" value="<?= sanitize(getSetting('invoice_prefix')) ?>" placeholder="e.g. INV-2026-">
                    <small class="text-muted">Will be prepended to the sequential sale ID (e.g. INV-2026-1045)</small>
                </div>
            </div>

            <div class="form-group">
                <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="tax_inclusive" name="tax_inclusive" value="1" <?= getSetting('tax_inclusive', '0') === '1' ? 'checked' : '' ?>>
                    Prices entered in the system include tax
                </label>
            </div>

            <h3 style="margin-top:30px; margin-bottom:15px; font-size:1.1rem; border-bottom:1px solid var(--border-color); padding-bottom:8px;">Inventory Settings</h3>
            
            <div class="form-group">
                <label for="low_stock_threshold">Global Low Stock Threshold</label>
                <input type="number" id="low_stock_threshold" name="low_stock_threshold" class="form-control" min="0" value="<?= sanitize(getSetting('low_stock_threshold', '10')) ?>">
                <small class="text-muted">Used if a specific product doesn't have its own threshold defined.</small>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label" style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="notify_low_stock" name="notify_low_stock" value="1" <?= getSetting('notify_low_stock', '1') === '1' ? 'checked' : '' ?>>
                    Generate notifications when items fall below low stock threshold
                </label>
            </div>
            
        </form>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/js/settings.js?v=<?= time() ?>"></script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
