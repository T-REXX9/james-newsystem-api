-- Independent Daily Call marker. This does not change customer status or debt type.
CREATE TABLE IF NOT EXISTS daily_call_do_not_contact_flags (
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  marked_by INT NOT NULL,
  marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (main_id, contact_id),
  KEY idx_daily_call_dnc_marked_by (main_id, marked_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
