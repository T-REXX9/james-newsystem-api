-- Add call timing metadata to call report threads
-- Migration: 029_add_call_report_call_details.sql
-- Safe to run multiple times.

SET @has_call_started_at := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'call_report_threads'
    AND COLUMN_NAME = 'call_started_at'
);

SET @ddl_call_started_at := IF(
  @has_call_started_at = 0,
  'ALTER TABLE call_report_threads ADD COLUMN call_started_at DATETIME NULL DEFAULT NULL AFTER report_body',
  'SELECT 1'
);
PREPARE stmt_call_started_at FROM @ddl_call_started_at;
EXECUTE stmt_call_started_at;
DEALLOCATE PREPARE stmt_call_started_at;

SET @has_call_ended_at := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'call_report_threads'
    AND COLUMN_NAME = 'call_ended_at'
);

SET @ddl_call_ended_at := IF(
  @has_call_ended_at = 0,
  'ALTER TABLE call_report_threads ADD COLUMN call_ended_at DATETIME NULL DEFAULT NULL AFTER call_started_at',
  'SELECT 1'
);
PREPARE stmt_call_ended_at FROM @ddl_call_ended_at;
EXECUTE stmt_call_ended_at;
DEALLOCATE PREPARE stmt_call_ended_at;

SET @has_duration_seconds := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'call_report_threads'
    AND COLUMN_NAME = 'duration_seconds'
);

SET @ddl_duration_seconds := IF(
  @has_duration_seconds = 0,
  'ALTER TABLE call_report_threads ADD COLUMN duration_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER call_ended_at',
  'SELECT 1'
);
PREPARE stmt_duration_seconds FROM @ddl_duration_seconds;
EXECUTE stmt_duration_seconds;
DEALLOCATE PREPARE stmt_duration_seconds;
