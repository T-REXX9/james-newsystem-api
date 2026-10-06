-- Store creation time separately from the source document's business date/time.
-- Existing rows remain NULL because backdated documents cannot be reliably
-- distinguished from their actual creation time.
SET @accounting_source_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinvoice_list') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinvoice_list' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tblinvoice_list ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_source_timestamp_stmt FROM @accounting_source_timestamp_sql; EXECUTE accounting_source_timestamp_stmt; DEALLOCATE PREPARE accounting_source_timestamp_stmt;

SET @accounting_source_timestamp_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbldelivery_receipt') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbldelivery_receipt' AND COLUMN_NAME = 'created_at') = 0, 'ALTER TABLE tbldelivery_receipt ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'SELECT 1');
PREPARE accounting_source_timestamp_stmt FROM @accounting_source_timestamp_sql; EXECUTE accounting_source_timestamp_stmt; DEALLOCATE PREPARE accounting_source_timestamp_stmt;
