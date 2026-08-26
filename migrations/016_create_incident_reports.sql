-- Customer incident reports previously lived only in Supabase. This local table
-- makes incident creation and history available through the James API.
-- Safe to run multiple times.

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
