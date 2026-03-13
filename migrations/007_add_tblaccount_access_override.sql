-- ============================================================================
-- Add access_override flag to tblaccount
-- Migration: 007_add_tblaccount_access_override.sql
-- When access_override = 0 (default), staff inherits rights from their group.
-- When access_override = 1, staff keeps individually-set permissions even when
-- the group's access_rights are updated.
-- Safe to run multiple times.
-- ============================================================================

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblaccount'
    AND COLUMN_NAME = 'access_override'
);

SET @alter_sql := IF(
  @col_exists = 0,
  "ALTER TABLE tblaccount ADD COLUMN access_override TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER group_id",
  'SELECT 1'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
