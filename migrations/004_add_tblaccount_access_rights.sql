-- ============================================================================
-- Add access rights storage for local staff permissions
-- Migration: 004_add_tblaccount_access_rights.sql
-- Safe to run multiple times.
-- ============================================================================

SET @column_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblaccount'
    AND COLUMN_NAME = 'laccess_rights'
);

SET @alter_sql := IF(
  @column_exists = 0,
  "ALTER TABLE tblaccount ADD COLUMN laccess_rights TEXT NULL COMMENT 'JSON array of module IDs e.g. [\"dashboard\",\"sales\"]'",
  'SELECT 1'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
