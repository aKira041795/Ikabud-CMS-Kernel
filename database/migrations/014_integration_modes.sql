SET @_m014_im := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'kernel_integrations' AND column_name = 'integration_mode');
SET @_m014_alt := IF(@_m014_im = 0, 'ALTER TABLE kernel_integrations ADD COLUMN integration_mode VARCHAR(100) DEFAULT NULL AFTER name', 'SELECT 1');
PREPARE _stmt FROM @_m014_alt; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
