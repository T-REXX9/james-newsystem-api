-- Agent Sales Report: message picture attachments + contact-level read state + direct conversation threads.
-- Idempotent; safe to re-run via setup.sh.

SET @add_msg_attachment_url := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_messages' AND COLUMN_NAME = 'attachment_url') = 0,
    'ALTER TABLE call_report_messages ADD COLUMN attachment_url VARCHAR(512) NULL AFTER body',
    'SELECT 1'
);
PREPARE stmt_msg_attachment_url FROM @add_msg_attachment_url;
EXECUTE stmt_msg_attachment_url;
DEALLOCATE PREPARE stmt_msg_attachment_url;

SET @add_msg_attachment_mime := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_messages' AND COLUMN_NAME = 'attachment_mime') = 0,
    'ALTER TABLE call_report_messages ADD COLUMN attachment_mime VARCHAR(64) NULL AFTER attachment_url',
    'SELECT 1'
);
PREPARE stmt_msg_attachment_mime FROM @add_msg_attachment_mime;
EXECUTE stmt_msg_attachment_mime;
DEALLOCATE PREPARE stmt_msg_attachment_mime;

SET @add_thread_is_direct := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_threads' AND COLUMN_NAME = 'is_direct') = 0,
    'ALTER TABLE call_report_threads ADD COLUMN is_direct TINYINT(1) NOT NULL DEFAULT 0 AFTER contact_id',
    'SELECT 1'
);
PREPARE stmt_thread_is_direct FROM @add_thread_is_direct;
EXECUTE stmt_thread_is_direct;
DEALLOCATE PREPARE stmt_thread_is_direct;

-- Allow direct conversation threads without a call-log entry (multiple NULLs are allowed in UNIQUE).
SET @nullable_call_log_entry := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'call_report_threads'
       AND COLUMN_NAME = 'call_log_entry_id'
       AND IS_NULLABLE = 'NO') > 0,
    'ALTER TABLE call_report_threads MODIFY COLUMN call_log_entry_id BIGINT UNSIGNED NULL',
    'SELECT 1'
);
PREPARE stmt_nullable_call_log_entry FROM @nullable_call_log_entry;
EXECUTE stmt_nullable_call_log_entry;
DEALLOCATE PREPARE stmt_nullable_call_log_entry;

CREATE TABLE IF NOT EXISTS call_report_contact_read_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  user_id INT NOT NULL,
  last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_call_report_contact_read (main_id, contact_id, user_id),
  KEY idx_call_report_contact_read_user (user_id, last_read_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
