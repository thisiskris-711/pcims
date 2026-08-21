-- =====================================================
-- Promotions Table Migration
-- =====================================================

CREATE TABLE IF NOT EXISTS `promotions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `type` ENUM('category_discount', 'spend_threshold', 'bundle_deal', 'buy_x_get_y') NOT NULL,
  `description` TEXT DEFAULT NULL,
  `config` JSON NOT NULL COMMENT 'Type-specific parameters',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_active_dates` (`is_active`, `start_date`, `end_date`)
) ENGINE=InnoDB;

-- Seed sample promotions
INSERT INTO `promotions` (`name`, `type`, `description`, `config`, `is_active`) VALUES
('Buy 2 Same Category - 10% Off', 'category_discount', 'Buy 2 or more products from the same category and get 10% off those items.', '{"min_items": 2, "discount_percent": 10}', 1),
('Spend ₱500 - Save ₱50', 'spend_threshold', 'Get ₱50 off when your cart subtotal reaches ₱500.', '{"threshold": 500, "discount_amount": 50}', 1),
('Spend ₱1000 - Save ₱120', 'spend_threshold', 'Get ₱120 off when your cart subtotal reaches ₱1,000.', '{"threshold": 1000, "discount_amount": 120}', 1);
