-- Store Concern and Action as distinct fields on Application call reports.
-- Replaces the single notes blob so the Master User Call Records page can
-- show structured documentation alongside each Phone record.
-- Safe to run multiple times.

SET @has_concern := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'call_report_threads'
    AND COLUMN_NAME = 'concern'
);

SET @ddl_concern := IF(
  @has_concern = 0,
  'ALTER TABLE `call_report_threads` ADD COLUMN `concern` TEXT NULL AFTER `report_body`',
  'SELECT 1'
);
PREPARE stmt_concern FROM @ddl_concern;
EXECUTE stmt_concern;
DEALLOCATE PREPARE stmt_concern;

SET @has_action := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'call_report_threads'
    AND COLUMN_NAME = 'action'
);

SET @ddl_action := IF(
  @has_action = 0,
  'ALTER TABLE `call_report_threads` ADD COLUMN `action` TEXT NULL AFTER `concern`',
  'SELECT 1'
);
PREPARE stmt_action FROM @ddl_action;
EXECUTE stmt_action;
DEALLOCATE PREPARE stmt_action;
