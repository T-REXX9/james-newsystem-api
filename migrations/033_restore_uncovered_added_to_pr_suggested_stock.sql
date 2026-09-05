-- Restore Item Suggested for Stock rows that were stamped AddedToPR but are no
-- longer covered by a live purchase request. Safe to re-run: already ProductCreated
-- rows and still-covered AddedToPR rows are left unchanged.
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
SET i.lremark = 'ProductCreated'
WHERE COALESCE(i.lremark, '') = 'AddedToPR'
  AND NOT EXISTS (
    SELECT 1
    FROM tblpr_item pri
    INNER JOIN tblpr_list pr ON pr.lrefno = pri.lrefno
    WHERE COALESCE(pr.ldeleted, 0) = 0
      AND LOWER(COALESCE(pr.lstatus, '')) <> 'deleted'
      AND pri.lpart_no = i.lpartno
      AND (
        pri.litem_code = i.litem_code
        OR (COALESCE(pri.litem_code, '') = '' AND COALESCE(i.litem_code, '') = '')
      )
      AND pri.ldesc = i.ldesc
  );
