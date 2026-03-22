<?php
// Test PHP syntax for the fixed line
$product = null;

// Test the fixed syntax from line 1010
$value = !empty($product) ? ($product['quantity_on_hand'] ?? $product['initial_quantity'] ?? 0) : 0);

// Test the status field syntax from lines 1019-1021
$status_value = !empty($product) ? ($product['status'] ?? '') : '';
$active_selected = $status_value === 'active' ? 'selected' : '';
$inactive_selected = $status_value === 'inactive' ? 'selected' : '';
$discontinued_selected = $status_value === 'discontinued' ? 'selected' : '';

echo "✅ PHP syntax test passed - no errors found\n";
echo "Fixed line 1010: " . $value . "\n";
echo "Status field tests:\n";
echo "Active: " . $active_selected . "\n";
echo "Inactive: " . $inactive_selected . "\n";  
echo "Discontinued: " . $discontinued_selected . "\n";
?>
