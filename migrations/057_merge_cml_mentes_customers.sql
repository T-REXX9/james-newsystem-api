-- Merge the duplicate customer records for the same real-world business into a
-- single surviving record, then retire the losers.
--
--   SURVIVOR : CML CALIBRATION AND AUTOMOTIVE SERVICES
--              tblpatient.lid = 893, lsessionid = '132021082403524074021'
--   LOSERS   : CML DIESEL CALIBRATION
--              tblpatient.lid = 161, lsessionid = '72987196120200807132647'
--              MENTES CALIBRATION & AUTOMOTIVE SERVICES
--              tblpatient.lid = 511, lsessionid = '17610677020200807132729'
--
-- Transaction history is keyed by lcustomerid (= tblpatient.lsessionid). The
-- merge re-points every history row owned by a loser's sessionid to the
-- survivor's sessionid across the six tables that actually hold rows for these
-- customers, then soft-deletes the two loser customer records so Daily Call /
-- Customer Database hide them. Line-item tables (tbldelivery_items,
-- tblinvoice_items) are keyed by refno off their header, so re-pointing the
-- headers is sufficient and their items follow.
--
-- Robust to any runner: each statement is self-contained (no cross-statement
-- @variables) and carries its own guard sub-select so history is only
-- re-pointed while BOTH loser records are still live.
-- Idempotent: after a first run the loser sessionids no longer exist, so the
-- UPDATEs match zero rows and the loser tblpatient guard short-circuits.

-- 1. Re-point transaction history from each loser sessionid to the survivor,
--    but only while both loser customer records are still live (un-deleted).
UPDATE tbldebit_memo_items SET lcustomerid = '132021082403524074021'
  WHERE lcustomerid IN ('72987196120200807132647', '17610677020200807132729')
    AND (SELECT COUNT(*) FROM tblpatient WHERE lmain_id = 1
         AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
         AND COALESCE(ldeleted, 0) = 0) = 2;

UPDATE tbldelivery_receipt SET lcustomerid = '132021082403524074021'
  WHERE lcustomerid IN ('72987196120200807132647', '17610677020200807132729')
    AND (SELECT COUNT(*) FROM tblpatient WHERE lmain_id = 1
         AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
         AND COALESCE(ldeleted, 0) = 0) = 2;

UPDATE tblinquiry SET lcustomerid = '132021082403524074021'
  WHERE lcustomerid IN ('72987196120200807132647', '17610677020200807132729')
    AND (SELECT COUNT(*) FROM tblpatient WHERE lmain_id = 1
         AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
         AND COALESCE(ldeleted, 0) = 0) = 2;

UPDATE tblinvoice_list SET lcustomerid = '132021082403524074021'
  WHERE lcustomerid IN ('72987196120200807132647', '17610677020200807132729')
    AND (SELECT COUNT(*) FROM tblpatient WHERE lmain_id = 1
         AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
         AND COALESCE(ldeleted, 0) = 0) = 2;

UPDATE tblledger SET lcustomerid = '132021082403524074021'
  WHERE lcustomerid IN ('72987196120200807132647', '17610677020200807132729')
    AND (SELECT COUNT(*) FROM tblpatient WHERE lmain_id = 1
         AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
         AND COALESCE(ldeleted, 0) = 0) = 2;

UPDATE tbltransaction SET lcustomerid = '132021082403524074021'
  WHERE lcustomerid IN ('72987196120200807132647', '17610677020200807132729')
    AND (SELECT COUNT(*) FROM tblpatient WHERE lmain_id = 1
         AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
         AND COALESCE(ldeleted, 0) = 0) = 2;

-- 2. Retire the two loser customer records (soft-delete, matching
--    CustomerDatabaseRepository::deleteCustomer so the UI hides them).
UPDATE tblpatient
SET
  ldeleted       = 1,
  ldeleted_at    = NOW(),
  ldeleted_by    = NULL,
  ldelete_reason = 'Migration 057: merged into CML CALIBRATION AND AUTOMOTIVE SERVICES (lid 893)',
  lstatus        = 0
WHERE lmain_id = 1
  AND lsessionid IN ('72987196120200807132647', '17610677020200807132729')
  AND COALESCE(ldeleted, 0) = 0;
