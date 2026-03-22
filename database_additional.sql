-- Additional tables and updates for PCIMS
-- Run this after the main database.sql file

-- Activity logs table
CREATE TABLE IF NOT EXISTS activity_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_activity_user (user_id),
    INDEX idx_activity_date (created_at)
);

-- Login attempts table
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    attempt_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    success BOOLEAN DEFAULT FALSE,
    failure_reason VARCHAR(100),
    INDEX idx_login_attempts_username (username),
    INDEX idx_login_attempts_ip_address (ip_address),
    INDEX idx_login_attempts_attempt_time (attempt_time)
);

-- Account lockouts table
CREATE TABLE IF NOT EXISTS account_lockouts (
    lockout_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    lockout_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    unlock_time DATETIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    failed_attempts INT DEFAULT 0,
    reason VARCHAR(100) DEFAULT 'Too many failed login attempts',
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_account_lockouts_user_id (user_id),
    INDEX idx_account_lockouts_username (username),
    INDEX idx_account_lockouts_ip_address (ip_address),
    INDEX idx_account_lockouts_lockout_time (lockout_time),
    INDEX idx_account_lockouts_is_active (is_active)
);

-- Create indexes for better performance on existing tables
CREATE INDEX IF NOT EXISTS idx_products_status ON products(status);
CREATE INDEX IF NOT EXISTS idx_inventory_available ON inventory(quantity_available);
CREATE INDEX IF NOT EXISTS idx_stock_movements_product_date ON stock_movements(product_id, movement_date);
CREATE INDEX IF NOT EXISTS idx_sales_orders_status_date ON sales_orders(status, order_date);
CREATE INDEX IF NOT EXISTS idx_sales_order_items_product_order ON sales_order_items(product_id, so_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_created ON notifications(created_at);

-- Add any missing columns if they don't exist
ALTER TABLE products 
ADD COLUMN IF NOT EXISTS reorder_level INT DEFAULT 10 AFTER cost_price,
ADD COLUMN IF NOT EXISTS lead_time_days INT DEFAULT 3 AFTER reorder_level,
ADD COLUMN IF NOT EXISTS unit_of_measure VARCHAR(20) DEFAULT 'pcs' AFTER reorder_level,
ADD COLUMN IF NOT EXISTS image_url VARCHAR(255) AFTER unit_of_measure;

ALTER TABLE users
ADD COLUMN IF NOT EXISTS profile_image VARCHAR(255) NULL AFTER phone,
ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) NULL AFTER status,
ADD COLUMN IF NOT EXISTS reset_expiry DATETIME NULL AFTER reset_token;

ALTER TABLE sales_orders
MODIFY COLUMN so_id INT NOT NULL AUTO_INCREMENT,
MODIFY COLUMN status ENUM('pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled') DEFAULT 'pending',
ADD COLUMN IF NOT EXISTS customer_type ENUM('walk_in', 'registered') DEFAULT 'walk_in' AFTER customer_phone,
ADD COLUMN IF NOT EXISTS global_discount_type ENUM('none', 'percent', 'fixed') DEFAULT 'none' AFTER status,
ADD COLUMN IF NOT EXISTS global_discount_value DECIMAL(10,2) DEFAULT 0 AFTER global_discount_type,
ADD COLUMN IF NOT EXISTS global_discount_amount DECIMAL(12,2) DEFAULT 0 AFTER global_discount_value;

ALTER TABLE sales_order_items
ADD COLUMN IF NOT EXISTS discount_type VARCHAR(50) DEFAULT NULL AFTER discount_percent;

