-- Local customer change/discount approvals. No legacy task/deal/AI tables.
CREATE TABLE IF NOT EXISTS customer_requests (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  kind ENUM('customer_update','discount') NOT NULL,
  payload JSON NOT NULL,
  baseline JSON NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  submitted_by INT NOT NULL,
  submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_note VARCHAR(2000) NOT NULL DEFAULT '',
  KEY idx_customer_requests_scope (main_id, contact_id, status, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
