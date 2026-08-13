-- =====================================================
-- Migration: Dealer Registration & Credit Management
-- =====================================================

USE `inventory_ms`;

-- -----------------------------------------------------
-- Dealers Table
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `dealers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dealer_code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `credit_limit` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `credit_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('active','suspended','inactive') NOT NULL DEFAULT 'active',
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dealer_code` (`dealer_code`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_dealers_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Credit Transactions Table (audit trail)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `credit_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dealer_id` INT UNSIGNED NOT NULL,
  `sale_id` INT UNSIGNED DEFAULT NULL,
  `type` ENUM('charge','payment') NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reference_no` VARCHAR(50) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dealer` (`dealer_id`),
  KEY `idx_sale` (`sale_id`),
  KEY `idx_type` (`type`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_credit_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_credit_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_credit_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- -----------------------------------------------------
-- Alter Sales Table: add dealer_id, add 'credit' to payment_status
-- -----------------------------------------------------
-- Add dealer_id column
ALTER TABLE `sales` ADD COLUMN `dealer_id` INT UNSIGNED DEFAULT NULL AFTER `invoice_no`;
ALTER TABLE `sales` ADD KEY `idx_dealer` (`dealer_id`);
ALTER TABLE `sales` ADD CONSTRAINT `fk_sales_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers`(`id`) ON DELETE SET NULL;

-- Expand payment_status enum to include 'credit'
ALTER TABLE `sales` MODIFY COLUMN `payment_status` ENUM('paid','pending','refunded','credit') NOT NULL DEFAULT 'paid';

-- Remove old walk-in sales data (will be replaced by dealer-based seed data)
DELETE FROM `sale_items`;
DELETE FROM `stock_transactions` WHERE notes LIKE 'POS Sale:%';
DELETE FROM `sales`;

-- Drop the old customer_name column (no longer needed)
ALTER TABLE `sales` DROP COLUMN `customer_name`;
