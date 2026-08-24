-- Required by notification polling and the automatic inventory-alert scan.
-- Safe to run multiple times.

SET @notification_lookup_index_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblnotifications'
    AND INDEX_NAME = 'idx_notifications_user_ref_status'
);

SET @notification_lookup_index_sql := IF(
  @notification_lookup_index_exists = 0,
  'ALTER TABLE tblnotifications ADD INDEX idx_notifications_user_ref_status (luserid, lrefno, lstatus)',
  'SELECT 1'
);

PREPARE notification_lookup_index_stmt FROM @notification_lookup_index_sql;
EXECUTE notification_lookup_index_stmt;
DEALLOCATE PREPARE notification_lookup_index_stmt;

SET @notification_polling_index_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblnotifications'
    AND INDEX_NAME = 'idx_notifications_user_status_datetime'
);

SET @notification_polling_index_sql := IF(
  @notification_polling_index_exists = 0,
  'ALTER TABLE tblnotifications ADD INDEX idx_notifications_user_status_datetime (luserid, lstatus, ldatetime, lid)',
  'SELECT 1'
);

PREPARE notification_polling_index_stmt FROM @notification_polling_index_sql;
EXECUTE notification_polling_index_stmt;
DEALLOCATE PREPARE notification_polling_index_stmt;
