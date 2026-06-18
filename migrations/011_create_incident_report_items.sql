-- ============================================================================
-- Create incident report item table
-- Migration: 011_create_incident_report_items.sql
-- Safe to run multiple times.
-- ============================================================================

CREATE TABLE IF NOT EXISTS incident_report_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  incident_report_id VARCHAR(64) NOT NULL,
  contact_id VARCHAR(64) NULL DEFAULT NULL,
  product_id VARCHAR(128) NULL DEFAULT NULL,
  item_code VARCHAR(128) NULL DEFAULT NULL,
  part_no VARCHAR(128) NULL DEFAULT NULL,
  description TEXT NOT NULL,
  supplier_id VARCHAR(128) NULL DEFAULT NULL,
  supplier_name VARCHAR(255) NULL DEFAULT NULL,
  quantity DECIMAL(12, 2) NULL DEFAULT NULL,
  issue_summary TEXT NULL DEFAULT NULL,
  match_source ENUM('manual', 'related_transaction', 'description_match', 'imported') NOT NULL DEFAULT 'manual',
  confidence_score DECIMAL(5, 4) NULL DEFAULT NULL,
  metadata JSON NULL DEFAULT NULL,
  created_by_user_id INT NULL DEFAULT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_incident_report_items_main_incident (main_id, incident_report_id),
  KEY idx_incident_report_items_main_supplier (main_id, supplier_id),
  KEY idx_incident_report_items_supplier_name (supplier_name),
  KEY idx_incident_report_items_main_product (main_id, product_id),
  KEY idx_incident_report_items_main_item_code (main_id, item_code),
  KEY idx_incident_report_items_main_part_no (main_id, part_no),
  KEY idx_incident_report_items_match_source (match_source),
  CONSTRAINT chk_incident_report_items_confidence
    CHECK (confidence_score IS NULL OR (confidence_score >= 0 AND confidence_score <= 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
