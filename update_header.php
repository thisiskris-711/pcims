<?php
$content = file_get_contents('c:\xampp\htdocs\pcims\views\layouts\header.php');

$replacements = [
    // Main Section (New Sale)
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER)): ?>' => "<?php if (hasPermission('create_sales')): ?>",
    
    // Inventory Section wrapper
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_STOCKER, ROLE_AUDITOR)): ?>' => "<?php if (hasPermission('manage_products') || hasPermission('manage_inventory') || hasPermission('manage_suppliers')): ?>",
    
    // Inventory -> Products/Categories
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_AUDITOR)): ?>
                        <a href="<?= APP_URL ?>/products"' => "<?php if (hasPermission('manage_products')): ?>\n                        <a href=\"<?= APP_URL ?>/products\"",
                        
    // Inventory -> Suppliers/Purchase Orders
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_AUDITOR)): ?>
                        <a href="<?= APP_URL ?>/suppliers"' => "<?php if (hasPermission('manage_suppliers')): ?>\n                        <a href=\"<?= APP_URL ?>/suppliers\"",
                        
    // Sales Section wrapper
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER, ROLE_AUDITOR)): ?>
                <div class="nav-section">
                    <span class="nav-section-title">Sales</span>' => "<?php if (hasPermission('view_sales') || hasPermission('view_reports')): ?>\n                <div class=\"nav-section\">\n                    <span class=\"nav-section-title\">Sales</span>",
                    
    // Sales -> Reports
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_AUDITOR)): ?>
                        <a href="<?= APP_URL ?>/reports"' => "<?php if (hasPermission('view_reports')): ?>\n                        <a href=\"<?= APP_URL ?>/reports\"",
                        
    // Dealers Section wrapper
    '<?php if (hasRole(ROLE_ADMIN, ROLE_MANAGER, ROLE_CASHIER, ROLE_AUDITOR)): ?>
                <div class="nav-section">
                    <span class="nav-section-title">Dealers</span>' => "<?php if (hasPermission('manage_dealers')): ?>\n                <div class=\"nav-section\">\n                    <span class=\"nav-section-title\">Dealers</span>",
                    
    // Administration
    '<?php if (hasRole(ROLE_ADMIN) || hasPermission(\'manage_users\') || hasPermission(\'manage_roles\')): ?>' => "<?php if (hasPermission('manage_users') || hasPermission('manage_roles')): ?>"
];

foreach ($replacements as $search => $replace) {
    $content = str_replace($search, $replace, $content);
}

// Add CSS to hide elements with missing permissions - wait, we just hide them server-side via PHP.
file_put_contents('c:\xampp\htdocs\pcims\views\layouts\header.php', $content);
echo "header.php updated\n";
