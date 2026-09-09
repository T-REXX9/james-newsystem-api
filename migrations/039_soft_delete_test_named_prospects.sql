-- Soft-delete prospect (and status=3 prospective) customers whose company name contains "test".
-- Matches CustomerDatabaseRepository::deleteCustomer so Daily Call / Customer Database hide them.
-- Idempotent: already-deleted rows are skipped.

UPDATE tblpatient
SET
  ldeleted = 1,
  ldeleted_at = NOW(),
  ldeleted_by = NULL,
  ldelete_reason = 'Migration 039: soft-delete test-named prospects',
  lstatus = 0
WHERE COALESCE(ldeleted, 0) = 0
  AND LOWER(COALESCE(lcompany, '')) LIKE '%test%'
  AND (
    LOWER(COALESCE(lprofile_type, '')) LIKE '%prospect%'
    OR COALESCE(lstatus, 1) = 3
  );
