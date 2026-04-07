-- Insert 5 sample products
INSERT INTO `wms_products` (`id`, `sku`, `name`, `description`, `unit`, `product_type`, `is_active`) VALUES
(1, 'SKU-001', 'Wireless Mouse', 'Ergonomic 2.4GHz wireless mouse', 'pcs', 'physical', 1),
(2, 'SKU-002', 'Mechanical Keyboard', 'RGB mechanical keyboard with blue switches', 'pcs', 'physical', 1),
(3, 'SKU-003', 'USB-C Hub', '7-in-1 Type-C adapter', 'pcs', 'physical', 1),
(4, 'SKU-004', 'Monitor Stand', 'Adjustable aluminum monitor riser', 'pcs', 'physical', 1),
(5, 'SKU-005', 'Desk Mat', 'Extra large leather desk pad', 'pcs', 'physical', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert 1 warehouse
INSERT INTO `wms_warehouses` (`id`, `code`, `name`, `address`, `is_active`) VALUES
(1, 'MAIN', 'Main Distribution Center', '123 Warehouse Blvd', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert locations (Receiving, Bin, Picking)
INSERT INTO `wms_locations` (`id`, `warehouse_id`, `code`, `name`, `type`, `is_active`) VALUES
(1, 1, 'RCV-01', 'Receiving Dock 1', 'dock', 1),
(2, 1, 'A-01', 'Aisle A Rack 01', 'bin', 1),
(3, 1, 'A-02', 'Aisle A Rack 02', 'bin', 1),
(4, 1, 'B-01', 'Aisle B Rack 01', 'bin', 1),
(5, 1, 'PACK-01', 'Packing Station 1', 'station', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Insert Delivery for Receiving
INSERT INTO `wms_deliveries` (`id`, `reference_number`, `supplier_name`, `warehouse_id`, `status`, `expected_at`) VALUES
(1, 'DEL-2023-001', 'TechSupplier Inc.', 1, 'pending', DATE_ADD(NOW(), INTERVAL 1 DAY))
ON DUPLICATE KEY UPDATE `supplier_name` = VALUES(`supplier_name`);

INSERT IGNORE INTO `wms_delivery_items` (`delivery_id`, `product_id`, `location_id`, `qty_expected`, `qty_received`) VALUES
(1, 1, 1, 50.0000, 0.0000),
(1, 2, 1, 20.0000, 0.0000),
(1, 3, 1, 100.0000, 0.0000);

-- Insert Order for Picking
INSERT INTO `wms_orders` (`id`, `order_number`, `customer_name`, `warehouse_id`, `status`, `priority`) VALUES
(1, 'ORD-2023-001', 'Acme Corp', 1, 'pending', 10)
ON DUPLICATE KEY UPDATE `customer_name` = VALUES(`customer_name`);

INSERT INTO `wms_order_items` (`order_id`, `product_id`, `qty_ordered`, `qty_reserved`, `qty_picked`) VALUES
(1, 1, 5.0000, 0.0000, 0.0000),
(1, 4, 2.0000, 0.0000, 0.0000),
(1, 5, 10.0000, 0.0000, 0.0000);

-- Insert Current Stock for Inventory
INSERT INTO `wms_stocks` (`product_id`, `warehouse_id`, `location_id`, `qty_on_hand`, `qty_reserved`) VALUES
(1, 1, 2, 100.0000, 0.0000),
(2, 1, 2, 45.0000, 0.0000),
(3, 1, 3, 200.0000, 0.0000),
(4, 1, 4, 15.0000, 0.0000),
(5, 1, 4, 30.0000, 0.0000);

-- Insert Some Movement History
INSERT INTO `wms_movements` (`movement_type`, `reference_type`, `reference_id`, `product_id`, `warehouse_id`, `location_id`, `qty`, `qty_before`, `qty_after`, `notes`) VALUES
('receive', 'delivery', 0, 1, 1, 2, 100.0000, 0.0000, 100.0000, 'Initial stock import'),
('receive', 'delivery', 0, 2, 1, 2, 45.0000, 0.0000, 45.0000, 'Initial stock import'),
('receive', 'delivery', 0, 3, 1, 3, 200.0000, 0.0000, 200.0000, 'Initial stock import'),
('receive', 'delivery', 0, 4, 1, 4, 15.0000, 0.0000, 15.0000, 'Initial stock import'),
('receive', 'delivery', 0, 5, 1, 4, 30.0000, 0.0000, 30.0000, 'Initial stock import');
