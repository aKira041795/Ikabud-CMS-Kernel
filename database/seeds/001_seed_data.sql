-- ============================================================
-- Baron Bakeshop Daily Ledger — Seed Data
-- ============================================================

-- Admin user (password: admin123)
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin', '$2y$10$chk.jYHX5dnrEHvDd0.3QewSlqzd5H5XHbsr4.EAr09GNav9q.j7a', 'System Admin', 'admin');

-- Supervisor user (password: super123)
INSERT INTO users (username, password_hash, full_name, role) VALUES
('supervisor', '$2y$10$nCZ2qDowxZoIRZEmticALuD7mSeDWj9qMwWXe.yOEjTeNAA0neZa2', 'Branch Supervisor', 'supervisor');

-- Cashier users (password: cashier123)
INSERT INTO users (username, password_hash, full_name, role) VALUES
('cashier1', '$2y$10$ymEqvzjoQQdKXEPRFFAIueXCOERn.q1wFL38NUw3e7cx9kyHOIBLi', 'Maria Santos', 'cashier'),
('cashier2', '$2y$10$ymEqvzjoQQdKXEPRFFAIueXCOERn.q1wFL38NUw3e7cx9kyHOIBLi', 'Juan Dela Cruz', 'cashier');

-- Sample branch
INSERT INTO branches (code, name, address) VALUES
('MAIN', 'Main Branch', 'Baron Bakeshop Main Store'),
('BR02', 'Branch 2', 'Baron Bakeshop Branch 2');

-- Assign cashier1 to MAIN, cashier2 to BR02
INSERT INTO user_branches (user_id, branch_id) VALUES
(3, 1),
(4, 2);

-- Assign supervisor to MAIN
INSERT INTO user_branches (user_id, branch_id) VALUES
(2, 1);

-- Products (from the paper ledger image)
INSERT INTO products (sku, name, current_price, sort_order) VALUES
('BBS-0001', 'BDAY CAKE 1 LAYER', 370.00, 1),
('BBS-0002', 'BDAY CAKE HALF', 270.00, 2),
('BBS-0003', 'B.FOREST', 400.00, 3),
('BBS-0004', 'B.FOREST HALF', 280.00, 4),
('BBS-0005', 'MOCHA CAKE HALF', 275.00, 5),
('BBS-0006', 'MOCHA CAKE ROUND', 445.00, 6),
('BBS-0007', 'MOCHA CAKE RECTANGLE', 475.00, 7),
('BBS-0008', 'UBE CAKE HALF', 275.00, 8);

-- All products available at all branches
INSERT INTO branch_products (branch_id, product_id)
SELECT b.id, p.id FROM branches b CROSS JOIN products p;

-- Initial price history snapshots
INSERT INTO product_price_history (product_id, price, changed_by)
SELECT id, current_price, 1 FROM products;
