-- ============================================================================
-- Add Client and City Targeting to Promotions
-- Migration: 002_add_promotion_targeting.sql
-- Safe to run multiple times.
-- ============================================================================

SET @target_all_clients_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'promotions'
    AND COLUMN_NAME = 'target_all_clients'
);

SET @target_all_clients_sql := IF(
  @target_all_clients_exists = 0,
  "ALTER TABLE promotions ADD COLUMN target_all_clients TINYINT(1) NOT NULL DEFAULT 1",
  'SELECT 1'
);

PREPARE stmt FROM @target_all_clients_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_client_ids_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'promotions'
    AND COLUMN_NAME = 'target_client_ids'
);

SET @target_client_ids_sql := IF(
  @target_client_ids_exists = 0,
  "ALTER TABLE promotions ADD COLUMN target_client_ids JSON DEFAULT NULL",
  'SELECT 1'
);

PREPARE stmt FROM @target_client_ids_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_cities_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'promotions'
    AND COLUMN_NAME = 'target_cities'
);

SET @target_cities_sql := IF(
  @target_cities_exists = 0,
  "ALTER TABLE promotions ADD COLUMN target_cities JSON DEFAULT NULL",
  'SELECT 1'
);

PREPARE stmt FROM @target_cities_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @target_all_clients_index_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'promotions'
    AND INDEX_NAME = 'idx_promotions_target_all_clients'
);

SET @target_all_clients_index_sql := IF(
  @target_all_clients_index_exists = 0,
  'CREATE INDEX idx_promotions_target_all_clients ON promotions(target_all_clients)',
  'SELECT 1'
);

PREPARE stmt FROM @target_all_clients_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
