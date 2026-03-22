<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('manager');

$page_title = 'Reports';
$report_type = $_GET['report_type'] ?? 'inventory';
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$report_templates = pcims_get_report_templates();
if (!isset($report_templates[$report_type])) {
    $report_type = 'inventory';
}
$report_template = pcims_get_report_template($report_type);
$category_label = pcims_get_business_label('category_singular', 'Category');
$product_label = pcims_get_business_label('product_singular', 'Product');
$supplier_label = pcims_get_business_label('supplier_singular', 'Supplier');

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: reports.php');
        exit();
    }
}

// Get report data
$report_data = [];
$summary_data = [];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    switch ($report_type) {
        case 'inventory':
            // Inventory Status Report
            $query = "SELECT p.product_id, p.product_code, p.product_name, p.unit_price,
                             i.quantity_on_hand, i.quantity_reserved, 
                             (i.quantity_on_hand - i.quantity_reserved) as available,
                             c.category_name,
                             CASE 
                                 WHEN i.quantity_on_hand = 0 THEN 'Out of Stock'
                                 WHEN i.quantity_on_hand <= 5 THEN 'Low Stock'
                                 ELSE 'In Stock'
                             END as stock_status
                      FROM products p
                      JOIN inventory i ON p.product_id = i.product_id
                      LEFT JOIN categories c ON p.category_id = c.category_id
                      WHERE p.status = 'active'
                      ORDER BY stock_status, p.product_name";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Summary data
            $summary_query = "SELECT 
                                  COUNT(*) as total_products,
                                  SUM(CASE WHEN quantity_on_hand = 0 THEN 1 ELSE 0 END) as out_of_stock,
                                  SUM(CASE WHEN quantity_on_hand <= 5 AND quantity_on_hand > 0 THEN 1 ELSE 0 END) as low_stock,
                                  SUM(CASE WHEN quantity_on_hand > 5 THEN 1 ELSE 0 END) as in_stock,
                                  SUM(quantity_on_hand) as total_quantity,
                                  SUM(quantity_reserved) as total_reserved,
                                  SUM(quantity_on_hand * unit_price) as total_value
                              FROM products p
                              JOIN inventory i ON p.product_id = i.product_id
                              WHERE p.status = 'active'";
            
            $stmt = $db->prepare($summary_query);
            $stmt->execute();
            $summary_data = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'sales':
            // Sales Report
            $query = "SELECT so.so_number, so.customer_name, so.order_date, so.total_amount, so.status,
                             COUNT(soi.so_id) as item_count,
                             GROUP_CONCAT(p.product_name SEPARATOR ', ') as products
                      FROM sales_orders so
                      LEFT JOIN sales_order_items soi ON so.so_id = soi.so_id
                      LEFT JOIN products p ON soi.product_id = p.product_id
                      WHERE so.order_date BETWEEN :start_date AND :end_date
                      GROUP BY so.so_id
                      ORDER BY so.order_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Summary data
            $summary_query = "SELECT 
                                  COUNT(*) as total_orders,
                                  SUM(total_amount) as total_revenue,
                                  AVG(total_amount) as avg_order_value,
                                  COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
                                  COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
                                  COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders
                              FROM sales_orders
                              WHERE order_date BETWEEN :start_date AND :end_date";
            
            $stmt = $db->prepare($summary_query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $summary_data = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'purchases':
            // Purchase Orders Report
            $query = "SELECT po.po_number, s.supplier_name, po.order_date, po.total_amount, po.status,
                             COUNT(poi.po_id) as item_count,
                             GROUP_CONCAT(p.product_name SEPARATOR ', ') as products
                      FROM purchase_orders po
                      LEFT JOIN purchase_order_items poi ON po.po_id = poi.po_id
                      LEFT JOIN suppliers s ON po.supplier_id = s.supplier_id
                      LEFT JOIN products p ON poi.product_id = p.product_id
                      WHERE po.order_date BETWEEN :start_date AND :end_date
                      GROUP BY po.po_id
                      ORDER BY po.order_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Summary data
            $summary_query = "SELECT 
                                  COUNT(*) as total_orders,
                                  SUM(total_amount) as total_purchases,
                                  AVG(total_amount) as avg_order_value,
                                  COUNT(CASE WHEN status = 'received' THEN 1 END) as received_orders,
                                  COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
                                  COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_orders
                              FROM purchase_orders
                              WHERE order_date BETWEEN :start_date AND :end_date";
            
            $stmt = $db->prepare($summary_query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $summary_data = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
            
        case 'stock_movements':
            // Stock Movements Report
            $query = "SELECT sm.movement_date, sm.movement_type, sm.quantity, sm.notes,
                             p.product_name, p.product_code,
                             CASE 
                                 WHEN sm.movement_type = 'in' THEN 'Stock In'
                                 WHEN sm.movement_type = 'out' THEN 'Stock Out'
                                 ELSE 'Adjustment'
                             END as movement_desc,
                             sm.reference_type, sm.reference_id
                      FROM stock_movements sm
                      JOIN products p ON sm.product_id = p.product_id
                      WHERE sm.movement_date BETWEEN :start_date AND :end_date
                      ORDER BY sm.movement_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Summary data
            $summary_query = "SELECT 
                                  COUNT(*) as total_movements,
                                  SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) as total_in,
                                  SUM(CASE WHEN movement_type = 'out' THEN quantity ELSE 0 END) as total_out,
                                  COUNT(CASE WHEN movement_type = 'in' THEN 1 END) as in_movements,
                                  COUNT(CASE WHEN movement_type = 'out' THEN 1 END) as out_movements,
                                  COUNT(CASE WHEN movement_type = 'adjustment' THEN 1 END) as adjustments
                              FROM stock_movements
                              WHERE movement_date BETWEEN :start_date AND :end_date";
            
            $stmt = $db->prepare($summary_query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            $summary_data = $stmt->fetch(PDO::FETCH_ASSOC);
            break;
    }
    
} catch(PDOException $exception) {
    $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
    error_log("Reports Error: " . $exception->getMessage());
}

include 'includes/header.php';
?>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-chart-bar me-2"></i><?php echo htmlspecialchars($report_template['label']); ?>
            </h1>
            <?php if (!empty($report_template['description'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($report_template['description']); ?></p>
            <?php endif; ?>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-primary me-2">
                <i class="fas fa-print me-2"></i>Print Report
            </button>
            <button onclick="exportReport()" class="btn btn-outline-success">
                <i class="fas fa-download me-2"></i>Export
            </button>
        </div>
    </div>
    
    <!-- Report Filters -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Report Filters</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="report_type" class="form-label">Report Type</label>
                    <select class="form-select" id="report_type" name="report_type" onchange="this.form.submit()">
                        <?php foreach ($report_templates as $template_key => $template_definition): ?>
                            <option value="<?php echo htmlspecialchars($template_key); ?>" <?php echo $report_type === $template_key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($template_definition['label']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <?php if ($report_type !== 'inventory'): ?>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" 
                           value="<?php echo htmlspecialchars($start_date); ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" 
                           value="<?php echo htmlspecialchars($end_date); ?>" onchange="this.form.submit()">
                </div>
                <?php endif; ?>
                
                <div class="col-md-<?php echo $report_type === 'inventory' ? '9' : '3'; ?>">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary flex-fill">
                            <i class="fas fa-sync me-2"></i>Refresh
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearFilters()">
                            <i class="fas fa-times me-2"></i>Clear
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Summary Cards -->
    <?php if (!empty($summary_data)): ?>
    <div class="row mb-4">
        <?php
        switch ($report_type) {
            case 'inventory':
                echo '<div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Products</h5>
                                <h2>' . number_format($summary_data['total_products']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h5 class="card-title">Out of Stock</h5>
                                <h2>' . number_format($summary_data['out_of_stock']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Low Stock</h5>
                                <h2>' . number_format($summary_data['low_stock']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Value</h5>
                                <h2>' . format_currency($summary_data['total_value']) . '</h2>
                            </div>
                        </div>
                      </div>';
                break;
                
            case 'sales':
                echo '<div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Revenue</h5>
                                <h2>' . format_currency($summary_data['total_revenue']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Orders</h5>
                                <h2>' . number_format($summary_data['total_orders']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">Avg Order Value</h5>
                                <h2>' . format_currency($summary_data['avg_order_value']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Completed</h5>
                                <h2>' . number_format($summary_data['completed_orders']) . '</h2>
                            </div>
                        </div>
                      </div>';
                break;
                
            case 'purchases':
                echo '<div class="col-md-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Purchases</h5>
                                <h2>' . format_currency($summary_data['total_purchases']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Orders</h5>
                                <h2>' . number_format($summary_data['total_orders']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Avg Order Value</h5>
                                <h2>' . format_currency($summary_data['avg_order_value']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Received</h5>
                                <h2>' . number_format($summary_data['received_orders']) . '</h2>
                            </div>
                        </div>
                      </div>';
                break;
                
            case 'stock_movements':
                echo '<div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Stock In</h5>
                                <h2>' . number_format($summary_data['total_in']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <h5 class="card-title">Stock Out</h5>
                                <h2>' . number_format($summary_data['total_out']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Movements</h5>
                                <h2>' . number_format($summary_data['total_movements']) . '</h2>
                            </div>
                        </div>
                      </div>';
                echo '<div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Adjustments</h5>
                                <h2>' . number_format($summary_data['adjustments']) . '</h2>
                            </div>
                        </div>
                      </div>';
                break;
        }
        ?>
    </div>
    <?php endif; ?>
    
    <!-- Charts Section -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <?php
                        switch ($report_type) {
                            case 'inventory': echo 'Stock Status Distribution'; break;
                            case 'sales': echo 'Sales Overview'; break;
                            case 'purchases': echo $supplier_label . ' Purchasing Status'; break;
                            case 'stock_movements': echo 'Stock Movement Trends'; break;
                        }
                        ?>
                    </h5>
                </div>
                <div class="card-body chart-container">
                    <canvas id="mainChart" height="300"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <?php
                        switch ($report_type) {
                            case 'inventory': echo $category_label . ' Analysis'; break;
                            case 'sales': echo 'Revenue Trends'; break;
                            case 'purchases': echo $supplier_label . ' Analysis'; break;
                            case 'stock_movements': echo 'Movement Types'; break;
                        }
                        ?>
                    </h5>
                </div>
                <div class="card-body chart-container">
                    <canvas id="secondaryChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Report Data Table -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <?php echo htmlspecialchars($report_template['heading']); ?>
                <?php if ($report_type !== 'inventory'): ?>
                    <?php echo ' (' . format_date($start_date) . ' to ' . format_date($end_date) . ')'; ?>
                <?php endif; ?>
            </h5>
        </div>
        <div class="card-body">
            <?php if ($report_type === 'inventory'): ?>
            <!-- Scrollable container for inventory report -->
            <div class="inventory-table-container">
                <div class="table-responsive">
                    <table class="table table-hover" id="reportTable">
                        <thead class="table-light sticky-top">
            <?php else: ?>
            <!-- Regular table for other reports -->
            <div class="table-responsive">
                <table class="table table-hover" id="reportTable">
                    <thead class="table-light">
            <?php endif; ?>
                        <?php
                        switch ($report_type) {
                            case 'inventory':
                                echo '<tr>
                                        <th>' . htmlspecialchars($product_label) . ' Code</th>
                                        <th>' . htmlspecialchars($product_label) . ' Name</th>
                                        <th>' . htmlspecialchars($category_label) . '</th>
                                        <th>Unit Price</th>
                                        <th>On Hand</th>
                                        <th>Reserved</th>
                                        <th>Available</th>
                                        <th>Status</th>
                                      </tr>';
                                break;
                                
                            case 'sales':
                                echo '<tr>
                                        <th>Order #</th>
                                        <th>Customer</th>
                                        <th>Order Date</th>
                                        <th>Items</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                      </tr>';
                                break;
                                
                            case 'purchases':
                                echo '<tr>
                                        <th>PO #</th>
                                        <th>' . htmlspecialchars($supplier_label) . '</th>
                                        <th>Order Date</th>
                                        <th>Items</th>
                                        <th>Total Amount</th>
                                        <th>Status</th>
                                      </tr>';
                                break;
                                
                            case 'stock_movements':
                                echo '<tr>
                                        <th>Date</th>
                                        <th>' . htmlspecialchars($product_label) . '</th>
                                        <th>Type</th>
                                        <th>Quantity</th>
                                        <th>Reference</th>
                                        <th>Notes</th>
                                      </tr>';
                                break;
                        }
                        ?>
                    </thead>
                    <tbody>
                        <?php if (!empty($report_data)): ?>
                            <?php foreach ($report_data as $row): ?>
                                <?php
                                switch ($report_type) {
                                    case 'inventory':
                                        echo '<tr>
                                                <td><span class="badge bg-light text-dark">' . htmlspecialchars($row['product_code']) . '</span></td>
                                                <td>' . htmlspecialchars($row['product_name']) . '</td>
                                                <td>' . htmlspecialchars($row['category_name'] ?: 'Uncategorized') . '</td>
                                                <td>' . format_currency($row['unit_price']) . '</td>
                                                <td>' . number_format($row['quantity_on_hand']) . '</td>
                                                <td>' . number_format($row['quantity_reserved']) . '</td>
                                                <td>' . number_format($row['available']) . '</td>
                                                <td>
                                                    <span class="badge bg-' . 
                                                    ($row['stock_status'] === 'Out of Stock' ? 'danger' : 
                                                     ($row['stock_status'] === 'Low Stock' ? 'warning' : 'success')) . '">
                                                        ' . htmlspecialchars($row['stock_status']) . '
                                                    </span>
                                                </td>
                                              </tr>';
                                        break;
                                        
                                    case 'sales':
                                        $customer_display = !empty($row['customer_name']) ? trim($row['customer_name']) : 'Walk-in Customer';
                                        if (in_array(strtolower($customer_display), ['walk-in customer', 'walkin', 'walk in', '', 'null'])) {
                                            $customer_display = 'Walk-in Customer';
                                        }
                                        echo '<tr>
                                                <td><span class="badge bg-light text-dark">' . htmlspecialchars($row['so_number']) . '</span></td>
                                                <td>' . htmlspecialchars($customer_display) . '</td>
                                                <td>' . format_date($row['order_date']) . '</td>
                                                <td>' . number_format($row['item_count']) . ' items</td>
                                                <td>' . format_currency($row['total_amount']) . '</td>
                                                <td>
                                                    <span class="badge bg-' . 
                                                    ($row['status'] === 'completed' ? 'success' : 
                                                     ($row['status'] === 'delivered' ? 'info' : 
                                                     ($row['status'] === 'processing' ? 'primary' : 
                                                     ($row['status'] === 'shipped' ? 'warning' : 'secondary')))) . '">
                                                        ' . ucfirst($row['status']) . '
                                                    </span>
                                                </td>
                                              </tr>';
                                        break;
                                        
                                    case 'purchases':
                                        echo '<tr>
                                                <td><span class="badge bg-light text-dark">' . htmlspecialchars($row['po_number']) . '</span></td>
                                                <td>' . htmlspecialchars($row['supplier_name']) . '</td>
                                                <td>' . format_date($row['order_date']) . '</td>
                                                <td>' . number_format($row['item_count']) . ' items</td>
                                                <td>' . format_currency($row['total_amount']) . '</td>
                                                <td>
                                                    <span class="badge bg-' . 
                                                    ($row['status'] === 'received' ? 'success' : 
                                                     ($row['status'] === 'processing' ? 'primary' : 'secondary')) . '">
                                                        ' . ucfirst($row['status']) . '
                                                    </span>
                                                </td>
                                              </tr>';
                                        break;
                                        
                                    case 'stock_movements':
                                        echo '<tr>
                                                <td>' . format_date($row['movement_date'], 'M d, Y H:i') . '</td>
                                                <td>
                                                    <strong>' . htmlspecialchars($row['product_name']) . '</strong>
                                                    <br><small class="text-muted">' . htmlspecialchars($row['product_code']) . '</small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-' . 
                                                    ($row['movement_type'] === 'in' ? 'success' : 
                                                     ($row['movement_type'] === 'out' ? 'danger' : 'warning')) . '">
                                                        ' . htmlspecialchars($row['movement_desc']) . '
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <strong>' . number_format(abs($row['quantity'])) . '</strong>
                                                </td>
                                                <td>' . htmlspecialchars($row['reference_type']) . ' #' . $row['reference_id'] . '</td>
                                                <td>' . htmlspecialchars($row['notes'] ?: '-') . '</td>
                                              </tr>';
                                        break;
                                }
                                ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="<?php echo ($report_type === 'inventory' ? '8' : '6'); ?>" class="text-center text-muted">
                                    No data found for the selected criteria.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php if ($report_type === 'inventory'): ?>
                </div>
            </div>
            <?php else: ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function clearFilters() {
    document.getElementById('report_type').value = 'inventory';
    document.getElementById('start_date').value = '';
    document.getElementById('end_date').value = '';
    window.location.href = 'reports.php';
}

function exportReport() {
    const reportType = document.getElementById('report_type').value;
    const startDate = document.getElementById('start_date')?.value || '';
    const endDate = document.getElementById('end_date')?.value || '';
    
    let url = 'reports.php?export=1&report_type=' + reportType;
    if (startDate) url += '&start_date=' + startDate;
    if (endDate) url += '&end_date=' + endDate;
    
    window.open(url, '_blank');
}

// Handle export functionality
<?php
if (isset($_GET['export']) && $_GET['export'] == '1') {
    // Simple CSV export
    $export_basename = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $report_template['label'] ?? $report_type));
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_' . trim($export_basename, '_') . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header row
    switch ($report_type) {
        case 'inventory':
            fputcsv($output, [$product_label . ' Code', $product_label . ' Name', $category_label, 'Unit Price', 'On Hand', 'Reserved', 'Available', 'Status']);
            break;
        case 'sales':
            fputcsv($output, ['Order #', 'Customer', 'Order Date', 'Items', 'Total Amount', 'Status']);
            break;
        case 'purchases':
            fputcsv($output, ['PO #', $supplier_label, 'Order Date', 'Items', 'Total Amount', 'Status']);
            break;
        case 'stock_movements':
            fputcsv($output, ['Date', $product_label, 'Type', 'Quantity', 'Reference', 'Notes']);
            break;
    }
    
    // Data rows
    foreach ($report_data as $row) {
        switch ($report_type) {
            case 'inventory':
                fputcsv($output, [
                    $row['product_code'],
                    $row['product_name'],
                    $row['category_name'] ?: 'Uncategorized',
                    $row['unit_price'],
                    $row['quantity_on_hand'],
                    $row['quantity_reserved'],
                    $row['available'],
                    $row['stock_status']
                ]);
                break;
            case 'sales':
                $customer_display = !empty($row['customer_name']) ? trim($row['customer_name']) : 'Walk-in Customer';
                if (in_array(strtolower($customer_display), ['walk-in customer', 'walkin', 'walk in', '', 'null'])) {
                    $customer_display = 'Walk-in Customer';
                }
                fputcsv($output, [
                    $row['so_number'],
                    $customer_display,
                    $row['order_date'],
                    $row['item_count'] . ' items',
                    $row['total_amount'],
                    $row['status']
                ]);
                break;
            case 'purchases':
                fputcsv($output, [
                    $row['po_number'],
                    $row['supplier_name'],
                    $row['order_date'],
                    $row['item_count'] . ' items',
                    $row['total_amount'],
                    $row['status']
                ]);
                break;
            case 'stock_movements':
                fputcsv($output, [
                    $row['movement_date'],
                    $row['product_name'],
                    $row['movement_desc'],
                    abs($row['quantity']),
                    $row['reference_type'] . ' #' . $row['reference_id'],
                    $row['notes'] ?: '-'
                ]);
                break;
        }
    }
    
    fclose($output);
    exit();
}
?>
</script>

<style>
@media print {
    .btn, .no-print {
        display: none !important;
    }
    
    .card {
        border: 1px solid #dee2e6 !important;
        box-shadow: none !important;
    }
    
    .table {
        page-break-inside: auto;
    }
    
    .table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}

@media (max-width: 768px) {
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .table th, .table td {
        padding: 0.5rem;
        vertical-align: middle;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .d-flex.justify-content-between {
        flex-direction: column;
        gap: 1rem;
    }
    
    /* Chart responsiveness */
    .chart-container {
        position: relative;
        height: 300px;
        width: 100%;
    }
    
    canvas {
        max-height: 300px !important;
    }
    
    /* Scrollable inventory table */
    .inventory-table-container {
        height: 500px;
        max-height: 500px;
        overflow-y: auto;
        overflow-x: auto;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        position: relative;
    }
    
    .inventory-table-container .table-responsive {
        margin: 0;
        height: 100%;
    }
    
    .inventory-table-container .sticky-top {
        position: sticky;
        top: 0;
        z-index: 10;
        background: #f8f9fa;
        box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
    }
    
    .inventory-table-container table {
        margin: 0;
        height: 100%;
    }
    
    .inventory-table-container thead th {
        border-top: none;
        border-bottom: 2px solid #dee2e6;
    }
    
    .inventory-table-container::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    .inventory-table-container::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 4px;
    }
    
    .inventory-table-container::-webkit-scrollbar-thumb {
        background: #c1c1c1;
        border-radius: 4px;
    }
    
    .inventory-table-container::-webkit-scrollbar-thumb:hover {
        background: #a8a8a8;
    }
    
    .inventory-table-container::-webkit-scrollbar-corner {
        background: #f1f1f1;
    }
    
    @media (max-width: 768px) {
        .row.mb-4 .col-md-6 {
            margin-bottom: 1rem;
        }
        
        .chart-container {
            height: 250px;
        }
        
        canvas {
            max-height: 250px !important;
        }
        
        .inventory-table-container {
            height: 400px;
            max-height: 400px;
        }
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const reportType = '<?php echo $report_type; ?>';
    const summaryData = <?php echo json_encode($summary_data); ?>;
    const reportData = <?php echo json_encode($report_data); ?>;
    
    // Chart default options
    const chartOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
            }
        }
    };
    
    // Initialize charts based on report type
    switch (reportType) {
        case 'inventory':
            // Stock Status Distribution (Doughnut Chart)
            const stockCtx = document.getElementById('mainChart').getContext('2d');
            new Chart(stockCtx, {
                type: 'doughnut',
                data: {
                    labels: ['In Stock', 'Low Stock', 'Out of Stock'],
                    datasets: [{
                        data: [
                            summaryData.in_stock || 0,
                            summaryData.low_stock || 0,
                            summaryData.out_of_stock || 0
                        ],
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                        borderWidth: 2
                    }]
                },
                options: {
                    ...chartOptions,
                    plugins: {
                        ...chartOptions.plugins,
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.parsed / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            
            // Category Analysis (Bar Chart)
            const categoryCtx = document.getElementById('secondaryChart').getContext('2d');
            const categoryData = {};
            reportData.forEach(item => {
                const category = item.category_name || 'Uncategorized';
                categoryData[category] = (categoryData[category] || 0) + 1;
            });
            
            new Chart(categoryCtx, {
                type: 'bar',
                data: {
                    labels: Object.keys(categoryData),
                    datasets: [{
                        label: 'Products per Category',
                        data: Object.values(categoryData),
                        backgroundColor: '#007bff',
                        borderColor: '#0056b3',
                        borderWidth: 1
                    }]
                },
                options: {
                    ...chartOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            });
            break;
            
        case 'sales':
            // Sales Overview (Line Chart - Daily Revenue)
            const salesCtx = document.getElementById('mainChart').getContext('2d');
            const dailyRevenue = {};
            reportData.forEach(item => {
                const date = item.order_date;
                dailyRevenue[date] = (dailyRevenue[date] || 0) + parseFloat(item.total_amount);
            });
            
            new Chart(salesCtx, {
                type: 'line',
                data: {
                    labels: Object.keys(dailyRevenue).sort(),
                    datasets: [{
                        label: 'Daily Revenue',
                        data: Object.keys(dailyRevenue).sort().map(date => dailyRevenue[date]),
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    ...chartOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        ...chartOptions.plugins,
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Revenue: ₱' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            
            // Order Status Distribution (Pie Chart)
            const statusCtx = document.getElementById('secondaryChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'pie',
                data: {
                    labels: ['Completed', 'Pending', 'Cancelled'],
                    datasets: [{
                        data: [
                            summaryData.completed_orders || 0,
                            summaryData.pending_orders || 0,
                            summaryData.cancelled_orders || 0
                        ],
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                        borderWidth: 2
                    }]
                },
                options: chartOptions
            });
            break;
            
        case 'purchases':
            // Purchase Orders Status (Doughnut Chart)
            const purchaseStatusCtx = document.getElementById('mainChart').getContext('2d');
            new Chart(purchaseStatusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Received', 'Pending', 'Cancelled'],
                    datasets: [{
                        data: [
                            summaryData.received_orders || 0,
                            summaryData.pending_orders || 0,
                            summaryData.cancelled_orders || 0
                        ],
                        backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                        borderWidth: 2
                    }]
                },
                options: chartOptions
            });
            
            // Supplier Analysis (Bar Chart)
            const supplierCtx = document.getElementById('secondaryChart').getContext('2d');
            const supplierData = {};
            reportData.forEach(item => {
                const supplier = item.supplier_name || 'Unknown';
                supplierData[supplier] = (supplierData[supplier] || 0) + parseFloat(item.total_amount);
            });
            
            new Chart(supplierCtx, {
                type: 'bar',
                data: {
                    labels: Object.keys(supplierData),
                    datasets: [{
                        label: 'Purchase Amount by Supplier',
                        data: Object.values(supplierData),
                        backgroundColor: '#6f42c1',
                        borderColor: '#5a32a3',
                        borderWidth: 1
                    }]
                },
                options: {
                    ...chartOptions,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    plugins: {
                        ...chartOptions.plugins,
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'Amount: ₱' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            break;
            
        case 'stock_movements':
            // Stock Movement Trends (Line Chart)
            const movementTrendsCtx = document.getElementById('mainChart').getContext('2d');
            const movementData = {'Stock In': {}, 'Stock Out': {}};
            
            reportData.forEach(item => {
                const date = item.movement_date;
                const type = item.movement_type === 'in' ? 'Stock In' : 'Stock Out';
                movementData[type][date] = (movementData[type][date] || 0) + Math.abs(item.quantity);
            });
            
            const allDates = [...new Set([
                ...Object.keys(movementData['Stock In']),
                ...Object.keys(movementData['Stock Out'])
            ])].sort();
            
            new Chart(movementTrendsCtx, {
                type: 'line',
                data: {
                    labels: allDates,
                    datasets: [
                        {
                            label: 'Stock In',
                            data: allDates.map(date => movementData['Stock In'][date] || 0),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            borderWidth: 2,
                            fill: false
                        },
                        {
                            label: 'Stock Out',
                            data: allDates.map(date => movementData['Stock Out'][date] || 0),
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            borderWidth: 2,
                            fill: false
                        }
                    ]
                },
                options: {
                    ...chartOptions,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
            
            // Movement Types (Pie Chart)
            const movementTypesCtx = document.getElementById('secondaryChart').getContext('2d');
            new Chart(movementTypesCtx, {
                type: 'pie',
                data: {
                    labels: ['Stock In', 'Stock Out', 'Adjustments'],
                    datasets: [{
                        data: [
                            summaryData.total_in || 0,
                            summaryData.total_out || 0,
                            summaryData.adjustments || 0
                        ],
                        backgroundColor: ['#28a745', '#dc3545', '#ffc107'],
                        borderWidth: 2
                    }]
                },
                options: chartOptions
            });
            break;
    }
});
</script>

<?php include 'includes/footer.php'; ?>
