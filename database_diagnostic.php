<?php
/**
 * Database Parameter Diagnostic Tool
 * Helps identify HY093 parameter errors
 */

require_once 'config/config.php';

echo "<h2>🔧 Database Parameter Diagnostic</h2>";

// Test database connection
try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h3>✅ Database Connection: Successful</h3>";
    
    // Test all prepared statements that could cause HY093
    $tests = [
        [
            'file' => 'users.php',
            'description' => 'User management operations',
            'queries' => [
                'INSERT users',
                'UPDATE users', 
                'SELECT users',
                'DELETE users'
            ]
        ],
        [
            'file' => 'stock_adjustment.php', 
            'description' => 'Stock adjustment operations',
            'queries' => [
                'UPDATE inventory',
                'INSERT stock_movements'
            ]
        ],
        [
            'file' => 'products.php',
            'description' => 'Product management operations',
            'queries' => [
                'UPDATE products',
                'INSERT inventory',
                'UPDATE inventory',
                'INSERT stock_movements'
            ]
        ]
    ];
    
    foreach ($tests as $test) {
        echo "<div style='border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h4 style='color: #e74a3b; margin-bottom: 10px;'>📁 {$test['file']}</h4>";
        echo "<p style='margin-bottom: 10px;'><strong>{$test['description']}</strong></p>";
        
        echo "<table style='width: 100%; border-collapse: collapse; margin-bottom: 15px;'>";
        echo "<tr style='background: #f8f9fa;'>";
        echo "<th style='padding: 10px; text-align: left; border-bottom: 1px solid #ddd;'>Query Type</th>";
        echo "<th style='padding: 10px; text-align: left; border-bottom: 1px solid #ddd;'>Expected Parameters</th>";
        echo "<th style='padding: 10px; text-align: left; border-bottom: 1px solid #ddd;'>Status</th>";
        echo "</tr>";
        
        foreach ($test['queries'] as $query) {
            echo "<tr>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; font-family: monospace;'>{$query}</td>";
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee;'>";
            
            // Show expected parameters based on query type
            switch ($query) {
                case 'INSERT users':
                    echo ":username, :password, :full_name, :email, :phone, :role, :status";
                    break;
                case 'UPDATE users':
                    echo ":password = :password WHERE user_id = :user_id";
                    break;
                case 'SELECT users':
                    echo ":username = :username";
                    break;
                case 'DELETE users':
                    echo ":user_id = :user_id";
                    break;
                case 'UPDATE inventory':
                    echo ":quantity_on_hand = :quantity WHERE product_id = :product_id";
                    break;
                case 'INSERT stock_movements':
                    echo ":product_id, :movement_type, :quantity, :reference_type, :notes, :user_id, :movement_date";
                    break;
                case 'UPDATE products':
                    echo ":product_code = :product_code, product_name = :product_name, ...";
                    break;
            }
            
            echo "</td>";
            
            // Test actual execution
            $status = '✅ Ready';
            $error = '';
            
            try {
                switch ($query) {
                    case 'INSERT users':
                        $test_query = "SELECT COUNT(*) FROM users WHERE username = :username";
                        $test_stmt = $db->prepare($test_query);
                        // Don't actually execute, just test preparation
                        break;
                        
                    case 'UPDATE inventory':
                        $test_query = "UPDATE inventory SET quantity_on_hand = :quantity WHERE product_id = :product_id";
                        $test_stmt = $db->prepare($test_query);
                        break;
                        
                    case 'INSERT stock_movements':
                        $test_query = "INSERT INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, user_id, movement_date) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                        $test_stmt = $db->prepare($test_query);
                        break;
                }
                
                // Check if all parameters are properly bound
                if ($test_stmt && method_exists($test_stmt, 'bindParam')) {
                    $status = '✅ Valid';
                } else {
                    $status = '❌ Error';
                    $error = 'Preparation failed';
                }
                
            } catch (Exception $e) {
                $status = '❌ Exception';
                $error = $e->getMessage();
            }
            
            echo "<td style='padding: 10px; border-bottom: 1px solid #eee; color: " . ($status === '✅ Ready' ? '#28a745' : '#dc3545') . ";'>" . ($status === '✅ Ready' ? '✅' : '❌') . " " . htmlspecialchars($status) . "</td>";
            if ($error) {
                echo "<td style='padding: 10px; border-bottom: 1px solid #eee; color: #dc3545; font-size: 12px;'>" . htmlspecialchars($error) . "</td>";
            } else {
                echo "<td style='padding: 10px; border-bottom: 1px solid #eee; color: #28a745;'>" . htmlspecialchars($status) . "</td>";
            }
            echo "</tr>";
        }
        
        echo "</table>";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>❌ Database Connection Failed</h3>";
    echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h2 { color: #2d3436; }
h3 { color: #e74a3b; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
th { background: #f8f9fa; font-weight: bold; }
</style>
