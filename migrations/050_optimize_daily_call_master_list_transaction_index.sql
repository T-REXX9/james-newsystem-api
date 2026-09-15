-- Supports the set-based tbltransaction scan used by the Daily Call Master
-- List. Safe to run multiple times.
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbltransaction'
      AND INDEX_NAME = 'idx_daily_call_transaction_main_date_customer');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tbltransaction ADD INDEX idx_daily_call_transaction_main_date_customer (lmain_id, ldate, lcustomerid(64))',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
