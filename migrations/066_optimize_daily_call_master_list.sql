-- The Daily Call purchase summary filters transactions by account and date.
-- Keep those reads inside the account's date range instead of scanning every
-- tenant's transaction history.
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbltransaction'
      AND INDEX_NAME = 'idx_transaction_main_date');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tbltransaction ADD INDEX idx_transaction_main_date (lmain_id, ldate)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblaudit_trail'
      AND INDEX_NAME = 'idx_audit_daily_call_verify');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblaudit_trail ADD INDEX idx_audit_daily_call_verify (lmain_id, lpage(64), laction(32), lrefno(64), lid, luser_id)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tlbCustomer_Details'
      AND INDEX_NAME = 'idx_customer_details_session_date');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tlbCustomer_Details ADD INDEX idx_customer_details_session_date (lsessionid(64), ldate, lid)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblinvoice_list'
      AND INDEX_NAME = 'idx_invoice_list_main_date');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblinvoice_list ADD INDEX idx_invoice_list_main_date (lmain_id(64), ldate)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tbldelivery_receipt'
      AND INDEX_NAME = 'idx_delivery_receipt_main_date');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tbldelivery_receipt ADD INDEX idx_delivery_receipt_main_date (lmain_id(64), ldate)',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