CREATE TABLE IF NOT EXISTS product_pair_suggestions (
    pair_id INT AUTO_INCREMENT PRIMARY KEY,
    source_product_id INT NOT NULL,
    suggested_product_id INT NOT NULL,
    display_order INT DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_pair (source_product_id, suggested_product_id),
    FOREIGN KEY (source_product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (suggested_product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_product_pair_source ON product_pair_suggestions(source_product_id);

-- Repair known placeholder password hashes from older seed data
UPDATE users
SET password = '$2y$10$V7O35lEUn2/GNcqmeacRR.xUmMZ6zjBaUsUji5WIs0L7KfcYA/Rf6'
WHERE username = 'admin'
  AND password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

UPDATE users
SET password = '$2y$10$CkM9xeSJweGib0A6Dlk0PeAfAPWefteWWSMZRWOoEAmuDwZVNWV9u'
WHERE username = 'manager'
  AND password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('forecast_days', '7', 'Default forecast horizon in days'),
('intelligence_sales_window_days', '30', 'Sales lookback window for rule-based insights'),
('restock_cover_days', '5', 'Number of days of demand to cover when recommending restocks'),
('default_lead_time_days', '3', 'Default lead time in days for predictive stock alerts')
ON DUPLICATE KEY UPDATE
setting_value = VALUES(setting_value),
description = VALUES(description);

-- Insert sample data for testing
INSERT IGNORE INTO categories (category_id, category_name, description) VALUES 
(1, 'Personal Care', 'Personal care and beauty products'),
(2, 'Health & Wellness', 'Health supplements and wellness products'),
(3, 'Home Care', 'Household cleaning and maintenance products'),
(4, 'Food & Beverages', 'Food items and beverages'),
(5, 'Fashion & Accessories', 'Clothing and fashion accessories');

-- Insert sample products
INSERT IGNORE INTO products (product_id, product_code, product_name, description, category_id, unit_price, cost_price, reorder_level, status) VALUES 
(1, 'PC001', 'Beauty Soap', 'Premium beauty soap for daily use', 1, 45.00, 30.00, 20, 'active'),
(2, 'PC002', 'Vitamin C', 'Vitamin C supplements 500mg', 2, 120.00, 80.00, 15, 'active'),
(3, 'PC003', 'Detergent', 'Liquid detergent for clothes', 3, 85.00, 60.00, 10, 'active'),
(4, 'PC004', 'Coffee Mix', '3-in-1 coffee mix', 4, 25.00, 18.00, 50, 'active'),
(5, 'PC005', 'T-Shirt', 'Cotton t-shirt', 5, 150.00, 100.00, 25, 'active');

-- Insert inventory records for sample products
INSERT IGNORE INTO inventory (product_id, quantity_on_hand, quantity_reserved) VALUES 
(1, 50, 5),
(2, 30, 2),
(3, 15, 1),
(4, 100, 10),
(5, 25, 3);

-- Insert sample supplier
INSERT IGNORE INTO suppliers (supplier_id, supplier_name, contact_person, email, phone, address) VALUES 
(1, 'Personal Collection Main', 'John Smith', 'main@pcollection.com', '123-456-7890', '123 Main St, Manila, Philippines');

-- Update products with supplier
UPDATE products SET supplier_id = 1 WHERE supplier_id IS NULL;

-- Insert sample stock movements
INSERT IGNORE INTO stock_movements (product_id, movement_type, quantity, reference_type, notes, user_id) VALUES 
(1, 'in', 100, 'purchase', 'Initial stock', 1),
(2, 'in', 50, 'purchase', 'Initial stock', 1),
(3, 'in', 30, 'purchase', 'Initial stock', 1),
(4, 'in', 200, 'purchase', 'Initial stock', 1),
(5, 'in', 40, 'purchase', 'Initial stock', 1);

-- Insert sample notifications
INSERT IGNORE INTO notifications (user_id, title, message, type, related_to, related_id) VALUES 
(1, 'Welcome to PCIMS', 'Your inventory management system is ready to use!', 'success', 'system', NULL),
(1, 'Low Stock Alert', 'Beauty Soap is running low on stock', 'warning', 'low_stock', 1);

-- Create upload directories
-- Note: These need to be created manually or via PHP
-- uploads/
-- uploads/profiles/
-- uploads/products/
-- uploads/documents/
