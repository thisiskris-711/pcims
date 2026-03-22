<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/security.php';
redirect_if_not_logged_in();
set_security_headers([
    'camera' => !empty($allow_camera_access)
]);

$page_heading = $page_title ?? 'Dashboard';
$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$user_name = isset($_SESSION['full_name']) ? trim((string) $_SESSION['full_name']) : 'User';
$user_email = isset($_SESSION['email']) ? trim((string) $_SESSION['email']) : 'No email';
$user_role = isset($_SESSION['role']) ? ucfirst((string) $_SESSION['role']) : 'User';
$user_profile_image = isset($_SESSION['profile_image']) ? (string) $_SESSION['profile_image'] : '';
$user_profile_image_url = get_profile_image_url($user_profile_image);
$user_initials = get_user_initials($user_name);

$unread_count = 0;
$recent_notifications = [];

try {
    $database = new Database();
    $db = $database->getConnection();

    if ($db) {
        $stmt = $db->prepare("SELECT COUNT(*) AS total FROM notifications WHERE user_id = ? AND is_read = FALSE");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $unread_count = (int) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? OR user_id IS NULL ORDER BY created_at DESC LIMIT 5");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $recent_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $exception) {
    $unread_count = 0;
    $recent_notifications = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_heading); ?> - <?php echo APP_NAME; ?></title>

    <meta name="theme-color" content="#c53d2f">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="PCIMS">
    <meta name="application-name" content="PCIMS">
    <meta name="description" content="Personal Collection Inventory Management System">
    <meta name="msapplication-TileColor" content="#c53d2f">
    <meta name="msapplication-config" content="/pcims/browserconfig.xml">

    <link rel="manifest" href="/pcims/manifest.json">
    <link rel="apple-touch-icon" href="/pcims/images/pc-logo-2.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/pcims/images/pc-logo-2.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/pcims/images/pc-logo-2.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/pcims/images/pc-logo-2.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/pcims/images/pc-logo-2.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="app-body">
    <div class="app-shell">
        <?php include 'sidebar.php'; ?>

        <main class="main-content">
            <header class="topbar">
                <div class="topbar__inner">
                    <div class="topbar__start">
                        <button class="mobile-menu-toggle d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="appSidebar" aria-expanded="false">
                            <i class="fas fa-bars"></i>
                        </button>
                        <div class="topbar__title-group">
                            <span class="topbar__eyebrow">PCIMS Workspace</span>
                            <h1 class="topbar__title"><?php echo htmlspecialchars($page_heading); ?></h1>
                        </div>
                    </div>

                    <div class="topbar__actions">
                        <span class="topbar__meta">
                            <i class="fas fa-user-shield"></i>
                            <?php echo htmlspecialchars($user_role); ?>
                        </span>

                        <div class="dropdown">
                            <a class="topbar__icon-button dropdown-toggle" href="#" id="notificationDropdown" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Open notifications">
                                <i class="fas fa-bell"></i>
                                <?php if ($unread_count > 0): ?>
                                    <span class="notification-badge"><?php echo $unread_count; ?></span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end notification-dropdown-menu">
                                <li>
                                    <h6 class="dropdown-header d-flex justify-content-between align-items-center">
                                        <span>Notifications</span>
                                        <a href="notifications.php" class="small text-decoration-none">View all</a>
                                    </h6>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <?php if (empty($recent_notifications)): ?>
                                    <li>
                                        <div class="notification-empty">
                                            <i class="fas fa-bell-slash mb-2"></i>
                                            <div>No notifications right now.</div>
                                        </div>
                                    </li>
                                <?php else: ?>
                                    <?php foreach ($recent_notifications as $notif): ?>
                                        <?php
                                        $icon = $notif['type'] === 'success'
                                            ? 'fa-check-circle text-success'
                                            : ($notif['type'] === 'warning'
                                                ? 'fa-exclamation-triangle text-warning'
                                                : ($notif['type'] === 'error'
                                                    ? 'fa-times-circle text-danger'
                                                    : 'fa-info-circle text-info'));
                                        ?>
                                        <li>
                                            <a class="dropdown-item notification-item <?php echo !empty($notif['is_read']) ? 'text-muted' : 'fw-semibold'; ?>" href="notifications.php?action=read&id=<?php echo (int) $notif['notification_id']; ?>">
                                                <div class="d-flex align-items-start gap-2">
                                                    <i class="fas <?php echo $icon; ?> mt-1"></i>
                                                    <div class="flex-grow-1">
                                                        <?php if (!empty($notif['title'])): ?>
                                                            <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                                        <?php endif; ?>
                                                        <div class="notification-content"><?php echo htmlspecialchars((string) $notif['message']); ?></div>
                                                        <small class="text-muted"><?php echo format_date($notif['created_at'], 'M d, h:i A'); ?></small>
                                                    </div>
                                                </div>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center fw-semibold" href="notifications.php"><i class="fas fa-list me-2"></i>Open notification center</a></li>
                            </ul>
                        </div>

                        <div class="dropdown">
                            <a class="topbar__user-button dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-label="Open user menu">
                                <span class="topbar__user-avatar<?php echo $user_profile_image_url ? ' has-image' : ''; ?>">
                                    <?php if ($user_profile_image_url): ?>
                                        <img src="<?php echo htmlspecialchars($user_profile_image_url); ?>" alt="<?php echo htmlspecialchars($user_name); ?>">
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($user_initials); ?>
                                    <?php endif; ?>
                                </span>
                                <span class="topbar__user-text">
                                    <span class="topbar__user-name"><?php echo htmlspecialchars($user_name); ?></span>
                                    <span class="topbar__user-role"><?php echo htmlspecialchars($user_role); ?></span>
                                </span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><h6 class="dropdown-header"><?php echo htmlspecialchars($user_name); ?></h6></li>
                                <li><span class="dropdown-item-text small text-muted px-3"><?php echo htmlspecialchars($user_email); ?></span></li>
                                <li><span class="dropdown-item-text small text-muted px-3">Role: <?php echo htmlspecialchars($user_role); ?></span></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <?php if (has_permission('admin')): ?>
                                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-gear me-2"></i>Settings</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-right-from-bracket me-2"></i>Log out</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </header>

            <div class="flash-stack">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-circle-check me-2"></i>
                        <?php
                        echo htmlspecialchars($_SESSION['success']);
                        unset($_SESSION['success']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-triangle-exclamation me-2"></i>
                        <?php
                        echo htmlspecialchars($_SESSION['error']);
                        unset($_SESSION['error']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['info'])): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-circle-info me-2"></i>
                        <?php
                        echo htmlspecialchars($_SESSION['info']);
                        unset($_SESSION['info']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-wrapper">
                <script>
                class NotificationManager {
                    constructor() {
                        this.unreadCount = 0;
                        this.init();
                    }

                    init() {
                        this.updateNotificationCount();
                        setInterval(() => this.updateNotificationCount(), 30000);
                        this.setupDropdownEvents();
                    }

                    setupDropdownEvents() {
                        const dropdown = document.getElementById('notificationDropdown');
                        if (!dropdown) {
                            return;
                        }

                        dropdown.addEventListener('show.bs.dropdown', () => this.loadRecentNotifications());
                        dropdown.addEventListener('shown.bs.dropdown', () => this.markNotificationsAsRead());
                    }

                    async updateNotificationCount() {
                        try {
                            const response = await fetch('api/notifications.php');
                            if (!response.ok) {
                                throw new Error('Failed to fetch notifications');
                            }

                            const data = await response.json();
                            this.updateBadge(data.unread_count);
                        } catch (error) {
                            console.error('Notification update error:', error);
                        }
                    }

                    updateBadge(count) {
                        const trigger = document.getElementById('notificationDropdown');
                        const bellIcon = trigger ? trigger.querySelector('i.fa-bell') : null;
                        if (!trigger || !bellIcon) {
                            return;
                        }

                        let badge = trigger.querySelector('.notification-badge');
                        this.unreadCount = count;

                        if (count > 0) {
                            if (!badge) {
                                badge = document.createElement('span');
                                badge.className = 'notification-badge';
                                trigger.appendChild(badge);
                            }

                            badge.textContent = count;
                            badge.style.display = 'inline-flex';
                            bellIcon.classList.add('fa-shake');
                            setTimeout(() => bellIcon.classList.remove('fa-shake'), 1000);
                        } else if (badge) {
                            badge.style.display = 'none';
                        }
                    }

                    async loadRecentNotifications() {
                        try {
                            const response = await fetch('api/notifications_recent.php');
                            if (!response.ok) {
                                throw new Error('Failed to load notifications');
                            }

                            const data = await response.json();
                            this.renderNotifications(data.notifications || []);
                        } catch (error) {
                            console.error('Error loading notifications:', error);
                            this.showErrorState();
                        }
                    }

                    renderNotifications(notifications) {
                        const dropdownMenu = document.querySelector('#notificationDropdown + .dropdown-menu');
                        if (!dropdownMenu) {
                            return;
                        }

                        const removableItems = dropdownMenu.querySelectorAll('li:not(:first-child):not(:nth-child(2))');
                        removableItems.forEach(item => item.remove());

                        if (notifications.length === 0) {
                            this.addEmptyState(dropdownMenu);
                            return;
                        }

                        notifications.forEach(notif => this.addNotificationItem(dropdownMenu, notif));
                        dropdownMenu.insertAdjacentHTML('beforeend', '<li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-center fw-semibold" href="notifications.php"><i class="fas fa-list me-2"></i>Open notification center</a></li>');
                    }

                    addEmptyState(dropdownMenu) {
                        dropdownMenu.insertAdjacentHTML('beforeend', '<li><div class="notification-empty"><i class="fas fa-bell-slash mb-2"></i><div>No notifications right now.</div></div></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-center fw-semibold" href="notifications.php"><i class="fas fa-list me-2"></i>Open notification center</a></li>');
                    }

                    addNotificationItem(dropdownMenu, notif) {
                        const isRead = notif.is_read ? 'text-muted' : 'fw-semibold';
                        const icon = this.getNotificationIcon(notif.type);
                        const title = notif.title ? `<div class="notification-title">${this.escapeHtml(notif.title)}</div>` : '';
                        const item = document.createElement('li');
                        item.innerHTML = `
                            <a class="dropdown-item notification-item ${isRead}" href="notifications.php?action=read&id=${notif.notification_id}">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="fas ${icon} mt-1"></i>
                                    <div class="flex-grow-1">
                                        ${title}
                                        <div class="notification-content">${this.escapeHtml(notif.message || '')}</div>
                                        <small class="text-muted">${this.formatDate(notif.created_at)}</small>
                                    </div>
                                </div>
                            </a>
                        `;
                        dropdownMenu.appendChild(item);
                    }

                    getNotificationIcon(type) {
                        const icons = {
                            success: 'fa-check-circle text-success',
                            warning: 'fa-exclamation-triangle text-warning',
                            error: 'fa-times-circle text-danger',
                            info: 'fa-info-circle text-info'
                        };
                        return icons[type] || 'fa-info-circle text-info';
                    }

                    async markNotificationsAsRead() {
                        if (this.unreadCount === 0) {
                            return;
                        }

                        try {
                            const response = await fetch('api/notifications_mark_read.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify({
                                    csrf_token: '<?php echo generate_csrf_token(); ?>'
                                })
                            });

                            if (response.ok) {
                                this.updateBadge(0);
                            }
                        } catch (error) {
                            console.error('Error marking notifications as read:', error);
                        }
                    }

                    showErrorState() {
                        const dropdownMenu = document.querySelector('#notificationDropdown + .dropdown-menu');
                        if (!dropdownMenu) {
                            return;
                        }

                        dropdownMenu.insertAdjacentHTML('beforeend', '<li><div class="notification-empty"><i class="fas fa-triangle-exclamation text-danger mb-2"></i><div>Error loading notifications</div></div></li>');
                    }

                    escapeHtml(text) {
                        const div = document.createElement('div');
                        div.textContent = text;
                        return div.innerHTML;
                    }

                    formatDate(dateString) {
                        const date = new Date(dateString);
                        if (Number.isNaN(date.getTime())) {
                            return '';
                        }

                        return date.toLocaleString([], {
                            month: 'short',
                            day: 'numeric',
                            hour: 'numeric',
                            minute: '2-digit'
                        });
                    }
                }

                document.addEventListener('DOMContentLoaded', function() {
                    window.notificationManager = new NotificationManager();
                });

                document.addEventListener('DOMContentLoaded', function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const action = urlParams.get('action');
                    const notificationId = urlParams.get('id');

                    if (action === 'read' && notificationId) {
                        fetch('api/notifications_mark_read.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                notification_id: notificationId,
                                csrf_token: '<?php echo generate_csrf_token(); ?>'
                            })
                        }).then(() => {
                            if (window.notificationManager) {
                                window.notificationManager.updateNotificationCount();
                            }
                        });
                    }
                });

                if ('serviceWorker' in navigator) {
                    window.addEventListener('load', function() {
                        navigator.serviceWorker.register('/pcims/sw.js')
                            .then(function(registration) {
                                console.log('ServiceWorker registration successful with scope:', registration.scope);
                            })
                            .catch(function(error) {
                                console.log('ServiceWorker registration failed:', error);
                            });
                    });
                }

                let deferredPrompt;
                const installButton = document.createElement('button');
                installButton.textContent = 'Install App';
                installButton.className = 'btn btn-primary pwa-install-button';
                installButton.style.display = 'none';
                document.body.appendChild(installButton);

                window.addEventListener('beforeinstallprompt', (event) => {
                    event.preventDefault();
                    deferredPrompt = event;
                    installButton.style.display = 'inline-flex';
                });

                installButton.addEventListener('click', async () => {
                    if (!deferredPrompt) {
                        return;
                    }

                    deferredPrompt.prompt();
                    const choice = await deferredPrompt.userChoice;
                    console.log('User response to the install prompt:', choice.outcome);
                    deferredPrompt = null;
                    installButton.style.display = 'none';
                });

                window.addEventListener('appinstalled', () => {
                    installButton.style.display = 'none';
                });

                window.addEventListener('online', () => {
                    const offlineAlert = document.getElementById('offlineAlert');
                    if (offlineAlert) {
                        offlineAlert.style.display = 'none';
                    }
                });

                window.addEventListener('offline', () => {
                    let offlineAlert = document.getElementById('offlineAlert');
                    if (!offlineAlert) {
                        offlineAlert = document.createElement('div');
                        offlineAlert.id = 'offlineAlert';
                        offlineAlert.className = 'alert alert-warning position-fixed top-0 start-50 translate-middle-x mt-3';
                        offlineAlert.style.zIndex = '1090';
                        offlineAlert.innerHTML = '<i class="fas fa-wifi-slash me-2"></i>You are currently offline. Some features may be limited.';
                        document.body.appendChild(offlineAlert);
                    }
                    offlineAlert.style.display = 'block';
                });
                </script>
