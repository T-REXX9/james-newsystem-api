-- Remove the two legacy customer rows that have no company name or customer code.
-- Keep this cleanup narrow: the session IDs are the stable identities observed in
-- the production data, and customer ledger history is intentionally untouched.
-- Idempotent: already soft-deleted rows are skipped.

SET @unnamed_customer_target_count := (
  SELECT COUNT(*)
  FROM tblpatient
  WHERE COALESCE(ldeleted, 0) = 0
    AND lmain_id = 1
    AND lsessionid IN ('152021011303113643901', '12202602100417528111')
    AND TRIM(COALESCE(lcompany, '')) = ''
    AND TRIM(COALESCE(lpatient_code, '')) = ''
);

UPDATE tblpatient
SET
  ldeleted = 1,
  ldeleted_at = NOW(),
  ldeleted_by = NULL,
  ldelete_reason = 'Migration 046: remove two unnamed customer records',
  lstatus = 0
WHERE COALESCE(ldeleted, 0) = 0
  AND lmain_id = 1
  AND lsessionid IN ('152021011303113643901', '12202602100417528111')
  AND TRIM(COALESCE(lcompany, '')) = ''
  AND TRIM(COALESCE(lpatient_code, '')) = ''
  AND @unnamed_customer_target_count = 2;
