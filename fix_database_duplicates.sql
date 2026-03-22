-- Database fix for product duplication issue
-- This script cleans up existing duplicates and adds constraints

-- 1. Clean up existing duplicate entries in sales_order_items
-- This consolidates duplicate items within the same sales order

-- Create a temporary table to store consolidated data
CREATE TEMPORARY TABLE IF NOT EXISTS temp_consolidated_items AS
SELECT 
    MIN(soi_id) as soi_id,
    so_id,
    product_id,
    SUM(quantity) as quantity,
    unit_price,
    discount_percent,
    discount_type
FROM sales_order_items 
GROUP BY so_id, product_id, unit_price, discount_percent, discount_type
HAVING COUNT(*) > 1;

-- Delete duplicate entries (keep the one with minimum soi_id)
DELETE soi1 FROM sales_order_items soi1
INNER JOIN sales_order_items soi2 
WHERE soi1.so_id = soi2.so_id 
AND soi1.product_id = soi2.product_id
AND soi1.unit_price = soi2.unit_price
AND soi1.discount_percent = soi2.discount_percent
AND soi1.discount_type = soi2.discount_type
AND soi1.soi_id > soi2.soi_id;

-- Update the remaining entries with consolidated quantities
UPDATE sales_order_items soi
INNER JOIN (
    SELECT 
        so_id,
        product_id,
        unit_price,
        discount_percent,
        discount_type,
        SUM(quantity) as total_quantity
    FROM sales_order_items 
    GROUP BY so_id, product_id, unit_price, discount_percent, discount_type
) consolidated ON soi.so_id = consolidated.so_id
AND soi.product_id = consolidated.product_id
AND soi.unit_price = consolidated.unit_price
AND soi.discount_percent = consolidated.discount_percent
AND soi.discount_type = consolidated.discount_type
SET soi.quantity = consolidated.total_quantity;

-- 2. Add a unique constraint to prevent future duplicates
-- This ensures no duplicate products per sales order

ALTER TABLE sales_order_items 
ADD CONSTRAINT unique_so_product 
UNIQUE (so_id, product_id);

-- 3. Create a trigger to automatically handle duplicates (if constraint fails)
DELIMITER //

CREATE TRIGGER prevent_duplicate_items
BEFORE INSERT ON sales_order_items
FOR EACH ROW
BEGIN
    DECLARE duplicate_count INT;
    
    -- Check if a similar item already exists
    SELECT COUNT(*) INTO duplicate_count
    FROM sales_order_items 
    WHERE so_id = NEW.so_id 
    AND product_id = NEW.product_id;
    
    -- If duplicate found, update existing instead of inserting new
    IF duplicate_count > 0 THEN
        UPDATE sales_order_items 
        SET quantity = quantity + NEW.quantity,
            unit_price = NEW.unit_price,
            discount_percent = NEW.discount_percent,
            discount_type = NEW.discount_type
        WHERE so_id = NEW.so_id 
        AND product_id = NEW.product_id;
        
        -- Set NEW values to prevent insertion
        SET NEW.so_id = NULL;
    END IF;
END//

DELIMITER ;

-- 4. Update stock movements to match consolidated quantities
-- This ensures inventory records are accurate

-- First, identify any stock movements that might be duplicated
SELECT 
    product_id,
    reference_type,
    reference_id,
    COUNT(*) as duplicate_count,
    SUM(quantity) as total_quantity
FROM stock_movements 
WHERE reference_type = 'sale'
GROUP BY product_id, reference_type, reference_id
HAVING COUNT(*) > 1;

-- Note: Manual review may be needed for stock movements
-- The above query helps identify potential issues

-- 5. Verification query to check for remaining duplicates
SELECT 
    so_id,
    product_id,
    COUNT(*) as duplicate_count,
    SUM(quantity) as total_quantity
FROM sales_order_items 
GROUP BY so_id, product_id
HAVING COUNT(*) > 1;

-- If this query returns no results, the fix is successful
