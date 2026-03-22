<?php
header('Content-Type: application/json');
require_once '../config/config.php';

// We can use a stricter permission level for API endpoints if needed
// For now, 'staff' is consistent with the inventory page.
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get inventory data
    $query = "SELECT 
                p.product_id, 
                i.quantity_on_hand, 
                i.quantity_reserved, 
                i.quantity_available,
                p.reorder_level,
                CASE 
                    WHEN i.quantity_on_hand = 0 THEN 'out_of_stock'
                    WHEN i.quantity_on_hand <= p.reorder_level THEN 'low_stock'
                    ELSE 'in_stock'
                END as stock_status
              FROM inventory i
              JOIN products p ON i.product_id = p.product_id
              WHERE p.status = 'active'";
              
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    $inventory_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return data as JSON
    echo json_encode([
        'success' => true,
        'inventory' => $inventory_data,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    // Return error message
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch stock levels.',
        'error' => $e->getMessage()
    ]);
}
?>