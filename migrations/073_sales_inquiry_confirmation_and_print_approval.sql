-- Persist inquiry confirmation time and staff reprint requests.
-- Safe to re-run; existing inquiry business data is not rewritten.
SET @add_confirmation_timestamp = IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinquiry' AND COLUMN_NAME = 'lconfirmed_at') = 0,
  'ALTER TABLE tblinquiry ADD COLUMN lconfirmed_at DATETIME NULL',
  'SELECT 1'
);
PREPARE stmt FROM @add_confirmation_timestamp;
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