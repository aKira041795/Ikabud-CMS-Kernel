# WMS Module — Warehouse Management System

**Module ID:** `wms`  
**Version:** 1.2.0 (Phase 5)
**Author:** Ikabud Kernel Team  
**Depends:** _(none — standalone)_

---

## Overview

The WMS module is a reference-grade, entity-driven Warehouse Management System that runs inside the Ikabud Kernel OS. It acts as the core proof-of-architecture for the Kernel OS, demonstrating how complex, transaction-heavy business logic (ERP-lite) can run securely alongside public-facing modules (like CMS and Ecommerce) without cross-contamination.

It conforms fully to the kernel's multi-tenant architecture: `wms_*` tables live in each tenant's own database. No dedicated WMS database is needed — `wmsDb()` wraps `module('wms')->db()` → `ModuleDB` → `app()->db()`.

### Core Principles

1. **Movement-First Architecture** — `wms_movements` is the immutable source of truth. `wms_stocks` is merely a cached projection of those movements. Stock is never mutated directly.
2. **Tenant Isolation at DB Level** — Each tenant has their own database. No `tenant_id` columns are used, eliminating query leaks.
3. **Concurrency Safe** — All stock mutations use strict `SELECT ... FOR UPDATE` row-level locking to prevent race conditions during high-volume picking.
4. **Idempotent Operations** — Movements support `idempotency_key` verification to safely handle network retries without double-deducting stock.
5. **Traceable Reservations** — Stock reservations are strictly linked to specific `reference_type` and `reference_id` (e.g. an Order ID), making allocation debuggable.
6. **Operational Intelligence** — Built-in auto-replenishment, slotting optimization, forecasting, and explicit worker task assignments (`wms_tasks`).
7. **Production Ready** — Natively handles Bill of Materials (Recipes) and automatic raw material consumption for manufacturing/bakery use cases.

---

## Database Architecture

### How `wmsDb()` Works

```
wmsDb()
  → module('wms')->db()           // ModuleContext
  → ModuleDB::db()                // table-sandbox enforcer
  → app()->db()                   // tenant PDO (auto-resolved per request)
  → kernel_tenant_db_connections  // control-plane lookup
  → cmsnewtest / baronbakeshop / etc.  // tenant's own database
```

`wms_*` tables are applied to each tenant's database on first request via `syncTenantMigrationsForCurrentRequest()`.

---

## Entity Definitions

### 1. `wms_products`

The product catalog. Each product is a SKU-addressable entity with optional barcode support and batch-tracking flag.

