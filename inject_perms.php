<?php
$files = [
    'categories.php' => 'manage_products',
    'products.php' => 'manage_products',
    'stock.php' => 'manage_inventory',
    'dealers.php' => 'manage_dealers',
    'dealer_applications.php' => 'manage_dealers',
    'suppliers.php' => 'manage_suppliers',
    'purchase_orders.php' => 'manage_suppliers',
    'reports.php' => 'view_reports'
];

$dir = __DIR__ . '/src/Api/';
foreach ($files as $file => $permission) {
    $path = $dir . $file;
    if (file_exists($path)) {
        $content = file_get_contents($path);
        // Remove existing requireRole if any at the top level
        $content = preg_replace('/requireRole\([^)]+\);\s*/', '', $content);
        
        // Add requirePermission after requireLogin
        if (strpos($content, "requirePermission('$permission');") === false) {
            $content = preg_replace('/requireLogin\(\);\s*/', "requireLogin();\nrequirePermission('$permission');\n\n", $content);
            file_put_contents($path, $content);
            echo "Updated $file\n";
        }
    }
}
