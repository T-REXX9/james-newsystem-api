-- Keep the Sales Inquiry page on indexed, bounded lookups.
-- Safe to run multiple times.

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblpatient'
      AND INDEX_NAME = 'idx_tblpatient_main_company_lid');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblpatient ADD INDEX idx_tblpatient_main_company_lid (lmain_id, lcompany(128), lid)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblinquiry'
      AND INDEX_NAME = 'idx_tblinquiry_main_cancel_lid');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblinquiry ADD INDEX idx_tblinquiry_main_cancel_lid (lmain_id, IsCancel, lid)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblinquiry_item'
      AND INDEX_NAME = 'idx_tblinquiry_item_ref');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblinquiry_item ADD INDEX idx_tblinquiry_item_ref (linq_refno(64))',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbltransaction'
      AND INDEX_NAME = 'idx_tbltransaction_main_inquiry_cancel');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tbltransaction ADD INDEX idx_tbltransaction_main_inquiry_cancel (lmain_id, linquiry_refno(64), lcancel)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblcontact_person'
      AND INDEX_NAME = 'idx_tblcontact_person_ref');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblcontact_person ADD INDEX idx_tblcontact_person_ref (lrefno(64))',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
