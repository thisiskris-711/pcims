-- =====================================================
-- Migration: Collections & Collection Efficiency
-- =====================================================

USE `inventory_ms`;

-- 1. Modify Sales Table
ALTER TABLE `sales` 
  ADD COLUMN `due_date` DATE DEFAULT NULL AFTER `created_at`,
  ADD COLUMN `adjustment_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total`;

-- 2. Collections Table
CREATE TABLE IF NOT EXISTS `collections` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id` INT UNSIGNED NOT NULL,
  `dealer_id` INT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` ENUM('cash_check', 'credit_memo') NOT NULL DEFAULT 'cash_check',
  `status` ENUM('active', 'voided') NOT NULL DEFAULT 'active',
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sale` (`sale_id`),
  KEY `idx_dealer` (`dealer_id`),
  KEY `idx_payment_date` (`payment_date`),
  CONSTRAINT `fk_collections_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_collections_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_collections_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3. Collection Audits Table
CREATE TABLE IF NOT EXISTS `collection_audits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collection_id` INT UNSIGNED NOT NULL,
  `action` ENUM('created', 'updated', 'voided', 'deleted') NOT NULL,
  `old_amount` DECIMAL(12,2) DEFAULT NULL,
  `new_amount` DECIMAL(12,2) DEFAULT NULL,
  `old_payment_date` DATE DEFAULT NULL,
  `new_payment_date` DATE DEFAULT NULL,
  `old_payment_method` ENUM('cash_check', 'credit_memo') DEFAULT NULL,
  `new_payment_method` ENUM('cash_check', 'credit_memo') DEFAULT NULL,
  `reason` TEXT DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collection` (`collection_id`),
  CONSTRAINT `fk_audit_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. Credit Memos Table
-- Represents approved credit memos that can be applied to accounts as payments
CREATE TABLE IF NOT EXISTS `credit_memos` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dealer_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `balance` DECIMAL(12,2) NOT NULL,
  `reason` TEXT NOT NULL,
  `status` ENUM('approved', 'applied', 'voided') NOT NULL DEFAULT 'approved',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dealer` (`dealer_id`),
  CONSTRAINT `fk_cm_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Update existing sales due_date (Set to created_at if cash, else +30 days as default)
UPDATE `sales` 
SET `due_date` = IF(payment_method = 'cash', DATE(created_at), DATE_ADD(DATE(created_at), INTERVAL 30 DAY))
WHERE `due_date` IS NULL;
