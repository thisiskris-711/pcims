<?php
/**
 * Helper / Utility Functions
 */

/**
 * Sanitize user input
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Format number as currency
 */
function formatCurrency(float $amount, string $symbol = '₱'): string {
    return $symbol . number_format($amount, 2);
}

/**
 * Generate a unique SKU
 */
function generateSKU(string $categoryPrefix = 'GN'): string {
    $db = getDB();
    $number = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    $sku = 'SKU-' . strtoupper($categoryPrefix) . '-' . $number;
    
    // Ensure uniqueness
    $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
    $stmt->execute([$sku]);
    if ($stmt->fetchColumn() > 0) {
        return generateSKU($categoryPrefix); // Retry
    }
    
    return $sku;
}

/**
 * Log an action to the audit_logs table
 */
function logAudit(string $action, ?int $targetUserId = null, ?string $oldValue = null, ?string $newValue = null): void {
    $db = getDB();
    $currentUserId = $_SESSION['user_id'] ?? null;
    
    try {
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, action, target_user_id, old_value, new_value) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$currentUserId, $action, $targetUserId, $oldValue, $newValue]);
    } catch (Exception $e) {
        // Silently fail for audit logs to not interrupt main processes, but you could error_log here
    }
}

/**
 * Generate invoice number
 */
function generateInvoiceNo(): string {
    $db = getDB();
    $year = date('Y');
    
    $stmt = $db->prepare("SELECT COUNT(*) + 1 FROM sales WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $seq = $stmt->fetchColumn();
    
    return 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

/**
 * Generate stock reference number
 */
function generateReferenceNo(string $type = 'ST'): string {
    $db = getDB();
    $prefix = $type === 'in' ? 'PO' : ($type === 'out' ? 'SO' : 'ADJ');
    $year = date('Y');
    
    $stmt = $db->prepare("SELECT COUNT(*) + 1 FROM stock_transactions WHERE YEAR(created_at) = ? AND type = ?");
    $stmt->execute([$year, $type]);
    $seq = $stmt->fetchColumn();
    
    // Add microsecond fallback to prevent rare race condition collisions
    $micro = substr(microtime(false), 2, 4);
    return $prefix . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT) . '-' . $micro;
}

/**
 * Generate credit reference number
 */
function generateCreditReferenceNo(string $prefix = 'PAY'): string {
    $db = getDB();
    $year = date('Y');
    
    $stmt = $db->prepare("SELECT COUNT(*) + 1 FROM credit_transactions WHERE YEAR(created_at) = ? AND type = ?");
    $type = $prefix === 'PAY' ? 'payment' : 'charge';
    $stmt->execute([$year, $type]);
    $seq = $stmt->fetchColumn();
    
    $micro = substr(microtime(false), 2, 4);
    return $prefix . '-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT) . '-' . $micro;
}

/**
 * Redirect to a URL
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Set a flash message
 */
function flashMessage(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = [
        'message' => $message,
        'type'    => $type, // success, error, warning, info
    ];
}

/**
 * Get and clear flash message
 */
function getFlashMessage(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Time ago helper
 */
function timeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $diff = time() - $time;
    
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    if ($diff < 2592000) return floor($diff / 604800) . 'w ago';
    
    return date('M j, Y', $time);
}

/**
 * Get category prefix from name for SKU generation
 */
function getCategoryPrefix(string $name): string {
    $words = explode(' ', $name);
    if (count($words) >= 2) {
        return substr($words[0], 0, 1) . substr($words[1], 0, 1);
    }
    return substr($name, 0, 2);
}

/**
 * Handle file upload (hardened)
 */
function handleImageUpload(array $file, string $directory = ''): ?string {
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $uploadDir = UPLOAD_DIR . ($directory ? $directory . '/' : '');
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if ($file['size'] > $maxSize) {
        return null;
    }

    // Whitelist allowed extensions (don't trust client-supplied type)
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowedExtensions)) {
        return null;
    }

    // Server-side MIME validation using file content analysis (not browser header)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedType = $finfo->file($file['tmp_name']);
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($detectedType, $allowedMimeTypes)) {
        return null;
    }

    // Verify it's actually a valid image (prevents disguised PHP files)
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return null;
    }
    
    // Generate safe filename (no user input in filename, no path traversal)
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ($directory ? $directory . '/' : '') . $filename;
    }
    
    return null;
}

/**
 * Handle 3D model upload
 */
function handle3DModelUpload(array $file, string $directory = ''): ?string {
    $uploadDir = UPLOAD_DIR . ($directory ? $directory . '/' : '');
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['glb', 'gltf'];
    $maxSize = 20 * 1024 * 1024; // 20MB
    
    if (!in_array($ext, $allowedExts)) {
        return null;
    }
    
    if ($file['size'] > $maxSize) {
        return null;
    }
    
    $filename = uniqid('model_', true) . '.' . $ext;
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return ($directory ? $directory . '/' : '') . $filename;
    }
    
    return null;
}

/**
 * Delete an uploaded file
 */
function deleteUploadedFile(?string $filename): bool {
    if (!$filename) return false;
    $filepath = UPLOAD_DIR . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return false;
}

/**
 * Get paginated results
 */
function paginate(string $table, string $where = '1=1', array $params = [], int $page = 1, int $perPage = ITEMS_PER_PAGE): array {
    $db = getDB();
    
    // Count total
    $countStmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    
    $totalPages = max(1, ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current_page'=> $page,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}

/**
 * JSON response helper for API endpoints
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get Lucide icon SVG (inline) — returns an <i> tag with data attribute for JS rendering
 */
function icon(string $name, int $size = 18, string $class = ''): string {
    return '<i data-lucide="' . sanitize($name) . '" class="icon ' . sanitize($class) . '" style="width:' . $size . 'px;height:' . $size . 'px;"></i>';
}

/**
 * Get setting value from database
 */
function getSetting(string $key, string $default = ''): string {
    static $cache = [];
    
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value !== false ? $value : $default;
    } catch (Exception $e) {
        $cache[$key] = $default;
    }
    
    return $cache[$key];
}

/**
 * CSRF token generation and validation
 */
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken(?string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

function verifyCSRFToken(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null;
    
    if (!$token && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['csrf_token'] ?? null;
    }
    
    if (!validateCSRFToken($token)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token']);
        exit;
    }
}

/**
 * Create a new notification
 */
function createNotification(?int $userId, string $title, string $message, string $type = 'info', ?string $link = null): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        return $stmt->execute([$userId, $type, $title, $message, $link]);
    } catch (Exception $e) {
        error_log("Failed to create notification: " . $e->getMessage());
        return false;
    }
}
