-- Restore Item Suggested for Stock rows that were stamped AddedToPR but are no
-- longer covered by a live purchase request. Safe to re-run: already ProductCreated
-- rows and still-covered AddedToPR rows are left unchanged.
--
-- Filter AddedToPR via the remark index. COALESCE(lremark,'') = 'AddedToPR' forced
-- a full table scan and a billion-row antijoin against every PR line.

UPDATE tblinquiry_item i
SET i.lremark = 'ProductCreated'
WHERE i.lremark = 'AddedToPR'
  AND NOT EXISTS (
    SELECT 1
    FROM tblpr_item pri
    INNER JOIN tblpr_list pr ON pr.lrefno = pri.lrefno
    WHERE pr.ldeleted = 0
      AND (pr.lstatus IS NULL OR pr.lstatus = '' OR LOWER(pr.lstatus) <> 'deleted')
      AND pri.lpart_no = i.lpartno
      AND pri.ldesc = i.ldesc
      AND (
        pri.litem_code = i.litem_code
        OR (
          (pri.litem_code IS NULL OR pri.litem_code = '')
          AND (i.litem_code IS NULL OR i.litem_code = '')
        )
      )
  );