```sql
CREATE TABLE IF NOT EXISTS wms_products (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku              VARCHAR(100) NOT NULL,
    barcode          VARCHAR(100) NULL DEFAULT NULL COMMENT 'EAN-13/QR/custom',
    name             VARCHAR(255) NOT NULL,
    description      TEXT NULL DEFAULT NULL,
    unit             VARCHAR(50) NOT NULL DEFAULT 'pcs' COMMENT 'pcs, kg, L, box, etc.',
    product_type     VARCHAR(50) NOT NULL DEFAULT 'physical',
    weight           DECIMAL(10,4) NULL DEFAULT NULL,
    dimensions       JSON NULL DEFAULT NULL COMMENT '{length, width, height, unit}',
    is_batch_tracked TINYINT(1) NOT NULL DEFAULT 0,
    meta             JSON NULL DEFAULT NULL,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at       DATETIME NULL DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wms_sku (sku),
    INDEX idx_wms_product_barcode (barcode),
    INDEX idx_wms_product_type (product_type),
    INDEX idx_wms_product_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2. `wms_warehouses`

Physical warehouse definitions. Each warehouse has a unique code and address metadata.

```sql
CREATE TABLE IF NOT EXISTS wms_warehouses (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(50) NOT NULL,
    name                VARCHAR(255) NOT NULL,
    address             TEXT NULL DEFAULT NULL,
    quarantine_location_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'Default location for returns inspection',
    contact_info        JSON NULL DEFAULT NULL COMMENT '{phone, email, manager}',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at          DATETIME NULL DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wms_warehouse_code (code),
    INDEX idx_wms_warehouse_active (is_active),
    INDEX idx_wms_warehouses_quarantine (quarantine_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3. `wms_locations`

Hierarchical storage locations within a warehouse. Supports zone → rack → shelf → bin depth via `parent_id` self-reference.

```sql
CREATE TABLE IF NOT EXISTS wms_locations (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    warehouse_id   INT UNSIGNED NOT NULL,
    parent_id      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Self-ref for hierarchy',
    code           VARCHAR(100) NOT NULL COMMENT 'e.g. A-01-02-03',
    name           VARCHAR(255) NOT NULL,
    type           ENUM('zone','rack','shelf','bin') NOT NULL DEFAULT 'bin',
    capacity       DECIMAL(14,4) NULL DEFAULT NULL,
    capacity_unit  VARCHAR(50) NULL DEFAULT NULL,
    sort_order     INT UNSIGNED NOT NULL DEFAULT 0,
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at     DATETIME NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wms_location_code (warehouse_id, code),
    INDEX idx_wms_location_warehouse (warehouse_id),
    INDEX idx_wms_location_parent (parent_id),
    INDEX idx_wms_location_type (type),
    CONSTRAINT fk_wms_location_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id) ON DELETE CASCADE,
    CONSTRAINT fk_wms_location_parent FOREIGN KEY (parent_id)
        REFERENCES wms_locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4. `wms_batches`

Lot/batch tracking with optional expiry. Enables FIFO/FEFO picking strategies.

```sql
CREATE TABLE IF NOT EXISTS wms_batches (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id     INT UNSIGNED NOT NULL,
    batch_number   VARCHAR(100) NOT NULL,
    lot_number     VARCHAR(100) NULL DEFAULT NULL,
    manufactured_at DATE NULL DEFAULT NULL,
    expires_at     DATE NULL DEFAULT NULL,
    meta           JSON NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wms_batch (product_id, batch_number),
    INDEX idx_wms_batch_product (product_id),
    INDEX idx_wms_batch_expires (expires_at),
    CONSTRAINT fk_wms_batch_product FOREIGN KEY (product_id)
        REFERENCES wms_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5. `wms_stocks` _(cached — never directly mutated)_

Materialized stock levels per product/location/batch. **Only `wmsMovementCreate()` may write to this table.** All reads are safe; all writes must go through the MovementService.

```sql
CREATE TABLE IF NOT EXISTS wms_stocks (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    warehouse_id  INT UNSIGNED NOT NULL,
    location_id   INT UNSIGNED NOT NULL,
    batch_id      INT UNSIGNED NULL DEFAULT NULL,
    qty_on_hand   DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    qty_reserved  DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    qty_available DECIMAL(14,4) GENERATED ALWAYS AS (qty_on_hand - qty_reserved) STORED,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wms_stock (product_id, location_id, batch_id),
    INDEX idx_wms_stock_product (product_id),
    INDEX idx_wms_stock_warehouse (warehouse_id),
    INDEX idx_wms_stock_location (location_id),
    INDEX idx_wms_stock_batch (batch_id),
    CONSTRAINT fk_wms_stock_product FOREIGN KEY (product_id)
        REFERENCES wms_products (id),
    CONSTRAINT fk_wms_stock_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id),
    CONSTRAINT fk_wms_stock_location FOREIGN KEY (location_id)
        REFERENCES wms_locations (id),
    CONSTRAINT fk_wms_stock_batch FOREIGN KEY (batch_id)
        REFERENCES wms_batches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6. `wms_movements` _(immutable — the core audit log)_

Every stock change is recorded here before `wms_stocks` is updated. Records are **never deleted or updated** — they are the single source of truth.

```sql
CREATE TABLE IF NOT EXISTS wms_movements (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    movement_type   ENUM(
                        'in',
                        'out',
                        'transfer_out',
                        'transfer_in',
                        'adjustment',
                        'cycle_count_adjustment',
                        'reserved',
                        'unreserved'
                    ) NOT NULL,
    reference_type  VARCHAR(50) NULL DEFAULT NULL COMMENT 'delivery|order|transfer|cycle_count',
    reference_id    INT UNSIGNED NULL DEFAULT NULL,
    product_id      INT UNSIGNED NOT NULL,
    warehouse_id    INT UNSIGNED NOT NULL,
    location_id     INT UNSIGNED NOT NULL,
    batch_id        INT UNSIGNED NULL DEFAULT NULL,
    qty             DECIMAL(14,4) NOT NULL COMMENT 'Positive = in, negative = out',
    qty_before      DECIMAL(14,4) NOT NULL,
    qty_after       DECIMAL(14,4) NOT NULL,
    unit_cost       DECIMAL(14,4) NULL DEFAULT NULL,
    notes           TEXT NULL DEFAULT NULL,
    actor_user_id   INT UNSIGNED NULL DEFAULT NULL,
    meta            JSON NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wms_movement_product (product_id),
    INDEX idx_wms_movement_location (location_id),
    INDEX idx_wms_movement_type (movement_type),
    INDEX idx_wms_movement_reference (reference_type, reference_id),
    INDEX idx_wms_movement_created (created_at),
    INDEX idx_wms_movement_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7. `wms_idempotency_keys`

Guarantees strict once-and-only-once execution of stock movements, preventing accidental duplicate shipments during network retries.

```sql
CREATE TABLE IF NOT EXISTS wms_idempotency_keys (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    idempotency_key VARCHAR(100) NOT NULL,
    movement_id     BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wms_idempotency_key (idempotency_key),
    KEY idx_wms_idempotency_movement (movement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8. `wms_deliveries`

Inbound delivery headers. Represents a supplier shipment arriving at a warehouse.

```sql
CREATE TABLE IF NOT EXISTS wms_deliveries (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_no   VARCHAR(100) NOT NULL,
    supplier_name  VARCHAR(255) NULL DEFAULT NULL,
    warehouse_id   INT UNSIGNED NOT NULL,
    status         ENUM('pending','partial','received','cancelled') NOT NULL DEFAULT 'pending',
    expected_at    DATE NULL DEFAULT NULL,
    received_at    DATETIME NULL DEFAULT NULL,
    notes          TEXT NULL DEFAULT NULL,
    actor_user_id  INT UNSIGNED NULL DEFAULT NULL,
    meta           JSON NULL DEFAULT NULL,
    deleted_at     DATETIME NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wms_delivery_warehouse (warehouse_id),
    INDEX idx_wms_delivery_status (status),
    INDEX idx_wms_delivery_expected (expected_at),
    CONSTRAINT fk_wms_delivery_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 9. `wms_delivery_items`

Line items within a delivery. Stores expected vs received quantities and the target put-away location.

```sql
CREATE TABLE IF NOT EXISTS wms_delivery_items (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_id    INT UNSIGNED NOT NULL,
    product_id     INT UNSIGNED NOT NULL,
    location_id    INT UNSIGNED NOT NULL COMMENT 'Target put-away location',
    batch_id       INT UNSIGNED NULL DEFAULT NULL,
    qty_expected   DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    qty_received   DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    unit_cost      DECIMAL(14,4) NULL DEFAULT NULL,
    notes          TEXT NULL DEFAULT NULL,
    INDEX idx_wms_ditem_delivery (delivery_id),
    INDEX idx_wms_ditem_product (product_id),
    CONSTRAINT fk_wms_ditem_delivery FOREIGN KEY (delivery_id)
        REFERENCES wms_deliveries (id) ON DELETE CASCADE,
    CONSTRAINT fk_wms_ditem_product FOREIGN KEY (product_id)
        REFERENCES wms_products (id),
    CONSTRAINT fk_wms_ditem_location FOREIGN KEY (location_id)
        REFERENCES wms_locations (id),
    CONSTRAINT fk_wms_ditem_batch FOREIGN KEY (batch_id)
        REFERENCES wms_batches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 10. `wms_orders`

Outbound order headers. Represents a fulfillment request from a warehouse.

```sql
CREATE TABLE IF NOT EXISTS wms_orders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_no   VARCHAR(100) NOT NULL,
    order_type     VARCHAR(50) NOT NULL DEFAULT 'standard',
    warehouse_id   INT UNSIGNED NOT NULL,
    status         ENUM('pending','picking','picked','dispatched','cancelled') NOT NULL DEFAULT 'pending',
    priority       TINYINT UNSIGNED NOT NULL DEFAULT 5,
    requested_at   DATETIME NULL DEFAULT NULL,
    dispatched_at  DATETIME NULL DEFAULT NULL,
    notes          TEXT NULL DEFAULT NULL,
    actor_user_id  INT UNSIGNED NULL DEFAULT NULL,
    meta           JSON NULL DEFAULT NULL,
    deleted_at     DATETIME NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wms_order_warehouse (warehouse_id),
    INDEX idx_wms_order_status (status),
    INDEX idx_wms_order_priority (priority),
    CONSTRAINT fk_wms_order_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 11. `wms_order_items`

Line items within an order. The `location_id` is assigned by the pick-list generator (FIFO/FEFO selection).

```sql
CREATE TABLE IF NOT EXISTS wms_order_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    batch_id     INT UNSIGNED NULL DEFAULT NULL COMMENT 'Set by pick-list (FIFO/FEFO)',
    location_id  INT UNSIGNED NULL DEFAULT NULL COMMENT 'Set by pick-list',
    qty_ordered  DECIMAL(14,4) NOT NULL,
    qty_picked   DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    notes        TEXT NULL DEFAULT NULL,
    INDEX idx_wms_oitem_order (order_id),
    INDEX idx_wms_oitem_product (product_id),
    CONSTRAINT fk_wms_oitem_order FOREIGN KEY (order_id)
        REFERENCES wms_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_wms_oitem_product FOREIGN KEY (product_id)
        REFERENCES wms_products (id),
    CONSTRAINT fk_wms_oitem_batch FOREIGN KEY (batch_id)
        REFERENCES wms_batches (id) ON DELETE SET NULL,
    CONSTRAINT fk_wms_oitem_location FOREIGN KEY (location_id)
        REFERENCES wms_locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 12. `wms_putaway_rules`

Configurable storage strategy rules. Evaluated when `wmsPutAwaySuggest()` is called to recommend a put-away location. Rules are ordered by `priority` descending.

```sql
CREATE TABLE IF NOT EXISTS wms_putaway_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    product_type    VARCHAR(50) NULL DEFAULT NULL COMMENT 'Match by product_type, null = all',
    product_id      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Match specific product, null = all',
    warehouse_id    INT UNSIGNED NOT NULL,
    preferred_zone  VARCHAR(100) NULL DEFAULT NULL COMMENT 'Location code prefix / zone code',
    strategy        ENUM('fifo','lifo','fefo','manual') NOT NULL DEFAULT 'fifo',
    priority        TINYINT UNSIGNED NOT NULL DEFAULT 50,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wms_putaway_warehouse (warehouse_id),
    INDEX idx_wms_putaway_priority (priority),
    CONSTRAINT fk_wms_putaway_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 13. `wms_cycle_counts`

Periodic audit / inventory count sessions. A count can be scoped to a specific location or cover an entire warehouse.

```sql
CREATE TABLE IF NOT EXISTS wms_cycle_counts (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference_no   VARCHAR(100) NOT NULL,
    warehouse_id   INT UNSIGNED NOT NULL,
    location_id    INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = full warehouse count',
    status         ENUM('open','in_progress','completed','cancelled') NOT NULL DEFAULT 'open',
    counted_by     INT UNSIGNED NULL DEFAULT NULL COMMENT 'actor user id',
    completed_at   DATETIME NULL DEFAULT NULL,
    notes          TEXT NULL DEFAULT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wms_count_warehouse (warehouse_id),
    INDEX idx_wms_count_status (status),
    CONSTRAINT fk_wms_count_warehouse FOREIGN KEY (warehouse_id)
        REFERENCES wms_warehouses (id),
    CONSTRAINT fk_wms_count_location FOREIGN KEY (location_id)
        REFERENCES wms_locations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 14. `wms_suppliers`

Supplier directory. Links to `wms_deliveries` via `supplier_id` for proper vendor tracking and lead-time management.

```sql
CREATE TABLE IF NOT EXISTS wms_suppliers (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code            VARCHAR(50) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    contact_person  VARCHAR(255) DEFAULT NULL,
    email           VARCHAR(255) DEFAULT NULL,
    phone           VARCHAR(50) DEFAULT NULL,
    address         TEXT DEFAULT NULL,
    lead_time_days  SMALLINT UNSIGNED DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    meta            JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wms_suppliers_code (code),
    KEY idx_wms_suppliers_active (is_active, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`wms_deliveries.supplier_id` (FK, nullable) was added in migration `011_wms_suppliers.sql`.

### 15. `wms_returns`

Reverse logistics header. Tracks goods coming back from customers or rejected inbound shipments.

```sql
CREATE TABLE IF NOT EXISTS wms_returns (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_number VARCHAR(100) NOT NULL,
    order_id         INT UNSIGNED DEFAULT NULL,
    customer_name    VARCHAR(255) DEFAULT NULL,
    warehouse_id     INT UNSIGNED NOT NULL,
    status           ENUM('pending','inspecting','restocked','disposed','cancelled') NOT NULL DEFAULT 'pending',
    reason           VARCHAR(500) DEFAULT NULL,
    received_at      DATETIME DEFAULT NULL,
    notes            TEXT DEFAULT NULL,
    meta             JSON DEFAULT NULL,
    created_by       INT UNSIGNED DEFAULT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wms_returns_reference_number (reference_number),
    KEY idx_wms_returns_warehouse (warehouse_id),
    KEY idx_wms_returns_order (order_id),
    KEY idx_wms_returns_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 16. `wms_return_items`

Line items within a return. Captures per-product condition, returned quantity, and the resulting restock movement (if eligible).

```sql
CREATE TABLE IF NOT EXISTS wms_return_items (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    return_id           INT UNSIGNED NOT NULL,
    product_id          INT UNSIGNED NOT NULL,
    location_id         INT UNSIGNED NOT NULL,
    batch_id            INT UNSIGNED DEFAULT NULL,
    qty_returned        DECIMAL(14,4) NOT NULL DEFAULT '0.0000',
    qty_restocked       DECIMAL(14,4) NOT NULL DEFAULT '0.0000',
    condition           ENUM('good','damaged','expired','unknown') NOT NULL DEFAULT 'unknown',
    notes               VARCHAR(500) DEFAULT NULL,
    restock_movement_id BIGINT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wms_return_items_return (return_id),
    KEY idx_wms_return_items_product (product_id),
    KEY idx_wms_return_items_location (location_id),
    KEY idx_wms_return_items_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Only items with `condition = 'good'` generate a `wmsMovementCreate()` call on `wmsApiReturnRestock()`. Damaged/expired items are logged but not restocked.

### 17. `wms_cycle_count_items`

Individual line items within a cycle count. Captures system qty at count time vs physically counted qty. Variance is computed. On `wmsCycleCountClose()`, non-zero variances generate `cycle_count_adjustment` movements.

```sql
CREATE TABLE IF NOT EXISTS wms_cycle_count_items (
    id                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cycle_count_id            INT UNSIGNED NOT NULL,
    product_id                INT UNSIGNED NOT NULL,
    location_id               INT UNSIGNED NOT NULL,
    batch_id                  INT UNSIGNED NULL DEFAULT NULL,
    qty_system                DECIMAL(14,4) NOT NULL COMMENT 'System stock at time of count snapshot',
    qty_counted               DECIMAL(14,4) NULL DEFAULT NULL COMMENT 'NULL = not yet counted',
    qty_variance              DECIMAL(14,4) GENERATED ALWAYS AS
                                  (COALESCE(qty_counted, qty_system) - qty_system) STORED,
    adjustment_movement_id    BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'Set after adjustment applied',
    notes                     TEXT NULL DEFAULT NULL,
    INDEX idx_wms_ccitem_count (cycle_count_id),
    INDEX idx_wms_ccitem_product (product_id),
    CONSTRAINT fk_wms_ccitem_count FOREIGN KEY (cycle_count_id)
        REFERENCES wms_cycle_counts (id) ON DELETE CASCADE,
    CONSTRAINT fk_wms_ccitem_product FOREIGN KEY (product_id)
        REFERENCES wms_products (id),
    CONSTRAINT fk_wms_ccitem_location FOREIGN KEY (location_id)
        REFERENCES wms_locations (id),
    CONSTRAINT fk_wms_ccitem_batch FOREIGN KEY (batch_id)
        REFERENCES wms_batches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Relationship Diagram

```
wms_warehouses ──────┬──────────────────────────────────────┐
                     │                                      │
                     ▼                                      ▼
              wms_locations ◄─── parent_id (self)    wms_deliveries
              (zone/rack/                             wms_orders
               shelf/bin)                            wms_cycle_counts
                     │                               wms_putaway_rules
                     │ location_id
                     ▼
              wms_stocks ◄──── ONLY updated by wmsMovementCreate()
              (cached)               │
                                     ▼
                              wms_movements  ◄─── reference_type/id
                              (immutable)         (delivery|order|transfer|cycle_count)

wms_products ────────┬──── wms_stocks
                     ├──── wms_movements
                     ├──── wms_delivery_items
                     ├──── wms_order_items
                     ├──── wms_cycle_count_items
                     ├──── wms_return_items
                     └──── wms_batches ──── wms_stocks (batch_id)
                                      └─── wms_movements (batch_id)

wms_suppliers ───────── wms_deliveries.supplier_id (nullable FK)

wms_returns ─────────── wms_return_items
                              │
                              └── restock_movement_id → wms_movements (on restock)
```

---

## Service Layer Design

All service functions live in `modules/wms/helpers/` and are loaded via `helpers.php`. The implemented split is:

- `00-bootstrap.php` — capability handler map
- `10-core.php` — module context helpers, render wrapper, settings, capability handlers
- `20-stock.php` — stock snapshot, low-stock checks, immutable movement creation, reserve/release flows
- `30-operations.php` — receiving, picking, transfers, putaway suggestions, cycle count closeout, reports

### Core Connection Helper

```php
// helpers/10-core.php
function wmsDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    $ctx = module('wms');
    if (!$ctx) {
        throw new \RuntimeException('WMS module context unavailable');
    }
    return $ctx->db();
}
```

### Other Service Functions

| Function | File | Description |
|----------|------|-------------|
| `wmsStockGet(productId, locationId, batchId)` | `helpers/20-stock.php` | Fetch single stock record |
| `wmsStockSnapshot(warehouseId, filters)` | `helpers/20-stock.php` | Current stock per product/location |
| `wmsMovementCreate(data)` | `helpers/20-stock.php` | Record immutable movement and update `wms_stocks` atomically |
| `wmsReserveStock(item)` | `helpers/20-stock.php` | Reserve available stock through movement ledger |
| `wmsReleaseStock(item)` | `helpers/20-stock.php` | Release reserved stock through movement ledger |
| `wmsDeliveryReceive(deliveryId, actorUserId)` | `helpers/30-operations.php` | Confirm receipt → creates `in` movements for all items |
| `wmsOrderGeneratePickList(orderId)` | `helpers/30-operations.php` | Assign batch+location per item using configured strategy |
| `wmsOrderPick(orderId, actorUserId)` | `helpers/30-operations.php` | Confirm picks → creates `out` movements |
| `wmsOrderDispatch(orderId)` | `helpers/30-operations.php` | Mark dispatched, fire `wms.order.dispatched` |
| `wmsTransferCreate(fromLocationId, toLocationId, items, actorUserId)` | `helpers/30-operations.php` | Create `transfer_out` + `transfer_in` movement pair |
| `wmsPutAwaySuggest(productId, warehouseId)` | `helpers/30-operations.php` | Evaluate `wms_putaway_rules`, return ranked location suggestions |
| `wmsCycleCountSnapshot(cycleCountId)` | `helpers/30-operations.php` | Snapshot current `wms_stocks` into `wms_cycle_count_items` |
| `wmsCycleCountClose(cycleCountId, actorUserId)` | `helpers/30-operations.php` | Finalize count → create `cycle_count_adjustment` movements for variances |
| `wmsVelocityReport(days)` | `helpers/30-operations.php` | Aggregate movement-driven product velocity |
| `wmsExpiryReport(days)` | `helpers/30-operations.php` | Report upcoming expiring batches |

### Manual Stock Adjustment

`POST /api/v1/wms/stock/adjust` uses `wmsMovementCreate()` with `movement_type = 'adjustment'` and `reference_type = 'manual_adjustment'`. Positive `qty` adds stock; negative removes. A non-empty `reason` is required and stored in both `wms_movements.notes` and the audit trail.

### FIFO / FEFO Pick Selection

`wmsOrderGeneratePickList()` selects the best batch+location for each order item:

```sql
-- FEFO (expires soonest first) when batch_tracked = 1
SELECT s.location_id, s.batch_id, b.expires_at, s.qty_on_hand - s.qty_reserved AS qty_available
FROM wms_stocks s
LEFT JOIN wms_batches b ON b.id = s.batch_id
WHERE s.product_id = :product_id
  AND s.warehouse_id = :warehouse_id
  AND (s.qty_on_hand - s.qty_reserved) > 0
ORDER BY b.expires_at ASC NULLS LAST, s.updated_at ASC
LIMIT 1;
```

---

## API Endpoints

All endpoints return JSON. Prefix: `/api/v1/wms/`

### Products

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/products` | `wmsApiProductsList` | List products (search, type, active filter) |
| POST | `/api/v1/wms/products` | `wmsApiProductCreate` | Create product |
| GET | `/api/v1/wms/products/{id}` | `wmsApiProductGet` | Get single product |
| POST | `/api/v1/wms/products/{id}` | `wmsApiProductUpdate` | Update product |
| POST | `/api/v1/wms/products/{id}/delete` | `wmsApiProductDelete` | Soft-delete product |

### Warehouses

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/warehouses` | `wmsApiWarehousesList` | List warehouses |
| POST | `/api/v1/wms/warehouses` | `wmsApiWarehouseCreate` | Create warehouse |
| GET | `/api/v1/wms/warehouses/{id}` | `wmsApiWarehouseGet` | Get warehouse |
| POST | `/api/v1/wms/warehouses/{id}` | `wmsApiWarehouseUpdate` | Update warehouse |

### Locations

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/locations` | `wmsApiLocationsList` | List locations (filter by warehouse, type, parent) |
| POST | `/api/v1/wms/locations` | `wmsApiLocationCreate` | Create location |
| GET | `/api/v1/wms/locations/{id}` | `wmsApiLocationGet` | Get location with hierarchy path |
| POST | `/api/v1/wms/locations/{id}` | `wmsApiLocationUpdate` | Update location |
| GET | `/api/v1/wms/locations/{id}/children` | `wmsApiLocationChildren` | Get direct children |

### Stock

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/stock` | `wmsApiStockSnapshot` | Current stock (filter: product, warehouse, location) |
| GET | `/api/v1/wms/stock/low` | `wmsApiStockLow` | Products below threshold |
| GET | `/api/v1/wms/movements` | `wmsApiMovementsList` | Movement history (filter: product, type, date range) |

### Deliveries (Inbound)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/deliveries` | `wmsApiDeliveriesList` | List deliveries |
| POST | `/api/v1/wms/deliveries` | `wmsApiDeliveryCreate` | Create delivery with items |
| GET | `/api/v1/wms/deliveries/{id}` | `wmsApiDeliveryGet` | Get delivery + items |
| POST | `/api/v1/wms/deliveries/{id}/receive` | `wmsApiDeliveryReceive` | Confirm receipt → generates `in` movements |
| POST | `/api/v1/wms/deliveries/{id}/cancel` | `wmsApiDeliveryCancel` | Cancel pending delivery |

### Orders (Outbound)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/orders` | `wmsApiOrdersList` | List orders |
| POST | `/api/v1/wms/orders` | `wmsApiOrderCreate` | Create order with items |
| GET | `/api/v1/wms/orders/{id}` | `wmsApiOrderGet` | Get order + items |
| GET | `/api/v1/wms/orders/{id}/picklist` | `wmsApiOrderPickList` | Generate pick list (FIFO/FEFO) |
| POST | `/api/v1/wms/orders/{id}/pick` | `wmsApiOrderPick` | Confirm picks → generates `out` movements |
| POST | `/api/v1/wms/orders/{id}/dispatch` | `wmsApiOrderDispatch` | Mark dispatched |
| POST | `/api/v1/wms/orders/{id}/cancel` | `wmsApiOrderCancel` | Cancel order |

### Transfers (Internal)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| POST | `/api/v1/wms/transfers` | `wmsApiTransferCreate` | Move stock between locations → `transfer_out` + `transfer_in` pair |
| GET | `/api/v1/wms/transfers` | `wmsApiTransfersList` | Movement history filtered to transfer types |

### Batches

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/batches` | `wmsApiBatchesList` | List batches (filter by product, expiry range) |
| POST | `/api/v1/wms/batches` | `wmsApiBatchCreate` | Create batch/lot |
| GET | `/api/v1/wms/batches/{id}` | `wmsApiBatchGet` | Get batch + stock levels |

### Cycle Counts (Audit)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/cycle-counts` | `wmsApiCycleCountsList` | List cycle counts |
| POST | `/api/v1/wms/cycle-counts` | `wmsApiCycleCountCreate` | Open a new count session |
| GET | `/api/v1/wms/cycle-counts/{id}` | `wmsApiCycleCountGet` | Get count + items |
| POST | `/api/v1/wms/cycle-counts/{id}/snapshot` | `wmsApiCycleCountSnapshot` | Snapshot current stock into items |
| POST | `/api/v1/wms/cycle-counts/{id}/item/{itemId}` | `wmsApiCycleCountRecordItem` | Record physical count for one item |
| POST | `/api/v1/wms/cycle-counts/{id}/complete` | `wmsApiCycleCountComplete` | Close count → adjustment movements for variances |

### Put-Away Rules

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/putaway-rules` | `wmsApiPutawayRulesList` | List rules |
| POST | `/api/v1/wms/putaway-rules` | `wmsApiPutawayRuleCreate` | Create rule |
| POST | `/api/v1/wms/putaway-rules/{id}` | `wmsApiPutawayRuleUpdate` | Update rule |
| GET | `/api/v1/wms/putaway/suggest` | `wmsApiPutawaySuggest` | `?product_id=&warehouse_id=` → suggested locations |

### Stock Adjustment

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| POST | `/api/v1/wms/stock/adjust` | `wmsApiStockAdjust` | Manual qty correction — positive or negative. Records `adjustment` movement. Requires `reason`. |

### Suppliers

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/suppliers` | `wmsApiSuppliersList` | List suppliers (filter by `q`, `active`) |
| POST | `/api/v1/wms/suppliers` | `wmsApiSupplierCreate` | Create supplier |
| GET | `/api/v1/wms/suppliers/{id}` | `wmsApiSupplierGet` | Get supplier + recent deliveries |
| POST | `/api/v1/wms/suppliers/{id}` | `wmsApiSupplierUpdate` | Update supplier |
| POST | `/api/v1/wms/suppliers/{id}/delete` | `wmsApiSupplierDelete` | Soft-delete supplier |

### Returns (Reverse Logistics)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/returns` | `wmsApiReturnsList` | List returns (filter by status, warehouse) |
| POST | `/api/v1/wms/returns` | `wmsApiReturnCreate` | Log a return with items |
| GET | `/api/v1/wms/returns/{id}` | `wmsApiReturnGet` | Get return header + items |
| POST | `/api/v1/wms/returns/{id}/restock` | `wmsApiReturnRestock` | Restock `good`-condition items → `in` movements |

### Users (Staff Management)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/users` | `wmsApiUsersList` | List WMS staff (admin only) |
| POST | `/api/v1/wms/users` | `wmsApiUserCreate` | Create staff account (admin only) |
| POST | `/api/v1/wms/users/{id}` | `wmsApiUserUpdate` | Update role, password, or active status |
| POST | `/api/v1/wms/users/{id}/delete` | `wmsApiUserDelete` | Deactivate account. Cannot self-deactivate. |

### Reports

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/api/v1/wms/reports/stock-snapshot` | `wmsApiReportStockSnapshot` | Full stock snapshot by warehouse |
| GET | `/api/v1/wms/reports/movement-history` | `wmsApiReportMovements` | Movement log with filters |
| GET | `/api/v1/wms/reports/velocity` | `wmsApiReportVelocity` | Fast vs slow movers (movements/day over period) |
| GET | `/api/v1/wms/reports/expiry` | `wmsApiReportExpiry` | Batches expiring within N days |

### Admin Pages (Entry Module)

| Method | Path | Handler | Description |
|--------|------|---------|-------------|
| GET | `/wms` | `wmsPageDashboard` | Dashboard: stock summary, pending deliveries/orders |
| GET | `/wms/receiving` | `wmsPageReceiving` | Inbound deliveries list + receive UI |
| GET | `/wms/picking` | `wmsPagePicking` | Outbound orders + pick list UI |
| GET | `/wms/inventory` | `wmsPageInventory` | Stock browser + manual adjustment widget |
| GET | `/wms/suppliers` | `wmsPageSuppliers` | Supplier directory CRUD |
| GET | `/wms/returns` | `wmsPageReturns` | Returns queue + restock actions |
| GET | `/wms/users` | `wmsPageUsers` | WMS staff management (admin only) |
| GET | `/wms/settings` | `wmsPageSettings` | Module settings (putaway rules, thresholds) |

---

## Sample Flows

### Flow 1: Receiving Stock (Inbound)

```
1. POST /api/v1/wms/deliveries
   {
     "reference_no": "PO-2026-001",
     "supplier_name": "Acme Supplies",
     "warehouse_id": 1,
     "expected_at": "2026-04-10",
     "items": [
       { "product_id": 5, "location_id": 12, "qty_expected": 100, "unit_cost": 45.00 },
       { "product_id": 6, "location_id": 13, "qty_expected": 50, "batch_number": "LOT-A1", "expires_at": "2027-01-01" }
     ]
   }
   → Creates wms_deliveries (status: pending) + wms_delivery_items

2. POST /api/v1/wms/deliveries/1/receive
   { "actor_user_id": 7 }
   → For each delivery_item:
       wmsMovementCreate({
           movement_type: 'in',
           reference_type: 'delivery',
           reference_id: 1,
           product_id: 5,
           warehouse_id: 1,
           location_id: 12,
           qty: 100,
           ...
       })
   → Updates wms_stocks (qty_on_hand += 100)
   → Updates delivery status to 'received'
   → Fires event: wms.delivery.received
```

### Flow 2: Internal Transfer

```
1. POST /api/v1/wms/transfers
   {
     "from_location_id": 12,
     "to_location_id": 25,
     "items": [
       { "product_id": 5, "qty": 30, "batch_id": null }
     ],
     "notes": "Rebalancing zone A to zone B",
     "actor_user_id": 7
   }

2. wmsTransferCreate() calls wmsMovementCreate() twice:
   → Movement A: transfer_out, location_id=12, qty=-30
   → Movement B: transfer_in,  location_id=25, qty=+30
   → Both in one DB transaction
   → wms_stocks updated at both locations
```

### Flow 3: Fulfilling an Order (Outbound)

```
1. POST /api/v1/wms/orders
   {
     "reference_no": "ORD-2026-0042",
     "warehouse_id": 1,
     "items": [
       { "product_id": 5, "qty_ordered": 20 },
       { "product_id": 6, "qty_ordered": 10 }
     ]
   }
   → Creates wms_orders (status: pending) + wms_order_items

2. GET /api/v1/wms/orders/1/picklist
   → wmsOrderGeneratePickList():
     - For product 5: SELECT location + batch with available stock, FEFO order
     - Updates wms_order_items with batch_id + location_id
     - Reserves qty (movement_type: 'reserved')
   → Returns pick list: [{ product, location, batch, qty }]

3. POST /api/v1/wms/orders/1/pick
   { "actor_user_id": 8 }
   → wmsOrderPick():
     - Creates 'out' movement for each item
     - Releases 'reserved' movement
     - Updates order status to 'picked'

4. POST /api/v1/wms/orders/1/dispatch
   → Updates order status to 'dispatched'
   → Sets dispatched_at
   → Fires event: wms.order.dispatched
```

### Flow 4: Cycle Count (Audit)

```
1. POST /api/v1/wms/cycle-counts
   { "reference_no": "CC-2026-Q1", "warehouse_id": 1, "location_id": null }
   → Creates wms_cycle_counts (status: open)

2. POST /api/v1/wms/cycle-counts/1/snapshot
   → wmsCycleCountSnapshot():
     Reads all wms_stocks for warehouse, inserts wms_cycle_count_items with qty_system

3. POST /api/v1/wms/cycle-counts/1/item/42
   { "qty_counted": 95 }  (system had 100)
   → Updates wms_cycle_count_items.qty_counted = 95
   → qty_variance computed as -5

4. POST /api/v1/wms/cycle-counts/1/complete
   → wmsCycleCountClose():
     For each item with qty_variance != 0:
       wmsMovementCreate({
           movement_type: 'cycle_count_adjustment',
           reference_type: 'cycle_count',
           reference_id: 1,
           qty: -5,  (the variance)
           ...
       })
   → Updates cycle count status to 'completed'
   → Full audit trail preserved in wms_movements
```

### Flow 5: Manual Stock Adjustment

```
1. POST /api/v1/wms/stock/adjust
   {
     "product_id": 3,
     "warehouse_id": 1,
     "location_id": 8,
     "qty": -2,
     "reason": "Damaged — found broken during pick"
   }

2. wmsMovementCreate({
       movement_type: 'adjustment',
       reference_type: 'manual_adjustment',
       qty: -2,
       notes: 'Damaged — found broken during pick',
       ...
   })
   → qty_on_hand -= 2 in wms_stocks
   → Immutable movement record created
   → Audit trail entry created
```

### Flow 6: Customer Return (Reverse Logistics)

```
1. POST /api/v1/wms/returns
   {
     "reference_number": "RET-2026-001",
     "customer_name": "Juan dela Cruz",
     "warehouse_id": 1,
     "reason": "Wrong item delivered",
     "items": [
       { "product_id": 5, "location_id": 12, "qty_returned": 2, "condition": "good" },
       { "product_id": 6, "location_id": 12, "qty_returned": 1, "condition": "damaged" }
     ]
   }
   → Creates wms_returns (status: pending) + wms_return_items

2. POST /api/v1/wms/returns/1/restock
   → For each item where condition = 'good':
       wmsMovementCreate({
           movement_type: 'in',
           reference_type: 'return',
           reference_id: 1,
           product_id: 5,
           qty: 2,
           ...
       })
       qty_on_hand += 2 for product 5
   → product_id 6 (damaged) is skipped
   → Return status → 'restocked'
   → wms_return_items.restock_movement_id set for restocked items
```

---

## Module File Structure

```
modules/wms/
├── module.json                          # Manifest
├── routes.php                           # Route definitions
├── handlers.php                         # Handler loader
├── helpers.php                          # Helper loader
│
├── handlers/
│   ├── 00-bootstrap.php                 # response guard + shared request helpers
│   ├── 05-auth.php                      # WMS login page, auth POST, logout handlers
│   ├── 10-pages.php                     # dashboard + admin page handlers
│   ├── 20-api-catalog.php               # products, warehouses, locations, batches
│   ├── 30-api-inventory.php             # stock, movements, putaway, reports, adjustment
│   ├── 40-api-operations.php            # deliveries, orders, transfers, cycle counts
│   ├── 50-api-suppliers.php             # supplier CRUD
│   ├── 60-api-returns.php               # returns + restock flow
│   └── 70-api-users.php                 # WMS staff management
│
├── helpers/
│   ├── 00-bootstrap.php                 # capability handler map
│   ├── 10-core.php                      # wmsDb(), wmsInput(), wmsRender(), settings
│   ├── 20-stock.php                     # stock snapshots, low-stock checks, movements
│   └── 30-operations.php                # receiving, picking, transfers, putaway, cycle counts, reports
│
├── database/
│   └── migrations/
│       ├── 001_wms_products.sql
│       ├── 002_wms_warehouses_locations.sql
│       ├── 003_wms_batches.sql
│       ├── 004_wms_stocks.sql
│       ├── 005_wms_movements.sql
│       ├── 006_wms_deliveries.sql
│       ├── 007_wms_orders.sql
│       ├── 008_wms_putaway_rules.sql
│       ├── 009_wms_cycle_counts.sql
│       ├── 010_wms_users.sql
│       ├── 011_wms_suppliers.sql         # wms_suppliers + supplier_id on wms_deliveries
│       └── 012_wms_returns.sql           # wms_returns + wms_return_items
│
└── templates/
    └── modules/wms/
        ├── layouts/
        │   └── admin.disyl                    # Shared WMS admin shell
        └── admin/
            ├── dashboard.disyl                # DiSyL — warehouse overview
            ├── receiving.disyl                # DiSyL — inbound deliveries UI
            ├── picking.disyl                  # DiSyL — pick queue UI
            ├── inventory.disyl                # DiSyL — stock browser + manual adjustment
            ├── suppliers.disyl                # DiSyL — supplier directory CRUD
            ├── returns.disyl                  # DiSyL — returns queue + restock actions
            ├── users.disyl                    # DiSyL — WMS staff management (admin only)
            └── settings.disyl                 # DiSyL — runtime + putaway rules view
```

---

## module.json Overview

```json
{
    "id": "wms",
    "name": "Warehouse Management System",
    "version": "1.1.0",
    "description": "Entity-driven WMS — inventory tracking, inbound/outbound, transfers, cycle counts, returns, suppliers.",
    "author": "Ikabud Kernel Team",
    "owns_tables": [
        "wms_products", "wms_warehouses", "wms_locations", "wms_batches",
        "wms_stocks", "wms_movements", "wms_deliveries", "wms_delivery_items",
        "wms_orders", "wms_order_items", "wms_putaway_rules",
        "wms_cycle_counts", "wms_cycle_count_items",
        "wms_users", "wms_suppliers", "wms_returns", "wms_return_items"
    ],
    "reads_tables": [],
    "migrations": [
        "database/migrations/001_wms_products.sql",
        "database/migrations/002_wms_warehouses_locations.sql",
        "database/migrations/003_wms_batches.sql",
        "database/migrations/004_wms_stocks.sql",
        "database/migrations/005_wms_movements.sql",
        "database/migrations/006_wms_deliveries.sql",
        "database/migrations/007_wms_orders.sql",
        "database/migrations/008_wms_putaway_rules.sql",
        "database/migrations/009_wms_cycle_counts.sql",
        "database/migrations/010_wms_users.sql",
        "database/migrations/011_wms_suppliers.sql",
        "database/migrations/012_wms_returns.sql"
    ],
    "capabilities": {
        "exposes": [
            { "id": "wms.stock.query@1",   "priority": 50, "modes": ["first"] },
            { "id": "wms.stock.reserve@1", "priority": 50, "modes": ["first"] },
            { "id": "wms.stock.release@1", "priority": 50, "modes": ["first"] }
        ],
        "depends": []
    },
    "events": [
        { "key": "wms.delivery.received",  "description": "Fired when a delivery is confirmed received" },
        { "key": "wms.order.dispatched",   "description": "Fired when an order is dispatched" },
        { "key": "wms.movement.created",   "description": "Fired after every stock movement" },
        { "key": "wms.stock.low",          "description": "Fired when product stock falls below threshold" }
    ],
    "nav": [
        { "label": "Warehouse", "url": "/wms",           "icon": "warehouse", "roles": ["admin", "supervisor", "viewer"] },
        { "label": "Receiving",  "url": "/wms/receiving", "icon": "inbox",     "roles": ["admin", "supervisor"] },
        { "label": "Picking",    "url": "/wms/picking",   "icon": "box",       "roles": ["admin", "supervisor"] },
        { "label": "Inventory",  "url": "/wms/inventory",  "icon": "layers",    "roles": ["admin", "supervisor", "viewer"] },
        { "label": "Suppliers",  "url": "/wms/suppliers",  "icon": "factory",   "roles": ["admin", "supervisor", "viewer"] },
        { "label": "Returns",    "url": "/wms/returns",    "icon": "return",    "roles": ["admin", "supervisor", "viewer"] },
        { "label": "Users",      "url": "/wms/users",      "icon": "user",      "roles": ["admin"] },
        { "label": "Settings",   "url": "/wms/settings",   "icon": "settings",  "roles": ["admin", "supervisor"] }
    ]
}
```

---

## Kernel Integration Points

| Point | Mechanism | Detail |
|-------|-----------|--------|
| **DB connection** | `module('wms')->db()` | ModuleDB wrapping `app()->db()` (tenant-resolved) |
| **Migration auto-apply** | `syncTenantMigrationsForCurrentRequest()` | `wms_*` tables created in tenant DB on first request |
| **Module catalog** | `kernel_module_catalog` in control DB | WMS registered on installation |
| **Auth** | `app()->requireAnyRole()` | All API endpoints require authentication |
| **Audit logging** | `module('wms')->audit()` | Stock mutations + delivery/order state changes |
| **Events** | `module('wms')->fireEvent()` | `wms.delivery.received`, `wms.order.dispatched`, `wms.stock.low`, `wms.movement.created` |
| **Capabilities** | `app()->capabilities()->register()` | `wms.stock.query@1`, `wms.stock.reserve@1`, `wms.stock.release@1` |
| **Entry module** | `kernel_tenants.entry_module_id = 'wms'` | Tenant entry via TenantEntryRouter; `/` rewrites to `/wms` |

---

## No `.env` Changes Required

The WMS module uses `app()->db()` — the tenant-resolved database connection — exactly like the CMS module. No new environment variables are needed. The WMS tables are created automatically in each tenant's own database via the kernel's migration runner.

---

## Roadmap & Next Phases (Post-Core Stability)

*Note: As of Version 1.2.0, Phases 2 through 6 have been implemented into the codebase. The following serves as a record of the architectural intent behind these features.*

### Phase 2 — Operational Intelligence Layer (Implemented)
Turning the tracking system into an active decision-making engine.
- **Demand & Stock Intelligence:** Introduce reorder points, safety stock calculations, and supplier lead-time evaluation via a new `wms.replenishment.suggest@1` capability.
- **Auto-Replenishment (Internal):** Generate automatic transfer tasks from bulk reserve areas to picking bins when stock thresholds are breached.
- **Explicit Task System (`wms_tasks`):** Shift from implicit operations to an explicit queue. Track, assign, and measure the execution of putaway, picking, transfer, and cycle count tasks.
- **Live Activity Dashboard:** Shift from historical reporting to live operational monitoring (active picks, pending deliveries, movement rates).

### Phase 3 — Execution Layer (Human + Device Integration)
Connecting the system to physical warehouse realities.
- **Barcode / QR Workflows:** Implement scanning for product, location, and action confirmation to reduce errors and UI friction during receiving, picking, and transfers.
- **Mobile-First Interfaces:** Design optimized, high-contrast UI screens tailored for warehouse workers (e.g., "My Tasks", "Scan to Confirm") on handheld devices.
- **Offline Mode:** Explore localized task caching to handle poor warehouse connectivity, syncing movements gracefully upon reconnection.

### Phase 4 — Intelligence & Optimization
Leveraging the data model for physical efficiency.
- **Slotting Optimization:** Utilize velocity reports to suggest warehouse rearrangement (e.g., moving fast-moving items to accessible zones).
- **Pick Path Routing:** Optimize pick lists to calculate the shortest path across bins (leveraging location code hierarchy).
- **Simple Forecasting:** Introduce 7–30 day moving averages to predict demand, feeding directly into internal replenishment suggestions.

### Phase 5 — Production & Assembly Integration

- **Entity Expansion:** Introduce `wms_recipes` and `wms_production_orders`.
- **BOM (Bill of Materials) Consumption:** Auto-deduct raw materials/ingredients via `OUT` movements and record finished goods via `IN` movements.
- **Expiry-Aware Production:** Enforce strict FEFO logic specifically for production consumption (e.g., consuming near-expiry raw materials first).

### Phase 6 — Kernel OS Ecosystem Leverage
Unlocking the multi-module power of the Kernel OS.
- **Cross-Module Expositions:** Expand usage of `wms.stock.query@1` and `wms.stock.reserve@1` so modules like POS/Ecommerce can synchronously validate stock before sale.
- **Event-Driven Automation:** Aggressively wire WMS events to Kernel OS triggers (e.g., `wms.stock.low` triggers a purchase request notification; `wms.order.dispatched` triggers the billing module).
- **Multi-Context Deployments:** Scale the exact same WMS module implementation across diverse tenant environments (school inventory, food production, enterprise asset tracking) without code changes.
