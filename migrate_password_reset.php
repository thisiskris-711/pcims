<?php
/**
 * Database Migration Script
 * Add password reset functionality to users table
 * 
 * Run this script once to add the required columns for password reset functionality
 */

require_once 'config/config.php';

echo "Starting database migration for password reset functionality...\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Check if reset_token column exists
    $check_token_query = "SHOW COLUMNS FROM users LIKE 'reset_token'";
    $stmt = $db->prepare($check_token_query);
    $stmt->execute();
    $token_exists = $stmt->fetch();
    
    // Check if reset_expiry column exists
    $check_expiry_query = "SHOW COLUMNS FROM users LIKE 'reset_expiry'";
    $stmt = $db->prepare($check_expiry_query);
    $stmt->execute();
    $expiry_exists = $stmt->fetch();
    
    $alter_queries = [];
    
    if (!$token_exists) {
        $alter_queries[] = "ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) NULL";
        echo "• Adding reset_token column\n";
    } else {
        echo "• reset_token column already exists\n";
    }
    
    if (!$expiry_exists) {
        $alter_queries[] = "ALTER TABLE users ADD COLUMN reset_expiry DATETIME NULL";
        echo "• Adding reset_expiry column\n";
    } else {
        echo "• reset_expiry column already exists\n";
    }
    
    // Execute alter queries if needed
    if (!empty($alter_queries)) {
        echo "\nExecuting migration queries...\n";
        
        foreach ($alter_queries as $query) {
            $stmt = $db->prepare($query);
            $stmt->execute();
            echo "✓ Query executed successfully\n";
        }
        
        echo "\n✓ Migration completed successfully!\n";
    } else {
        echo "\n✓ Database schema is already up to date!\n";
    }
    
    // Verify the columns were added
    echo "\nVerifying schema...\n";
    $verify_query = "DESCRIBE users";
    $stmt = $db->prepare($verify_query);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $found_token = false;
    $found_expiry = false;
    
    foreach ($columns as $column) {
        if ($column['Field'] === 'reset_token') {
            $found_token = true;
            echo "✓ reset_token column found (Type: {$column['Type']})\n";
        }
        if ($column['Field'] === 'reset_expiry') {
            $found_expiry = true;
            echo "✓ reset_expiry column found (Type: {$column['Type']})\n";
        }
    }
    
    if ($found_token && $found_expiry) {
        echo "\n🎉 Password reset functionality is now ready!\n";
        echo "\nNext steps:\n";
        echo "1. Test the forgot password functionality\n";
        echo "2. Configure email settings in config/email_config.php\n";
        echo "3. Remove this migration file for security\n";
    } else {
        echo "\n❌ Migration failed. Please check the database and try again.\n";
    }
    
} catch (PDOException $e) {
    echo "\n❌ Database error: " . $e->getMessage() . "\n";
    echo "Please check your database connection and permissions.\n";
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
}

echo "\n";
?>
