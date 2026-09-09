-- Safe to run repeatedly during setup/update deployments.
SET @session_version_column_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblaccount'
    AND COLUMN_NAME = 'lsession_version'
);

SET @alter_session_version_sql := IF(
  @session_version_column_exists = 0,
  'ALTER TABLE tblaccount ADD COLUMN lsession_version BIGINT UNSIGNED NOT NULL DEFAULT 0',
  'SELECT 1'
);

PREPARE stmt FROM @alter_session_version_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
