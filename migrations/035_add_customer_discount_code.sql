-- Discount code on customer records (tblpatient).
-- Safe to run multiple times: setup.sh -productionupdate re-applies every migration.

SET @discount_code_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tblpatient'
    AND COLUMN_NAME = 'ldiscount_code'
);

SET @add_discount_code := IF(
  @discount_code_exists = 0,
  'ALTER TABLE tblpatient ADD COLUMN ldiscount_code varchar(50) NULL DEFAULT NULL AFTER lprice_group',
  'SELECT 1'
);
PREPARE stmt FROM @add_discount_code;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
