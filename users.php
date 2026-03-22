<?php
require_once 'config/config.php';
require_once 'includes/security.php';
redirect_if_not_logged_in();
redirect_if_no_permission('admin');

$page_title = 'User Management';
$action = $_GET['action'] ?? 'list';
$user_id = $_GET['id'] ?? null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: users.php');
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if ($action === 'add') {
            // Check if username already exists
            $query = "SELECT user_id FROM users WHERE username = :username";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $_POST['username']);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                $_SESSION['error'] = 'Username already exists.';
                header('Location: users.php?action=add');
                exit();
            }
            
            // Check if email already exists
            if (!empty($_POST['email'])) {
                $query = "SELECT user_id FROM users WHERE email = :email";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $_POST['email']);
                $stmt->execute();
                
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Email already exists.';
                    header('Location: users.php?action=add');
                    exit();
                }
            }
            
            // Validate password strength
            $password_errors = validate_password_strength($_POST['password']);
            if (!empty($password_errors)) {
                $_SESSION['error'] = 'Password requirements not met: ' . implode(', ', $password_errors);
                header('Location: users.php?action=add');
                exit();
            }
            
            // Add new user
            $query = "INSERT INTO users (username, password, full_name, email, phone, role, status) 
                      VALUES (:username, :password, :full_name, :email, :phone, :role, :status)";
            
            $stmt = $db->prepare($query);
            
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            $stmt->bindParam(':username', $_POST['username']);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':full_name', $_POST['full_name']);
            $stmt->bindParam(':email', $_POST['email']);
            $stmt->bindParam(':phone', $_POST['phone']);
            $stmt->bindParam(':role', $_POST['role']);
            $stmt->bindParam(':status', $_POST['status']);
            
            $stmt->execute();
            
            $_SESSION['success'] = 'User added successfully!';
            header('Location: users.php');
            exit();
            
        } elseif ($action === 'edit' && $user_id) {
            // Check if username already exists (excluding current user)
            $query = "SELECT user_id FROM users WHERE username = :username AND user_id != :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':username', $_POST['username']);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                $_SESSION['error'] = 'Username already exists.';
                header('Location: users.php?action=edit&id=' . $user_id);
                exit();
            }
            
            // Check if email already exists (excluding current user)
            if (!empty($_POST['email'])) {
                $query = "SELECT user_id FROM users WHERE email = :email AND user_id != :user_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $_POST['email']);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Email already exists.';
                    header('Location: users.php?action=edit&id=' . $user_id);
                    exit();
                }
            }
            
            // Update user
            $query = "UPDATE users SET username = :username, full_name = :full_name, 
                      email = :email, phone = :phone, role = :role, status = :status";
            
            $params = [
                ':username' => $_POST['username'],
                ':full_name' => $_POST['full_name'],
                ':email' => $_POST['email'],
                ':phone' => $_POST['phone'],
                ':role' => $_POST['role'],
                ':status' => $_POST['status']
            ];
            
            // Update password if provided
            if (!empty($_POST['password'])) {
                // Validate password strength
                $password_errors = validate_password_strength($_POST['password']);
                if (!empty($password_errors)) {
                    $_SESSION['error'] = 'Password requirements not met: ' . implode(', ', $password_errors);
                    header('Location: users.php?action=edit&id=' . $user_id);
                    exit();
                }
                
                $query .= ", password = :password";
                $params[':password'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
            }
            
            $query .= " WHERE user_id = :user_id";
            $params[':user_id'] = $user_id;
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            
            $_SESSION['success'] = 'User updated successfully!';
            header('Location: users.php');
            exit();
            
        } elseif ($action === 'delete' && $user_id) {
            // Prevent deletion of the current user
            if ($user_id == $_SESSION['user_id']) {
                $_SESSION['error'] = 'You cannot delete your own account.';
                header('Location: users.php');
                exit();
            }
            
            // Delete user
            $query = "DELETE FROM users WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            $_SESSION['success'] = 'User deleted successfully!';
            header('Location: users.php');
            exit();
            
        } elseif ($action === 'reset_password' && $user_id) {
            // Reset user password with strong random password
            $new_password = generate_strong_random_password();
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $query = "UPDATE users SET password = :password WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            $_SESSION['success'] = "Password reset successfully! New password: $new_password";
            header('Location: users.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: users.php');
        exit();
    }
}

