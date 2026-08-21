<?php
/**
 * Rate Limiter using Database
 */

class RateLimiter {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Check if a request is allowed under the rate limit.
     *
     * @param string $ip       The IP address of the client
     * @param string $endpoint The endpoint identifier (e.g., 'login', 'api')
     * @param int $maxRequests Maximum number of requests allowed in the window
     * @param int $windowSec   Time window in seconds
     * @return bool            True if allowed, false if rate limited
     */
    public function check(string $ip, string $endpoint, int $maxRequests, int $windowSec): bool {
        // First, clean up old records for this IP and endpoint to keep table small
        $cleanupStmt = $this->db->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND endpoint = ? AND window_start < (NOW() - INTERVAL ? SECOND)");
        $cleanupStmt->execute([$ip, $endpoint, $windowSec]);

        // Get current active record
        $stmt = $this->db->prepare("SELECT id, requests FROM rate_limits WHERE ip_address = ? AND endpoint = ? LIMIT 1");
        $stmt->execute([$ip, $endpoint]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            if ($record['requests'] >= $maxRequests) {
                return false; // Rate limit exceeded
            }

            // Increment request count
            $updateStmt = $this->db->prepare("UPDATE rate_limits SET requests = requests + 1 WHERE id = ?");
            $updateStmt->execute([$record['id']]);
        } else {
            // Insert new record
            $insertStmt = $this->db->prepare("INSERT INTO rate_limits (ip_address, endpoint, requests, window_start) VALUES (?, ?, 1, NOW())");
            $insertStmt->execute([$ip, $endpoint]);
        }

        return true;
    }

    /**
     * Get the current status of the rate limit without incrementing.
     *
     * @param string $ip       The IP address of the client
     * @param string $endpoint The endpoint identifier (e.g., 'login', 'api')
     * @param int $maxRequests Maximum number of requests allowed in the window
     * @param int $windowSec   Time window in seconds
     * @return array           Array containing 'allowed' bool and 'remaining' int seconds
     */
    public function getStatus(string $ip, string $endpoint, int $maxRequests, int $windowSec): array {
        $cleanupStmt = $this->db->prepare("DELETE FROM rate_limits WHERE ip_address = ? AND endpoint = ? AND window_start < (NOW() - INTERVAL ? SECOND)");
        $cleanupStmt->execute([$ip, $endpoint, $windowSec]);

        $stmt = $this->db->prepare("SELECT id, requests, UNIX_TIMESTAMP(window_start) as window_start_ts FROM rate_limits WHERE ip_address = ? AND endpoint = ? LIMIT 1");
        $stmt->execute([$ip, $endpoint]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            if ($record['requests'] >= $maxRequests) {
                $remaining = ($record['window_start_ts'] + $windowSec) - time();
                return ['allowed' => false, 'remaining' => max(0, $remaining)];
            }
        }
        return ['allowed' => true, 'remaining' => 0];
    }
}

/**
 * Enforce rate limit globally and exit with 429 if exceeded.
 */
function enforceRateLimit(string $endpoint, int $maxRequests, int $windowSec = 60) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // We get the DB connection from helpers.php -> database.php
    $limiter = new RateLimiter(getDB());
    
    if (!$limiter->check($ip, $endpoint, $maxRequests, $windowSec)) {
        http_response_code(429);
        header('Retry-After: ' . $windowSec);
        
        // Return JSON if API endpoint, otherwise simple text
        if (strpos($endpoint, 'api') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Too Many Requests', 'message' => 'Rate limit exceeded. Please try again later.']);
        } else {
            echo "429 Too Many Requests. Please try again later.";
        }
        exit;
    }
}
