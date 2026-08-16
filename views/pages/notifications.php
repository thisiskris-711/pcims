<?php
/**
 * Notifications Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
requireLogin();

$pageTitle = 'Notifications';
$currentPage = 'notifications';
$pageScripts = ['notifications.js'];
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="toolbar">
    <div class="toolbar-left">
        <h2 style="font-size:1rem;font-weight:600;">All Notifications</h2>
    </div>
    <div class="toolbar-right">
        <button class="btn btn-primary btn-sm" id="pageMarkAllReadBtn">
            <i data-lucide="check-check" style="width:16px;height:16px;"></i> Mark All as Read
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body" style="padding: 0;">
        <div id="fullNotificationList" class="full-notification-list">
            <div class="text-center text-muted" style="padding: 40px;">
                <i data-lucide="loader-2" class="spin" style="width: 24px; height: 24px;"></i>
                <div class="mt-2">Loading notifications...</div>
            </div>
        </div>
    </div>
    <div class="card-footer" style="padding: 16px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color);">
        <div class="text-muted" style="font-size: 0.85rem;" id="paginationInfo">Showing 0 of 0</div>
        <div class="pagination-controls" style="display: flex; gap: 8px;">
            <button class="btn btn-sm btn-outline" id="prevPageBtn" disabled>
                <i data-lucide="chevron-left" style="width:16px;height:16px;"></i> Prev
            </button>
            <button class="btn btn-sm btn-outline" id="nextPageBtn" disabled>
                Next <i data-lucide="chevron-right" style="width:16px;height:16px;"></i>
            </button>
        </div>
    </div>
</div>

<style>
.full-notification-list .notification-item {
    padding: 16px 20px;
}
.full-notification-list .notification-title {
    font-size: 0.95rem;
}
.full-notification-list .notification-message {
    font-size: 0.85rem;
}
.full-notification-list .notification-time {
    font-size: 0.75rem;
    margin-top: 4px;
}
.spin {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
