<?php
$files = [
    'categories.php' => 'manage_products',
    'products.php' => 'manage_products',
    'product_form.php' => 'manage_products',
    'stock.php' => 'manage_inventory',
    'dealers.php' => 'manage_dealers',
    'dealer_application_form.php' => 'manage_dealers',
    'suppliers.php' => 'manage_suppliers',
    'purchase_orders.php' => 'manage_suppliers',
    'purchase_order_form.php' => 'manage_suppliers',
    'reports.php' => 'view_reports',
    'users.php' => 'manage_users', // Note: already has requireRole(ROLE_ADMIN) in its old state? Let's check
    'sales.php' => 'view_sales',
    'create_invoice.php' => 'create_sales',
    'invoice_print.php' => 'create_sales' // Assuming if they can print, they can create, or maybe view_sales. create_sales is what we planned.
];

$dir = __DIR__ . '/views/pages/';
foreach ($files as $file => $permission) {
    $path = $dir . $file;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        
        // Remove existing requireRole
        $content = preg_replace('/requireRole\([^)]+\);\s*/', '', $content);
        
        // Ensure requirePermission is added just after requireLogin
        if (strpos($content, "requirePermission('$permission');") === false) {
            $content = preg_replace('/requireLogin\(\);\s*/', "requireLogin();\nrequirePermission('$permission');\n", $content);
            file_put_contents($path, $content);
            echo "Updated $file\n";
        }
    }
}
