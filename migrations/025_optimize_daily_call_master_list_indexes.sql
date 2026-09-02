-- Indexes to support the daily-call-monitoring/master-list query.
-- The query filters tblledger by lmainid (varchar), groups by lcustomerid,
-- and aggregates over ldatetime / ldebit. Without an index on lmainid MySQL
-- performs a full table scan (~180k rows) and spills to tmpdir, causing
-- "OS errno 28 - No space left on device" errors on low-disk environments.
-- Safe to run multiple times.

-- Primary covering index: lmainid → ldatetime, ldebit, lcustomerid, lrefno
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'tblledger'
      AND INDEX_NAME   = 'idx_ledger_mainid_datetime_customer');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblledger ADD INDEX idx_ledger_mainid_datetime_customer (lmainid(32), ldatetime, ldebit, lcustomerid(64), lrefno(64))',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
