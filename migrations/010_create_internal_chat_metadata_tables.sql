-- ============================================================================
-- Create internal chat metadata tables
-- Migration: 010_create_internal_chat_metadata_tables.sql
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS internal_chat_message_reactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_key VARCHAR(64) NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  emoji VARCHAR(16) NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_internal_chat_message_reactions_message_user (message_id, user_id),
  KEY idx_internal_chat_message_reactions_conversation_message (conversation_key, message_id),
  KEY idx_internal_chat_message_reactions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_chat_message_replies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_key VARCHAR(64) NOT NULL,
  message_id BIGINT UNSIGNED NOT NULL,
  reply_to_message_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_internal_chat_message_replies_message (message_id),
  KEY idx_internal_chat_message_replies_conversation_message (conversation_key, message_id),
  KEY idx_internal_chat_message_replies_reply_target (reply_to_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_chat_typing_states (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_key VARCHAR(64) NOT NULL,
  user_id INT NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uk_internal_chat_typing_states_conversation_user (conversation_key, user_id),
  KEY idx_internal_chat_typing_states_conversation_expires (conversation_key, expires_at),
  KEY idx_internal_chat_typing_states_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
