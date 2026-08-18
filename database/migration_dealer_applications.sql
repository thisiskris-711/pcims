-- =====================================================
-- Migration: Dealer Applications
-- =====================================================

USE `inventory_ms`;

CREATE TABLE IF NOT EXISTS `dealer_applications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `address1` TEXT DEFAULT NULL,
  `region` VARCHAR(100) DEFAULT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `barangay` VARCHAR(100) DEFAULT NULL,
  `preferred_branch` VARCHAR(100) DEFAULT NULL,
  `source` VARCHAR(100) DEFAULT NULL,
  `recruiter_id` VARCHAR(50) DEFAULT NULL,
  `recruiter_name` VARCHAR(150) DEFAULT NULL,
  `recruiter_phone` VARCHAR(20) DEFAULT NULL,
  `recruiter_fb` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB;
