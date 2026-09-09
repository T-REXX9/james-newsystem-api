-- Preserve the staff explanation when a customer/prospect is intentionally
-- created despite a possible existing customer match.
SET @column_exists := (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'tblpatient'
    AND column_name = 'lduplicate_override_reason'
);
SET @sql := IF(
  @column_exists = 0,
  'ALTER TABLE tblpatient ADD COLUMN lduplicate_override_reason TEXT NULL AFTER lnotes',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
