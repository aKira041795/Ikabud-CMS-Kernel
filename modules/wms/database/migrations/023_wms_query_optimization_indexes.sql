-- WMS Query Optimization Indexes (April 16, 2026)
-- Targets: dashboard COUNT queries, status-based filtering, N+1 query pattern reduction

-- Index for dashboard: wms_products count by active status
ALTER TABLE `wms_products` ADD INDEX `idx_wms_products_deleted_status` (`deleted_at`, `sku`) 
    COMMENT 'Optimize: SELECT COUNT(*) FROM wms_products WHERE deleted_at IS NULL';

-- Index for dashboard: wms_warehouses count by active status
ALTER TABLE `wms_warehouses` ADD INDEX `idx_wms_warehouses_active` (`deleted_at`)
    COMMENT 'Optimize: SELECT COUNT(*) FROM wms_warehouses WHERE deleted_at IS NULL';

-- Index for dashboard: wms_locations count by active status
ALTER TABLE `wms_locations` ADD INDEX `idx_wms_locations_active` (`deleted_at`, `warehouse_id`)
    COMMENT 'Optimize: SELECT COUNT(*) WHERE deleted_at IS NULL';

-- Index for dashboard: wms_deliveries pending/partial/staged count
-- Covers status AND deleted_at for WHERE clause, includes created_at for ORDER BY
ALTER TABLE `wms_deliveries` ADD INDEX `idx_wms_deliveries_status_active` (`status`, `deleted_at`, `created_at`)
    COMMENT 'Optimize: dashboard pending/partial/staged counts';

-- Index for dashboard: wms_orders pending/picking/picked count
ALTER TABLE `wms_orders` ADD INDEX `idx_wms_orders_status_active` (`status`, `deleted_at`, `created_at`)
    COMMENT 'Optimize: dashboard pending/picking/picked counts';

-- Index for intelligence queries: movements by warehouse + type + date range
-- Covers WHERE clause and order
ALTER TABLE `wms_movements` ADD INDEX `idx_wms_movements_warehouse_type_date` (`warehouse_id`, `movement_type`, `created_at`)
    COMMENT 'Optimize: intelligence slotting and forecast queries';

-- Index for low-stock queries: coverage includes status checks
ALTER TABLE `wms_stocks` ADD INDEX `idx_wms_stocks_qty_available` (`qty_available`, `product_id`, `warehouse_id`)
    COMMENT 'Optimize: low-stock item queries';

-- Index for task exceptions: used in wmsPageTasks and task diagnostics
ALTER TABLE `wms_task_exceptions` ADD INDEX `idx_wms_task_exceptions_status_task` (`status`, `task_id`, `created_at`)
    COMMENT 'Optimize: task exception filtering and counts';

-- Index for orders: movement type and date filtering (movements list optimization)
ALTER TABLE `wms_movements` ADD INDEX `idx_wms_movements_type_created` (`movement_type`, `created_at`)
    COMMENT 'Optimize: movements list pagination queries';

-- Composite index for common stock lookups during picking/receiving
ALTER TABLE `wms_stocks` ADD INDEX `idx_wms_stocks_location_product` (`location_id`, `product_id`, `qty_available`)
    COMMENT 'Optimize: location-based product lookups during operations';
