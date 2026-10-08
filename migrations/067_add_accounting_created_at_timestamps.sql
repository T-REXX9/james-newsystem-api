-- Add explicit creation timestamps for Accounting tables whose legacy date
-- columns represent business dates or were populated inconsistently.
-- Existing rows remain NULL: their creation time cannot be reconstructed
-- reliably from the legacy data.
-- Customer rows are already at InnoDB's inline row-size limit. Keep their
-- creation timestamp in a narrow companion table instead of widening tblpatient.
CREATE TABLE IF NOT EXISTS tblpatient_created_at (
    lmain_id INT NOT NULL,
    lsessionid VARCHAR(64) NOT NULL,
    created_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (lmain_id, lsessionid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @customer_timestamp_backfill_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'created_at') = 1, 'INSERT INTO tblpatient_created_at (lmain_id, lsessionid, created_at) SELECT lmain_id, lsessionid, created_at FROM tblpatient WHERE created_at IS NOT NULL ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)', 'SELECT 1');
PREPARE customer_timestamp_backfill_stmt FROM @customer_timestamp_backfill_sql; EXECUTE customer_timestamp_backfill_stmt; DEALLOCATE PREPARE customer_timestamp_backfill_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblledger') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblledger' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblledger ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbldebit_memo') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbldebit_memo' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tbldebit_memo ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcredit_memo') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcredit_memo' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblcredit_memo ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbladjustment') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbladjustment' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tbladjustment ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcollection') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcollection' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblcollection ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcollection_item') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcollection_item' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblcollection_item ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcredit_return_item') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcredit_return_item' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblcredit_return_item ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;
