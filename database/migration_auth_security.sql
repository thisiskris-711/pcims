-- Add authentication security columns to users table

ALTER TABLE `users`
  ADD COLUMN `email_verified_at` DATETIME DEFAULT NULL AFTER `email`,
  ADD COLUMN `email_verification_token` VARCHAR(64) DEFAULT NULL AFTER `email_verified_at`,
  ADD COLUMN `reset_token` VARCHAR(64) DEFAULT NULL AFTER `email_verification_token`,
  ADD COLUMN `reset_token_expires` DATETIME DEFAULT NULL AFTER `reset_token`;
