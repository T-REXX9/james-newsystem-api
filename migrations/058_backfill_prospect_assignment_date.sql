-- Backfill missing assignment dates for prospects with assigned agents.
--
-- For prospects/customers that were created by staff and auto-assigned to an
-- agent before the create-path fix (PR #25), the ldate_assigned column was
-- never populated. This migration sets the assignment date to the staff-creation
-- date (ldatereg, falling back to ldatetime) for all such records.
--
-- SAFE:
--   - Only touches rows with an assigned agent AND a blank/NULL/zero
--     assignment date. Already-correct rows are never modified.
--   - Idempotent: re-running changes nothing further.
--   - Scoped to main_id=1 and skips deleted rows.
--
-- Selection predicate: row must have lsales_person set AND ldate_assigned
-- empty/null/zero AND a valid creation date (ldatereg or ldatetime).

UPDATE tblpatient p
SET p.ldate_assigned = DATE(
    CASE
        WHEN TRIM(COALESCE(p.ldatereg, '')) <> ''
         AND TRIM(p.ldatereg) NOT LIKE '0000-00-00%'
        THEN p.ldatereg
        ELSE p.ldatetime
    END
)
WHERE p.lmain_id = 1
  AND COALESCE(p.ldeleted, 0) = 0
  AND TRIM(COALESCE(p.lsales_person, '')) <> ''
  AND (
      p.ldate_assigned IS NULL
      OR TRIM(p.ldate_assigned) = ''
      OR TRIM(p.ldate_assigned) LIKE '0000-00-00%'
  )
  AND (
      (TRIM(COALESCE(p.ldatereg, '')) <> '' AND TRIM(p.ldatereg) NOT LIKE '0000-00-00%')
      OR (TRIM(COALESCE(p.ldatetime, '')) <> '' AND TRIM(p.ldatetime) NOT LIKE '0000-00-00%')
  );
