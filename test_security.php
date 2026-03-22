<?php
// Simple test to debug the 500 error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting security test...<br>";

try {
    require_once 'config/config.php';
    echo "Config loaded successfully<br>";
    
    require_once 'includes/security.php';
    echo "Security loaded successfully<br>";
    
    set_security_headers();
    echo "Security headers set successfully<br>";
    
    $username = 'test';
    $ip_address = '127.0.0.1';
    
    $is_limited = is_rate_limited($username, $ip_address);
    echo "Rate limiting check: " . ($is_limited ? 'limited' : 'not limited') . "<br>";
    
    $is_locked = is_account_locked($username, $ip_address);
    echo "Account lockout check: " . ($is_locked ? 'locked' : 'not locked') . "<br>";
    
    echo "All tests passed!";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
    echo "Trace: " . $e->getTraceAsString() . "<br>";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "<br>";
    echo "Trace: " . $e->getTraceAsString() . "<br>";
}
?>
