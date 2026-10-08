-- Phase 2 customer merge workflow.  The merge service refuses to execute until
-- every customer reference visible in the supported schema inventory is safe.
CREATE TABLE IF NOT EXISTS customer_merge_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    main_id INT NOT NULL,
    survivor_session_id VARCHAR(64) NOT NULL,
    duplicate_session_id VARCHAR(64) NOT NULL,
    final_company_name VARCHAR(255) NOT NULL,
    merge_reason TEXT NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    status ENUM('draft','previewed','approved','executing','completed','failed','cancelled','reversed') NOT NULL DEFAULT 'draft',
    requested_by INT NOT NULL,
    approved_by INT NULL,
    field_decisions JSON NULL,
    previewed_at DATETIME NULL,
    executing_at DATETIME NULL,
    completed_at DATETIME NULL,
    before_snapshot JSON NULL,
    after_snapshot JSON NULL,
    preview_snapshot JSON NULL,
    preview_checksum CHAR(64) NULL,
    failure_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_merge_idempotency (main_id, idempotency_key),
    KEY idx_customer_merge_pair (main_id, survivor_session_id, duplicate_session_id),
    KEY idx_customer_merge_status (main_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_merge_transfer_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    merge_id BIGINT UNSIGNED NOT NULL,
    table_name VARCHAR(128) NOT NULL,
    customer_reference_column VARCHAR(128) NOT NULL,
    source_customer_id VARCHAR(128) NOT NULL,
    target_customer_id VARCHAR(128) NOT NULL,
    rows_found INT NOT NULL DEFAULT 0,
    rows_updated INT NOT NULL DEFAULT 0,
    affected_checksum CHAR(64) NULL,
    transfer_status ENUM('planned','transferred','skipped','failed') NOT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_customer_merge_transfer (merge_id),
    CONSTRAINT fk_customer_merge_transfer_request FOREIGN KEY (merge_id) REFERENCES customer_merge_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_merge_redirects (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    main_id INT NOT NULL,
    old_customer_session_id VARCHAR(64) NOT NULL,
    surviving_customer_session_id VARCHAR(64) NOT NULL,
    merge_id BIGINT UNSIGNED NOT NULL,
    merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    merged_by INT NOT NULL,
    UNIQUE KEY uq_customer_merge_redirect (main_id, old_customer_session_id),
    KEY idx_customer_merge_redirect_target (main_id, surviving_customer_session_id),
    CONSTRAINT fk_customer_merge_redirect_request FOREIGN KEY (merge_id) REFERENCES customer_merge_requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'ldeleted') = 0, 'ALTER TABLE tblpatient ADD COLUMN ldeleted TINYINT(1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'ldeleted_at') = 0, 'ALTER TABLE tblpatient ADD COLUMN ldeleted_at DATETIME NULL', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'ldeleted_by') = 0, 'ALTER TABLE tblpatient ADD COLUMN ldeleted_by INT NULL', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'ldelete_reason') = 0, 'ALTER TABLE tblpatient ADD COLUMN ldelete_reason VARCHAR(500) NULL', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_merge_requests' AND COLUMN_NAME = 'field_decisions') = 0, 'ALTER TABLE customer_merge_requests ADD COLUMN field_decisions JSON NULL AFTER approved_by', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_merge_requests' AND COLUMN_NAME = 'preview_checksum') = 0, 'ALTER TABLE customer_merge_requests ADD COLUMN preview_checksum CHAR(64) NULL AFTER preview_snapshot', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient_duplicate_request') = 1 AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient_duplicate_request' AND COLUMN_NAME = 'lmerge_id') = 0, 'ALTER TABLE tblpatient_duplicate_request ADD COLUMN lmerge_id BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;

SET @merge_column_sql := IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient_duplicate_request') = 1, 'ALTER TABLE tblpatient_duplicate_request MODIFY COLUMN lstatus ENUM(''pending'', ''approved'', ''rejected'', ''snoozed'', ''merged'') DEFAULT ''pending''', 'SELECT 1');
PREPARE merge_column_stmt FROM @merge_column_sql;
EXECUTE merge_column_stmt;
DEALLOCATE PREPARE merge_column_stmt;
