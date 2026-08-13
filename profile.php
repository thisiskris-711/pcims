<?php
/**
 * Profile Page
 */
require_once __DIR__ . '/config/app.php';
requireLogin();

$db = getDB();
$user = $db->prepare("SELECT * FROM users WHERE id = ?")->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (empty($fullName) || empty($email)) {
            flashMessage('Name and email are required.', 'error');
        } else {
            // Check email uniqueness
            $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, getCurrentUserId()]);
            if ($check->fetch()) {
                flashMessage('Email already in use.', 'error');
            } else {
                $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?")->execute([$fullName, $email, getCurrentUserId()]);
                $_SESSION['full_name'] = $fullName;
                flashMessage('Profile updated successfully!');
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([getCurrentUserId()]);
        $userData = $stmt->fetch();
        
        if (!password_verify($currentPassword, $userData['password_hash'])) {
            flashMessage('Current password is incorrect.', 'error');
        } elseif (strlen($newPassword) < 6) {
            flashMessage('New password must be at least 6 characters.', 'error');
        } elseif ($newPassword !== $confirmPassword) {
            flashMessage('New passwords do not match.', 'error');
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, getCurrentUserId()]);
            flashMessage('Password changed successfully!');
        }
    }
    
    redirect(APP_URL . '/profile.php');
}

// Re-fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([getCurrentUserId()]);
$user = $stmt->fetch();

$pageTitle = 'Profile';
$currentPage = 'profile';
include __DIR__ . '/includes/header.php';
?>

<div style="max-width:700px;">
    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
        <div class="profile-info">
            <h2><?= sanitize($user['full_name']) ?></h2>
            <p>@<?= sanitize($user['username']) ?> · <?= ucfirst($user['role']) ?></p>
            <p style="font-size:0.78rem;margin-top:4px;">
                Joined <?= date('M j, Y', strtotime($user['created_at'])) ?>
                <?php if ($user['last_login']): ?>
                · Last login <?= timeAgo($user['last_login']) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
    
    <!-- Update Profile -->
    <div class="card mb-3">
        <div class="card-header">
            <h3 class="card-title">Edit Profile</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="full_name" value="<?= sanitize($user['full_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= sanitize($user['email']) ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes
                </button>
            </form>
        </div>
    </div>
    
    <!-- Change Password -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Change Password</h3>
        </div>
        <div class="card-body">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" class="form-control" name="current_password" required placeholder="Enter current password">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6" placeholder="Min 6 characters">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6" placeholder="Repeat new password">
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary">
                    <i data-lucide="key" style="width:16px;height:16px;"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
