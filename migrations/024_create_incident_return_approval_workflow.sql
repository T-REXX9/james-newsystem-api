-- Incident approval and sales-return authorization workflow.
-- Approval records a single disposition without changing inventory immediately;
-- warehouse/accounting can then complete the authorized return safely.

CREATE TABLE IF NOT EXISTS incident_return_actions (
  id VARCHAR(64) NOT NULL,
  main_id INT NOT NULL,
  incident_report_id VARCHAR(64) NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  disposition ENUM('return_to_stock', 'return_to_factory') NOT NULL,
  status ENUM('authorized', 'completed', 'cancelled') NOT NULL DEFAULT 'authorized',
  product_id VARCHAR(128) NULL DEFAULT NULL,
  item_code VARCHAR(128) NULL DEFAULT NULL,
  part_no VARCHAR(128) NULL DEFAULT NULL,
  description TEXT NOT NULL,
  quantity DECIMAL(12, 2) NULL DEFAULT NULL,
  supplier_id VARCHAR(128) NULL DEFAULT NULL,
  supplier_name VARCHAR(255) NULL DEFAULT NULL,
  authorized_by_user_id INT NOT NULL,
  authorized_by_name VARCHAR(255) NOT NULL,
  authorized_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_at DATETIME(3) NULL DEFAULT NULL,
  notes TEXT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_incident_return_actions_main_incident (main_id, incident_report_id),
  KEY idx_incident_return_actions_main_contact (main_id, contact_id),
  KEY idx_incident_return_actions_main_part (main_id, part_no),
  KEY idx_incident_return_actions_status (main_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @incident_decision_note_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports' AND COLUMN_NAME = 'decision_note'
);
SET @incident_decision_note_sql := IF(
  @incident_decision_note_exists = 0,
  'ALTER TABLE incident_reports ADD COLUMN decision_note TEXT NULL AFTER approval_date',
  'SELECT 1'
);
PREPARE incident_decision_note_stmt FROM @incident_decision_note_sql;
EXECUTE incident_decision_note_stmt;
DEALLOCATE PREPARE incident_decision_note_stmt;
