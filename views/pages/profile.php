<?php
/**
 * Profile Page
 */
require_once dirname(__DIR__, 2) . '/config/app.php';
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
    
    redirect(APP_URL . '/profile');
}

// Re-fetch user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([getCurrentUserId()]);
$user = $stmt->fetch();

$pageTitle = 'Profile';
$currentPage = 'profile';
include dirname(__DIR__) . '/layouts/header.php';
?>

<div class="profile-page-layout">
    <!-- Profile Sidebar (Left) -->
    <div class="profile-sidebar-wrapper">
        <div class="profile-header card">
            <div class="profile-avatar-wrapper">
                <div class="profile-avatar"><?= strtoupper(substr($user['full_name'], 0, 1)) ?></div>
            </div>
            <div class="profile-info text-center">
                <h2><?= sanitize($user['full_name']) ?></h2>
                <span class="badge badge-<?= strtolower($user['role']) ?> mb-2"><?= ucfirst($user['role']) ?></span>
                <p class="text-muted">@<?= sanitize($user['username']) ?></p>
                
                <div class="profile-meta">
                    <div class="meta-item">
                        <i data-lucide="calendar" style="width:16px;height:16px;"></i>
                        <span>Joined <?= date('M j, Y', strtotime($user['created_at'])) ?></span>
                    </div>
                    <?php if ($user['last_login']): ?>
                    <div class="meta-item">
                        <i data-lucide="clock" style="width:16px;height:16px;"></i>
                        <span>Active <?= timeAgo($user['last_login']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Content (Right) -->
    <div class="profile-content-wrapper">
        <!-- Update Profile -->
        <div class="card mb-4 profile-card">
            <div class="card-header border-bottom">
                <div>
                    <h3 class="card-title"><i data-lucide="user" style="width:18px;height:18px;margin-right:8px;"></i> Edit Profile</h3>
                    <p class="text-muted" style="margin-top:4px; font-size:0.85rem;">Update your personal information and email address.</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <div class="input-icon-wrapper">
                                <i data-lucide="user" class="input-icon"></i>
                                <input type="text" class="form-control" name="full_name" value="<?= sanitize($user['full_name']) ?>" required style="padding-left:36px;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <div class="input-icon-wrapper">
                                <i data-lucide="mail" class="input-icon"></i>
                                <input type="email" class="form-control" name="email" value="<?= sanitize($user['email']) ?>" required style="padding-left:36px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-actions text-right mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i data-lucide="save" style="width:16px;height:16px;"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card profile-card">
            <div class="card-header border-bottom">
                <div>
                    <h3 class="card-title"><i data-lucide="shield" style="width:18px;height:18px;margin-right:8px;"></i> Security</h3>
                    <p class="text-muted" style="margin-top:4px; font-size:0.85rem;">Ensure your account is using a long, random password to stay secure.</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-group mb-3">
                        <label class="form-label">Current Password</label>
                        <div class="input-icon-wrapper">
                            <i data-lucide="key" class="input-icon"></i>
                            <input type="password" class="form-control" name="current_password" required placeholder="Enter current password" style="padding-left:36px;">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">New Password</label>
                            <div class="input-icon-wrapper">
                                <i data-lucide="lock" class="input-icon"></i>
                                <input type="password" class="form-control" name="new_password" required minlength="6" placeholder="Min 6 characters" style="padding-left:36px;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-icon-wrapper">
                                <i data-lucide="lock" class="input-icon"></i>
                                <input type="password" class="form-control" name="confirm_password" required minlength="6" placeholder="Repeat new password" style="padding-left:36px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-actions text-right mt-3">
                        <button type="submit" class="btn btn-secondary">
                            <i data-lucide="refresh-cw" style="width:16px;height:16px;"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
