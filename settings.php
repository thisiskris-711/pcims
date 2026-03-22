<?php
require_once 'config/config.php';
redirect_if_not_logged_in();
redirect_if_no_permission('admin');

$page_title = 'Settings';
$tab = $_GET['tab'] ?? 'general';
$message = '';
$error = '';

function redirect_settings_tab($tab)
{
    header('Location: settings.php?tab=' . urlencode($tab));
    exit();
}

function validate_backup_filename($filename)
{
    $filename = basename((string) $filename);

    if ($filename === '' || !preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $filename)) {
        throw new InvalidArgumentException('Invalid backup filename.');
    }

    return $filename;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        $_SESSION['error'] = 'Invalid request. Please try again.';
        redirect_settings_tab($tab);
    }
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        if (isset($_POST['reset_settings']) && $_POST['reset_settings'] === '1') {
            $stmt = $db->prepare("DELETE FROM system_settings");
            $stmt->execute();
            $_SESSION['success'] = 'All settings have been reset to default values!';
        } elseif (isset($_POST['delete_backup']) && $_POST['delete_backup'] !== '') {
            $backup_file = 'backups/' . validate_backup_filename($_POST['delete_backup']);

            if (file_exists($backup_file)) {
                if (unlink($backup_file)) {
                    $_SESSION['success'] = 'Backup file deleted successfully!';
                } else {
                    $_SESSION['error'] = 'Failed to delete backup file.';
                }
            } else {
                $_SESSION['error'] = 'Backup file not found.';
            }
        } else {
            switch ($tab) {
                case 'general':
                    // Update general settings
                    $settings = [
                        'company_name' => $_POST['company_name'] ?? '',
                        'company_email' => $_POST['company_email'] ?? '',
                        'company_phone' => $_POST['company_phone'] ?? '',
                        'company_address' => $_POST['company_address'] ?? '',
                        'default_currency' => $_POST['default_currency'] ?? 'USD',
                        'date_format' => $_POST['date_format'] ?? 'Y-m-d',
                        'time_format' => $_POST['time_format'] ?? '24h',
                        'timezone' => $_POST['timezone'] ?? 'UTC'
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $query = "INSERT INTO system_settings (setting_key, setting_value) 
                                 VALUES (:key, :value) 
                                 ON DUPLICATE KEY UPDATE setting_value = :value";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':key', $key);
                        $stmt->bindParam(':value', $value);
                        $stmt->execute();
                    }
                    
                    $_SESSION['success'] = 'General settings updated successfully!';
                    break;

                case 'business':
                    $business_presets = pcims_get_business_presets();
                    $business_type = (string) ($_POST['business_type'] ?? 'personal_collection');
                    if (!isset($business_presets[$business_type])) {
                        throw new InvalidArgumentException('Invalid business preset selected.');
                    }

                    $preset = $business_presets[$business_type];
                    $updated_by = $_SESSION['user_id'] ?? null;

                    $labels = [
                        'category_singular' => trim((string) ($_POST['label_category_singular'] ?? $preset['labels']['category_singular'])),
                        'category_plural' => trim((string) ($_POST['label_category_plural'] ?? $preset['labels']['category_plural'])),
                        'product_singular' => trim((string) ($_POST['label_product_singular'] ?? $preset['labels']['product_singular'])),
                        'product_plural' => trim((string) ($_POST['label_product_plural'] ?? $preset['labels']['product_plural'])),
                        'supplier_singular' => trim((string) ($_POST['label_supplier_singular'] ?? $preset['labels']['supplier_singular'])),
                        'supplier_plural' => trim((string) ($_POST['label_supplier_plural'] ?? $preset['labels']['supplier_plural'])),
                    ];

                    $discount_catalog_input = trim((string) ($_POST['discount_catalog'] ?? ''));
                    $pricing_strategy = (string) ($_POST['pricing_strategy'] ?? $preset['pricing']['pricing_strategy']);
                    $price_rounding = (string) ($_POST['price_rounding'] ?? $preset['pricing']['price_rounding']);
                    $tax_mode = (string) ($_POST['tax_mode'] ?? $preset['pricing']['tax_mode']);

                    if (!in_array($pricing_strategy, ['standard_markup', 'keystone', 'custom_margin'], true)) {
                        $pricing_strategy = $preset['pricing']['pricing_strategy'];
                    }

                    if (!in_array($price_rounding, ['none', 'nearest_0_05', 'nearest_0_10', 'nearest_1_00'], true)) {
                        $price_rounding = $preset['pricing']['price_rounding'];
                    }

                    if (!in_array($tax_mode, ['none', 'inclusive', 'exclusive'], true)) {
                        $tax_mode = $preset['pricing']['tax_mode'];
                    }

                    $pricing = [
                        'pricing_strategy' => $pricing_strategy,
                        'default_markup_percent' => max(0, (float) ($_POST['default_markup_percent'] ?? $preset['pricing']['default_markup_percent'])),
                        'price_rounding' => $price_rounding,
                        'tax_mode' => $tax_mode,
                        'tax_percent' => max(0, min(100, (float) ($_POST['tax_percent'] ?? $preset['pricing']['tax_percent']))),
                        'discount_catalog' => $discount_catalog_input !== ''
                            ? pcims_parse_discount_catalog($discount_catalog_input)
                            : $preset['pricing']['discount_catalog'],
                    ];

                    $report_templates = [];
                    foreach ($preset['reports'] as $report_key => $report_defaults) {
                        $label_field = $report_key . '_report_label';
                        $heading_field = $report_key . '_report_heading';
                        $description_field = $report_key . '_report_description';

                        $report_templates[$report_key] = [
                            'label' => trim((string) ($_POST[$label_field] ?? $report_defaults['label'])) ?: $report_defaults['label'],
                            'heading' => trim((string) ($_POST[$heading_field] ?? $report_defaults['heading'])) ?: $report_defaults['heading'],
                            'description' => trim((string) ($_POST[$description_field] ?? $report_defaults['description'])),
                        ];
                    }

                    set_setting($db, 'business_type', $business_type, 'Active business preset', $updated_by);
                    set_setting($db, 'business_module_labels', json_encode($labels), 'Custom module labels', $updated_by);
                    set_setting($db, 'business_pricing_rules', json_encode($pricing), 'Business pricing rules', $updated_by);
                    set_setting($db, 'business_report_templates', json_encode($report_templates), 'Business report templates', $updated_by);

                    $inserted_categories = 0;
                    if (isset($_POST['apply_preset_categories'])) {
                        $inserted_categories = pcims_apply_business_category_preset($db, $business_type);
                    }

                    $_SESSION['success'] = $inserted_categories > 0
                        ? 'Business model updated and ' . $inserted_categories . ' preset categories were added.'
                        : 'Business model settings updated successfully!';
                    break;
                    
                case 'inventory':
                    // Update inventory settings
                    $settings = [
                        'low_stock_threshold' => $_POST['low_stock_threshold'] ?? '5',
                        'auto_reorder' => isset($_POST['auto_reorder']) ? '1' : '0',
                        'default_reorder_quantity' => $_POST['default_reorder_quantity'] ?? '10',
                        'allow_negative_stock' => isset($_POST['allow_negative_stock']) ? '1' : '0',
                        'stock_movement_logging' => isset($_POST['stock_movement_logging']) ? '1' : '0',
                        'inventory_valuation_method' => $_POST['inventory_valuation_method'] ?? 'FIFO'
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $query = "INSERT INTO system_settings (setting_key, setting_value) 
                                 VALUES (:key, :value) 
                                 ON DUPLICATE KEY UPDATE setting_value = :value";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':key', $key);
                        $stmt->bindParam(':value', $value);
                        $stmt->execute();
                    }
                    
                    $_SESSION['success'] = 'Inventory settings updated successfully!';
                    break;
                    
                case 'notifications':
                    // Update notification settings
                    $settings = [
                        'email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
                        'low_stock_alerts' => isset($_POST['low_stock_alerts']) ? '1' : '0',
                        'new_order_alerts' => isset($_POST['new_order_alerts']) ? '1' : '0',
                        'system_alerts' => isset($_POST['system_alerts']) ? '1' : '0',
                        'notification_email' => $_POST['notification_email'] ?? '',
                        'alert_threshold_days' => $_POST['alert_threshold_days'] ?? '7'
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $query = "INSERT INTO system_settings (setting_key, setting_value) 
                                 VALUES (:key, :value) 
                                 ON DUPLICATE KEY UPDATE setting_value = :value";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':key', $key);
                        $stmt->bindParam(':value', $value);
                        $stmt->execute();
                    }
                    
                    $_SESSION['success'] = 'Notification settings updated successfully!';
                    break;
                    
                case 'email':
                    // Update email settings
                    $settings = [
                        'email_enabled' => isset($_POST['email_enabled']) ? '1' : '0',
                        'email_host' => $_POST['email_host'] ?? '',
                        'email_port' => $_POST['email_port'] ?? '587',
                        'email_username' => $_POST['email_username'] ?? '',
                        'email_password' => $_POST['email_password'] ?? '',
                        'email_encryption' => $_POST['email_encryption'] ?? 'tls',
                        'email_from' => $_POST['email_from'] ?? '',
                        'email_from_name' => $_POST['email_from_name'] ?? 'PCIMS System',
                        'email_test_address' => $_POST['email_test_address'] ?? ''
                    ];
                    
                    // Handle test email
                    if (isset($_POST['test_email']) && !empty($settings['email_test_address'])) {
                        require_once 'includes/email.php';
                        $emailHelper = new EmailHelper();
                        
                        // Temporarily update config with new settings
                        $emailHelper->updateConfig($settings);
                        
                        if ($emailHelper->testConfiguration($settings['email_test_address'])) {
                            $_SESSION['success'] = 'Test email sent successfully! Email configuration is working.';
                        } else {
                            $_SESSION['error'] = 'Test email failed. Please check your email configuration.';
                            redirect_settings_tab('email');
                        }
                    }
                    
                    foreach ($settings as $key => $value) {
                        $query = "INSERT INTO system_settings (setting_key, setting_value) 
                                 VALUES (:key, :value) 
                                 ON DUPLICATE KEY UPDATE setting_value = :value";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':key', $key);
                        $stmt->bindParam(':value', $value);
                        $stmt->execute();
                    }
                    
                    if (!isset($_POST['test_email'])) {
                        $_SESSION['success'] = 'Email settings updated successfully!';
                    }
                    break;
                    
                case 'security':
                    // Update security settings
                    $settings = [
                        'session_timeout' => $_POST['session_timeout'] ?? '30',
                        'password_min_length' => $_POST['password_min_length'] ?? '8',
                        'password_require_special' => isset($_POST['password_require_special']) ? '1' : '0',
                        'password_require_numbers' => isset($_POST['password_require_numbers']) ? '1' : '0',
                        'max_login_attempts' => $_POST['max_login_attempts'] ?? '5',
                        'lockout_duration' => $_POST['lockout_duration'] ?? '15',
                        'two_factor_auth' => isset($_POST['two_factor_auth']) ? '1' : '0'
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $query = "INSERT INTO system_settings (setting_key, setting_value) 
                                 VALUES (:key, :value) 
                                 ON DUPLICATE KEY UPDATE setting_value = :value";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':key', $key);
                        $stmt->bindParam(':value', $value);
                        $stmt->execute();
                    }
                    
                    $_SESSION['success'] = 'Security settings updated successfully!';
                    break;
                    
                case 'backup':
                    $backup_type = $_POST['backup_type'] ?? 'full';
                    if (!in_array($backup_type, ['full', 'data'], true)) {
                        throw new InvalidArgumentException('Invalid backup type selected.');
                    }

                    if (!is_dir('backups')) {
                        mkdir('backups', 0755, true);
                    }

                    $backup_file = 'backup_' . date('Y-m-d_H-i-s') . ($backup_type === 'data' ? '_data' : '') . '.sql';
                    $backup_path = 'backups/' . $backup_file;

                    $command_parts = [
                        'mysqldump',
                        '--host=' . escapeshellarg(DB_HOST),
                        '--user=' . escapeshellarg(DB_USER),
                    ];

                    if (DB_PASS !== '') {
                        $command_parts[] = '--password=' . escapeshellarg(DB_PASS);
                    }

                    if ($backup_type === 'data') {
                        $command_parts[] = '--no-create-info';
                    }

                    $command_parts[] = escapeshellarg(DB_NAME);

                    $output = [];
                    $exit_code = 0;
                    $command = implode(' ', $command_parts) . ' > ' . escapeshellarg($backup_path) . ' 2>&1';
                    exec($command, $output, $exit_code);

                    if ($exit_code === 0 && file_exists($backup_path) && filesize($backup_path) > 0) {
                        $_SESSION['success'] = ($backup_type === 'data')
                            ? 'Data backup created successfully!'
                            : 'Full backup created successfully!';
                    } else {
                        if (file_exists($backup_path) && filesize($backup_path) === 0) {
                            unlink($backup_path);
                        }

                        error_log('Settings Backup Error: ' . implode(PHP_EOL, $output));
                        $_SESSION['error'] = 'Failed to create backup. Please check your database configuration.';
                    }
                    break;
            }
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['error'] = $exception->getMessage();
    } catch(PDOException $exception) {
        $_SESSION['error'] = 'Database error. Please try again.';
        error_log("Settings Error: " . $exception->getMessage());
    } catch (Exception $exception) {
        $_SESSION['error'] = 'Unable to complete the requested settings action.';
        error_log("Settings Unexpected Error: " . $exception->getMessage());
    }
    
    redirect_settings_tab($tab);
}

