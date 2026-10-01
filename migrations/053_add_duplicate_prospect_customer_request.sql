-- Compatibility migration for deployments and contract tests that reference
-- the original Phase 1 filename. The canonical migration is
-- 060_add_duplicate_prospect_customer_request.sql; this statement is
-- idempotent for the same database state.
ALTER TABLE customer_requests
  MODIFY kind ENUM('customer_update', 'discount', 'duplicate_prospect') NOT NULL;
