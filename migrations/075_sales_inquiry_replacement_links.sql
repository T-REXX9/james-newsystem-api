-- Keep replacement links outside tblinquiry because the legacy row is already
-- close to MySQL's maximum inline row size.
CREATE TABLE IF NOT EXISTS sales_inquiry_replacement_links (
  lid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  source_refno VARCHAR(64) NOT NULL,
  replacement_refno VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (lid),
  UNIQUE KEY uniq_sales_inquiry_replacement_source (main_id, source_refno),
  UNIQUE KEY uniq_sales_inquiry_replacement_target (main_id, replacement_refno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
