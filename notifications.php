<?php
require_once 'config/config.php';
redirect_if_not_logged_in();

$page_title = 'Notifications';

// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: notifications.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($_POST['action'] === 'mark_read' && isset($_POST['notification_id'])) {
            $notification_id = $_POST['notification_id'];
            
            $query = "UPDATE notifications SET is_read = TRUE WHERE notification_id = :notification_id AND (user_id = :user_id OR user_id IS NULL)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':notification_id', $notification_id);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            
            $_SESSION['success'] = 'Notification marked as read.';
            
        } elseif ($_POST['action'] === 'mark_all_read') {
            $query = "UPDATE notifications SET is_read = TRUE WHERE user_id = :user_id AND is_read = FALSE";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            
            $_SESSION['success'] = 'All notifications marked as read.';
            
        } elseif ($_POST['action'] === 'delete' && isset($_POST['notification_id'])) {
            $notification_id = $_POST['notification_id'];
            
            $query = "DELETE FROM notifications WHERE notification_id = :notification_id AND user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':notification_id', $notification_id);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->execute();
            
            $_SESSION['success'] = 'Notification deleted.';
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Notifications Error: " . $exception->getMessage());
    }
    
    header('Location: notifications.php');
    exit();
}

// Handle GET request for marking as read
if (isset($_GET['action']) && $_GET['action'] === 'read' && isset($_GET['id'])) {
    $notification_id = $_GET['id'];
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "UPDATE notifications SET is_read = TRUE WHERE notification_id = :notification_id AND (user_id = :user_id OR user_id IS NULL)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':notification_id', $notification_id);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        
        $_SESSION['info'] = 'Notification marked as read.';
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Notifications Read Error: " . $exception->getMessage());
    }
    
    header('Location: notifications.php');
    exit();
}

