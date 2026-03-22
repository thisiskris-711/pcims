<?php
require_once 'config/config.php';
redirect_if_not_logged_in();

$page_title = 'My Profile';
$allow_camera_access = true;

function pcims_profile_fetch_user(PDO $db, $user_id)
{
    $query = "SELECT * FROM users WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', (int) $user_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pcims_profile_success_message($password_changed, $photo_changed)
{
    $parts = ['profile information'];
    if ($photo_changed) {
        $parts[] = 'profile picture';
    }
    if ($password_changed) {
        $parts[] = 'password';
    }

    if (count($parts) === 1) {
        return 'Profile updated successfully!';
    }

    $last_part = array_pop($parts);
    return ucfirst(implode(', ', $parts) . ' and ' . $last_part) . ' updated successfully!';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';

    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        header('Location: profile.php');
        exit();
    }

    $new_profile_image = null;

    try {
        $full_name = sanitize_input($_POST['full_name'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        $phone = sanitize_input($_POST['phone'] ?? '');
        $current_password = (string) ($_POST['current_password'] ?? '');
        $new_password = (string) ($_POST['new_password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');
        $captured_image_data = (string) ($_POST['captured_image_data'] ?? '');

        if ($full_name === '') {
            throw new RuntimeException('Full name is required.');
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }

        $database = new Database();
        $db = $database->getConnection();
        $user = pcims_profile_fetch_user($db, $_SESSION['user_id']);

        if (!$user) {
            throw new RuntimeException('User not found.');
        }

        $profile_image = (string) ($user['profile_image'] ?? '');
        $photo_changed = false;
        $password_changed = false;
        $new_hash = null;

        if ($captured_image_data !== '') {
            $new_profile_image = save_captured_profile_image($captured_image_data);
            $profile_image = $new_profile_image;
            $photo_changed = true;
        } elseif (isset($_FILES['profile_image']) && (int) ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $new_profile_image = save_uploaded_profile_image($_FILES['profile_image']);
            $profile_image = $new_profile_image;
            $photo_changed = true;
        }

        if ($current_password !== '' || $new_password !== '' || $confirm_password !== '') {
            if ($current_password === '' || $new_password === '' || $confirm_password === '') {
                throw new RuntimeException('Please fill in all password fields to change your password.');
            }

            if (!password_verify($current_password, (string) $user['password'])) {
                throw new RuntimeException('Current password is incorrect.');
            }

            if (strlen($new_password) < 6) {
                throw new RuntimeException('New password must be at least 6 characters long.');
            }

            if ($new_password !== $confirm_password) {
                throw new RuntimeException('New password and confirmation do not match.');
            }

            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $password_changed = true;
        }

        $db->beginTransaction();

        $query = "UPDATE users
                  SET full_name = :full_name,
                      email = :email,
                      phone = :phone,
                      profile_image = :profile_image";
        if ($password_changed) {
            $query .= ", password = :password";
        }
        $query .= " WHERE user_id = :user_id";

        $stmt = $db->prepare($query);
        $stmt->bindValue(':full_name', $full_name);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':phone', $phone !== '' ? $phone : null, $phone !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':profile_image', $profile_image !== '' ? $profile_image : null, $profile_image !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        if ($password_changed) {
            $stmt->bindValue(':password', $new_hash);
        }
        $stmt->bindValue(':user_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
        $stmt->execute();
        $db->commit();

        if ($photo_changed && !empty($user['profile_image']) && $user['profile_image'] !== $new_profile_image) {
            delete_profile_image_file($user['profile_image']);
        }

        $_SESSION['full_name'] = $full_name;
        $_SESSION['email'] = $email;
        $_SESSION['profile_image'] = $profile_image;

        $detail_parts = ['Updated profile information'];
        if ($photo_changed) {
            $detail_parts[] = 'profile picture';
        }
        if ($password_changed) {
            $detail_parts[] = 'password';
        }
        log_activity($_SESSION['user_id'], 'profile_update', implode(', ', $detail_parts));

        $_SESSION['success'] = pcims_profile_success_message($password_changed, $photo_changed);
        header('Location: profile.php');
        exit();
    } catch (PDOException $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        if ($new_profile_image !== null) {
            delete_profile_image_file($new_profile_image);
        }

        $_SESSION['error'] = $exception->getCode() === '23000'
            ? 'That email address is already being used by another account.'
            : 'Unable to update your profile right now. Please try again.';
        error_log("Profile Update Error: " . $exception->getMessage());
        header('Location: profile.php');
        exit();
    } catch (Exception $exception) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        if ($new_profile_image !== null) {
            delete_profile_image_file($new_profile_image);
        }

        $_SESSION['error'] = $exception->getMessage();
        header('Location: profile.php');
        exit();
    }
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $user = pcims_profile_fetch_user($db, $_SESSION['user_id']);

    if (!$user) {
        $_SESSION['error'] = 'User not found.';
        header('Location: dashboard.php');
        exit();
    }

    $query = "SELECT COUNT(*) as total_activities, MAX(created_at) as last_activity
              FROM activity_logs
              WHERE user_id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $query = "SELECT action, details, created_at
              FROM activity_logs
              WHERE user_id = :user_id
              ORDER BY created_at DESC
              LIMIT 10";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':user_id', (int) $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $exception) {
    $_SESSION['error'] = 'Unable to load your profile right now.';
    error_log("Profile Load Error: " . $exception->getMessage());
    header('Location: dashboard.php');
    exit();
}

$current_profile_image_url = get_profile_image_url($user['profile_image'] ?? '');
$current_initials = get_user_initials($user['full_name'] ?? 'User');
$_SESSION['profile_image'] = $user['profile_image'] ?? '';

include 'includes/header.php';
?>

<style>
.activity-list {
    max-height: 400px;
    overflow-y: auto;
    overflow-x: hidden;
}

.activity-list::-webkit-scrollbar {
    width: 6px;
}

.activity-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.activity-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.activity-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

.profile-avatar-display {
    width: 132px;
    height: 132px;
    border-radius: 28px;
    display: grid;
    place-items: center;
    margin: 0 auto;
    background: linear-gradient(135deg, rgba(197, 61, 47, 0.14), rgba(242, 181, 98, 0.26));
    color: var(--pc-primary);
    font-size: 2.4rem;
    font-weight: 800;
    box-shadow: var(--pc-shadow-sm);
    overflow: hidden;
}

.profile-avatar-display--editor {
    margin: 0;
    width: 112px;
    height: 112px;
    border-radius: 24px;
    flex-shrink: 0;
    font-size: 2rem;
}

.profile-avatar-display img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.profile-photo-editor {
    display: flex;
    gap: 1.25rem;
    align-items: flex-start;
    padding: 1rem;
    border: 1px solid rgba(95, 45, 24, 0.08);
    border-radius: 20px;
    background: rgba(248, 244, 237, 0.72);
}

.profile-photo-controls {
    flex: 1;
    min-width: 0;
}

.camera-capture-panel {
    margin-top: 1rem;
    padding: 1rem;
    border: 1px dashed rgba(95, 45, 24, 0.18);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.8);
}

.camera-preview-shell {
    position: relative;
    overflow: hidden;
    border-radius: 18px;
    background: #120a07;
    aspect-ratio: 4 / 3;
}

.camera-preview-shell video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.camera-preview-shell.is-idle {
    display: grid;
    place-items: center;
    color: rgba(255, 255, 255, 0.78);
}

@media (max-width: 767.98px) {
    .profile-photo-editor {
        flex-direction: column;
    }

    .profile-avatar-display--editor {
        margin: 0 auto;
    }

    .profile-photo-controls {
        width: 100%;
    }
}
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-body text-center">
                    <div class="profile-avatar-display<?php echo $current_profile_image_url ? ' has-image' : ''; ?>" id="profileCardAvatar">
                        <?php if ($current_profile_image_url): ?>
                            <img src="<?php echo htmlspecialchars($current_profile_image_url); ?>" alt="<?php echo htmlspecialchars($user['full_name']); ?>">
                        <?php else: ?>
                            <span><?php echo htmlspecialchars($current_initials); ?></span>
                        <?php endif; ?>
                    </div>
                    <h4 class="mt-3"><?php echo htmlspecialchars($user['full_name']); ?></h4>
                    <p class="text-muted">@<?php echo htmlspecialchars($user['username']); ?></p>
                    <span class="badge bg-<?php
                        echo $user['role'] === 'admin' ? 'danger' :
                            ($user['role'] === 'manager' ? 'warning' :
                            ($user['role'] === 'staff' ? 'info' : 'secondary'));
                    ?>">
                        <?php echo ucfirst($user['role']); ?>
                    </span>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Activity Summary</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <small class="text-muted">Total Activities</small>
                        <h4><?php echo number_format($stats['total_activities'] ?? 0); ?></h4>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted">Last Activity</small>
                        <p><?php echo !empty($stats['last_activity']) ? format_date($stats['last_activity'], 'M d, Y H:i') : 'No activities yet'; ?></p>
                    </div>
                    <div class="mb-3">
                        <small class="text-muted">Member Since</small>
                        <p><?php echo format_date($user['created_at'], 'M d, Y'); ?></p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Account Status</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span>Status</span>
                        <span class="badge bg-<?php echo $user['status'] === 'active' ? 'success' : 'secondary'; ?>">
                            <?php echo ucfirst($user['status']); ?>
                        </span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Session</span>
                        <span class="badge bg-info">Active</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Edit Profile</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="captured_image_data" id="captured_image_data">

                        <div class="profile-photo-editor mb-4">
                            <div class="profile-avatar-display profile-avatar-display--editor<?php echo $current_profile_image_url ? ' has-image' : ''; ?>" id="profilePhotoPreview">
                                <?php if ($current_profile_image_url): ?>
                                    <img src="<?php echo htmlspecialchars($current_profile_image_url); ?>" alt="<?php echo htmlspecialchars($user['full_name']); ?>">
                                <?php else: ?>
                                    <span><?php echo htmlspecialchars($current_initials); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="profile-photo-controls">
                                <label class="form-label">Profile Picture</label>
                                <p class="text-muted small mb-3">Upload a JPG, PNG, or GIF image up to 2MB, or capture a new photo using your device camera.</p>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="button" class="btn btn-outline-primary" id="chooseProfileImageButton">
                                        <i class="fas fa-upload me-2"></i>Upload Image
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" id="openCameraButton">
                                        <i class="fas fa-camera me-2"></i>Use Camera
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" id="clearPendingPhotoButton" style="display: none;">
                                        <i class="fas fa-rotate-left me-2"></i>Clear Pending Photo
                                    </button>
                                </div>
                                <input type="file" class="d-none" id="profile_image" name="profile_image" accept="image/jpeg,image/png,image/gif" capture="user">
                                <div class="form-text mt-2" id="profilePhotoStatus">Your profile picture will appear in your profile, header, and sidebar after saving.</div>

                                <div class="camera-capture-panel" id="cameraCapturePanel" hidden>
                                    <div class="camera-preview-shell is-idle" id="cameraPreviewShell">
                                        <video id="cameraPreview" playsinline autoplay muted></video>
                                        <div id="cameraIdleMessage">Open your camera to capture a photo.</div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <button type="button" class="btn btn-primary" id="capturePhotoButton">
                                            <i class="fas fa-camera-retro me-2"></i>Capture Photo
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" id="closeCameraButton">
                                            <i class="fas fa-xmark me-2"></i>Close Camera
                                        </button>
                                    </div>
                                    <div class="form-text mt-2" id="cameraStatus">Allow camera access when prompted to capture a new profile photo.</div>
                                    <canvas id="cameraCanvas" class="d-none"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                    <div class="form-text">Username cannot be changed.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="full_name" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <h6 class="mb-3">Change Password</h6>
                                <p class="text-muted small mb-3">Leave all password fields blank if you do not want to change your password.</p>

                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password">
                                </div>

                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                    <div class="form-text">Minimum 6 characters.</div>
                                </div>

                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="submit" class="btn btn-primary" data-loading-text="Updating Profile...">
                                <i class="fas fa-save me-2"></i>Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Recent Activities</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_activities)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-history fa-2x mb-2"></i>
                            <p>No recent activities found.</p>
                        </div>
                    <?php else: ?>
                        <div class="activity-list">
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                    <div>
                                        <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                        <?php if (!empty($activity['details'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr((string) $activity['details'], 0, 100)); ?>...</small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted"><?php echo format_date($activity['created_at'], 'M d, H:i'); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const currentPassword = document.getElementById('current_password');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const fullNameInput = document.getElementById('full_name');
    const fileInput = document.getElementById('profile_image');
    const capturedInput = document.getElementById('captured_image_data');
    const chooseImageButton = document.getElementById('chooseProfileImageButton');
    const openCameraButton = document.getElementById('openCameraButton');
    const clearPendingPhotoButton = document.getElementById('clearPendingPhotoButton');
    const profilePhotoStatus = document.getElementById('profilePhotoStatus');
    const profilePhotoPreview = document.getElementById('profilePhotoPreview');
    const profileCardAvatar = document.getElementById('profileCardAvatar');
    const cameraPanel = document.getElementById('cameraCapturePanel');
    const cameraPreviewShell = document.getElementById('cameraPreviewShell');
    const cameraPreview = document.getElementById('cameraPreview');
    const cameraCanvas = document.getElementById('cameraCanvas');
    const cameraStatus = document.getElementById('cameraStatus');
    const closeCameraButton = document.getElementById('closeCameraButton');
    const capturePhotoButton = document.getElementById('capturePhotoButton');
    const cameraIdleMessage = document.getElementById('cameraIdleMessage');

    const originalImageUrl = <?php echo json_encode($current_profile_image_url); ?>;
    let currentObjectUrl = null;
    let cameraStream = null;
    let hasPendingPhoto = false;

    function getInitials(name) {
        const cleaned = (name || '').replace(/[^A-Za-z0-9 ]/g, ' ').trim();
        if (!cleaned) {
            return 'US';
        }

        return cleaned
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map(part => part.charAt(0).toUpperCase())
            .join('') || 'US';
    }

    function renderAvatar(container, imageUrl) {
        if (!container) {
            return;
        }

        const initials = getInitials(fullNameInput.value);
        if (imageUrl) {
            container.classList.add('has-image');
            container.innerHTML = '<img src="' + imageUrl + '" alt="Profile picture">';
        } else {
            container.classList.remove('has-image');
            container.innerHTML = '<span>' + initials + '</span>';
        }
    }

    function updateAvatarViews(imageUrl, pending) {
        renderAvatar(profilePhotoPreview, imageUrl);
        renderAvatar(profileCardAvatar, imageUrl);
        hasPendingPhoto = !!pending;
        clearPendingPhotoButton.style.display = hasPendingPhoto ? 'inline-flex' : 'none';
    }

    function setPhotoStatus(message, isError) {
        profilePhotoStatus.textContent = message;
        profilePhotoStatus.classList.toggle('text-danger', !!isError);
    }

    function stopCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(function(track) {
                track.stop();
            });
            cameraStream = null;
        }

        cameraPreview.srcObject = null;
        cameraPanel.hidden = true;
        cameraPreviewShell.classList.add('is-idle');
        cameraIdleMessage.style.display = '';
    }

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setPhotoStatus('Camera capture is not supported on this device or browser.', true);
            return;
        }

        try {
            stopCamera();
            cameraStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: 'user'
                }
            });

            cameraPreview.srcObject = cameraStream;
            cameraPanel.hidden = false;
            cameraPreviewShell.classList.remove('is-idle');
            cameraIdleMessage.style.display = 'none';
            cameraStatus.textContent = 'Camera is ready. Position yourself and capture a photo.';
            cameraPanel.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        } catch (error) {
            const message = error && error.name === 'NotAllowedError'
                ? 'Camera access was blocked. Please allow access and try again.'
                : 'Unable to access the camera right now.';
            setPhotoStatus(message, true);
            stopCamera();
        }
    }

    function clearPendingPhoto() {
        if (currentObjectUrl) {
            URL.revokeObjectURL(currentObjectUrl);
            currentObjectUrl = null;
        }

        fileInput.value = '';
        capturedInput.value = '';
        updateAvatarViews(originalImageUrl, false);
        setPhotoStatus('Reverted to your current saved profile picture.', false);
    }

    chooseImageButton.addEventListener('click', function() {
        fileInput.click();
    });

    openCameraButton.addEventListener('click', function() {
        setPhotoStatus('Opening camera...', false);
        startCamera();
    });

    closeCameraButton.addEventListener('click', function() {
        stopCamera();
        setPhotoStatus('Camera closed.', false);
    });

    capturePhotoButton.addEventListener('click', function() {
        if (!cameraStream) {
            setPhotoStatus('Open the camera first before capturing a photo.', true);
            return;
        }

        const videoWidth = cameraPreview.videoWidth;
        const videoHeight = cameraPreview.videoHeight;
        if (!videoWidth || !videoHeight) {
            setPhotoStatus('Camera is still loading. Please try again in a moment.', true);
            return;
        }

        cameraCanvas.width = videoWidth;
        cameraCanvas.height = videoHeight;
        const context = cameraCanvas.getContext('2d');
        context.drawImage(cameraPreview, 0, 0, videoWidth, videoHeight);

        const dataUrl = cameraCanvas.toDataURL('image/jpeg', 0.92);
        capturedInput.value = dataUrl;
        fileInput.value = '';

        if (currentObjectUrl) {
            URL.revokeObjectURL(currentObjectUrl);
            currentObjectUrl = null;
        }

        updateAvatarViews(dataUrl, true);
        setPhotoStatus('Captured photo is ready. Save your profile to apply it.', false);
        stopCamera();
    });

    clearPendingPhotoButton.addEventListener('click', clearPendingPhoto);

    fileInput.addEventListener('change', function() {
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
            return;
        }

        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            fileInput.value = '';
            setPhotoStatus('Please choose a JPG, PNG, or GIF image file.', true);
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            fileInput.value = '';
            setPhotoStatus('Please choose an image smaller than 2MB.', true);
            return;
        }

        if (currentObjectUrl) {
            URL.revokeObjectURL(currentObjectUrl);
        }

        currentObjectUrl = URL.createObjectURL(file);
        capturedInput.value = '';
        updateAvatarViews(currentObjectUrl, true);
        setPhotoStatus('Selected image is ready. Save your profile to apply it.', false);
        stopCamera();
    });

    fullNameInput.addEventListener('input', function() {
        if (!profilePhotoPreview.querySelector('img')) {
            renderAvatar(profilePhotoPreview, '');
        }
        if (!profileCardAvatar.querySelector('img')) {
            renderAvatar(profileCardAvatar, '');
        }
    });

    form.addEventListener('submit', function(e) {
        if (newPassword.value || confirmPassword.value || currentPassword.value) {
            if (!currentPassword.value) {
                e.preventDefault();
                alert('Please enter your current password.');
                currentPassword.focus();
                return;
            }

            if (newPassword.value.length < 6) {
                e.preventDefault();
                alert('New password must be at least 6 characters long.');
                newPassword.focus();
                return;
            }

            if (newPassword.value !== confirmPassword.value) {
                e.preventDefault();
                alert('New password and confirm password do not match.');
                confirmPassword.focus();
                return;
            }
        }

        if ((currentPassword.value || newPassword.value || confirmPassword.value) &&
            !(currentPassword.value && newPassword.value && confirmPassword.value)) {
            e.preventDefault();
            alert('Please fill all password fields or leave them all empty.');
            return;
        }

        stopCamera();
    });

    newPassword.addEventListener('input', function() {
        confirmPassword.value = '';
    });

    window.addEventListener('beforeunload', stopCamera);
    updateAvatarViews(originalImageUrl, false);
});
</script>

<?php include 'includes/footer.php'; ?>
