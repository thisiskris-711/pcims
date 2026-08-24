<?php

/**
 * Header Template
 * Include after setting $pageTitle and $currentPage
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$currentPage = $currentPage ?? 'dashboard';
$user = getCurrentUser();
$flash = getFlashMessage();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sanitize(APP_NAME) ?> - Professional Inventory Management System">
    <title><?= sanitize($pageTitle) ?> - <?= sanitize(APP_NAME) ?></title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- App Styles -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/style.css') ?>">

    <!-- PWA Manifest & Icons -->
    <link rel="manifest" href="<?= APP_URL ?>/manifest.json">
    <link rel="icon" type="image/png" href="<?= APP_URL ?>/assets/icon.png">
    <link rel="apple-touch-icon" href="<?= APP_URL ?>/assets/icon-192x192.png">
    <meta name="theme-color" content="#4f46e5">

    <!-- Dark Mode FOUC Prevention -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
        window.APP_URL = '<?= APP_URL ?>';
    </script>

    <!-- Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('<?= APP_URL ?>/sw.js')
                    .then(registration => {
                        console.log('ServiceWorker registration successful with scope: ', registration.scope);
                    })
                    .catch(err => {
                        console.log('ServiceWorker registration failed: ', err);
                    });
            });
        }
    </script>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header" style="padding-top: 20px; align-items: center;">
            <div class="sidebar-logo" style="display: flex; align-items: center; gap: 10px;">
                <img src="<?= APP_URL ?>/assets/icon.png" alt="Logo" style="height: 32px; object-fit: contain; margin-top: 2px;">
                <span class="logo-text"><?= sanitize(APP_NAME) ?></span>
            </div>
            <button class="sidebar-close" id="sidebarClose" aria-label="Close sidebar">
                <i data-lucide="x" style="width:20px;height:20px;"></i>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <span class="nav-section-title">Main</span>
                <a href="<?= APP_URL ?>/" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard" style="width:20px;height:20px;"></i>
                    <span>Dashboard</span>
                </a>
                <?php if (hasPermission('create_sales')): ?>
                    <a href="<?= APP_URL ?>/create-invoice" class="nav-link <?= $currentPage === 'create_invoice' ? 'active' : '' ?>">
                        <i data-lucide="shopping-cart" style="width:20px;height:20px;"></i>
                        <span>New Sale</span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if (hasPermission('manage_products') || hasPermission('manage_inventory') || hasPermission('manage_suppliers')): ?>
                <div class="nav-section">
                    <span class="nav-section-title">Inventory</span>
                    <?php if (hasPermission('manage_products')): ?>
                        <a href="<?= APP_URL ?>/products" class="nav-link <?= $currentPage === 'products' ? 'active' : '' ?>">
                            <i data-lucide="box" style="width:20px;height:20px;"></i>
                            <span>Products</span>
                        </a>
                        <a href="<?= APP_URL ?>/categories" class="nav-link <?= $currentPage === 'categories' ? 'active' : '' ?>">
                            <i data-lucide="tags" style="width:20px;height:20px;"></i>
                            <span>Categories</span>
                        </a>
                    <?php endif; ?>
                    <?php if (hasPermission('manage_inventory')): ?>
                    <a href="<?= APP_URL ?>/stock" class="nav-link <?= $currentPage === 'stock' ? 'active' : '' ?>">
                        <i data-lucide="arrow-left-right" style="width:20px;height:20px;"></i>
                        <span>Stock Movement</span>
                    </a>
                    <?php endif; ?>
                    <?php if (hasPermission('manage_suppliers')): ?>
                        <a href="<?= APP_URL ?>/suppliers" class="nav-link <?= $currentPage === 'suppliers' ? 'active' : '' ?>">
                            <i data-lucide="truck" style="width:20px;height:20px;"></i>
                            <span>Suppliers</span>
                        </a>
                        <a href="<?= APP_URL ?>/purchase-orders" class="nav-link <?= $currentPage === 'purchase_orders' ? 'active' : '' ?>">
                            <i data-lucide="file-text" style="width:20px;height:20px;"></i>
                            <span>Purchase Orders</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>


            <?php if (hasPermission('view_sales') || hasPermission('view_reports')): ?>
                <div class="nav-section">
                    <span class="nav-section-title">Sales</span>
                    <a href="<?= APP_URL ?>/sales" class="nav-link <?= $currentPage === 'sales' ? 'active' : '' ?>">
                        <i data-lucide="receipt" style="width:20px;height:20px;"></i>
                        <span>Sales Invoices</span>
                    </a>
                    <?php if (hasPermission('view_reports')): ?>
                        <a href="<?= APP_URL ?>/reports" class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>">
                            <i data-lucide="bar-chart-3" style="width:20px;height:20px;"></i>
                            <span>Reports</span>
                        </a>
                    <?php endif; ?>
                    <?php if (hasPermission('manage_products')): ?>
                        <a href="<?= APP_URL ?>/promotions" class="nav-link <?= $currentPage === 'promotions' ? 'active' : '' ?>">
                            <i data-lucide="badge-percent" style="width:20px;height:20px;"></i>
                            <span>Promotions</span>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_dealers')): ?>
                <div class="nav-section">
                    <span class="nav-section-title">Dealers</span>
                    <a href="<?= APP_URL ?>/dealers" class="nav-link <?= $currentPage === 'dealers' ? 'active' : '' ?>">
                        <i data-lucide="building-2" style="width:20px;height:20px;"></i>
                        <span>Dealers</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (hasPermission('manage_users') || hasPermission('manage_roles') || hasRole(ROLE_ADMIN)): ?>
                <div class="nav-section">
                    <span class="nav-section-title">Administration</span>
                    <a href="<?= APP_URL ?>/users" class="nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">
                        <i data-lucide="users" style="width:20px;height:20px;"></i>
                        <span>Users & Roles</span>
                    </a>
                    <?php if (hasRole(ROLE_ADMIN)): ?>
                    <a href="<?= APP_URL ?>/backup" class="nav-link <?= $currentPage === 'backup' ? 'active' : '' ?>">
                        <i data-lucide="database-backup" style="width:20px;height:20px;"></i>
                        <span>Backup & Restore</span>
                    </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <a href="<?= APP_URL ?>/profile" class="sidebar-user <?= $currentPage === 'profile' ? 'active' : '' ?>" style="text-decoration: none; display: flex; align-items: center; padding: 10px; border-radius: 8px; transition: all 0.2s ease; <?= $currentPage === 'profile' ? 'background-color: rgba(154, 0, 2, 0.08); border: 1px solid rgba(154, 0, 2, 0.2);' : '' ?>">
                <div class="user-avatar">
                    <?= strtoupper(substr($user['full_name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="user-info">
                    <span class="user-name"><?= sanitize($user['full_name'] ?? 'User') ?></span>
                    <span class="user-role"><?= ucfirst($user['role'] ?? 'staff') ?></span>
                </div>
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <!-- Top Bar -->
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                    <i data-lucide="menu" style="width:22px;height:22px;"></i>
                </button>
                <div class="page-title-wrapper">
                    <nav class="breadcrumb" aria-label="breadcrumb">
                        <ol>
                            <li><a href="<?= APP_URL ?>/dashboard">Home</a></li>
                            <?php if ($currentPage !== 'dashboard'): ?>
                                <li class="separator" style="color: var(--text-muted); margin: 0 8px;">/</li>
                                <li class="current" style="font-weight: 500;"><?= sanitize($pageTitle) ?></li>
                            <?php endif; ?>
                        </ol>
                    </nav>
                    <h1 class="page-title"><?= sanitize($pageTitle) ?></h1>
                </div>
            </div>
            <div class="topbar-right">
                <div class="topbar-actions">
                    <button class="topbar-btn" id="themeToggleBtn" title="Toggle Theme">
                        <i data-lucide="moon" style="width:20px;height:20px;" id="themeToggleIcon"></i>
                    </button>
                    <div class="dropdown-wrapper" id="notificationDropdownWrapper">
                        <button class="topbar-btn position-relative" id="notificationBtn" title="Notifications">
                            <i data-lucide="bell" style="width:20px;height:20px;"></i>
                            <span class="notification-badge" id="notificationBadge" style="display:none;">0</span>
                        </button>
                        <div class="notification-dropdown" id="notificationDropdown">
                            <div class="notification-header">
                                <h3>Notifications</h3>
                                <button class="btn btn-sm btn-ghost" id="markAllReadBtn">Mark all as read</button>
                            </div>
                            <div class="notification-list" id="notificationList">
                                <!-- Notifications will be loaded here via JS -->
                            </div>
                            <div class="notification-footer" style="padding: 10px; text-align: center; border-top: 1px solid var(--border-color); background: var(--bg-card);">
                                <a href="<?= APP_URL ?>/notifications" style="font-size: 0.85rem; color: var(--accent-violet); text-decoration: none; font-weight: 500;">View all notifications</a>
                            </div>
                        </div>
                    </div>
                    <a href="<?= APP_URL ?>/profile" class="topbar-btn" title="Profile">
                        <i data-lucide="user" style="width:20px;height:20px;"></i>
                    </a>
                    <a href="#" class="topbar-btn" title="Logout" onclick="event.preventDefault(); openModal('logoutModal')">
                        <i data-lucide="log-out" style="width:20px;height:20px;"></i>
                    </a>
                </div>
            </div>
        </header>

        <!-- Flash Messages -->
        <?php if ($flash): ?>
            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    if (typeof showToast === 'function') {
                        showToast('<?= addslashes(sanitize($flash['message'])) ?>', '<?= sanitize($flash['type']) ?>', 4000);
                    }
                });
            </script>
        <?php endif; ?>

        <!-- Page Content -->
        <div class="content-wrapper">