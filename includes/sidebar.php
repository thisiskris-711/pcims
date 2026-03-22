<?php
require_once __DIR__ . '/../config/config.php';

$current_page = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));
$current_title = $page_title ?? '';

$full_name = isset($_SESSION['full_name']) ? trim((string) $_SESSION['full_name']) : 'User';
$role_name = isset($_SESSION['role']) ? ucfirst((string) $_SESSION['role']) : 'User';
$initials = get_user_initials($full_name);
$profile_image = isset($_SESSION['profile_image']) ? (string) $_SESSION['profile_image'] : '';
$profile_image_url = get_profile_image_url($profile_image);

$is_inventory_active = in_array($current_page, ['products.php', 'inventory.php', 'stock_movements.php', 'stock_adjustment.php'], true)
    || strpos($current_title, 'Products') !== false
    || strpos($current_title, 'Inventory') !== false
    || strpos($current_title, 'Stock') !== false;

$is_orders_active = in_array($current_page, ['purchase_orders.php', 'sales_orders.php'], true)
    || strpos($current_title, 'Purchase') !== false
    || strpos($current_title, 'Sales') !== false;

$is_master_active = in_array($current_page, ['suppliers.php', 'categories.php'], true)
    || strpos($current_title, 'Suppliers') !== false
    || strpos($current_title, 'Categories') !== false;

$is_system_active = in_array($current_page, ['users.php', 'reports.php', 'settings.php'], true)
    || strpos($current_title, 'User Management') !== false
    || strpos($current_title, 'Reports') !== false
    || strpos($current_title, 'Settings') !== false;
?>

