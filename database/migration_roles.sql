-- Migration for dynamic custom roles

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL UNIQUE,
  `display_name` VARCHAR(100) NOT NULL,
  `permissions` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

INSERT IGNORE INTO `roles` (`name`, `display_name`, `permissions`) VALUES 
('admin', 'Administrator', '["manage_users","manage_roles","manage_products","manage_inventory","manage_dealers","manage_suppliers","view_sales","create_sales","approve_sales","view_reports"]'),
('manager', 'Manager', '["manage_products","manage_inventory","manage_dealers","view_sales","create_sales","approve_sales","view_reports"]'),
('cashier', 'Cashier', '["view_sales","create_sales"]'),
('stocker', 'Stocker', '["manage_inventory"]'),
('auditor', 'Auditor', '["view_sales","view_reports"]');

-- Update users table role column
ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'cashier';