// Get notifications
$notifications = [];
$type_filter = $_GET['type'] ?? '';
$read_filter = $_GET['read'] ?? '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT n.*, p.product_name 
              FROM notifications n 
              LEFT JOIN products p ON n.related_id = p.product_id AND n.related_to = 'low_stock'
              WHERE n.user_id = :user_id OR n.user_id IS NULL";
    
    $params = [':user_id' => $_SESSION['user_id']];
    
    if (!empty($type_filter)) {
        $query .= " AND n.type = :type";
        $params[':type'] = $type_filter;
    }
    
    if ($read_filter !== '') {
        $query .= " AND n.is_read = :read";
        $params[':read'] = $read_filter === 'read' ? 1 : 0;
    }
    
    $query .= " ORDER BY n.created_at DESC";
    
    $stmt = $db->prepare($query);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get notification counts
    $query = "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN is_read = FALSE THEN 1 END) as unread,
                COUNT(CASE WHEN type = 'warning' THEN 1 END) as warnings,
                COUNT(CASE WHEN type = 'error' THEN 1 END) as errors
              FROM notifications 
              WHERE user_id = :user_id OR user_id IS NULL";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch(PDOException $exception) {
    $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
    error_log("Notifications List Error: " . $exception->getMessage());
}

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-bell me-2"></i>Notifications
            <?php if ($stats['unread'] > 0): ?>
                <span class="badge bg-danger ms-2"><?php echo $stats['unread']; ?> New</span>
            <?php endif; ?>
        </h1>
        <div>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline-primary" <?php echo $stats['unread'] == 0 ? 'disabled' : ''; ?>>
                    <i class="fas fa-check-double me-2"></i>Mark All as Read
                </button>
            </form>
        </div>
    </div>
    
    <!-- Notification Statistics -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h5 class="card-title">Total</h5>
                    <h2><?php echo number_format($stats['total'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h5 class="card-title">Unread</h5>
                    <h2><?php echo number_format($stats['unread'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h5 class="card-title">Warnings</h5>
                    <h2><?php echo number_format($stats['warnings'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <h5 class="card-title">Errors</h5>
                    <h2><?php echo number_format($stats['errors'] ?? 0); ?></h2>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <select class="form-select" name="type">
                        <option value="">All Types</option>
                        <option value="info" <?php echo $type_filter === 'info' ? 'selected' : ''; ?>>Information</option>
                        <option value="warning" <?php echo $type_filter === 'warning' ? 'selected' : ''; ?>>Warning</option>
                        <option value="error" <?php echo $type_filter === 'error' ? 'selected' : ''; ?>>Error</option>
                        <option value="success" <?php echo $type_filter === 'success' ? 'selected' : ''; ?>>Success</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <select class="form-select" name="read">
                        <option value="">All Status</option>
                        <option value="unread" <?php echo $read_filter === 'unread' ? 'selected' : ''; ?>>Unread</option>
                        <option value="read" <?php echo $read_filter === 'read' ? 'selected' : ''; ?>>Read</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-filter me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Notifications List -->
    <div class="card">
        <div class="card-body">
            <?php if (empty($notifications)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-bell-slash fa-4x mb-3"></i>
                    <h5>No notifications found</h5>
                    <p>You're all caught up! No notifications match your current filters.</p>
                </div>
            <?php else: ?>
                <div class="notification-list">
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item border-bottom pb-3 mb-3 <?php echo !$notification['is_read'] ? 'bg-light' : ''; ?>">
                            <div class="row align-items-start">
                                <div class="col-md-1 text-center">
                                    <div class="notification-icon">
                                        <?php
                                        $icon = match($notification['type']) {
                                            'info' => 'fa-info-circle',
                                            'warning' => 'fa-exclamation-triangle',
                                            'error' => 'fa-times-circle',
                                            'success' => 'fa-check-circle',
                                            default => 'fa-bell'
                                        };
                                        $color = match($notification['type']) {
                                            'info' => 'text-info',
                                            'warning' => 'text-warning',
                                            'error' => 'text-danger',
                                            'success' => 'text-success',
                                            default => 'text-secondary'
                                        };
                                        ?>
                                        <i class="fas <?php echo $icon; ?> <?php echo $color; ?> fa-2x"></i>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-1">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                            <?php if (!$notification['is_read']): ?>
                                                <span class="badge bg-primary ms-2">New</span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-muted">
                                            <?php echo format_date($notification['created_at'], 'M d, Y H:i'); ?>
                                        </small>
                                    </div>
                                    
                                    <p class="mb-2">
                                        <?php echo htmlspecialchars($notification['message']); ?>
                                    </p>
                                    
                                    <?php if ($notification['related_to'] === 'low_stock' && !empty($notification['product_name'])): ?>
                                        <div class="alert alert-warning py-2 mb-2">
                                            <small>
                                                <i class="fas fa-box me-2"></i>
                                                Product: <?php echo htmlspecialchars($notification['product_name']); ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="badge bg-<?php 
                                            echo $notification['type'] === 'error' ? 'danger' : 
                                                 ($notification['type'] === 'warning' ? 'warning' : 
                                                 ($notification['type'] === 'success' ? 'success' : 'info')); 
                                        ?>">
                                            <?php echo ucfirst($notification['type']); ?>
                                        </span>
                                        
                                        <small class="text-muted">
                                            Related to: <?php echo ucfirst($notification['related_to']); ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-3 text-end">
                                    <div class="btn-group-vertical" role="group">
                                        <?php if (!$notification['is_read']): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="action" value="mark_read">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary mb-1" title="Mark as Read">
                                                    <i class="fas fa-check me-1"></i>Mark Read
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <?php if ($notification['related_to'] === 'low_stock' && $notification['related_id']): ?>
                                            <a href="inventory.php" class="btn btn-sm btn-outline-info mb-1">
                                                <i class="fas fa-warehouse me-1"></i>View Inventory
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($notification['user_id'] == $_SESSION['user_id']): ?>
                                            <form method="POST" style="display: inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this notification?')">
                                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['notification_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash me-1"></i>Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
