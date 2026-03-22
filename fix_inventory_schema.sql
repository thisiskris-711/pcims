-- Fix for inventory table - Add updated_by column
-- Run this script to add the missing updated_by column to the inventory table

ALTER TABLE inventory 
ADD COLUMN updated_by INT NULL,
ADD FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL;

-- Update existing records to set updated_by to NULL (or you can set a default user ID)
-- UPDATE inventory SET updated_by = NULL WHERE updated_by IS NULL;
