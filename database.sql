-- Personal Collection Direct Selling, Inc. Inventory Management System Database Schema
-- Created for PCIMS - Inventory Management System

-- Create database
CREATE DATABASE IF NOT EXISTS pcims_db;
USE pcims_db;

-- Users table for authentication and role-based access
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    profile_image VARCHAR(255) NULL,
    role ENUM('admin', 'manager', 'staff', 'viewer') NOT NULL DEFAULT 'staff',
    status ENUM('active', 'inactive') DEFAULT 'active',
    reset_token VARCHAR(64) NULL,
    reset_expiry DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Activity logs table
CREATE TABLE activity_logs (
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
CREATE TABLE login_attempts (
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
CREATE TABLE account_lockouts (
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

-- Categories table for product categorization
CREATE TABLE categories (
    category_id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL,
    description TEXT,
    parent_id INT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES categories(category_id) ON DELETE SET NULL
);

-- Suppliers table
CREATE TABLE suppliers (
    supplier_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(100) NOT NULL,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    tin VARCHAR(20),
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products table
CREATE TABLE products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    product_code VARCHAR(50) UNIQUE NOT NULL,
    product_name VARCHAR(200) NOT NULL,
    description TEXT,
    category_id INT,
    supplier_id INT,
    unit_price DECIMAL(10,2) NOT NULL,
    cost_price DECIMAL(10,2) NOT NULL,
    reorder_level INT DEFAULT 10,
    lead_time_days INT DEFAULT 3,
    unit_of_measure VARCHAR(20) DEFAULT 'pcs',
    image_url VARCHAR(255),
    status ENUM('active', 'inactive', 'discontinued') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(category_id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
);

-- Inventory table (stock levels)
CREATE TABLE inventory (
    inventory_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNIQUE NOT NULL,
    quantity_on_hand INT NOT NULL DEFAULT 0,
    quantity_reserved INT NOT NULL DEFAULT 0,
    quantity_available INT GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
);

-- Stock movements table
CREATE TABLE stock_movements (
    movement_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    movement_type ENUM('in', 'out', 'adjustment', 'transfer') NOT NULL,
    quantity INT NOT NULL,
    reference_type ENUM('purchase', 'sale', 'adjustment', 'transfer', 'return') NOT NULL,
    reference_id INT,
    notes TEXT,
    user_id INT NOT NULL,
    movement_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(product_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Purchase orders table
CREATE TABLE purchase_orders (
    po_id INT AUTO_INCREMENT PRIMARY KEY,
    po_number VARCHAR(50) UNIQUE NOT NULL,
    supplier_id INT NOT NULL,
    order_date DATE NOT NULL,
    expected_date DATE,
    status ENUM('draft', 'sent', 'partial', 'received', 'cancelled') DEFAULT 'draft',
    total_amount DECIMAL(12,2) NOT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

-- Purchase order items
CREATE TABLE purchase_order_items (
    poi_id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity_ordered INT NOT NULL,
    quantity_received INT DEFAULT 0,
    unit_price DECIMAL(10,2) NOT NULL,
    total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity_ordered * unit_price) STORED,
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- Sales orders table
CREATE TABLE sales_orders (
    so_id INT AUTO_INCREMENT PRIMARY KEY,
    so_number VARCHAR(50) UNIQUE NOT NULL,
    customer_name VARCHAR(100) NOT NULL,
    customer_email VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_type ENUM('walk_in', 'registered') DEFAULT 'walk_in',
    order_date DATE NOT NULL,
    status ENUM('pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled') DEFAULT 'pending',
    global_discount_type ENUM('none', 'percent', 'fixed') DEFAULT 'none',
    global_discount_value DECIMAL(10,2) DEFAULT 0,
    global_discount_amount DECIMAL(12,2) DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    notes TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(user_id)
);

-- Sales order items
CREATE TABLE sales_order_items (
    soi_id INT AUTO_INCREMENT PRIMARY KEY,
    so_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    discount_type VARCHAR(50) DEFAULT NULL,
    total_price DECIMAL(12,2) GENERATED ALWAYS AS (quantity * unit_price * (1 - discount_percent/100)) STORED,
    FOREIGN KEY (so_id) REFERENCES sales_orders(so_id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(product_id)
);

-- Product pair suggestions table
CREATE TABLE product_pair_suggestions (
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

-- Notifications table
CREATE TABLE notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'error', 'success') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    related_to ENUM('low_stock', 'high_stock', 'order', 'system') DEFAULT 'system',
    related_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- System settings table
CREATE TABLE system_settings (
    setting_id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id)
);

-- Insert default admin user (password: admin123)
INSERT INTO users (username, password, full_name, email, role) VALUES 
('admin', '$2y$10$V7O35lEUn2/GNcqmeacRR.xUmMZ6zjBaUsUji5WIs0L7KfcYA/Rf6', 'System Administrator', 'admin@pcollection.com', 'admin');

-- Insert default manager user (password: manager123)
INSERT INTO users (username, password, full_name, email, role) VALUES 
('manager', '$2y$10$CkM9xeSJweGib0A6Dlk0PeAfAPWefteWWSMZRWOoEAmuDwZVNWV9u', 'Manager', 'manager@pcollection.com', 'manager');

-- Insert default categories
INSERT INTO categories (category_name, description) VALUES 
('Personal Care', 'Personal care and beauty products'),
('Health & Wellness', 'Health supplements and wellness products'),
('Home Care', 'Household cleaning and maintenance products'),
('Food & Beverages', 'Food items and beverages'),
('Fashion & Accessories', 'Clothing and fashion accessories');

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES 
('company_name', 'Personal Collection Direct Selling, Inc.', 'Company name'),
('low_stock_threshold', '10', 'Default low stock threshold'),
('enable_email_notifications', '1', 'Enable email notifications'),
('currency', 'PHP', 'Default currency'),
('forecast_days', '7', 'Default forecast horizon in days'),
('intelligence_sales_window_days', '30', 'Sales lookback window for rule-based insights'),
('restock_cover_days', '5', 'Number of days of demand to cover when recommending restocks'),
('default_lead_time_days', '3', 'Default lead time in days for predictive stock alerts');

-- Create indexes for better performance
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_supplier ON products(supplier_id);
CREATE INDEX idx_products_status_lead_time ON products(status, lead_time_days);
CREATE INDEX idx_inventory_product ON inventory(product_id);
CREATE INDEX idx_stock_movements_product ON stock_movements(product_id);
CREATE INDEX idx_stock_movements_date ON stock_movements(movement_date);
CREATE INDEX idx_sales_orders_status_date ON sales_orders(status, order_date);
CREATE INDEX idx_sales_order_items_product_order ON sales_order_items(product_id, so_id);
CREATE INDEX idx_product_pair_source ON product_pair_suggestions(source_product_id);
CREATE INDEX idx_notifications_user ON notifications(user_id);
CREATE INDEX idx_notifications_read ON notifications(is_read);
