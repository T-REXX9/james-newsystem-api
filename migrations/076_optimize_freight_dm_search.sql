-- Scope freight debit memo searches to a company and scan newest records first.
SET @freight_dm_index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbldebit_memo'
      AND INDEX_NAME = 'idx_debit_memo_mainid_lid'
);
SET @freight_dm_index_sql := IF(
    @freight_dm_index_exists = 0
    AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbldebit_memo') = 1,
    'ALTER TABLE tbldebit_memo ADD INDEX idx_debit_memo_mainid_lid (lmain_id(32), lid)',
    'SELECT 1'
);
PREPARE freight_dm_index_stmt FROM @freight_dm_index_sql;
EXECUTE freight_dm_index_stmt;
DEALLOCATE PREPARE freight_dm_index_stmt;
