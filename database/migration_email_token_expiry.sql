-- Add email verification token expiry
ALTER TABLE `users`
  ADD COLUMN `email_verification_token_expires` DATETIME DEFAULT NULL AFTER `email_verification_token`;
