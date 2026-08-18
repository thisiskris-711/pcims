ALTER TABLE `users` ADD COLUMN `permissions` JSON DEFAULT NULL AFTER `last_login`;
