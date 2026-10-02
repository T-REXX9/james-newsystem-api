-- One persistent Daily Call stop point per sales agent and account.
CREATE TABLE IF NOT EXISTS daily_call_bookmarks (
  main_id INT NOT NULL,
  agent_user_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (main_id, agent_user_id),
  KEY idx_daily_call_bookmark_contact (main_id, contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
