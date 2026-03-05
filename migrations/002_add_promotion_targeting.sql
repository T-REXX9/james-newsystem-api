-- ============================================================================
-- Add Client and City Targeting to Promotions
-- Migration: 002_add_promotion_targeting.sql
-- ============================================================================

ALTER TABLE promotions
  ADD COLUMN target_all_clients TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE promotions
  ADD COLUMN target_client_ids JSON DEFAULT NULL;

ALTER TABLE promotions
  ADD COLUMN target_cities JSON DEFAULT NULL;

CREATE INDEX idx_promotions_target_all_clients
  ON promotions(target_all_clients);
