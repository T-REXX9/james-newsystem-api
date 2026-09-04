-- Preserve legacy Suggested Stock entries that previously disappeared as soon
-- as their matching product was created.  They remain eligible for direct PR
-- selection under the new ProductCreated workflow state.
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
INNER JOIN tblinventory_item product ON product.lmain_id = tr.lmain_id
  AND COALESCE(product.lnot_inventory, 0) = 0
  AND COALESCE(product.ldeleted, 0) = 0
  AND (
    (COALESCE(i.litem_code, '') <> '' AND product.litemcode = i.litem_code)
    OR (COALESCE(i.lpartno, '') <> '' AND product.lpartno = i.lpartno)
  )
SET i.lremark = 'ProductCreated'
WHERE COALESCE(i.lremark, '') = 'Listed';

-- Supports the group-level completion update after a PR is created.
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinquiry_item' AND INDEX_NAME = 'idx_suggested_pr_completion');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinquiry_item ADD INDEX idx_suggested_pr_completion (lpartno(64), litem_code(64), lremark(16), linq_refno(64))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
