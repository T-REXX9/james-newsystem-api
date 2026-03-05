-- ============================================================================
-- Product Promotion Management System Schema (MySQL)
-- Migration: 001_create_promotions_tables.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS promotions (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  campaign_title VARCHAR(500) NOT NULL,
  description TEXT,
  start_date DATETIME,
  end_date DATETIME NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'Draft',
  created_by INT DEFAULT NULL,
  assigned_to JSON DEFAULT NULL,
  target_platforms JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at DATETIME DEFAULT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_promotions_status (status),
  INDEX idx_promotions_end_date (end_date),
  INDEX idx_promotions_created_by (created_by),
  INDEX idx_promotions_is_deleted (is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_products (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promotion_id INT NOT NULL,
  product_id VARCHAR(255) NOT NULL,
  promo_price_aa DECIMAL(10, 2) DEFAULT NULL,
  promo_price_bb DECIMAL(10, 2) DEFAULT NULL,
  promo_price_cc DECIMAL(10, 2) DEFAULT NULL,
  promo_price_dd DECIMAL(10, 2) DEFAULT NULL,
  promo_price_vip1 DECIMAL(10, 2) DEFAULT NULL,
  promo_price_vip2 DECIMAL(10, 2) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_promotion_product (promotion_id, product_id),
  INDEX idx_promotion_products_promotion (promotion_id),
  INDEX idx_promotion_products_product (product_id),
  CONSTRAINT fk_promotion_products_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promotion_postings (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promotion_id INT NOT NULL,
  platform_name VARCHAR(255) NOT NULL,
  posted_by INT DEFAULT NULL,
  post_url TEXT,
  screenshot_url TEXT,
  status VARCHAR(50) NOT NULL DEFAULT 'Not Posted',
  reviewed_by INT DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  rejection_reason TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_promotion_postings_promotion (promotion_id),
  INDEX idx_promotion_postings_status (status),
  INDEX idx_promotion_postings_posted_by (posted_by),
  CONSTRAINT fk_promotion_postings_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