// Get current settings
$settings = [];
try {
    $database = new Database();
    $db = $database->getConnection();
    
    $query = "SELECT setting_key, setting_value FROM system_settings";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
} catch(PDOException $exception) {
    error_log("Settings Load Error: " . $exception->getMessage());
}

$business_presets = pcims_get_business_presets();
$business_config = pcims_get_business_configuration();
$business_discount_catalog_text = pcims_discount_catalog_to_text($business_config['pricing']['discount_catalog']);

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">
            <i class="fas fa-cog me-2"></i>Settings
        </h1>
        <div>
            <button onclick="resetAllSettings()" class="btn btn-outline-danger">
                <i class="fas fa-undo me-2"></i>Reset to Defaults
            </button>
        </div>
    </div>
    
    <!-- Settings Navigation -->
    <div class="card mb-4">
        <div class="card-body p-0">
            <ul class="nav nav-tabs" id="settingsTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'general' ? 'active' : ''; ?>" 
                       href="settings.php?tab=general">
                        <i class="fas fa-building me-2"></i>General
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'business' ? 'active' : ''; ?>" 
                       href="settings.php?tab=business">
                        <i class="fas fa-store me-2"></i>Business Model
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'inventory' ? 'active' : ''; ?>" 
                       href="settings.php?tab=inventory">
                        <i class="fas fa-boxes me-2"></i>Inventory
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'notifications' ? 'active' : ''; ?>" 
                       href="settings.php?tab=notifications">
                        <i class="fas fa-bell me-2"></i>Notifications
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'email' ? 'active' : ''; ?>" 
                       href="settings.php?tab=email">
                        <i class="fas fa-envelope me-2"></i>Email
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'security' ? 'active' : ''; ?>" 
                       href="settings.php?tab=security">
                        <i class="fas fa-shield-alt me-2"></i>Security
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $tab === 'backup' ? 'active' : ''; ?>" 
                       href="settings.php?tab=backup">
                        <i class="fas fa-database me-2"></i>Backup
                    </a>
                </li>
            </ul>
        </div>
    </div>
    
    <!-- Settings Content -->
    <div class="card">
        <div class="card-body">
            <?php if ($tab === 'general'): ?>
                <!-- General Settings -->
                <form method="POST" action="settings.php?tab=general">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <h5 class="mb-4">Company Information</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="company_name" class="form-label">Company Name</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" 
                                       value="<?php echo htmlspecialchars($settings['company_name'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="company_email" class="form-label">Company Email</label>
                                <input type="email" class="form-control" id="company_email" name="company_email" 
                                       value="<?php echo htmlspecialchars($settings['company_email'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="company_phone" class="form-label">Company Phone</label>
                                <input type="tel" class="form-control" id="company_phone" name="company_phone" 
                                       value="<?php echo htmlspecialchars($settings['company_phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="company_address" class="form-label">Company Address</label>
                                <textarea class="form-control" id="company_address" name="company_address" rows="3"><?php echo htmlspecialchars($settings['company_address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Regional Settings</h5>
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="default_currency" class="form-label">Default Currency</label>
                                <select class="form-select" id="default_currency" name="default_currency">
                                    <option value="USD" <?php echo ($settings['default_currency'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD ($)</option>
                                    <option value="EUR" <?php echo ($settings['default_currency'] ?? '') === 'EUR' ? 'selected' : ''; ?>>EUR (€)</option>
                                    <option value="GBP" <?php echo ($settings['default_currency'] ?? '') === 'GBP' ? 'selected' : ''; ?>>GBP (£)</option>
                                    <option value="PHP" <?php echo ($settings['default_currency'] ?? '') === 'PHP' ? 'selected' : ''; ?>>PHP (₱)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="date_format" class="form-label">Date Format</label>
                                <select class="form-select" id="date_format" name="date_format">
                                    <option value="Y-m-d" <?php echo ($settings['date_format'] ?? '') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                    <option value="m/d/Y" <?php echo ($settings['date_format'] ?? '') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                    <option value="d/m/Y" <?php echo ($settings['date_format'] ?? '') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                                    <option value="M d, Y" <?php echo ($settings['date_format'] ?? '') === 'M d, Y' ? 'selected' : ''; ?>>Month DD, YYYY</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="time_format" class="form-label">Time Format</label>
                                <select class="form-select" id="time_format" name="time_format">
                                    <option value="24h" <?php echo ($settings['time_format'] ?? '') === '24h' ? 'selected' : ''; ?>>24 Hour</option>
                                    <option value="12h" <?php echo ($settings['time_format'] ?? '') === '12h' ? 'selected' : ''; ?>>12 Hour (AM/PM)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save General Settings
                        </button>
                    </div>
                </form>

            <?php elseif ($tab === 'business'): ?>
                <form method="POST" action="settings.php?tab=business">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3 mb-4">
                        <div>
                            <h5 class="mb-2">Business Preset</h5>
                            <p class="text-muted mb-0">Switch the system vocabulary, pricing defaults, and reporting language to fit a different small retail model.</p>
                        </div>
                        <div class="badge bg-light text-dark px-3 py-2"><?php echo htmlspecialchars($business_config['preset_label']); ?></div>
                    </div>

                    <div class="row g-4 mb-4">
                        <div class="col-lg-6">
                            <label for="business_type" class="form-label">Preset Type</label>
                            <select class="form-select" id="businessTypeSelect" name="business_type">
                                <?php foreach ($business_presets as $preset_key => $preset): ?>
                                    <option value="<?php echo htmlspecialchars($preset_key); ?>" <?php echo $business_config['business_type'] === $preset_key ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($preset['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Choose the operating model that is closest to your business.</div>
                        </div>
                        <div class="col-lg-6">
                            <div class="card h-100 bg-light border-0">
                                <div class="card-body">
                                    <div class="fw-semibold mb-2">Preset Guidance</div>
                                    <p class="text-muted mb-0" id="businessPresetDescription"><?php echo htmlspecialchars($business_config['preset_description']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h5 class="mb-3">Interface Labels</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label for="label_category_singular" class="form-label">Category Label</label>
                            <input type="text" class="form-control" id="label_category_singular" name="label_category_singular" value="<?php echo htmlspecialchars($business_config['labels']['category_singular']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="label_category_plural" class="form-label">Categories Label</label>
                            <input type="text" class="form-control" id="label_category_plural" name="label_category_plural" value="<?php echo htmlspecialchars($business_config['labels']['category_plural']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="label_product_singular" class="form-label">Product Label</label>
                            <input type="text" class="form-control" id="label_product_singular" name="label_product_singular" value="<?php echo htmlspecialchars($business_config['labels']['product_singular']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="label_product_plural" class="form-label">Products Label</label>
                            <input type="text" class="form-control" id="label_product_plural" name="label_product_plural" value="<?php echo htmlspecialchars($business_config['labels']['product_plural']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="label_supplier_singular" class="form-label">Supplier Label</label>
                            <input type="text" class="form-control" id="label_supplier_singular" name="label_supplier_singular" value="<?php echo htmlspecialchars($business_config['labels']['supplier_singular']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="label_supplier_plural" class="form-label">Suppliers Label</label>
                            <input type="text" class="form-control" id="label_supplier_plural" name="label_supplier_plural" value="<?php echo htmlspecialchars($business_config['labels']['supplier_plural']); ?>">
                        </div>
                    </div>

                    <h5 class="mb-3">Pricing Rules</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label for="pricing_strategy" class="form-label">Pricing Strategy</label>
                            <select class="form-select" id="pricing_strategy" name="pricing_strategy">
                                <option value="standard_markup" <?php echo $business_config['pricing']['pricing_strategy'] === 'standard_markup' ? 'selected' : ''; ?>>Standard Markup</option>
                                <option value="keystone" <?php echo $business_config['pricing']['pricing_strategy'] === 'keystone' ? 'selected' : ''; ?>>Keystone Retail</option>
                                <option value="custom_margin" <?php echo $business_config['pricing']['pricing_strategy'] === 'custom_margin' ? 'selected' : ''; ?>>Custom Margin</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="default_markup_percent" class="form-label">Default Markup %</label>
                            <input type="number" class="form-control" id="default_markup_percent" name="default_markup_percent" min="0" step="0.01" value="<?php echo htmlspecialchars((string) $business_config['pricing']['default_markup_percent']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="price_rounding" class="form-label">Price Rounding</label>
                            <select class="form-select" id="price_rounding" name="price_rounding">
                                <option value="none" <?php echo $business_config['pricing']['price_rounding'] === 'none' ? 'selected' : ''; ?>>No Rounding</option>
                                <option value="nearest_0_05" <?php echo $business_config['pricing']['price_rounding'] === 'nearest_0_05' ? 'selected' : ''; ?>>Nearest 0.05</option>
                                <option value="nearest_0_10" <?php echo $business_config['pricing']['price_rounding'] === 'nearest_0_10' ? 'selected' : ''; ?>>Nearest 0.10</option>
                                <option value="nearest_1_00" <?php echo $business_config['pricing']['price_rounding'] === 'nearest_1_00' ? 'selected' : ''; ?>>Nearest 1.00</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="tax_mode" class="form-label">Tax Handling</label>
                            <select class="form-select" id="tax_mode" name="tax_mode">
                                <option value="inclusive" <?php echo $business_config['pricing']['tax_mode'] === 'inclusive' ? 'selected' : ''; ?>>Tax Inclusive</option>
                                <option value="exclusive" <?php echo $business_config['pricing']['tax_mode'] === 'exclusive' ? 'selected' : ''; ?>>Tax Exclusive</option>
                                <option value="none" <?php echo $business_config['pricing']['tax_mode'] === 'none' ? 'selected' : ''; ?>>No Tax Layer</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="tax_percent" class="form-label">Tax %</label>
                            <input type="number" class="form-control" id="tax_percent" name="tax_percent" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) $business_config['pricing']['tax_percent']); ?>">
                        </div>
                        <div class="col-12">
                            <label for="discount_catalog" class="form-label">Discount Catalog</label>
                            <textarea class="form-control" id="discount_catalog" name="discount_catalog" rows="6"><?php echo htmlspecialchars($business_discount_catalog_text); ?></textarea>
                            <div class="form-text">One rule per line using `code|Label|Percent`, for example `member|Loyalty Member|5`.</div>
                        </div>
                    </div>

                    <h5 class="mb-3">Report Templates</h5>
                    <div class="row g-3 mb-4">
                        <?php foreach ($business_config['reports'] as $report_key => $report_template): ?>
                            <div class="col-12">
                                <div class="card border-0 bg-light">
                                    <div class="card-body">
                                        <div class="fw-semibold mb-3 text-capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $report_key)); ?> Template</div>
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="<?php echo $report_key; ?>_report_label" class="form-label">Menu Label</label>
                                                <input type="text" class="form-control" id="<?php echo $report_key; ?>_report_label" name="<?php echo $report_key; ?>_report_label" value="<?php echo htmlspecialchars($report_template['label']); ?>">
                                            </div>
                                            <div class="col-md-8">
                                                <label for="<?php echo $report_key; ?>_report_heading" class="form-label">Report Heading</label>
                                                <input type="text" class="form-control" id="<?php echo $report_key; ?>_report_heading" name="<?php echo $report_key; ?>_report_heading" value="<?php echo htmlspecialchars($report_template['heading']); ?>">
                                            </div>
                                            <div class="col-12">
                                                <label for="<?php echo $report_key; ?>_report_description" class="form-label">Description</label>
                                                <input type="text" class="form-control" id="<?php echo $report_key; ?>_report_description" name="<?php echo $report_key; ?>_report_description" value="<?php echo htmlspecialchars($report_template['description']); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h5 class="mb-3">Preset Category Suggestions</h5>
                    <div class="row g-4 mb-4">
                        <div class="col-lg-8">
                            <div class="card border-0 bg-light">
                                <div class="card-body">
                                    <div class="fw-semibold mb-3">Suggested Inventory Structure</div>
                                    <div id="businessCategorySuggestions" class="row g-2">
                                        <?php foreach ($business_config['category_suggestions'] as $category_suggestion): ?>
                                            <div class="col-md-6">
                                                <div class="border rounded-3 p-3 h-100 bg-white">
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($category_suggestion['name']); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($category_suggestion['description']); ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body">
                                    <div class="fw-semibold mb-2">Import Preset Categories</div>
                                    <p class="text-muted small mb-3">Append missing categories from the selected preset into your live category list without deleting current ones.</p>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="apply_preset_categories" name="apply_preset_categories" value="1">
                                        <label class="form-check-label" for="apply_preset_categories">
                                            Add suggested categories on save
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Business Model
                        </button>
                    </div>
                </form>

            <?php elseif ($tab === 'inventory'): ?>
                <!-- Inventory Settings -->
                <form method="POST" action="settings.php?tab=inventory">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <h5 class="mb-4">Stock Management</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="low_stock_threshold" class="form-label">Low Stock Threshold</label>
                                <input type="number" class="form-control" id="low_stock_threshold" name="low_stock_threshold" 
                                       value="<?php echo htmlspecialchars($settings['low_stock_threshold'] ?? '5'); ?>" min="1" required>
                                <div class="form-text">Alert when stock falls below this quantity</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="default_reorder_quantity" class="form-label">Default Reorder Quantity</label>
                                <input type="number" class="form-control" id="default_reorder_quantity" name="default_reorder_quantity" 
                                       value="<?php echo htmlspecialchars($settings['default_reorder_quantity'] ?? '10'); ?>" min="1" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="inventory_valuation_method" class="form-label">Inventory Valuation Method</label>
                                <select class="form-select" id="inventory_valuation_method" name="inventory_valuation_method">
                                    <option value="FIFO" <?php echo ($settings['inventory_valuation_method'] ?? '') === 'FIFO' ? 'selected' : ''; ?>>FIFO (First In, First Out)</option>
                                    <option value="LIFO" <?php echo ($settings['inventory_valuation_method'] ?? '') === 'LIFO' ? 'selected' : ''; ?>>LIFO (Last In, First Out)</option>
                                    <option value="AVG" <?php echo ($settings['inventory_valuation_method'] ?? '') === 'AVG' ? 'selected' : ''; ?>>Average Cost</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Inventory Options</h5>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="auto_reorder" name="auto_reorder" 
                                       <?php echo ($settings['auto_reorder'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="auto_reorder">
                                    Enable Automatic Reordering
                                </label>
                                <div class="form-text">Automatically create purchase orders when stock falls below threshold</div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="allow_negative_stock" name="allow_negative_stock" 
                                       <?php echo ($settings['allow_negative_stock'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="allow_negative_stock">
                                    Allow Negative Stock
                                </label>
                                <div class="form-text">Allow selling items with zero or negative stock</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stock_movement_logging" name="stock_movement_logging" 
                                       <?php echo ($settings['stock_movement_logging'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="stock_movement_logging">
                                    Enable Stock Movement Logging
                                </label>
                                <div class="form-text">Log all stock movements for audit trail</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Inventory Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($tab === 'notifications'): ?>
                <!-- Notification Settings -->
                <form method="POST" action="settings.php?tab=notifications">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <h5 class="mb-4">Email Notifications</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="notification_email" class="form-label">Notification Email</label>
                                <input type="email" class="form-control" id="notification_email" name="notification_email" 
                                       value="<?php echo htmlspecialchars($settings['notification_email'] ?? ''); ?>">
                                <div class="form-text">Email address for system notifications</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="alert_threshold_days" class="form-label">Alert Threshold (Days)</label>
                                <input type="number" class="form-control" id="alert_threshold_days" name="alert_threshold_days" 
                                       value="<?php echo htmlspecialchars($settings['alert_threshold_days'] ?? '7'); ?>" min="1">
                                <div class="form-text">Days before sending expiration/reminder alerts</div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Notification Types</h5>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" 
                                       <?php echo ($settings['email_notifications'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_notifications">
                                    Enable Email Notifications
                                </label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="low_stock_alerts" name="low_stock_alerts" 
                                       <?php echo ($settings['low_stock_alerts'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="low_stock_alerts">
                                    Low Stock Alerts
                                </label>
                                <div class="form-text">Send alerts when items fall below threshold</div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="new_order_alerts" name="new_order_alerts" 
                                       <?php echo ($settings['new_order_alerts'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="new_order_alerts">
                                    New Order Alerts
                                </label>
                                <div class="form-text">Notify when new orders are received</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="system_alerts" name="system_alerts" 
                                       <?php echo ($settings['system_alerts'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="system_alerts">
                                    System Alerts
                                </label>
                                <div class="form-text">Critical system notifications and errors</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Notification Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($tab === 'email'): ?>
                <!-- Email Settings -->
                <form method="POST" action="settings.php?tab=email">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <h5 class="mb-4">Email Configuration</h5>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="email_enabled" name="email_enabled" 
                                       <?php echo ($settings['email_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="email_enabled">
                                    Enable Email Notifications
                                </label>
                                <div class="form-text">Enable sending of email notifications and alerts</div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">SMTP Settings</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_host" class="form-label">SMTP Host</label>
                                <input type="text" class="form-control" id="email_host" name="email_host" 
                                       value="<?php echo htmlspecialchars($settings['email_host'] ?? 'smtp.gmail.com'); ?>" 
                                       placeholder="smtp.gmail.com">
                                <div class="form-text">Your SMTP server hostname</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_port" class="form-label">SMTP Port</label>
                                <input type="number" class="form-control" id="email_port" name="email_port" 
                                       value="<?php echo htmlspecialchars($settings['email_port'] ?? '587'); ?>" 
                                       placeholder="587">
                                <div class="form-text">SMTP server port (587 for TLS, 465 for SSL)</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_encryption" class="form-label">Encryption</label>
                                <select class="form-select" id="email_encryption" name="email_encryption">
                                    <option value="tls" <?php echo ($settings['email_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                    <option value="ssl" <?php echo ($settings['email_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="" <?php echo ($settings['email_encryption'] ?? '') === '' ? 'selected' : ''; ?>>None</option>
                                </select>
                                <div class="form-text">Encryption method for SMTP connection</div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Authentication</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="email_username" name="email_username" 
                                       value="<?php echo htmlspecialchars($settings['email_username'] ?? ''); ?>" 
                                       placeholder="your-email@gmail.com">
                                <div class="form-text">SMTP username (usually your email address)</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="email_password" name="email_password" 
                                       value="<?php echo htmlspecialchars($settings['email_password'] ?? ''); ?>" 
                                       placeholder="Your email password or app password">
                                <div class="form-text">Use app passwords for Gmail/Google Workspace</div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">From Settings</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_from" class="form-label">From Email</label>
                                <input type="email" class="form-control" id="email_from" name="email_from" 
                                       value="<?php echo htmlspecialchars($settings['email_from'] ?? SMTP_FROM); ?>" 
                                       placeholder="noreply@yourcompany.com">
                                <div class="form-text">Email address that sends notifications</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_from_name" class="form-label">From Name</label>
                                <input type="text" class="form-control" id="email_from_name" name="email_from_name" 
                                       value="<?php echo htmlspecialchars($settings['email_from_name'] ?? SMTP_FROM_NAME); ?>" 
                                       placeholder="PCIMS System">
                                <div class="form-text">Display name for outgoing emails</div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Test Email</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email_test_address" class="form-label">Test Email Address</label>
                                <input type="email" class="form-control" id="email_test_address" name="email_test_address" 
                                       value="<?php echo htmlspecialchars($settings['email_test_address'] ?? ''); ?>" 
                                       placeholder="test@example.com">
                                <div class="form-text">Send a test email to verify configuration</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end gap-2">
                        <button type="submit" name="test_email" value="1" class="btn btn-outline-primary" 
                                onclick="return confirmTestEmail()">
                            <i class="fas fa-paper-plane me-2"></i>Send Test Email
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Email Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($tab === 'security'): ?>
                <!-- Security Settings -->
                <form method="POST" action="settings.php?tab=security">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    
                    <h5 class="mb-4">Session Management</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="session_timeout" class="form-label">Session Timeout (Minutes)</label>
                                <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                       value="<?php echo htmlspecialchars($settings['session_timeout'] ?? '30'); ?>" min="5" max="480">
                                <div class="form-text">Automatically log out users after inactivity</div>
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Password Policy</h5>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password_min_length" class="form-label">Minimum Password Length</label>
                                <input type="number" class="form-control" id="password_min_length" name="password_min_length" 
                                       value="<?php echo htmlspecialchars($settings['password_min_length'] ?? '8'); ?>" min="6" max="50">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                                <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                       value="<?php echo htmlspecialchars($settings['max_login_attempts'] ?? '5'); ?>" min="3" max="10">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="lockout_duration" class="form-label">Lockout Duration (Minutes)</label>
                                <input type="number" class="form-control" id="lockout_duration" name="lockout_duration" 
                                       value="<?php echo htmlspecialchars($settings['lockout_duration'] ?? '15'); ?>" min="5" max="1440">
                            </div>
                        </div>
                    </div>
                    
                    <h5 class="mb-4">Password Requirements</h5>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="password_require_special" name="password_require_special" 
                                       <?php echo ($settings['password_require_special'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="password_require_special">
                                    Require Special Characters
                                </label>
                                <div class="form-text">Require at least one special character (!@#$%^&*)</div>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" id="password_require_numbers" name="password_require_numbers" 
                                       <?php echo ($settings['password_require_numbers'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="password_require_numbers">
                                    Require Numbers
                                </label>
                                <div class="form-text">Require at least one numeric character</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="two_factor_auth" name="two_factor_auth" 
                                       <?php echo ($settings['two_factor_auth'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="two_factor_auth">
                                    Enable Two-Factor Authentication
                                </label>
                                <div class="form-text">Require additional authentication for admin users</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Security Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($tab === 'backup'): ?>
                <!-- Backup Settings -->
                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-4">Create Backup</h5>
                        <form method="POST" action="settings.php?tab=backup">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            
                            <div class="mb-3">
                                <label class="form-label">Backup Type</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" id="backup_full" name="backup_type" value="full" checked>
                                    <label class="form-check-label" for="backup_full">
                                        Full Backup
                                        <div class="form-text">Complete database backup with structure and data</div>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" id="backup_data" name="backup_type" value="data">
                                    <label class="form-check-label" for="backup_data">
                                        Data Only Backup
                                        <div class="form-text">Backup only data, faster but larger files</div>
                                    </label>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-download me-2"></i>Create Backup
                            </button>
                        </form>
                    </div>
                    
                    <div class="col-md-6">
                        <h5 class="mb-4">Backup History</h5>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $backup_dir = 'backups/';
                                    if (is_dir($backup_dir)) {
                                        $files = glob($backup_dir . '*.sql');
                                        rsort($files);
                                        foreach (array_slice($files, 0, 5) as $file) {
                                            $filename = basename($file);
                                            $filetime = filemtime($file);
                                            $filesize = filesize($file);
                                            echo '<tr>
                                                    <td>' . date('Y-m-d H:i', $filetime) . '</td>
                                                    <td>Full</td>
                                                    <td>' . format_bytes($filesize) . '</td>
                                                    <td>
                                                        <a href="' . $file . '" class="btn btn-sm btn-outline-primary" download>
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                        <button onclick="deleteBackup(\'' . $filename . '\')" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                  </tr>';
                                        }
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (!is_dir('backups') || count(glob('backups/*.sql')) === 0): ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-database fa-3x mb-3"></i>
                                <p>No backup files found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function confirmTestEmail() {
    const testAddress = document.getElementById('email_test_address').value;
    if (!testAddress) {
        alert('Please enter a test email address first.');
        return false;
    }
    return confirm('Send a test email to ' + testAddress + '?');
}

function resetAllSettings() {
    if (confirm('Are you sure you want to reset all settings to their default values? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'settings.php?tab=' + '<?php echo $tab; ?>';
        
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?php echo generate_csrf_token(); ?>';
        form.appendChild(csrfToken);
        
        const resetInput = document.createElement('input');
        resetInput.type = 'hidden';
        resetInput.name = 'reset_settings';
        resetInput.value = '1';
        form.appendChild(resetInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteBackup(filename) {
    if (confirm('Are you sure you want to delete this backup file?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'settings.php?tab=backup';
        
        const csrfToken = document.createElement('input');
        csrfToken.type = 'hidden';
        csrfToken.name = 'csrf_token';
        csrfToken.value = '<?php echo generate_csrf_token(); ?>';
        form.appendChild(csrfToken);
        
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = 'delete_backup';
        deleteInput.value = filename;
        form.appendChild(deleteInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

const businessPresets = <?php echo json_encode($business_presets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const businessTypeSelect = document.getElementById('businessTypeSelect');
const businessPresetDescription = document.getElementById('businessPresetDescription');
const businessCategorySuggestions = document.getElementById('businessCategorySuggestions');

function renderBusinessPresetPreview() {
    if (!businessTypeSelect || !businessPresetDescription || !businessCategorySuggestions) {
        return;
    }

    const selectedPreset = businessPresets[businessTypeSelect.value];
    if (!selectedPreset) {
        return;
    }

    businessPresetDescription.textContent = selectedPreset.description || '';
    businessCategorySuggestions.innerHTML = (selectedPreset.category_suggestions || []).map((category) => `
        <div class="col-md-6">
            <div class="border rounded-3 p-3 h-100 bg-white">
                <div class="fw-semibold">${category.name}</div>
                <div class="small text-muted">${category.description || ''}</div>
            </div>
        </div>
    `).join('');
}

if (businessTypeSelect) {
    businessTypeSelect.addEventListener('change', renderBusinessPresetPreview);
    renderBusinessPresetPreview();
}
</script>

<?php include 'includes/footer.php'; ?>
