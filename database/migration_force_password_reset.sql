-- Add force password reset flag
ALTER TABLE `users`
ADD COLUMN `force_password_reset` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`;

-- Force reset for existing admin account
UPDATE `users` SET `force_password_reset` = 1 WHERE `role` = 'admin';
