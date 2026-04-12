-- ============================================================================
-- Create internal chat group tables
-- Migration: 009_create_internal_chat_group_tables.sql
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS internal_chat_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_by_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_internal_chat_groups_main_id (main_id),
  KEY idx_internal_chat_groups_creator (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS internal_chat_group_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id BIGINT UNSIGNED NOT NULL,
  user_id INT NOT NULL,
  added_by_user_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  removed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_internal_chat_group_members_group (group_id),
  KEY idx_internal_chat_group_members_user (user_id),
  KEY idx_internal_chat_group_members_removed (removed_at),
  KEY idx_internal_chat_group_members_group_user (group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
