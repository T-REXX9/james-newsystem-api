-- Add explicit time fields so incident reports record both date and time.
-- Safe to run multiple times.

SET @report_time_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'incident_reports'
    AND COLUMN_NAME = 'report_time'
);

SET @add_report_time := IF(
  @report_time_exists = 0,
  'ALTER TABLE incident_reports ADD COLUMN report_time TIME NOT NULL DEFAULT ''00:00:00'' AFTER report_date',
  'SELECT 1'
);
PREPARE stmt FROM @add_report_time;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @done_by_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'incident_reports'
    AND COLUMN_NAME = 'done_by'
);

SET @add_done_by := IF(
  @done_by_exists = 0,
  'ALTER TABLE incident_reports ADD COLUMN done_by VARCHAR(255) NOT NULL DEFAULT '''' AFTER reported_by',
  'SELECT 1'
);
PREPARE stmt FROM @add_done_by;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @incident_time_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'incident_reports'
    AND COLUMN_NAME = 'incident_time'
);

SET @add_incident_time := IF(
  @incident_time_exists = 0,
  'ALTER TABLE incident_reports ADD COLUMN incident_time TIME NOT NULL DEFAULT ''00:00:00'' AFTER incident_date',
  'SELECT 1'
);
PREPARE stmt FROM @add_incident_time;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