// Get user data for editing
$user = null;
if ($action === 'edit' && $user_id) {
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT * FROM users WHERE user_id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $_SESSION['error'] = 'User not found.';
            header('Location: users.php');
            exit();
        }
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        header('Location: users.php');
        exit();
    }
}

// Handle API request for user activity
if ($action === 'get_activity') {
    $user_id = $_GET['user_id'] ?? '';
    $csrf_token = $_GET['csrf_token'] ?? '';
    
    header('Content-Type: application/json');
    
    if (!verify_csrf_token($csrf_token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit();
    }
    
    if (empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit();
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Get user activities
        $query = "SELECT action, details, created_at 
                  FROM activity_logs 
                  WHERE user_id = :user_id 
                  ORDER BY created_at DESC 
                  LIMIT 50";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'activities' => $activities
        ]);
        
    } catch(PDOException $exception) {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $exception->getMessage()
        ]);
    }
    exit();
}

if ($action === 'list') {
    // Get users list
    $users = [];
    $search = $_GET['search'] ?? '';
    $role_filter = $_GET['role'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT u.*,
                         (SELECT COUNT(*) FROM sales_orders WHERE created_by = u.user_id) as sales_count,
                         (SELECT COUNT(*) FROM purchase_orders WHERE created_by = u.user_id) as purchase_count,
                         u.created_at as last_activity
                  FROM users u 
                  WHERE 1=1";
        $params = [];
        
        if (!empty($search)) {
            $query .= " AND (u.username LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        if (!empty($role_filter)) {
            $query .= " AND u.role = :role";
            $params[':role'] = $role_filter;
        }
        
        if (!empty($status_filter)) {
            $query .= " AND u.status = :status";
            $params[':status'] = $status_filter;
        }
        
        $query .= " ORDER BY u.created_at DESC";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
        error_log("Users List Error: " . $exception->getMessage());
    }
    
    include 'includes/header.php';
    ?>
    
    <style>
/* Users Page Responsive Styles */
@media (max-width: 1200px) {
    .users-filter-card {
        margin-bottom: 1rem;
    }
    
    .users-table-container {
        font-size: 0.9rem;
    }
    
    .user-stats-card {
        margin-bottom: 0.75rem;
    }
}

@media (max-width: 992px) {
    .users-filter-card .card-body {
        padding: 1rem;
    }
    
    .users-filter-card .row {
        gap: 0.5rem;
    }
    
    .users-table-container {
        font-size: 0.85rem;
    }
    
    .users-table-container .table th,
    .users-table-container .table td {
        padding: 0.75rem 0.5rem;
    }
    
    .user-stats-card .card-body {
        padding: 1rem;
    }
    
    .user-stats-card .h2 {
        font-size: 1.5rem;
    }
    
    .btn-group {
        flex-direction: column;
        gap: 0.25rem;
    }
    
    .btn-group .btn {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .users-page-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    .users-page-header h1 {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
    }
    
    .users-filter-card .row {
        flex-direction: column;
    }
    
    .users-filter-card .col-md-3,
    .users-filter-card .col-md-4 {
        flex: 0 0 100%;
        margin-bottom: 0.75rem;
    }
    
    .users-filter-card .btn {
        width: 100%;
        margin-bottom: 0.5rem;
    }
    
    .users-table-container {
        font-size: 0.8rem;
    }
    
    .users-table-container .table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
    }
    
    .users-table-container .table th,
    .users-table-container .table td {
        padding: 0.5rem 0.375rem;
        min-width: 120px;
    }
    
    .users-table-container .table .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }
    
    .user-stats-card {
        margin-bottom: 1rem;
    }
    
    .user-stats-card .card-body {
        padding: 0.75rem;
    }
    
    .user-stats-card .h2 {
        font-size: 1.25rem;
    }
    
    .user-stats-card .card-title {
        font-size: 0.8rem;
    }
}

