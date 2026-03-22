<?php
require_once 'config/config.php';

echo "<h2>PCIMS Product Add Functionality Test</h2>";

// Test 1: Database Connection
echo "<h3>Test 1: Database Connection</h3>";
try {
    $database = new Database();
    $db = $database->getConnection();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

// Test 2: Check if required tables exist and have correct columns
echo "<h3>Test 2: Database Tables and Columns</h3>";
$tables = ['products', 'categories', 'suppliers', 'inventory'];
foreach ($tables as $table) {
    try {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        if ($result->rowCount() > 0) {
            echo "<p style='color: green;'>✓ Table '$table' exists</p>";
            
            // Check specific columns for inventory table
            if ($table === 'inventory') {
                $columns_result = $db->query("SHOW COLUMNS FROM inventory LIKE 'updated_by'");
                if ($columns_result->rowCount() > 0) {
                    echo "<p style='color: green;'>✓ Inventory table has 'updated_by' column</p>";
                } else {
                    echo "<p style='color: orange;'>⚠ Inventory table missing 'updated_by' column (not critical for current functionality)</p>";
                }
            }
        } else {
            echo "<p style='color: red;'>✗ Table '$table' missing</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Error checking table '$table': " . $e->getMessage() . "</p>";
    }
}

// Test 3: Check upload directory permissions
echo "<h3>Test 3: Upload Directory Permissions</h3>";
$upload_dir = 'uploads/products/';
if (file_exists($upload_dir)) {
    if (is_writable($upload_dir)) {
        echo "<p style='color: green;'>✓ Upload directory is writable</p>";
    } else {
        echo "<p style='color: red;'>✗ Upload directory is not writable</p>";
    }
} else {
    echo "<p style='color: orange;'>⚠ Upload directory does not exist (will be created automatically)</p>";
}

// Test 4: Check categories and suppliers
echo "<h3>Test 4: Categories and Suppliers</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM categories WHERE status = 'active'");
    $categories = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✓ Found " . $categories['count'] . " active categories</p>";
    
    $stmt = $db->query("SELECT COUNT(*) as count FROM suppliers WHERE status = 'active'");
    $suppliers = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✓ Found " . $suppliers['count'] . " active suppliers</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error checking categories/suppliers: " . $e->getMessage() . "</p>";
}

// Test 5: Session and CSRF Token
echo "<h3>Test 5: Session and CSRF Token</h3>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "<p style='color: green;'>✓ Session is active</p>";
} else {
    echo "<p style='color: red;'>✗ Session is not active</p>";
}

$csrf_token = generate_csrf_token();
if (!empty($csrf_token)) {
    echo "<p style='color: green;'>✓ CSRF token generation working</p>";
} else {
    echo "<p style='color: red;'>✗ CSRF token generation failed</p>";
}

// Test 6: Test auto-generated product code function
echo "<h3>Test 6: Auto-Generated Product Code</h3>";
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Include the products.php functions (we need to redefine them here)
    function generateUniqueProductCode($db) {
        $prefix = 'PC';
        $timestamp = date('Ymd');
        $random = mt_rand(1000, 9999);
        $product_code = $prefix . $timestamp . $random;
        
        // Check if the generated code already exists
        $check_query = "SELECT COUNT(*) as count FROM products WHERE product_code = :product_code";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':product_code', $product_code);
        $check_stmt->execute();
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If code exists, generate a new one
        if ($result['count'] > 0) {
            return generateUniqueProductCode($db); // Recursive call with new random number
        }
        
        return $product_code;
    }
    
    $test_code = generateUniqueProductCode($db);
    if (preg_match('/^PC\d{8}\d{4}$/', $test_code)) {
        echo "<p style='color: green;'>✓ Auto-generated product code format correct: " . htmlspecialchars($test_code) . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Invalid product code format: " . htmlspecialchars($test_code) . "</p>";
    }
    
    // Test uniqueness
    $test_code2 = generateUniqueProductCode($db);
    if ($test_code !== $test_code2) {
        echo "<p style='color: green;'>✓ Generated codes are unique: " . htmlspecialchars($test_code) . " vs " . htmlspecialchars($test_code2) . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Generated codes are not unique</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error testing auto-generation: " . $e->getMessage() . "</p>";
}

echo "<h3>Summary</h3>";
echo "<p><a href='products.php?action=add'>Go to Add Product Form</a> (Product code is now optional)</p>";
echo "<p><a href='products.php'>Go to Products List</a></p>";
echo "<p><strong>New Feature:</strong> Product codes will be auto-generated if left empty (Format: PCYYYYMMDD####)</p>";
echo "<p><strong>Schema Fix:</strong> Inventory table updated_by column issue has been resolved</p>";
echo "<p><small>Note: If you want to add the updated_by column to inventory table for audit purposes, run fix_inventory_schema.sql</small>";
?>
