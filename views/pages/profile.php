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
    verifyCSRFToken();
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
        } elseif (strlen($newPassword) < 8 || !preg_match('/[0-9\W]/', $newPassword)) {
            flashMessage('New password must be at least 8 characters and contain at least one number or symbol.', 'error');
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

$preferences = json_decode($user['preferences'] ?? '{}', true) ?: [];
$theme = $preferences['theme'] ?? 'light';
$notifySales = $preferences['notify_email_sales'] ?? false;
$notifyInventory = $preferences['notify_email_inventory'] ?? false;

$pageTitle = 'Profile';
$currentPage = 'profile';
include dirname(__DIR__) . '/layouts/header.php';

// Format joined date correctly
$joinedDate = '—';
if (!empty($user['created_at']) && $user['created_at'] !== '0000-00-00 00:00:00' && strtotime($user['created_at']) > 0) {
    $joinedDate = date('M j, Y', strtotime($user['created_at']));
}
?>

<div class="profile-page-layout">
    <!-- Profile Sidebar (Left) -->
    <div class="profile-sidebar-wrapper">
        <div class="profile-header card" style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); border-radius: 12px; padding: 32px 24px; text-align: center;">
            <div class="profile-avatar-wrapper" style="margin: 0 auto 20px;">
                <div class="profile-avatar" style="width: 80px; height: 80px; font-size: 2rem; background: var(--accent-primary); color: #fff; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: 600; margin: 0 auto; box-shadow: 0 4px 12px rgba(154, 0, 2, 0.2);">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
            </div>
            <div class="profile-info text-center">
                <h2 style="font-weight: 600; font-size: 1.25rem; margin-bottom: 4px; color: var(--text-color);"><?= sanitize($user['full_name']) ?></h2>
                <p class="text-muted" style="margin-bottom: 12px; font-size: 0.95rem;">@<?= sanitize($user['username']) ?></p>
                
                <span class="badge badge-<?= strtolower($user['role']) ?>" style="margin-bottom: 24px; display: inline-block; padding: 6px 12px; font-weight: 500; font-size: 0.85rem; border-radius: 20px;"><?= ucfirst($user['role']) ?></span>
                
                <div class="profile-meta" style="display: flex; flex-direction: column; gap: 12px; text-align: left; padding-top: 20px; border-top: 1px solid var(--border-color); font-size: 0.9rem; color: var(--text-muted);">
                    <div class="meta-item" style="display: flex; align-items: center; gap: 10px;">
                        <i data-lucide="check-circle" style="width:18px;height:18px; color: #10b981;"></i>
                        <span>Account Status: <strong style="color: var(--text-color); font-weight: 500;"><?= ucfirst($user['status'] ?? 'Active') ?></strong></span>
                    </div>
                    <div class="meta-item" style="display: flex; align-items: center; gap: 10px;">
                        <i data-lucide="calendar" style="width:18px;height:18px;"></i>
                        <span>Joined: <strong style="color: var(--text-color); font-weight: 500;"><?= $joinedDate ?></strong></span>
                    </div>
                    <?php if ($user['last_login']): ?>
                    <div class="meta-item" style="display: flex; align-items: center; gap: 10px;">
                        <i data-lucide="clock" style="width:18px;height:18px;"></i>
                        <span>Last Login: <strong style="color: var(--text-color); font-weight: 500;"><?= timeAgo($user['last_login']) ?></strong></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Content (Right) -->
    <div class="profile-content-wrapper" style="flex: 1; max-width: 800px;">
        <!-- Update Profile -->
        <div class="card mb-4 profile-card" style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); border-radius: 12px; overflow: hidden; margin-bottom: 32px;">
            <div class="card-header border-bottom" style="padding: 24px;">
                <div>
                    <h3 class="card-title" style="font-weight: 600; font-size: 1.25rem; display: flex; align-items: center; color: var(--text-color);">
                        <i data-lucide="user" style="width:20px;height:20px;margin-right:10px; color: var(--accent-primary);"></i> Edit Profile
                    </h3>
                    <p class="text-muted" style="margin-top:6px; font-size:0.9rem; margin-bottom: 0;">Update your personal information and email address.</p>
                </div>
            </div>
            <div class="card-body" style="padding: 24px;">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div class="form-group mb-0">
                            <label class="form-label" for="full_name" style="font-weight: 500; margin-bottom: 8px;">Full Name</label>
                            <input type="text" id="full_name" class="form-control" name="full_name" value="<?= sanitize($user['full_name'] ?? '') ?>" autocomplete="name" required style="height: 48px; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; display: block;">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="email" style="font-weight: 500; margin-bottom: 8px;">Email Address</label>
                            <input type="email" id="email" class="form-control" name="email" value="<?= sanitize($user['email'] ?? '') ?>" autocomplete="email" required style="height: 48px; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; display: block;">
                            <small class="text-muted" style="display: block; margin-top: 6px; font-size: 0.8rem;">Changing this may require re-verification.</small>
                        </div>
                    </div>
                    
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                        <div class="form-group mb-0">
                            <label class="form-label" for="username" style="font-weight: 500; margin-bottom: 8px;">Username</label>
                            <input type="text" id="username" class="form-control" value="<?= sanitize($user['username'] ?? '') ?>" autocomplete="username" disabled style="height: 48px; border-radius: 8px; font-size: 1rem; cursor: not-allowed; width: 100%; box-sizing: border-box; display: block; opacity: 0.7;">
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="role" style="font-weight: 500; margin-bottom: 8px;">Role</label>
                            <input type="text" id="role" class="form-control" value="<?= ucfirst($user['role'] ?? '') ?>" disabled style="height: 48px; border-radius: 8px; font-size: 1rem; cursor: not-allowed; width: 100%; box-sizing: border-box; display: block; opacity: 0.7;">
                        </div>
                    </div>

                    <div class="form-actions" style="text-align: right; border-top: 1px solid var(--border-color); padding-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="saveProfileBtn" disabled style="height: 44px; padding: 0 24px; font-weight: 600; border-radius: 6px; background-color: #9A0002; border-color: #9A0002; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s ease; opacity: 0.5; cursor: not-allowed;">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="card profile-card" style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); border-radius: 12px; overflow: hidden;">
            <div class="card-header border-bottom" style="padding: 24px;">
                <div>
                    <h3 class="card-title" style="font-weight: 600; font-size: 1.25rem; display: flex; align-items: center; color: var(--text-color);">
                        <i data-lucide="shield" style="width:20px;height:20px;margin-right:10px; color: var(--accent-primary);"></i> Security
                    </h3>
                    <p class="text-muted" style="margin-top:6px; font-size:0.9rem; margin-bottom: 0;">Ensure your account is using a long, random password to stay secure.</p>
                </div>
            </div>
            <div class="card-body" style="padding: 24px;">
                <form method="POST" action="" id="passwordForm">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label" for="current_password" style="font-weight: 500; margin-bottom: 8px;">Current Password</label>
                        <div style="position: relative;">
                            <input type="password" id="current_password" class="form-control" name="current_password" autocomplete="current-password" required placeholder="Enter current password" style="height: 48px; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; display: block; padding-right: 40px;">
                            <button type="button" class="toggle-password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; display: flex;" tabindex="-1">
                                <i data-lucide="eye" style="width:20px;height:20px;"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px;">
                        <div class="form-group mb-0">
                            <label class="form-label" for="new_password" style="font-weight: 500; margin-bottom: 8px;">New Password</label>
                            <div style="position: relative;">
                                <input type="password" id="new_password" class="form-control" name="new_password" autocomplete="new-password" required minlength="8" placeholder="New password" style="height: 48px; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; display: block; padding-right: 40px;">
                                <button type="button" class="toggle-password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; display: flex;" tabindex="-1">
                                    <i data-lucide="eye" style="width:20px;height:20px;"></i>
                                </button>
                            </div>
                            <small class="text-muted" style="display: block; margin-top: 6px; font-size: 0.8rem;">Requirement: Minimum 8 characters, at least 1 number/symbol.</small>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" for="confirm_password" style="font-weight: 500; margin-bottom: 8px;">Confirm New Password</label>
                            <div style="position: relative;">
                                <input type="password" id="confirm_password" class="form-control" name="confirm_password" autocomplete="new-password" required minlength="8" placeholder="Repeat new password" style="height: 48px; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; display: block; padding-right: 40px;">
                                <button type="button" class="toggle-password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; display: flex;" tabindex="-1">
                                    <i data-lucide="eye" style="width:20px;height:20px;"></i>
                                </button>
                            </div>
                            <small id="passwordMatchError" style="display: none; color: #ef4444; margin-top: 6px; font-size: 0.8rem;">Passwords do not match.</small>
                        </div>
                    </div>
                    <div class="form-actions" style="text-align: right; border-top: 1px solid var(--border-color); padding-top: 20px;">
                        <button type="submit" class="btn btn-secondary" id="savePasswordBtn" disabled style="height: 44px; padding: 0 24px; font-weight: 500; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s ease; opacity: 0.5; cursor: not-allowed;">
                            Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Preferences -->
        <div class="card profile-card" style="box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06); border-radius: 12px; overflow: hidden; margin-top: 32px;">
            <div class="card-header border-bottom" style="padding: 24px;">
                <div>
                    <h3 class="card-title" style="font-weight: 600; font-size: 1.25rem; display: flex; align-items: center; color: var(--text-color);">
                        <i data-lucide="sliders" style="width:20px;height:20px;margin-right:10px; color: var(--accent-primary);"></i> Preferences
                    </h3>
                    <p class="text-muted" style="margin-top:6px; font-size:0.9rem; margin-bottom: 0;">Customize your interface and notification settings.</p>
                </div>
            </div>
            <div class="card-body" style="padding: 24px;">
                <form id="preferencesForm">
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label class="form-label" for="pref_theme" style="font-weight: 500; margin-bottom: 8px;">Theme Preference</label>
                        <select id="pref_theme" name="theme" class="form-control" style="height: 48px; border-radius: 8px; font-size: 1rem; width: 100%; box-sizing: border-box; display: block;">
                            <option value="light" <?= $theme === 'light' ? 'selected' : '' ?>>Light Mode</option>
                            <option value="dark" <?= $theme === 'dark' ? 'selected' : '' ?>>Dark Mode</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 24px;">
                        <span class="form-label" style="font-weight: 500; margin-bottom: 8px; display: block;">Email Notifications</span>
                        
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px; cursor:pointer;">
                            <input type="checkbox" name="notify_email_sales" value="1" <?= $notifySales ? 'checked' : '' ?>>
                            Notify me on new sales and invoices
                        </label>
                        
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="checkbox" name="notify_email_inventory" value="1" <?= $notifyInventory ? 'checked' : '' ?>>
                            Notify me on low inventory alerts
                        </label>
                    </div>

                    <div class="form-actions" style="text-align: right; border-top: 1px solid var(--border-color); padding-top: 20px;">
                        <button type="submit" class="btn btn-primary" id="savePrefsBtn" style="height: 44px; padding: 0 24px; font-weight: 500; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s ease;">
                            Save Preferences
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show/Hide Password
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.previousElementSibling;
            const icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.setAttribute('data-lucide', 'eye-off');
            } else {
                input.type = 'password';
                icon.setAttribute('data-lucide', 'eye');
            }
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
    });

    // Save Profile Disabled State
    const pForm = document.getElementById('profileForm');
    if(pForm) {
        const btn = document.getElementById('saveProfileBtn');
        const initialData = new FormData(pForm);
        
        pForm.addEventListener('input', function() {
            let changed = false;
            const currentData = new FormData(pForm);
            for (let [key, value] of initialData.entries()) {
                if (key !== 'action' && currentData.get(key) !== value) {
                    changed = true;
                    break;
                }
            }
            if (changed) {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            } else {
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
            }
        });

        pForm.addEventListener('submit', function(e) {
            if(btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px;border-color:#fff;border-bottom-color:transparent;margin-right:8px;display:inline-block;animation:spin 1s linear infinite;"></span> Saving...';
            
            // Add keyframes if missing
            if (!document.getElementById('spinnerKeyframes')) {
                const style = document.createElement('style');
                style.id = 'spinnerKeyframes';
                style.innerHTML = '@keyframes spin { 100% { transform: rotate(360deg); } }';
                document.head.appendChild(style);
            }
        });
    }

    // Password Form Validation
    const pwForm = document.getElementById('passwordForm');
    if(pwForm) {
        const currentPw = document.getElementById('current_password');
        const newPw = document.getElementById('new_password');
        const confirmPw = document.getElementById('confirm_password');
        const matchError = document.getElementById('passwordMatchError');
        const pwBtn = document.getElementById('savePasswordBtn');

        function validatePassword() {
            const hasValues = currentPw.value.length > 0 && newPw.value.length >= 8 && confirmPw.value.length >= 8 && /[0-9\W]/.test(newPw.value);
            const passwordsMatch = newPw.value === confirmPw.value;
            
            if (confirmPw.value && !passwordsMatch) {
                matchError.style.display = 'block';
            } else {
                matchError.style.display = 'none';
            }
            
            if (hasValues && passwordsMatch) {
                pwBtn.disabled = false;
                pwBtn.style.opacity = '1';
                pwBtn.style.cursor = 'pointer';
            } else {
                pwBtn.disabled = true;
                pwBtn.style.opacity = '0.5';
                pwBtn.style.cursor = 'not-allowed';
            }
        }

        currentPw.addEventListener('input', validatePassword);
        newPw.addEventListener('input', validatePassword);
        confirmPw.addEventListener('input', validatePassword);

        pwForm.addEventListener('submit', function(e) {
            if(pwBtn.disabled) return;
            pwBtn.disabled = true;
            pwBtn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px;border-color:#64748b;border-bottom-color:transparent;margin-right:8px;display:inline-block;animation:spin 1s linear infinite;"></span> Updating...';
            
            if (!document.getElementById('spinnerKeyframes')) {
                const style = document.createElement('style');
                style.id = 'spinnerKeyframes';
                style.innerHTML = '@keyframes spin { 100% { transform: rotate(360deg); } }';
                document.head.appendChild(style);
            }
        });
    }

    // Preferences Form
    const prefForm = document.getElementById('preferencesForm');
    if (prefForm) {
        prefForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('savePrefsBtn');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner" style="width:16px;height:16px;border-width:2px;border-color:#fff;border-bottom-color:transparent;margin-right:8px;display:inline-block;animation:spin 1s linear infinite;"></span> Saving...';
            
            try {
                const formData = new FormData(prefForm);
                const data = {
                    theme: formData.get('theme'),
                    notify_email_sales: formData.get('notify_email_sales') === '1',
                    notify_email_inventory: formData.get('notify_email_inventory') === '1'
                };
                
                const res = await fetch(`${window.APP_URL}/api/account`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const json = await res.json();
                if (!res.ok) throw new Error(json.error || 'Failed to update preferences');
                
                // Sync theme immediately
                localStorage.setItem('theme', data.theme);
                const themeBtn = document.getElementById('themeToggleBtn');
                if (data.theme === 'dark') {
                    document.documentElement.setAttribute('data-theme', 'dark');
                    if (themeBtn) {
                        themeBtn.innerHTML = '<i data-lucide="sun" style="width:20px;height:20px;" id="themeToggleIcon"></i>';
                    }
                } else {
                    document.documentElement.removeAttribute('data-theme');
                    if (themeBtn) {
                        themeBtn.innerHTML = '<i data-lucide="moon" style="width:20px;height:20px;" id="themeToggleIcon"></i>';
                    }
                }
                if (typeof lucide !== 'undefined' && themeBtn) {
                    lucide.createIcons({ root: themeBtn });
                }
                
                if (typeof showToast === 'function') {
                    showToast('Preferences updated successfully', 'success');
                } else {
                    alert('Preferences updated successfully');
                }
            } catch (error) {
                if (typeof showToast === 'function') {
                    showToast(error.message, 'error');
                } else {
                    alert(error.message);
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        });
    }
});
</script>

<?php include dirname(__DIR__) . '/layouts/footer.php'; ?>
