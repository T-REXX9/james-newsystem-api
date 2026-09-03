-- Call report conversation threads (sales agent report + master user replies)
-- Migration: 028_create_call_report_threads.sql
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS call_report_threads (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  call_log_entry_id BIGINT UNSIGNED NOT NULL,
  call_log_refno VARCHAR(64) NOT NULL,
  agent_user_id INT NOT NULL,
  agent_name VARCHAR(255) NOT NULL,
  outcome VARCHAR(32) NOT NULL DEFAULT 'note',
  report_body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_call_report_thread_call_log_entry (call_log_entry_id),
  KEY idx_call_report_threads_contact (main_id, contact_id, created_at DESC),
  KEY idx_call_report_threads_agent (main_id, agent_user_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_report_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id BIGINT UNSIGNED NOT NULL,
  sender_user_id INT NOT NULL,
  sender_name VARCHAR(255) NOT NULL,
  sender_role ENUM('agent', 'master') NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_call_report_messages_thread (thread_id, created_at ASC),
  CONSTRAINT fk_call_report_messages_thread
    FOREIGN KEY (thread_id) REFERENCES call_report_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_report_read_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_call_report_read_states_thread_user (thread_id, user_id),
  KEY idx_call_report_read_states_user (user_id, last_read_at DESC),
  CONSTRAINT fk_call_report_read_states_thread
    FOREIGN KEY (thread_id) REFERENCES call_report_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
