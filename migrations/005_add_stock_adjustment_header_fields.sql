-- ============================================================================
-- Add header fields for local stock adjustment documents
-- Migration: 005_add_stock_adjustment_header_fields.sql
-- Safe to run multiple times.
-- ============================================================================

SET @notes_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblstock_adjustment'
    AND COLUMN_NAME = 'lnotes'
);

SET @notes_sql := IF(
  @notes_exists = 0,
  "ALTER TABLE tblstock_adjustment ADD COLUMN lnotes TEXT NULL",
  'SELECT 1'
);

PREPARE notes_stmt FROM @notes_sql;
EXECUTE notes_stmt;
DEALLOCATE PREPARE notes_stmt;

SET @type_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblstock_adjustment'
    AND COLUMN_NAME = 'ladjustment_type'
);

SET @type_sql := IF(
  @type_exists = 0,
  "ALTER TABLE tblstock_adjustment ADD COLUMN ladjustment_type VARCHAR(50) NULL DEFAULT 'physical_count'",
  'SELECT 1'
);

PREPARE type_stmt FROM @type_sql;
EXECUTE type_stmt;
DEALLOCATE PREPARE type_stmt;
