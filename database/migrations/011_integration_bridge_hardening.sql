SET @_m011_req := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'kernel_integration_logs' AND column_name = 'request_id');
SET @_m011_alt1 := IF(@_m011_req = 0, 'ALTER TABLE kernel_integration_logs ADD COLUMN request_id VARCHAR(64) DEFAULT NULL AFTER error_message', 'DO 0');
PREPARE _m011_stmt1 FROM @_m011_alt1; EXECUTE _m011_stmt1; DEALLOCATE PREPARE _m011_stmt1;

SET @_m011_corr := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'kernel_integration_logs' AND column_name = 'correlation_id');
SET @_m011_alt2 := IF(@_m011_corr = 0, 'ALTER TABLE kernel_integration_logs ADD COLUMN correlation_id VARCHAR(64) DEFAULT NULL AFTER request_id', 'DO 0');
PREPARE _m011_stmt2 FROM @_m011_alt2; EXECUTE _m011_stmt2; DEALLOCATE PREPARE _m011_stmt2;

SET @_m011_dur := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'kernel_integration_logs' AND column_name = 'duration_ms');
SET @_m011_alt3 := IF(@_m011_dur = 0, 'ALTER TABLE kernel_integration_logs ADD COLUMN duration_ms INT UNSIGNED DEFAULT NULL AFTER correlation_id', 'DO 0');
PREPARE _m011_stmt3 FROM @_m011_alt3; EXECUTE _m011_stmt3; DEALLOCATE PREPARE _m011_stmt3;

SET @_m011_idx := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'kernel_integration_logs' AND index_name = 'idx_kernel_integration_logs_created');
SET @_m011_alt4 := IF(@_m011_idx = 0, 'ALTER TABLE kernel_integration_logs ADD KEY idx_kernel_integration_logs_created (created_at)', 'DO 0');
PREPARE _m011_stmt4 FROM @_m011_alt4; EXECUTE _m011_stmt4; DEALLOCATE PREPARE _m011_stmt4;