<nav class="sidebar app-sidebar" id="appSidebar" aria-label="Primary navigation">
    <div class="app-sidebar__inner">
        <a class="app-sidebar__brand" href="dashboard.php" aria-label="Go to dashboard">
            <span class="app-sidebar__brand-mark">
                <img src="<?php echo APP_URL; ?>/images/pc-logo-2.png" alt="PCIMS logo">
            </span>
            <span class="app-sidebar__brand-text">
                <span class="app-sidebar__brand-title">PCIMS</span>
                <span class="app-sidebar__brand-subtitle">Inventory made simple</span>
            </span>
        </a>

        <div class="app-sidebar__nav">
            <ul class="sidebar-menu">
                <li class="sidebar-label-item"><span class="sidebar-section-label">Workspace</span></li>
                <li class="sidebar-item <?php echo $current_page === 'dashboard.php' || $current_title === 'Dashboard' ? 'is-active' : ''; ?>">
                    <a class="sidebar-link" href="dashboard.php">
                        <span class="sidebar-link__icon"><i class="fas fa-chart-pie"></i></span>
                        <span class="sidebar-link__label">Dashboard</span>
                    </a>
                </li>

                <?php if (has_permission('staff')): ?>
                    <li class="sidebar-item sidebar-item--group <?php echo $is_inventory_active ? 'is-open is-active' : ''; ?>">
                        <button type="button" class="sidebar-group-toggle" data-sidebar-group-toggle aria-expanded="<?php echo $is_inventory_active ? 'true' : 'false'; ?>">
                            <span class="sidebar-link__icon"><i class="fas fa-boxes-stacked"></i></span>
                            <span class="sidebar-link__label">Inventory</span>
                            <span class="sidebar-link__arrow"><i class="fas fa-chevron-down"></i></span>
                        </button>
                        <ul class="sidebar-submenu">
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'products.php' ? 'active' : ''; ?>" href="products.php"><i class="fas fa-box"></i><span>Products</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'inventory.php' ? 'active' : ''; ?>" href="inventory.php"><i class="fas fa-cubes"></i><span>Stock Overview</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'stock_movements.php' ? 'active' : ''; ?>" href="stock_movements.php"><i class="fas fa-arrow-right-arrow-left"></i><span>Stock Movements</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'stock_adjustment.php' ? 'active' : ''; ?>" href="stock_adjustment.php"><i class="fas fa-sliders"></i><span>Stock Adjustment</span></a></li>
                        </ul>
                    </li>

                    <li class="sidebar-item sidebar-item--group <?php echo $is_orders_active ? 'is-open is-active' : ''; ?>">
                        <button type="button" class="sidebar-group-toggle" data-sidebar-group-toggle aria-expanded="<?php echo $is_orders_active ? 'true' : 'false'; ?>">
                            <span class="sidebar-link__icon"><i class="fas fa-receipt"></i></span>
                            <span class="sidebar-link__label">Sales & Orders</span>
                            <span class="sidebar-link__arrow"><i class="fas fa-chevron-down"></i></span>
                        </button>
                        <ul class="sidebar-submenu">
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'purchase_orders.php' ? 'active' : ''; ?>" href="purchase_orders.php"><i class="fas fa-cart-plus"></i><span>Purchase Orders</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'sales_orders.php' ? 'active' : ''; ?>" href="sales_orders.php"><i class="fas fa-cash-register"></i><span>Sales Orders</span></a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (has_permission('manager')): ?>
                    <li class="sidebar-label-item"><span class="sidebar-section-label">Catalog</span></li>
                    <li class="sidebar-item sidebar-item--group <?php echo $is_master_active ? 'is-open is-active' : ''; ?>">
                        <button type="button" class="sidebar-group-toggle" data-sidebar-group-toggle aria-expanded="<?php echo $is_master_active ? 'true' : 'false'; ?>">
                            <span class="sidebar-link__icon"><i class="fas fa-layer-group"></i></span>
                            <span class="sidebar-link__label">Master Data</span>
                            <span class="sidebar-link__arrow"><i class="fas fa-chevron-down"></i></span>
                        </button>
                        <ul class="sidebar-submenu">
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'suppliers.php' ? 'active' : ''; ?>" href="suppliers.php"><i class="fas fa-truck"></i><span>Suppliers</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'categories.php' ? 'active' : ''; ?>" href="categories.php"><i class="fas fa-tags"></i><span>Categories</span></a></li>
                        </ul>
                    </li>
                <?php endif; ?>

                <?php if (has_permission('admin')): ?>
                    <li class="sidebar-label-item"><span class="sidebar-section-label">Administration</span></li>
                    <li class="sidebar-item sidebar-item--group <?php echo $is_system_active ? 'is-open is-active' : ''; ?>">
                        <button type="button" class="sidebar-group-toggle" data-sidebar-group-toggle aria-expanded="<?php echo $is_system_active ? 'true' : 'false'; ?>">
                            <span class="sidebar-link__icon"><i class="fas fa-shield-halved"></i></span>
                            <span class="sidebar-link__label">System</span>
                            <span class="sidebar-link__arrow"><i class="fas fa-chevron-down"></i></span>
                        </button>
                        <ul class="sidebar-submenu">
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'users.php' ? 'active' : ''; ?>" href="users.php"><i class="fas fa-users"></i><span>User Management</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'reports.php' ? 'active' : ''; ?>" href="reports.php"><i class="fas fa-chart-column"></i><span>Reports & Analytics</span></a></li>
                            <li><a class="sidebar-submenu-link <?php echo $current_page === 'settings.php' ? 'active' : ''; ?>" href="settings.php"><i class="fas fa-gear"></i><span>System Settings</span></a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="app-sidebar__footer">
            <div class="app-sidebar__user">
                <div class="app-sidebar__avatar<?php echo $profile_image_url ? ' has-image' : ''; ?>">
                    <?php if ($profile_image_url): ?>
                        <img src="<?php echo htmlspecialchars($profile_image_url); ?>" alt="<?php echo htmlspecialchars($full_name); ?>">
                    <?php else: ?>
                        <?php echo htmlspecialchars($initials); ?>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="app-sidebar__user-name"><?php echo htmlspecialchars($full_name); ?></span>
                    <span class="app-sidebar__user-role"><?php echo htmlspecialchars($role_name); ?></span>
                </div>
            </div>
        </div>
    </div>
</nav>

<div class="app-sidebar-backdrop" id="appSidebarBackdrop" aria-hidden="true"></div>
