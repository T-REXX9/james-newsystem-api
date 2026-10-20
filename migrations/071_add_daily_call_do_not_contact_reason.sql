SET @daily_call_dnc_reason_sql := IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_call_do_not_contact_flags') = 1
  AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_call_do_not_contact_flags' AND COLUMN_NAME = 'reason') = 0,
  'ALTER TABLE daily_call_do_not_contact_flags ADD COLUMN reason VARCHAR(500) NULL AFTER marked_by',
  'SELECT 1'
);
PREPARE daily_call_dnc_reason_stmt FROM @daily_call_dnc_reason_sql;
EXECUTE daily_call_dnc_reason_stmt;
DEALLOCATE PREPARE daily_call_dnc_reason_stmt;
