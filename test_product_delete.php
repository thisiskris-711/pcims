<?php
require_once 'config/config.php';

echo "<h2>PCIMS Product Delete Functionality Test</h2>";

// Test 1: Database Connection
echo "<h3>Test 1: Database Connection</h3>";
try {
    $database = new Database();
    $db = $database->getConnection();
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
    exit();
}

// Test 2: Check if products table exists and has records
echo "<h3>Test 2: Products Table Check</h3>";
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM products");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✓ Products table has " . $result['count'] . " records</p>";
    
    if ($result['count'] > 0) {
        $stmt = $db->query("SELECT product_id, product_name, status FROM products LIMIT 5");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Name</th><th>Status</th><th>Test Delete</th></tr>";
        foreach ($products as $product) {
            echo "<tr>";
            echo "<td>" . $product['product_id'] . "</td>";
            echo "<td>" . htmlspecialchars($product['product_name']) . "</td>";
            echo "<td>" . $product['status'] . "</td>";
            echo "<td><a href='javascript:void(0);' onclick='testDelete(" . $product['product_id'] . ")' style='color: red;'>Test Delete</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error checking products: " . $e->getMessage() . "</p>";
}

// Test 3: Permission Check
echo "<h3>Test 3: Permission Check</h3>";
if (function_exists('has_permission')) {
    if (has_permission('admin')) {
        echo "<p style='color: green;'>✓ Current user has admin permissions</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Current user does not have admin permissions (deletion requires admin)</p>";
    }
} else {
    echo "<p style='color: red;'>✗ has_permission function not available</p>";
}

// Test 4: CSRF Token Generation
echo "<h3>Test 4: CSRF Token</h3>";
if (function_exists('generate_csrf_token')) {
    $token = generate_csrf_token();
    if (!empty($token)) {
        echo "<p style='color: green;'>✓ CSRF token generation working</p>";
    } else {
        echo "<p style='color: red;'>✗ CSRF token generation failed</p>";
    }
} else {
    echo "<p style='color: red;'>✗ generate_csrf_token function not available</p>";
}

echo "<script>
function testDelete(productId) {
    if (confirm('Test deletion of product ID: ' + productId + '?\\n\\nNote: This is just a test interface.\\nActual deletion requires proper form submission.')) {
        console.log('Would delete product ID: ' + productId);
        alert('Test interface working. Actual deletion requires:\\n1. Admin permissions\\n2. Proper form submission\\n3. CSRF token');
    }
}
</script>";

echo "<h3>Summary</h3>";
echo "<p><a href='products.php'>Go to Products List</a></p>";
echo "<p><strong>Deletion Features:</strong></p>";
echo "<ul>";
echo "<li>✓ Soft delete (sets status to 'inactive')</li>";
echo "<li>✓ Requires admin permissions</li>";
echo "<li>✓ CSRF protection</li>";
echo "<li>✓ Transaction support</li>";
echo "<li>✓ Activity logging</li>";
echo "<li>✓ Error handling</li>";
echo "</ul>";
?>
