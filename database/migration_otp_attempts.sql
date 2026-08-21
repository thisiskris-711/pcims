-- Add OTP attempt tracking columns to users table

ALTER TABLE `users`
  ADD COLUMN `email_verification_attempts` INT DEFAULT 0 AFTER `email_verification_token_expires`,
  ADD COLUMN `reset_token_attempts` INT DEFAULT 0 AFTER `reset_token_expires`;
