<?php
require_once 'config/config.php';

echo "<h2>Database Duplicate Fix - Safe Application</h2>\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h3>Step 1: Checking for existing duplicates...</h3>\n";
    
    // Check for duplicates
    $query = "SELECT 
                so_id,
                product_id,
                COUNT(*) as duplicate_count,
                SUM(quantity) as total_quantity,
                GROUP_CONCAT(soi_id) as item_ids
              FROM sales_order_items 
              GROUP BY so_id, product_id
              HAVING COUNT(*) > 1";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($duplicates)) {
        echo "<p style='color: green;'>✅ No duplicates found in database.</p>\n";
    } else {
        echo "<p style='color: orange;'>⚠️ Found " . count($duplicates) . " sets of duplicates. Fixing...</p>\n";
        
        echo "<h3>Step 2: Consolidating duplicates...</h3>\n";
        
        $db->beginTransaction();
        
        try {
            foreach ($duplicates as $duplicate) {
                $so_id = $duplicate['so_id'];
                $product_id = $duplicate['product_id'];
                $total_quantity = $duplicate['total_quantity'];
                $item_ids = explode(',', $duplicate['item_ids']);
                
                // Keep the first item, delete the rest
                $keep_id = array_shift($item_ids);
                
                // Update the kept item with total quantity
                $update_query = "UPDATE sales_order_items 
                                 SET quantity = :total_quantity 
                                 WHERE soi_id = :keep_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':total_quantity', $total_quantity);
                $update_stmt->bindParam(':keep_id', $keep_id);
                $update_stmt->execute();
                
                // Delete duplicate items
                foreach ($item_ids as $delete_id) {
                    $delete_query = "DELETE FROM sales_order_items WHERE soi_id = :delete_id";
                    $delete_stmt = $db->prepare($delete_query);
                    $delete_stmt->bindParam(':delete_id', $delete_id);
                    $delete_stmt->execute();
                }
                
                echo "<p>✅ Consolidated duplicates for SO #{$so_id}, Product #{$product_id}</p>\n";
            }
            
            $db->commit();
            echo "<p style='color: green;'>✅ All duplicates consolidated successfully!</p>\n";
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
    
    echo "<h3>Step 3: Adding unique constraint to prevent future duplicates...</h3>\n";
    
    // Check if constraint already exists
    $constraint_check = "SELECT COUNT(*) FROM information_schema.table_constraints 
                         WHERE table_schema = DATABASE() 
                         AND table_name = 'sales_order_items' 
                         AND constraint_name = 'unique_so_product'";
    
    $stmt = $db->prepare($constraint_check);
    $stmt->execute();
    $constraint_exists = $stmt->fetchColumn();
    
    if ($constraint_exists > 0) {
        echo "<p style='color: blue;'>ℹ️ Unique constraint already exists.</p>\n";
    } else {
        try {
            $db->beginTransaction();
            
            // Add the unique constraint
            $alter_query = "ALTER TABLE sales_order_items 
                           ADD CONSTRAINT unique_so_product 
                           UNIQUE (so_id, product_id)";
            
            $stmt = $db->prepare($alter_query);
            $stmt->execute();
            
            $db->commit();
            echo "<p style='color: green;'>✅ Unique constraint added successfully!</p>\n";
            
        } catch (Exception $e) {
            $db->rollBack();
            echo "<p style='color: orange;'>⚠️ Could not add constraint (may already exist): " . $e->getMessage() . "</p>\n";
        }
    }
    
    echo "<h3>Step 4: Final verification...</h3>\n";
    
    // Verify no duplicates remain
    $verify_query = "SELECT 
                       so_id,
                       product_id,
                       COUNT(*) as duplicate_count
                     FROM sales_order_items 
                     GROUP BY so_id, product_id
                     HAVING COUNT(*) > 1";
    
    $stmt = $db->prepare($verify_query);
    $stmt->execute();
    $remaining_duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($remaining_duplicates)) {
        echo "<p style='color: green; font-weight: bold;'>🎉 SUCCESS: No duplicates remain in database!</p>\n";
    } else {
        echo "<p style='color: red;'>❌ Still found " . count($remaining_duplicates) . " sets of duplicates.</p>\n";
    }
    
    echo "<h3>Step 5: Testing the fix...</h3>\n";
    echo "<p>The fix includes:</p>\n";
    echo "<ul>\n";
    echo "<li>✅ Database-level duplicate prevention</li>\n";
    echo "<li>✅ Backend consolidation during sales</li>\n";
    echo "<li>✅ Receipt display consolidation</li>\n";
    echo "<li>✅ JavaScript click prevention</li>\n";
    echo "</ul>\n";
    
    echo "<p><a href='sales_orders.php?action=add' class='btn btn-primary'>Test the POS System</a></p>\n";
    echo "<p><a href='sales_orders.php' class='btn btn-outline-info'>View Sales History</a></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>\n";
}
?>
