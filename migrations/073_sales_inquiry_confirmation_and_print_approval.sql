-- Store confirmation timestamps outside tblinquiry: the legacy table can be
-- close to InnoDB's maximum inline row size, so even a small ALTER can fail.
-- Existing timestamps from installations that applied the old version are
-- copied without changing or deleting their source values.
CREATE TABLE IF NOT EXISTS sales_inquiry_confirmations (
  main_id INT NOT NULL,
  inquiry_refno VARCHAR(64) NOT NULL,
  confirmed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (main_id, inquiry_refno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @copy_legacy_confirmation = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinquiry' AND COLUMN_NAME = 'lconfirmed_at') > 0,
  'INSERT IGNORE INTO sales_inquiry_confirmations (main_id, inquiry_refno, confirmed_at) SELECT lmain_id, lrefno, lconfirmed_at FROM tblinquiry WHERE lconfirmed_at IS NOT NULL AND lrefno IS NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @copy_legacy_confirmation;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS sales_inquiry_print_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  inquiry_refno VARCHAR(64) NOT NULL,
  requested_by INT NOT NULL,
  status ENUM('pending', 'approved', 'rejected', 'used') NOT NULL DEFAULT 'pending',
  reviewed_by INT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_si_print_request_review (main_id, status, requested_at, id),
  KEY idx_si_print_request_inquiry (main_id, inquiry_refno, requested_by, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @extend_print_request_status = IF(
  (SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales_inquiry_print_requests' AND COLUMN_NAME = 'status') NOT LIKE '%used%',
  "ALTER TABLE sales_inquiry_print_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'used') NOT NULL DEFAULT 'pending'",
  'SELECT 1'
);
PREPARE stmt FROM @extend_print_request_status;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS sales_inquiry_prints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  inquiry_refno VARCHAR(64) NOT NULL,
  printed_by INT NOT NULL,
  printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_si_print_history (main_id, inquiry_refno, printed_by, printed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;