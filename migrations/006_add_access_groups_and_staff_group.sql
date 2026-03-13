-- ============================================================================
-- Add access groups and staff group assignments
-- Migration: 006_add_access_groups_and_staff_group.sql
-- Safe to run multiple times.
-- ============================================================================

SET @access_groups_table_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'access_groups'
);

SET @create_access_groups_sql := IF(
  @access_groups_table_exists = 0,
  "CREATE TABLE access_groups (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      main_id INT NOT NULL,
      name VARCHAR(255) NOT NULL,
      description VARCHAR(255) NULL,
      access_rights JSON NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_access_groups_main_id (main_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
  'SELECT 1'
);

PREPARE stmt FROM @create_access_groups_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @staff_group_column_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblaccount'
    AND COLUMN_NAME = 'group_id'
);

SET @alter_staff_group_sql := IF(
  @staff_group_column_exists = 0,
  "ALTER TABLE tblaccount ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER laccess_rights",
  'SELECT 1'
);

PREPARE stmt FROM @alter_staff_group_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @staff_group_index_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblaccount'
    AND INDEX_NAME = 'idx_tblaccount_group_id'
);

SET @create_staff_group_index_sql := IF(
  @staff_group_index_exists = 0,
  'CREATE INDEX idx_tblaccount_group_id ON tblaccount (group_id)',
  'SELECT 1'
);

PREPARE stmt FROM @create_staff_group_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
