CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `requests` INT NOT NULL DEFAULT 1,
  `window_start` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ip_endpoint` (`ip_address`, `endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
