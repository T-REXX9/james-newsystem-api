-- Sales Inquiry ownership is the account recorded in tblinquiry.luser.
-- Customer sales-agent assignment is intentionally unrelated and must never
-- overwrite document ownership. Keep historical records correct even when the
-- creator's account is now inactive.
CREATE INDEX idx_transaction_inquiry_ref_main
    ON tbltransaction (linquiry_refno, lmain_id);

UPDATE tblinquiry AS inquiry
INNER JOIN tblaccount AS creator
    ON creator.lid = CAST(inquiry.luser AS UNSIGNED)
   AND (creator.lmother_id = inquiry.lmain_id OR creator.lid = inquiry.lmain_id)
SET
    inquiry.lsales_person_id = CAST(creator.lid AS CHAR),
    inquiry.lsalesperson = TRIM(CONCAT(COALESCE(creator.lfname, ''), ' ', COALESCE(creator.llname, '')))
WHERE COALESCE(inquiry.luser, '') REGEXP '^[0-9]+$'
  AND (
      COALESCE(inquiry.lsales_person_id, '') <> CAST(creator.lid AS CHAR)
      OR TRIM(COALESCE(inquiry.lsalesperson, '')) <> TRIM(CONCAT(COALESCE(creator.lfname, ''), ' ', COALESCE(creator.llname, '')))
  );

-- Linked Sales Orders mirror their Sales Inquiry header, so repair their
-- display fields too. This keeps inquiry, order, and print views consistent.
UPDATE tbltransaction AS sales_order
INNER JOIN tblinquiry AS inquiry
    ON inquiry.lmain_id = sales_order.lmain_id
   AND inquiry.lrefno = sales_order.linquiry_refno
INNER JOIN tblaccount AS creator
    ON creator.lid = CAST(inquiry.luser AS UNSIGNED)
   AND (creator.lmother_id = inquiry.lmain_id OR creator.lid = inquiry.lmain_id)
SET
    sales_order.lsales_person_id = CAST(creator.lid AS CHAR),
    sales_order.lsales_person = TRIM(CONCAT(COALESCE(creator.lfname, ''), ' ', COALESCE(creator.llname, '')))
WHERE COALESCE(inquiry.luser, '') REGEXP '^[0-9]+$'
  AND (
      COALESCE(sales_order.lsales_person_id, '') <> CAST(creator.lid AS CHAR)
      OR TRIM(COALESCE(sales_order.lsales_person, '')) <> TRIM(CONCAT(COALESCE(creator.lfname, ''), ' ', COALESCE(creator.llname, '')))
  );
