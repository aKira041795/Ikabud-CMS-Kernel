SET @_bakeshop004_tv := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'bakeshop_users'
      AND column_name = 'token_version'
);

SET @_bakeshop004_sql := IF(
    @_bakeshop004_tv = 0,
    'ALTER TABLE bakeshop_users ADD COLUMN token_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER role',
    'SELECT 1'
);

PREPARE bakeshop004_stmt FROM @_bakeshop004_sql;
EXECUTE bakeshop004_stmt;
DEALLOCATE PREPARE bakeshop004_stmt;