@media (max-width: 576px) {
    .users-page-header {
        text-align: center;
        margin-bottom: 1.5rem;
    }
    
    .users-page-header h1 {
        font-size: 1.25rem;
        margin-bottom: 0.5rem;
    }
    
    .users-filter-card .card-header {
        text-align: center;
        padding: 0.75rem;
    }
    
    .users-filter-card .card-body {
        padding: 0.75rem;
    }
    
    .users-table-container {
        font-size: 0.75rem;
        max-height: 400px;
        overflow-y: auto;
    }
    
    .users-table-container .table th,
    .users-table-container .table td {
        padding: 0.375rem 0.25rem;
        min-width: 100px;
        font-size: 0.7rem;
    }
    
    .users-table-container .table .btn {
        padding: 0.2rem 0.4rem;
        font-size: 0.7rem;
        margin: 0.1rem;
    }
    
    .user-stats-card {
        margin-bottom: 0.75rem;
    }
    
    .user-stats-card .card-body {
        padding: 0.5rem;
    }
    
    .user-stats-card .h2 {
        font-size: 1.1rem;
    }
    
    .user-stats-card .card-title {
        font-size: 0.75rem;
    }
    
    .user-form-card {
        margin-bottom: 1rem;
    }
    
    .user-form-card .card-body {
        padding: 0.75rem;
    }
    
    .user-form-card .form-control,
    .user-form-card .form-select {
        font-size: 0.8rem;
        padding: 0.5rem;
    }
    
    .user-form-card .row {
        gap: 0.5rem;
    }
    
    .user-form-card .col-md-6 {
        margin-bottom: 0.5rem;
    }
}

