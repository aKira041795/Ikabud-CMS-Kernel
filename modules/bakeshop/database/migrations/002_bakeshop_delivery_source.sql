SET @_bakeshop_delivery_source_type := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_deliveries'
      AND column_name = 'source_type'
);
SET @_bakeshop_delivery_source_type_sql := IF(
    @_bakeshop_delivery_source_type = 0,
    "ALTER TABLE bakeshop_deliveries ADD COLUMN source_type ENUM('commissary','other') NOT NULL DEFAULT 'commissary' AFTER reference",
    'SELECT 1'
);
PREPARE _bakeshop_delivery_source_type_stmt FROM @_bakeshop_delivery_source_type_sql;
EXECUTE _bakeshop_delivery_source_type_stmt;
DEALLOCATE PREPARE _bakeshop_delivery_source_type_stmt;

SET @_bakeshop_delivery_source_name := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_deliveries'
      AND column_name = 'source_name'
);
SET @_bakeshop_delivery_source_name_sql := IF(
    @_bakeshop_delivery_source_name = 0,
    "ALTER TABLE bakeshop_deliveries ADD COLUMN source_name VARCHAR(255) NULL AFTER source_type",
    'SELECT 1'
);
PREPARE _bakeshop_delivery_source_name_stmt FROM @_bakeshop_delivery_source_name_sql;
EXECUTE _bakeshop_delivery_source_name_stmt;
DEALLOCATE PREPARE _bakeshop_delivery_source_name_stmt;