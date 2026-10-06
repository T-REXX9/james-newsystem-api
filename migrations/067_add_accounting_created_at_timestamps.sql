-- Add explicit creation timestamps for Accounting tables whose legacy date
-- columns represent business dates or were populated inconsistently.
-- Existing rows remain NULL: their creation time cannot be reconstructed
-- reliably from the legacy data.
SET @accounting_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblpatient ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_timestamp_stmt FROM @accounting_timestamp_sql; EXECUTE accounting_timestamp_stmt; DEALLOCATE PREPARE accounting_timestamp_stmt;

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