/* Touch-friendly improvements */
@media (hover: none) and (pointer: coarse) {
    .users-table-container .table .btn {
        min-height: 44px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .user-form-card .form-control,
    .user-form-card .form-select {
        min-height: 44px;
    }
}

/* Landscape mobile adjustments */
@media (max-width: 768px) and (orientation: landscape) {
    .users-table-container {
        max-height: 300px;
    }
    
    .users-filter-card .row {
        flex-direction: row;
        flex-wrap: wrap;
    }
    
    .users-filter-card .col-md-3,
    .users-filter-card .col-md-4 {
        flex: 0 0 50%;
    }
}

/* Print styles */
@media print {
    .users-filter-card,
    .btn-group {
        display: none !important;
    }
    
    .user-stats-card,
    .user-form-card {
        break-inside: avoid;
        box-shadow: none !important;
        border: 1px solid #dee2e6 !important;
    }
    
    .users-table-container .table {
        page-break-inside: auto;
    }
    
    .users-table-container .table tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-users me-2"></i>User Management
            </h1>
            <a href="users.php?action=add" class="btn btn-primary">
                <i class="fas fa-user-plus me-2"></i>Add New User
            </a>
        </div>
        
        <!-- User Statistics -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Total Users</h5>
                        <h2><?php echo count($users); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">Active</h5>
                        <h2>
                            <?php 
                            $active = array_filter($users, function($u) { return $u['status'] === 'active'; });
                            echo count($active);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-secondary text-white">
                    <div class="card-body">
                        <h5 class="card-title">Inactive</h5>
                        <h2>
                            <?php 
                            $inactive = array_filter($users, function($u) { return $u['status'] === 'inactive'; });
                            echo count($inactive);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">Admins</h5>
                        <h2>
                            <?php 
                            $admins = array_filter($users, function($u) { return $u['role'] === 'admin'; });
                            echo count($admins);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">Managers</h5>
                        <h2>
                            <?php 
                            $managers = array_filter($users, function($u) { return $u['role'] === 'manager'; });
                            echo count($managers);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-dark text-white">
                    <div class="card-body">
                        <h5 class="card-title">Staff</h5>
                        <h2>
                            <?php 
                            $staff = array_filter($users, function($u) { return $u['role'] === 'staff'; });
                            echo count($staff);
                            ?>
                        </h2>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control" name="search" placeholder="Search users..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="manager" <?php echo $role_filter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                            <option value="staff" <?php echo $role_filter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                            <option value="viewer" <?php echo $role_filter === 'viewer' ? 'selected' : ''; ?>>Viewer</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary w-100">
                            <i class="fas fa-search me-2"></i>Search
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Users Table -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="usersTable">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Contact Info</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Activity</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-users fa-3x mb-3"></i>
                                        <p>No users found.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr class="<?php echo $user['user_id'] == $_SESSION['user_id'] ? 'table-info' : ''; ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                    <?php echo strtoupper(substr($user['full_name'], 0, 2)); ?>
                                                </div>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                                    <br><small class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></small>
                                                    <?php if ($user['user_id'] == $_SESSION['user_id']): ?>
                                                        <span class="badge bg-info ms-2">You</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($user['email']): ?>
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($user['email']); ?>
                                                <br>
                                            <?php endif; ?>
                                            <?php if ($user['phone']): ?>
                                                <i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($user['phone']); ?>
                                            <?php endif; ?>
                                            <?php if (!$user['email'] && !$user['phone']): ?>
                                                <span class="text-muted">No contact info</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $user['role'] === 'admin' ? 'danger' : 
                                                     ($user['role'] === 'manager' ? 'warning' : 
                                                     ($user['role'] === 'staff' ? 'info' : 'secondary')); 
                                            ?>">
                                                <?php echo ucfirst($user['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['last_activity']): ?>
                                                <small><?php echo format_date($user['last_activity'], 'M d, Y H:i'); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">Never</span>
                                            <?php endif; ?>
                                            <br><small class="text-muted"><?php echo ($user['sales_count'] ?? 0) + ($user['purchase_count'] ?? 0); ?> activities</small>
                                        </td>
                                        <td>
                                            <small><?php echo format_date($user['created_at'], 'M d, Y'); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button onclick="viewActivityLog(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')" 
                                                        class="btn btn-sm btn-outline-info" title="View Activity Log">
                                                    <i class="fas fa-history"></i>
                                                </button>
                                                <a href="users.php?action=edit&id=<?php echo $user['user_id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button onclick="resetPassword(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                        class="btn btn-sm btn-outline-warning" title="Reset Password">
                                                    <i class="fas fa-key"></i>
                                                </button>
                                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                                <button onclick="return confirmDelete(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                        class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
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
    
    <script>
    function confirmDelete(userId, username) {
        if (confirm('Are you sure you want to delete this user?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'users.php?action=delete&id=' + userId;
            
            const csrfToken = document.createElement('input');
            csrfToken.type = 'hidden';
            csrfToken.name = 'csrf_token';
            csrfToken.value = '<?php echo generate_csrf_token(); ?>';
            form.appendChild(csrfToken);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function resetPassword(userId, username) {
        const newPassword = prompt('Enter new password for ' + username + ':');
        if (newPassword && newPassword.length >= 6) {
            if (confirm('Are you sure you want to reset the password for ' + username + '?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'users.php?action=reset_password&id=' + userId;
                
                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = 'csrf_token';
                csrfToken.value = '<?php echo generate_csrf_token(); ?>';
                form.appendChild(csrfToken);
                
                const passwordField = document.createElement('input');
                passwordField.type = 'hidden';
                passwordField.name = 'password';
                passwordField.value = newPassword;
                form.appendChild(passwordField);
                
                document.body.appendChild(form);
                form.submit();
            }
        } else if (newPassword) {
            alert('Password must be at least 6 characters long.');
        }
    }
    </script>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif (in_array($action, ['add', 'edit'])) {
    include 'includes/header.php';
    ?>
    
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3">
                <i class="fas fa-users me-2"></i><?php echo $action === 'add' ? 'Add New User' : 'Edit User'; ?>
            </h1>
            <a href="users.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Users
            </a>
        </div>
        
        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username *</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                                <div class="form-text">Unique username for login.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="full_name" class="form-label">Full Name *</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    Password <?php echo $action === 'add' ? '*' : '(leave blank to keep current)'; ?>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password" name="password" 
                                           <?php echo $action === 'add' ? 'required' : ''; ?>>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword" 
                                            title="Toggle password visibility">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </button>
                                </div>
                                <div class="form-text">Minimum 6 characters.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="role" class="form-label">Role *</label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin" <?php echo ($user['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin - Full system access</option>
                                    <option value="manager" <?php echo ($user['role'] ?? '') === 'manager' ? 'selected' : ''; ?>>Manager - Product & inventory management</option>
                                    <option value="staff" <?php echo ($user['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff - Basic inventory operations</option>
                                    <option value="viewer" <?php echo ($user['role'] ?? '') === 'viewer' ? 'selected' : ''; ?>>Viewer - Read-only access</option>
                                </select>
                                <div class="form-text">Select appropriate user role.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?php echo ($user['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo ($user['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <div class="form-text">Inactive users cannot log in.</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Role Permissions Info -->
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>Role Permissions:</h6>
                        <div class="row">
                            <div class="col-md-3">
                                <strong>Admin:</strong><br>
                                <small>Full system access, user management, all features</small>
                            </div>
                            <div class="col-md-3">
                                <strong>Manager:</strong><br>
                                <small>Products, inventory, suppliers, categories, orders</small>
                            </div>
                            <div class="col-md-3">
                                <strong>Staff:</strong><br>
                                <small>View/update inventory, stock movements, orders</small>
                            </div>
                            <div class="col-md-3">
                                <strong>Viewer:</strong><br>
                                <small>Read-only access to all modules</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="d-flex justify-content-end gap-2">
                                <a href="users.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i><?php echo $action === 'add' ? 'Add User' : 'Update User'; ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($action === 'edit' && $user): ?>
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0">User Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>User ID:</strong> <?php echo $user['user_id']; ?></p>
                        <p><strong>Created:</strong> <?php echo format_date($user['created_at']); ?></p>
                        <p><strong>Status:</strong> <?php echo ucfirst($user['status']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Account Type:</strong> <?php echo ucfirst($user['role']); ?></p>
                        <p><strong>Contact:</strong> <?php echo $user['email'] ?: $user['phone'] ?: 'No contact info'; ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <?php include 'includes/footer.php'; ?>
    
    <?php
} elseif ($action === 'reset_password' && $user_id) {
    // Handle password reset
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf_token = $_POST['csrf_token'] ?? '';
        
        if (!verify_csrf_token($csrf_token)) {
            $_SESSION['error'] = 'Invalid request. Please try again.';
            header('Location: users.php');
            exit();
        }
        
        try {
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "UPDATE users SET password = :password WHERE user_id = :user_id";
            $stmt = $db->prepare($query);
            $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            $_SESSION['success'] = 'Password reset successfully!';
            header('Location: users.php');
            exit();
            
        } catch(PDOException $exception) {
            $_SESSION['error'] = 'Database error: ' . $exception->getMessage();
            error_log("Password Reset Error: " . $exception->getMessage());
            header('Location: users.php');
            exit();
        }
    }
} else {
    header('Location: users.php');
    exit();
}
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Handle form submissions with AJAX for better UX
    const userForm = document.getElementById('userForm');
    if (userForm) {
        userForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            // Show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            
            fetch(this.action, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    showAlert('success', data.success || 'User saved successfully!');
                    
                    // If it's an add form, reset it
                    if (window.location.search.includes('action=add')) {
                        this.reset();
                    }
                    
                    // Redirect after a short delay
                    setTimeout(() => {
                        window.location.href = 'users.php';
                    }, 1500);
                } else {
                    showAlert('danger', data.error || 'An error occurred. Please try again.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'An error occurred. Please try again.');
            })
            .finally(() => {
                // Restore button state
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    }

    // Handle delete confirmations
    const deleteButtons = document.querySelectorAll('.delete-user');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const userId = this.dataset.userId;
            const userName = this.dataset.userName;
            
            if (confirm(`Are you sure you want to delete user "${userName}"? This action cannot be undone.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'users.php?action=delete&id=' + userId;
                
                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = 'csrf_token';
                csrfToken.value = '<?php echo generate_csrf_token(); ?>';
                form.appendChild(csrfToken);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    });

    // Handle password reset
    const resetButtons = document.querySelectorAll('.reset-password');
    resetButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            
            const userId = this.dataset.userId;
            const userName = this.dataset.userName;
            
            if (confirm(`Are you sure you want to reset password for user "${userName}"? A new temporary password will be generated.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'users.php?action=reset_password&id=' + userId;
                
                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = 'csrf_token';
                csrfToken.value = '<?php echo generate_csrf_token(); ?>';
                form.appendChild(csrfToken);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    });

    // Handle filter form submissions
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const params = new URLSearchParams(formData);
            
            // Redirect with filter parameters
            window.location.href = 'users.php?' + params.toString();
        });
    }

    // Clear filters
    const clearFiltersBtn = document.getElementById('clearFilters');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function(e) {
            e.preventDefault();
            window.location.href = 'users.php';
        });
    }

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        let searchTimeout;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            
            searchTimeout = setTimeout(() => {
                const searchValue = this.value.trim();
                if (searchValue.length >= 2 || searchValue.length === 0) {
                    // Trigger search
                    const form = document.createElement('form');
                    form.method = 'GET';
                    form.action = 'users.php';
                    
                    const searchField = document.createElement('input');
                    searchField.type = 'hidden';
                    searchField.name = 'search';
                    searchField.value = searchValue;
                    form.appendChild(searchField);
                    
                    // Preserve other filters
                    const roleFilter = document.getElementById('roleFilter');
                    const statusFilter = document.getElementById('statusFilter');
                    
                    if (roleFilter && roleFilter.value) {
                        const roleField = document.createElement('input');
                        roleField.type = 'hidden';
                        roleField.name = 'role';
                        roleField.value = roleFilter.value;
                        form.appendChild(roleField);
                    }
                    
                    if (statusFilter && statusFilter.value) {
                        const statusField = document.createElement('input');
                        statusField.type = 'hidden';
                        statusField.name = 'status';
                        statusField.value = statusFilter.value;
                        form.appendChild(statusField);
                    }
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            }, 500);
        });
    }

    // Password strength indicator
    const passwordInput = document.getElementById('password');
    const passwordStrength = document.getElementById('passwordStrength');
    
    if (passwordInput && passwordStrength) {
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let feedback = '';
            
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            // Update strength indicator
            passwordStrength.className = 'progress-bar';
            
            if (password.length === 0) {
                passwordStrength.style.width = '0%';
                feedback = '';
            } else if (strength <= 2) {
                passwordStrength.style.width = '33%';
                passwordStrength.classList.add('bg-danger');
                feedback = 'Weak';
            } else if (strength <= 4) {
                passwordStrength.style.width = '66%';
                passwordStrength.classList.add('bg-warning');
                feedback = 'Medium';
            } else {
                passwordStrength.style.width = '100%';
                passwordStrength.classList.add('bg-success');
                feedback = 'Strong';
            }
            
            // Update feedback text
            const feedbackElement = document.getElementById('passwordFeedback');
            if (feedbackElement) {
                feedbackElement.textContent = feedback;
            }
        });
    }

    // Password visibility toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordField = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    if (togglePassword && passwordField && toggleIcon) {
        togglePassword.addEventListener('click', function() {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            
            // Update icon
            if (type === 'text') {
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
                togglePassword.setAttribute('title', 'Hide password');
            } else {
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
                togglePassword.setAttribute('title', 'Show password');
            }
        });
    }
});

// Helper function to show alerts
function showAlert(type, message) {
    const alertContainer = document.getElementById('alertContainer');
    if (alertContainer) {
        alertContainer.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => {
            const alert = alertContainer.querySelector('.alert');
            if (alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }
        }, 5000);
    }
}

// Activity Log Modal and Functions
function viewActivityLog(userId, userName) {
    // Set user name in modal
    document.getElementById('activityLogUserName').textContent = userName;
    
    // Show loading state
    const activityLogBody = document.getElementById('activityLogBody');
    activityLogBody.innerHTML = `
        <tr>
            <td colspan="3" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2 mb-0">Loading activity log...</p>
            </td>
        </tr>
    `;
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('activityLogModal'));
    modal.show();
    
    // Load activity data
    loadUserActivity(userId);
}

function loadUserActivity(userId) {
    fetch('users.php?action=get_activity&user_id=' + userId + '&csrf_token=<?php echo generate_csrf_token(); ?>')
        .then(response => response.json())
        .then(data => {
            const activityLogBody = document.getElementById('activityLogBody');
            
            if (data.success && data.activities.length > 0) {
                activityLogBody.innerHTML = data.activities.map(activity => `
                    <tr>
                        <td>
                            <strong>${escapeHtml(activity.action)}</strong>
                            ${activity.details ? `<br><small class="text-muted">${escapeHtml(activity.details)}</small>` : ''}
                        </td>
                        <td><small>${formatActivityDate(activity.created_at)}</small></td>
                        <td><small class="text-muted">${getRelativeTime(activity.created_at)}</small></td>
                    </tr>
                `).join('');
            } else {
                activityLogBody.innerHTML = `
                    <tr>
                        <td colspan="3" class="text-center py-4">
                            <i class="fas fa-history fa-2x mb-2 text-muted"></i>
                            <p class="text-muted mb-0">No activity found for this user.</p>
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading activity:', error);
            const activityLogBody = document.getElementById('activityLogBody');
            activityLogBody.innerHTML = `
                <tr>
                    <td colspan="3" class="text-center py-4">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2 text-danger"></i>
                        <p class="text-danger mb-0">Error loading activity log.</p>
                    </td>
                </tr>
            `;
        });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatActivityDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function getRelativeTime(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return diffMins + ' minute' + (diffMins > 1 ? 's' : '') + ' ago';
    if (diffHours < 24) return diffHours + ' hour' + (diffHours > 1 ? 's' : '') + ' ago';
    if (diffDays < 7) return diffDays + ' day' + (diffDays > 1 ? 's' : '') + ' ago';
    return date.toLocaleDateString();
}

// Helper function to generate random password (if needed)
function generateRandomPassword(length = 8) {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < length; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return password;
}
</script>

<!-- Activity Log Modal -->
<div class="modal fade" id="activityLogModal" tabindex="-1" aria-labelledby="activityLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="activityLogModalLabel">
                    <i class="fas fa-history me-2"></i>Activity Log
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <strong>User:</strong> <span id="activityLogUserName"></span>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Activity</th>
                                <th>Date & Time</th>
                                <th>Relative Time</th>
                            </tr>
                        </thead>
                        <tbody id="activityLogBody">
                            <!-- Activities will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
