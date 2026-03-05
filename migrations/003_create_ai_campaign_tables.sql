-- ============================================================================
-- AI Sales Agent Campaign Outreach Extensions
-- Migration: 003_create_ai_campaign_tables.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS ai_campaign_outreach (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  client_id VARCHAR(255) NOT NULL,
  outreach_type ENUM('sms', 'call', 'chat') NOT NULL DEFAULT 'sms',
  status ENUM('pending', 'sent', 'delivered', 'failed', 'responded') NOT NULL DEFAULT 'pending',
  language ENUM('tagalog', 'english') NOT NULL DEFAULT 'tagalog',
  message_content TEXT,
  scheduled_at DATETIME DEFAULT NULL,
  sent_at DATETIME DEFAULT NULL,
  response_received TINYINT(1) NOT NULL DEFAULT 0,
  response_content TEXT,
  outcome ENUM('interested', 'not_interested', 'no_response', 'converted', 'escalated') DEFAULT NULL,
  conversation_id VARCHAR(255) DEFAULT NULL,
  error_message TEXT,
  retry_count INT NOT NULL DEFAULT 0,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ai_campaign_outreach_campaign (campaign_id),
  INDEX idx_ai_campaign_outreach_client (client_id),
  INDEX idx_ai_campaign_outreach_status (status),
  INDEX idx_ai_campaign_outreach_scheduled (scheduled_at),
  CONSTRAINT fk_ai_campaign_outreach_campaign FOREIGN KEY (campaign_id) REFERENCES promotions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_campaign_feedback (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT NOT NULL,
  outreach_id INT DEFAULT NULL,
  client_id VARCHAR(255) DEFAULT NULL,
  feedback_type ENUM('objection', 'interest', 'question', 'conversion', 'complaint', 'positive') NOT NULL,
  content TEXT NOT NULL,
  sentiment ENUM('positive', 'neutral', 'negative') DEFAULT NULL,
  tags JSON DEFAULT NULL,
  ai_analysis JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ai_campaign_feedback_campaign (campaign_id),
  INDEX idx_ai_campaign_feedback_outreach (outreach_id),
  INDEX idx_ai_campaign_feedback_type (feedback_type),
  INDEX idx_ai_campaign_feedback_sentiment (sentiment),
  CONSTRAINT fk_ai_campaign_feedback_campaign FOREIGN KEY (campaign_id) REFERENCES promotions(id) ON DELETE CASCADE,
  CONSTRAINT fk_ai_campaign_feedback_outreach FOREIGN KEY (outreach_id) REFERENCES ai_campaign_outreach(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_message_templates (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(500) NOT NULL,
  language ENUM('tagalog', 'english') NOT NULL DEFAULT 'tagalog',
  template_type VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  variables JSON DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ai_message_templates_language (language),
  INDEX idx_ai_message_templates_type (template_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ai_message_templates (name, language, template_type, content, variables) VALUES
('Tagalog Greeting', 'tagalog', 'greeting', 'Magandang araw po, {client_name}! Ito po si {agent_name} mula sa aming kumpanya.', JSON_ARRAY('client_name', 'agent_name')),
('Tagalog Promo Intro', 'tagalog', 'promo_intro', 'May magandang balita po kami para sa inyo! Kasalukuyang may promo ang {product_name} na may {discount_percentage}% discount. Gusto niyo po bang malaman ang detalye?', JSON_ARRAY('product_name', 'discount_percentage')),
('Tagalog Follow Up', 'tagalog', 'follow_up', 'Kumusta po? Follow up lang po sa aming pinag-usapan tungkol sa {product_name}. May tanong pa po ba kayo?', JSON_ARRAY('product_name')),
('Tagalog Closing', 'tagalog', 'closing', 'Maraming salamat po sa inyong oras! Kung may katanungan kayo, tawag o text lang po kayo anytime. Ingat po!', JSON_ARRAY()),
('English Greeting', 'english', 'greeting', 'Good day, {client_name}! This is {agent_name} from our company.', JSON_ARRAY('client_name', 'agent_name')),
('English Promo Intro', 'english', 'promo_intro', 'We have great news for you! {product_name} is currently on promotion with {discount_percentage}% off. Would you like to know more?', JSON_ARRAY('product_name', 'discount_percentage')),
('English Follow Up', 'english', 'follow_up', 'Hi there! Just following up on our conversation about {product_name}. Do you have any questions?', JSON_ARRAY('product_name')),
('English Closing', 'english', 'closing', 'Thank you for your time! If you have any questions, feel free to call or text us anytime. Take care!', JSON_ARRAY());
