-- =====================================================
-- Inventory Management System - Seed Data
-- =====================================================

USE `inventory_ms`;

-- -----------------------------------------------------
-- Default Admin User (password: admin123)
-- -----------------------------------------------------
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`, `status`) VALUES
('admin', 'admin@inventory.local', '$2y$12$EQMB.HpV.mxXawhfnY..2.fS5HaPk27s4eMlWSOO6U6VRoGu0b8c6', 'System Administrator', 'admin', 'active'),
('manager', 'manager@inventory.local', '$2y$12$EQMB.HpV.mxXawhfnY..2.fS5HaPk27s4eMlWSOO6U6VRoGu0b8c6', 'Store Manager', 'manager', 'active'),
('staff', 'staff@inventory.local', '$2y$12$EQMB.HpV.mxXawhfnY..2.fS5HaPk27s4eMlWSOO6U6VRoGu0b8c6', 'Store Staff', 'staff', 'active');

-- NOTE: The hash above is a bcrypt hash of "password". We'll generate proper hashes via PHP on first run.
-- For seeding, we'll use a known hash.

-- -----------------------------------------------------
-- Default Categories
-- -----------------------------------------------------
INSERT INTO `categories` (`name`, `description`, `color`, `icon`) VALUES
('Electronics', 'Electronic devices, gadgets, and accessories', '#8b5cf6', 'cpu'),
('Clothing', 'Apparel, footwear, and fashion accessories', '#ec4899', 'shirt'),
('Food & Beverages', 'Packaged food items and drinks', '#f59e0b', 'coffee'),
('Home & Garden', 'Furniture, decor, and garden supplies', '#10b981', 'home'),
('Sports & Outdoors', 'Sports equipment and outdoor gear', '#06b6d4', 'activity'),
('Books & Stationery', 'Books, office supplies, and stationery', '#6366f1', 'book-open'),
('Health & Beauty', 'Personal care, cosmetics, and health products', '#f43f5e', 'heart'),
('Automotive', 'Car parts, accessories, and maintenance products', '#64748b', 'truck');

-- -----------------------------------------------------
-- Sample Products
-- -----------------------------------------------------
INSERT INTO `products` (`sku`, `name`, `description`, `category_id`, `cost_price`, `selling_price`, `quantity`, `low_stock_threshold`, `status`, `created_by`) VALUES
('SKU-EL-0001', 'Wireless Bluetooth Headphones', 'Premium noise-cancelling wireless headphones with 30hr battery', 1, 45.00, 89.99, 150, 20, 'active', 1),
('SKU-EL-0002', 'USB-C Fast Charger 65W', 'Universal fast charger compatible with laptops and phones', 1, 12.00, 29.99, 300, 50, 'active', 1),
('SKU-EL-0003', 'Mechanical Keyboard RGB', 'Full-size mechanical keyboard with customizable RGB lighting', 1, 35.00, 74.99, 85, 15, 'active', 1),
('SKU-EL-0004', '4K Webcam Pro', 'Ultra HD webcam with auto-focus and noise reduction mic', 1, 28.00, 59.99, 60, 10, 'active', 1),
('SKU-EL-0005', 'Portable SSD 1TB', 'High-speed portable solid state drive', 1, 50.00, 109.99, 45, 10, 'active', 1),
('SKU-CL-0001', 'Classic Cotton T-Shirt', 'Comfortable 100% cotton crew neck t-shirt', 2, 5.00, 19.99, 500, 50, 'active', 1),
('SKU-CL-0002', 'Slim Fit Denim Jeans', 'Modern slim fit jeans in dark wash', 2, 18.00, 49.99, 200, 30, 'active', 1),
('SKU-CL-0003', 'Running Sneakers', 'Lightweight breathable running shoes', 2, 25.00, 69.99, 120, 20, 'active', 1),
('SKU-FB-0001', 'Organic Coffee Beans 1kg', 'Single origin Arabica coffee beans', 3, 8.00, 22.99, 400, 40, 'active', 1),
('SKU-FB-0002', 'Green Tea Collection Box', 'Assorted premium green tea varieties - 50 bags', 3, 4.00, 14.99, 250, 30, 'active', 1),
('SKU-HG-0001', 'LED Desk Lamp', 'Adjustable LED desk lamp with USB charging port', 4, 15.00, 39.99, 90, 15, 'active', 1),
('SKU-HG-0002', 'Indoor Plant Pot Set', 'Set of 3 ceramic plant pots with drainage', 4, 10.00, 34.99, 75, 10, 'active', 1),
('SKU-SP-0001', 'Yoga Mat Premium', 'Non-slip TPE yoga mat 6mm thick', 5, 12.00, 34.99, 180, 25, 'active', 1),
('SKU-SP-0002', 'Stainless Steel Water Bottle', 'Insulated 750ml water bottle - keeps cold 24hrs', 5, 6.00, 24.99, 350, 40, 'active', 1),
('SKU-BK-0001', 'Leather Notebook A5', 'Premium leather-bound lined notebook', 6, 3.50, 16.99, 220, 30, 'active', 1),
('SKU-HB-0001', 'Vitamin C Serum', 'Brightening face serum with 20% Vitamin C', 7, 5.00, 24.99, 160, 20, 'active', 1),
('SKU-AU-0001', 'Car Phone Mount', 'Universal magnetic car phone holder', 8, 4.00, 18.99, 275, 35, 'active', 1),
('SKU-EL-0006', 'Smart Watch Band', 'Replacement silicone band - multiple colors', 1, 2.50, 12.99, 8, 15, 'active', 1),
('SKU-FB-0003', 'Protein Bar Box (12)', 'High protein snack bars - chocolate flavor', 3, 10.00, 29.99, 5, 20, 'active', 1),
('SKU-SP-0003', 'Resistance Bands Set', '5-piece resistance band set with carry bag', 5, 7.00, 22.99, 3, 10, 'active', 1);

-- -----------------------------------------------------
-- Sample Stock Transactions
-- -----------------------------------------------------
INSERT INTO `stock_transactions` (`product_id`, `type`, `quantity`, `balance_after`, `reference_no`, `notes`, `created_by`, `created_at`) VALUES
(1, 'in', 200, 200, 'PO-2026-001', 'Initial stock purchase', 1, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(2, 'in', 500, 500, 'PO-2026-001', 'Initial stock purchase', 1, DATE_SUB(NOW(), INTERVAL 30 DAY)),
(3, 'in', 100, 100, 'PO-2026-002', 'Initial stock purchase', 1, DATE_SUB(NOW(), INTERVAL 28 DAY)),
(1, 'out', 50, 150, 'SO-2026-001', 'Sales order fulfillment', 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(2, 'out', 200, 300, 'SO-2026-002', 'Sales order fulfillment', 1, DATE_SUB(NOW(), INTERVAL 10 DAY)),
(6, 'in', 600, 600, 'PO-2026-003', 'Bulk purchase - summer stock', 1, DATE_SUB(NOW(), INTERVAL 20 DAY)),
(6, 'out', 100, 500, 'SO-2026-003', 'Wholesale order', 1, DATE_SUB(NOW(), INTERVAL 7 DAY)),
(9, 'in', 500, 500, 'PO-2026-004', 'Monthly restock', 1, DATE_SUB(NOW(), INTERVAL 14 DAY)),
(9, 'out', 100, 400, 'SO-2026-004', 'Weekly sales', 1, DATE_SUB(NOW(), INTERVAL 5 DAY));

-- -----------------------------------------------------
-- Sample Sales
-- -----------------------------------------------------
INSERT INTO `sales` (`invoice_no`, `customer_name`, `subtotal`, `discount`, `tax`, `total`, `payment_method`, `payment_status`, `created_by`, `created_at`) VALUES
('INV-2026-0001', 'John Smith', 179.97, 0.00, 21.60, 201.57, 'card', 'paid', 1, DATE_SUB(NOW(), INTERVAL 15 DAY)),
('INV-2026-0002', 'Walk-in Customer', 89.99, 5.00, 10.20, 95.19, 'cash', 'paid', 2, DATE_SUB(NOW(), INTERVAL 12 DAY)),
('INV-2026-0003', 'Sarah Johnson', 149.97, 10.00, 16.80, 156.77, 'transfer', 'paid', 1, DATE_SUB(NOW(), INTERVAL 10 DAY)),
('INV-2026-0004', 'Walk-in Customer', 49.99, 0.00, 6.00, 55.99, 'cash', 'paid', 2, DATE_SUB(NOW(), INTERVAL 7 DAY)),
('INV-2026-0005', 'Mike Davis', 259.96, 15.00, 29.40, 274.36, 'card', 'paid', 1, DATE_SUB(NOW(), INTERVAL 3 DAY)),
('INV-2026-0006', 'Walk-in Customer', 34.99, 0.00, 4.20, 39.19, 'cash', 'paid', 2, DATE_SUB(NOW(), INTERVAL 1 DAY));

INSERT INTO `sale_items` (`sale_id`, `product_id`, `product_name`, `quantity`, `unit_price`, `discount`, `total`) VALUES
(1, 1, 'Wireless Bluetooth Headphones', 2, 89.99, 0.00, 179.98),
(2, 1, 'Wireless Bluetooth Headphones', 1, 89.99, 5.00, 84.99),
(3, 3, 'Mechanical Keyboard RGB', 2, 74.99, 10.00, 139.98),
(4, 7, 'Slim Fit Denim Jeans', 1, 49.99, 0.00, 49.99),
(5, 5, 'Portable SSD 1TB', 2, 109.99, 10.00, 209.98),
(5, 2, 'USB-C Fast Charger 65W', 1, 29.99, 0.00, 29.99),
(5, 14, 'Stainless Steel Water Bottle', 1, 24.99, 5.00, 19.99),
(6, 10, 'Green Tea Collection Box', 1, 14.99, 0.00, 14.99),
(6, 15, 'Leather Notebook A5', 1, 16.99, 0.00, 16.99);

-- -----------------------------------------------------
-- Default Settings
-- -----------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'InventoryPro'),
('currency', 'USD'),
('currency_symbol', '$'),
('tax_rate', '12'),
('company_name', 'My Store'),
('company_address', '123 Main Street, City'),
('company_phone', '+1 234 567 8900'),
('company_email', 'store@example.com'),
('low_stock_alert', '1'),
('items_per_page', '15');
