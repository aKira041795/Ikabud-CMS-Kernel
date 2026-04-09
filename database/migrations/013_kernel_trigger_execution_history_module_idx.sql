-- In MariaDB 10.6+ / MySQL 5.7+, duplicate indexes are somewhat warned, but we can just DO it, or better yet, since it's a new migration, just run it blindly.
ALTER TABLE kernel_trigger_executions ADD INDEX idx_module_created_sys (module, created_at);
