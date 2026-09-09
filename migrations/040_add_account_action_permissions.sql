-- Per-account Add/Edit/Delete/Post/Unpost settings. Safe to run repeatedly.
SET @action_permissions_column_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblaccount'
    AND COLUMN_NAME = 'laction_permissions'
);

SET @alter_action_permissions_sql := IF(
  @action_permissions_column_exists = 0,
  "ALTER TABLE tblaccount ADD COLUMN laction_permissions TEXT NULL COMMENT 'JSON object of per-account action permissions'",
  'SELECT 1'
);

PREPARE stmt FROM @alter_action_permissions_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
