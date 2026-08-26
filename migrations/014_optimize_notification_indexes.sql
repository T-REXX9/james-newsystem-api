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

-- Compatibility bootstrap: older deployed setup.sh versions execute this
-- migration by name after pulling the API repository. Keep the incident table
-- creation here as well as in migration 016 so the first update is sufficient.
CREATE TABLE IF NOT EXISTS incident_reports (
  id VARCHAR(64) NOT NULL,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  report_date DATE NOT NULL,
  incident_date DATE NOT NULL,
  issue_type ENUM('product_quality', 'service_quality', 'delivery', 'other') NOT NULL,
  description TEXT NOT NULL,
  reported_by VARCHAR(255) NOT NULL,
  attachments JSON NULL DEFAULT NULL,
  related_transactions JSON NULL DEFAULT NULL,
  approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  approved_by VARCHAR(128) NULL DEFAULT NULL,
  approval_date DATETIME NULL DEFAULT NULL,
  notes TEXT NULL DEFAULT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_incident_reports_main_contact_date (main_id, contact_id, report_date),
  KEY idx_incident_reports_main_status (main_id, approval_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
