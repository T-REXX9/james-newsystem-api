-- Keep-in-view (KIV) folder for Item Suggested for Stock Report.
-- Items parked here stay off the main report until restored.
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS suggested_stock_kiv (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id VARCHAR(64) NOT NULL,
  part_no VARCHAR(255) NOT NULL DEFAULT '',
  item_code VARCHAR(255) NOT NULL DEFAULT '',
  description VARCHAR(1024) NOT NULL DEFAULT '',
  item_key CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by VARCHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suggested_stock_kiv_item (main_id, item_key),
  KEY idx_suggested_stock_kiv_lookup (main_id, part_no(100), item_code(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
