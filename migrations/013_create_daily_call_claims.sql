-- One agent may claim a customer per business day. Active claims expire if abandoned.
CREATE TABLE IF NOT EXISTS daily_call_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  main_id INT NOT NULL,
  contact_id VARCHAR(64) NOT NULL,
  claim_date DATE NOT NULL,
  agent_user_id INT NOT NULL,
  agent_name VARCHAR(255) NOT NULL,
  status ENUM('in_progress', 'completed') NOT NULL DEFAULT 'in_progress',
  claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  completed_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_daily_call_claim_customer (main_id, contact_id, claim_date),
  KEY idx_daily_call_claim_agent (main_id, agent_user_id, claim_date),
  KEY idx_daily_call_claim_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
