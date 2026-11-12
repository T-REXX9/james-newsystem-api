-- Keep confirmed-inquiry edit requests separate from both the wide legacy inquiry row and print approvals.
CREATE TABLE IF NOT EXISTS sales_inquiry_edit_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  inquiry_refno VARCHAR(64) NOT NULL,
  requested_by INT NOT NULL,
  status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  reviewed_by INT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_si_edit_request_review (main_id, status, requested_at, id),
  KEY idx_si_edit_request_access (main_id, inquiry_refno, requested_by, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
