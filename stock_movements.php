<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('staff');

$page_title = 'Stock Movements';

// Get stock movements data
$movements = [];
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$product_filter = $_GET['product_id'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT sm.*, p.product_name, p.product_code, u.full_name, u.username 
              FROM stock_movements sm 
              JOIN products p ON sm.product_id = p.product_id 
              JOIN users u ON sm.user_id = u.user_id 
              WHERE 1=1";
    
    $params = [];
    
    if (!empty($search)) {
        $query .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search OR u.full_name LIKE :search)";
        $search_param = "%$search%";
        $params[':search'] = $search_param;
    }
    
    if (!empty($type_filter)) {
        $query .= " AND sm.movement_type = :type";
        $params[':type'] = $type_filter;
    }
    
    if (!empty($product_filter)) {
        $query .= " AND sm.product_id = :product_id";
        $params[':product_id'] = $product_filter;
    }
    
    if (!empty($date_from)) {
        $query .= " AND DATE(sm.movement_date) >= :date_from";
        $params[':date_from'] = $date_from;
    }
    
    if (!empty($date_to)) {
        $query .= " AND DATE(sm.movement_date) <= :date_to";
        $params[':date_to'] = $date_to;
    }
    
    $query .= " ORDER BY sm.movement_date DESC";
    
    $stmt = $db->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get products for filter dropdown
    $query = "SELECT product_id, product_name, product_code FROM products WHERE status = 'active' ORDER BY product_name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Movement statistics
    $query = "SELECT 
                SUM(CASE WHEN movement_type = 'in' THEN quantity ELSE 0 END) as total_in,
                SUM(CASE WHEN movement_type = 'out' THEN ABS(quantity) ELSE 0 END) as total_out,
                COUNT(*) as total_movements
              FROM stock_movements 
              WHERE DATE(movement_date) BETWEEN :date_from AND :date_to";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':date_from', $date_from);
    $stmt->bindParam(':date_to', $date_to);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $exception) {
    $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
    error_log("Stock Movements Error: " . $exception->getMessage());
}

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-exchange-alt me-2"></i>Stock Movements
        </h1>
        <div>
            <a href="stock_adjustment.php" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>New Adjustment
            </a>
            <a href="stock_movements.php?export=1&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>" class="btn btn-outline-primary">
                <i class="fas fa-download me-2"></i>Export
            </a>
        </div>
    </div>
    
    <!-- Movement Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h5 class="card-title">Stock In</h5>
                    <h2><?php echo number_format($stats['total_in'] ?? 0); ?></h2>
                    <small>Total units received</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Stock Out</h5>
                    <h2><?php echo number_format($stats['total_out'] ?? 0); ?></h2>
                    <small>Total units dispatched</small>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total Movements</h5>
                    <h2><?php echo number_format($stats['total_movements'] ?? 0); ?></h2>
                    <small>Transactions recorded</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <input type="text" class="form-control" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="type">
                        <option value="">All Types</option>
                        <option value="in" <?php echo $type_filter === 'in' ? 'selected' : ''; ?>>Stock In</option>
                        <option value="out" <?php echo $type_filter === 'out' ? 'selected' : ''; ?>>Stock Out</option>
                        <option value="adjustment" <?php echo $type_filter === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                        <option value="transfer" <?php echo $type_filter === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" name="product_id">
                        <option value="">All Products</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo $product['product_id']; ?>" 
                                    <?php echo $product_filter == $product['product_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($product['product_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Movements Table -->
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="movementsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Reference</th>
                            <th>User</th>
                            <th>Notes</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-history fa-3x mb-3"></i>
                                    <p>No stock movements found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td>
                                        <?php echo format_date($movement['movement_date'], 'M d, Y H:i'); ?>
                                        <br><small class="text-muted"><?php echo format_date($movement['movement_date'], 'M d, Y'); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($movement['product_name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($movement['product_code']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $movement['movement_type'] === 'in' ? 'success' : 
                                                 ($movement['movement_type'] === 'out' ? 'danger' : 
                                                 ($movement['movement_type'] === 'adjustment' ? 'warning' : 'info')); 
                                        ?>">
                                            <?php 
                                            echo match($movement['movement_type']) {
                                                'in' => 'Stock In',
                                                'out' => 'Stock Out',
                                                'adjustment' => 'Adjustment',
                                                'transfer' => 'Transfer',
                                                default => ucfirst($movement['movement_type'])
                                            };
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold <?php echo $movement['movement_type'] === 'in' ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $movement['movement_type'] === 'in' ? '+' : '-'; ?>
                                            <?php echo number_format(abs($movement['quantity'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php 
                                            echo match($movement['reference_type']) {
                                                'purchase' => 'Purchase Order',
                                                'sale' => 'Sales Order',
                                                'adjustment' => 'Adjustment',
                                                'transfer' => 'Transfer',
                                                'return' => 'Return',
                                                default => ucfirst($movement['reference_type'])
                                            };
                                            ?>
                                        </span>
                                        <?php if ($movement['reference_id']): ?>
                                            <br><small class="text-muted">#<?php echo $movement['reference_id']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($movement['full_name']); ?>
                                        <br><small class="text-muted">@<?php echo htmlspecialchars($movement['username']); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($movement['notes']): ?>
                                            <span data-bs-toggle="tooltip" title="<?php echo htmlspecialchars($movement['notes']); ?>">
                                                <?php echo htmlspecialchars(substr($movement['notes'], 0, 30)); ?>...
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="product_details.php?id=<?php echo $movement['product_id']; ?>" 
                                               class="btn btn-sm btn-outline-info" title="View Product">
                                                <i class="fas fa-box"></i>
                                            </a>
                                            <?php if (has_permission('admin')): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                    onclick="showMovementDetails(<?php echo htmlspecialchars(json_encode($movement)); ?>)"
                                                    title="View Details">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Movement Details Modal -->
<div class="modal fade" id="movementDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Movement Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="movementDetailsContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
function showMovementDetails(movement) {
    const content = `
        <div class="row">
            <div class="col-md-6">
                <h6>Movement Information</h6>
                <table class="table table-sm">
                    <tr>
                        <th>Movement ID:</th>
                        <td>${movement.movement_id}</td>
                    </tr>
                    <tr>
                        <th>Date:</th>
                        <td>${formatDate(movement.movement_date)}</td>
                    </tr>
                    <tr>
                        <th>Type:</th>
                        <td><span class="badge bg-${getMovementTypeColor(movement.movement_type)}">${movement.movement_type.toUpperCase()}</span></td>
                    </tr>
                    <tr>
                        <th>Quantity:</th>
                        <td class="fw-bold ${movement.movement_type === 'in' ? 'text-success' : 'text-danger'}">
                            ${movement.movement_type === 'in' ? '+' : '-'}${Math.abs(movement.quantity)}
                        </td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6>Product Information</h6>
                <table class="table table-sm">
                    <tr>
                        <th>Product:</th>
                        <td>${movement.product_name}</td>
                    </tr>
                    <tr>
                        <th>Code:</th>
                        <td>${movement.product_code}</td>
                    </tr>
                    <tr>
                        <th>User:</th>
                        <td>${movement.full_name} (@${movement.username})</td>
                    </tr>
                    <tr>
                        <th>Reference:</th>
                        <td>${movement.reference_type} #${movement.reference_id || 'N/A'}</td>
                    </tr>
                </table>
            </div>
        </div>
        ${movement.notes ? `
        <div class="mt-3">
            <h6>Notes</h6>
            <div class="alert alert-info">
                ${movement.notes}
            </div>
        </div>
        ` : ''}
    `;
    
    document.getElementById('movementDetailsContent').innerHTML = content;
    var modal = new bootstrap.Modal(document.getElementById('movementDetailsModal'));
    modal.show();
}

function getMovementTypeColor(type) {
    switch(type) {
        case 'in': return 'success';
        case 'out': return 'danger';
        case 'adjustment': return 'warning';
        case 'transfer': return 'info';
        default: return 'secondary';
    }
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Handle export functionality
<?php if (isset($_GET['export']) && $_GET['export'] == '1'): ?>
    exportTableToCSV('movementsTable', 'stock_movements_<?php echo date('Y-m-d'); ?>.csv');
<?php endif; ?>
</script>
