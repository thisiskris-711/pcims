-- Add type column to products table
ALTER TABLE `products`
ADD COLUMN `type` ENUM('standard', 'bundle') NOT NULL DEFAULT 'standard' AFTER `status`;

-- Create product_bundle_items table
CREATE TABLE IF NOT EXISTS `product_bundle_items` (
  `bundle_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`bundle_id`, `product_id`),
  CONSTRAINT `fk_bundle_parent` FOREIGN KEY (`bundle_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bundle_component` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;
