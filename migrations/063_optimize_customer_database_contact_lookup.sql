-- The legacy contact index starts with lid, so lookups by customer reference
-- scan the whole contact table. This supports both per-customer and batched
-- customer-database contact reads. The prefix keeps the index portable across
-- older utf8mb4 deployments while covering normal session IDs.
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'tblcontact_person'
      AND INDEX_NAME = 'idx_contact_person_refno');
SET @idx_sql := IF(
    @idx_exists = 0,
    'ALTER TABLE tblcontact_person ADD INDEX idx_contact_person_refno (lrefno(64))',
    'SELECT 1'
);
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
