-- Master-only Agent Sales Report deletion is soft and independently auditable.
-- Safe to run multiple times.

SET @add_thread_deleted_at = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_threads' AND COLUMN_NAME = 'report_deleted_at') = 0, 'ALTER TABLE call_report_threads ADD COLUMN report_deleted_at DATETIME NULL AFTER report_body', 'SELECT 1');
PREPARE stmt FROM @add_thread_deleted_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_thread_deleted_by = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_threads' AND COLUMN_NAME = 'report_deleted_by') = 0, 'ALTER TABLE call_report_threads ADD COLUMN report_deleted_by INT NULL AFTER report_deleted_at', 'SELECT 1');
PREPARE stmt FROM @add_thread_deleted_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_thread_delete_reason = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_threads' AND COLUMN_NAME = 'report_delete_reason') = 0, 'ALTER TABLE call_report_threads ADD COLUMN report_delete_reason VARCHAR(2000) NULL AFTER report_deleted_by', 'SELECT 1');
PREPARE stmt FROM @add_thread_delete_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_message_deleted_at = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_messages' AND COLUMN_NAME = 'deleted_at') = 0, 'ALTER TABLE call_report_messages ADD COLUMN deleted_at DATETIME NULL AFTER attachment_mime', 'SELECT 1');
PREPARE stmt FROM @add_message_deleted_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_message_deleted_by = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_messages' AND COLUMN_NAME = 'deleted_by') = 0, 'ALTER TABLE call_report_messages ADD COLUMN deleted_by INT NULL AFTER deleted_at', 'SELECT 1');
PREPARE stmt FROM @add_message_deleted_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_message_delete_reason = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_messages' AND COLUMN_NAME = 'delete_reason') = 0, 'ALTER TABLE call_report_messages ADD COLUMN delete_reason VARCHAR(2000) NULL AFTER deleted_by', 'SELECT 1');
PREPARE stmt FROM @add_message_delete_reason;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS call_report_deletion_audits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  thread_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  record_type ENUM('agent_report', 'reply') NOT NULL,
  deleted_by_user_id INT NOT NULL,
  deleted_by_name VARCHAR(255) NOT NULL,
  deleted_by_role VARCHAR(32) NOT NULL,
  delete_reason VARCHAR(2000) NOT NULL,
  original_payload JSON NOT NULL,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_call_report_deletion_audit_record (record_type, thread_id, message_id),
  KEY idx_call_report_deletion_audits_contact (main_id, contact_id, deleted_at DESC),
  KEY idx_call_report_deletion_audits_actor (main_id, deleted_by_user_id, deleted_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
