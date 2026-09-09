-- Store per-page action defaults for Access Groups.
-- Safe to run multiple times.

SET @column_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'access_groups'
    AND COLUMN_NAME = 'action_permissions'
);

SET @alter_sql := IF(
  @column_exists = 0,
  "ALTER TABLE access_groups ADD COLUMN action_permissions JSON NULL AFTER access_rights",
  'SELECT 1'
);

PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
