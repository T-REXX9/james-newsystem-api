-- Sequential Incident Report Number (IR-2601 shape), separate from UUID identity.
-- Safe to run multiple times.

SET @ir_number_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'incident_reports'
    AND COLUMN_NAME = 'ir_number'
);

SET @add_ir_number := IF(
  @ir_number_exists = 0,
  'ALTER TABLE incident_reports ADD COLUMN ir_number VARCHAR(32) NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @add_ir_number;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ir_number_index_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'incident_reports'
    AND INDEX_NAME = 'uq_incident_reports_ir_number'
);

SET @add_ir_number_index := IF(
  @ir_number_index_exists = 0,
  'ALTER TABLE incident_reports ADD UNIQUE INDEX uq_incident_reports_ir_number (ir_number)',
  'SELECT 1'
);
PREPARE stmt FROM @add_ir_number_index;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ir_seq_base := (
  SELECT CAST(COALESCE(MAX(lmax_no), 0) AS SIGNED)
  FROM tblnumber_generator
  WHERE ltransaction_type = 'Incident Report'
);

UPDATE incident_reports ir
INNER JOIN (
  SELECT
    unlabeled.id,
    @ir_seq_base + unlabeled.rn AS next_seq
  FROM (
    SELECT
      id,
      ROW_NUMBER() OVER (ORDER BY created_at ASC, id ASC) AS rn
    FROM incident_reports
    WHERE ir_number IS NULL OR ir_number = ''
  ) unlabeled
) numbered ON numbered.id = ir.id
SET ir.ir_number = CONCAT(
  'IR-',
  DATE_FORMAT(NOW(), '%y'),
  LPAD(numbered.next_seq, 2, '0')
);

SET @ir_seq_max := (
  SELECT CAST(COALESCE(MAX(CAST(SUBSTRING(ir_number, 6) AS UNSIGNED)), 0) AS SIGNED)
  FROM incident_reports
  WHERE ir_number REGEXP '^IR-[0-9]{2}[0-9]+$'
);

SET @ir_gen_max := (
  SELECT CAST(COALESCE(MAX(lmax_no), 0) AS SIGNED)
  FROM tblnumber_generator
  WHERE ltransaction_type = 'Incident Report'
);

INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
SELECT 'Incident Report', @ir_seq_max
FROM DUAL
WHERE @ir_seq_max > 0 AND @ir_seq_max > @ir_gen_max;
