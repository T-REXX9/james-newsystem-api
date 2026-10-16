-- Preserve each previous message body and the agent actor for Master User review.
-- Safe to run multiple times.

SET @add_report_edited_at = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_threads' AND COLUMN_NAME = 'report_edited_at') = 0, 'ALTER TABLE call_report_threads ADD COLUMN report_edited_at DATETIME NULL AFTER report_body', 'SELECT 1');
PREPARE stmt FROM @add_report_edited_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @add_message_edited_at = IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'call_report_messages' AND COLUMN_NAME = 'edited_at') = 0, 'ALTER TABLE call_report_messages ADD COLUMN edited_at DATETIME NULL AFTER body', 'SELECT 1');
PREPARE stmt FROM @add_message_edited_at;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS call_report_message_edit_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  thread_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  record_type ENUM('agent_report', 'reply') NOT NULL,
  record_id BIGINT UNSIGNED NOT NULL,
  previous_body TEXT NOT NULL,
  actor_user_id INT NOT NULL,
  actor_name VARCHAR(255) NOT NULL,
  edited_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_call_report_edit_history_record (main_id, contact_id, record_type, record_id, edited_at, id),
  KEY idx_call_report_edit_history_actor (main_id, actor_user_id, edited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
