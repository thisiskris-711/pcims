-- Add 'completed' status to sales_orders table
-- This allows proper business cycle completion tracking

ALTER TABLE sales_orders 
MODIFY COLUMN status ENUM('pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled') DEFAULT 'pending';

-- Update existing delivered orders to completed if they should be business-complete
-- Uncomment the line below if you want to migrate existing delivered orders
-- UPDATE sales_orders SET status = 'completed' WHERE status = 'delivered' AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
