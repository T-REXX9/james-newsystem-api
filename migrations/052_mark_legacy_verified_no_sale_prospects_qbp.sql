-- Mark legacy no-sale prospects with an imported Verified flag as QBP records.
-- New-system verification is identified only by its Daily Call verification audit,
-- so this never changes the source of a prospect verified through the new workflow.
-- The migration is idempotent and only changes tblpatient.lrefer_by.

UPDATE tblpatient p
SET p.lrefer_by = 'QBP'
WHERE p.lmain_id = 1
  AND COALESCE(p.ldeleted, 0) = 0
  AND COALESCE(p.lstatus, 1) = 3
  AND LOWER(TRIM(COALESCE(p.lverification, ''))) = 'verified'
  AND COALESCE(p.lrefer_by, '') <> 'QBP'
  AND NOT EXISTS (
    SELECT 1
    FROM tblaudit_trail audit
    WHERE audit.lmain_id = p.lmain_id
      AND audit.lrefno = p.lsessionid
      AND audit.lpage = 'Daily Call Monitoring Dashboard'
      AND audit.laction = 'Verify Prospect'
  )
  AND NOT EXISTS (
    SELECT 1
    FROM tblledger ledger
    WHERE ledger.lmainid = CAST(p.lmain_id AS CHAR)
      AND ledger.lcustomerid = p.lsessionid
      AND ledger.ldatetime < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
      AND LOWER(TRIM(COALESCE(ledger.ltype, ''))) = 'debit'
      AND LOWER(TRIM(COALESCE(ledger.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')
  )
  AND NOT EXISTS (
    SELECT 1
    FROM tbltransaction transaction_row
    WHERE transaction_row.lmain_id = p.lmain_id
      AND transaction_row.lcustomerid = p.lsessionid
      AND transaction_row.ldate < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
      AND COALESCE(transaction_row.lcancel, 0) = 0
      AND COALESCE(transaction_row.lsubmitstat, '') IN ('Approved', 'Posted', 'Submitted')
      AND COALESCE(transaction_row.invoice_refno, '') = ''
      AND COALESCE(transaction_row.ldr_refno, '') = ''
  );
