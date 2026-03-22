-- Add discount_type field to sales_order_items table
-- This will store the type of discount selected (Senior Citizen, PWD, etc.)

ALTER TABLE sales_order_items 
ADD COLUMN discount_type VARCHAR(50) DEFAULT NULL 
AFTER discount_percent;

-- Add index for better query performance
CREATE INDEX idx_sales_order_items_discount_type ON sales_order_items(discount_type);
