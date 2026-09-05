-- Preserve legacy Suggested Stock entries that previously disappeared as soon
-- as their matching product was created.  They remain eligible for direct PR
-- selection under the new ProductCreated workflow state.
--
-- Two indexed updates instead of one OR-join: the OR form nested-looped
-- inventory x inquiries x items (billions of estimated rows) and hung
-- productionupdate after 031 with no output.

UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
INNER JOIN tblinventory_item product
  ON CAST(product.lmain_id AS CHAR) = tr.lmain_id
 AND product.litemcode = i.litem_code
 AND IFNULL(product.lnot_inventory, 0) = 0
 AND IFNULL(product.ldeleted, 0) = 0
SET i.lremark = 'ProductCreated'
WHERE i.lremark = 'Listed'
  AND i.litem_code <> '';

UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
INNER JOIN tblinventory_item product
  ON CAST(product.lmain_id AS CHAR) = tr.lmain_id
 AND product.lpartno = i.lpartno
 AND IFNULL(product.lnot_inventory, 0) = 0
 AND IFNULL(product.ldeleted, 0) = 0
SET i.lremark = 'ProductCreated'
WHERE i.lremark = 'Listed'
  AND i.lpartno <> '';

-- Supports the group-level completion update after a PR is created.
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinquiry_item' AND INDEX_NAME = 'idx_suggested_pr_completion');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinquiry_item ADD INDEX idx_suggested_pr_completion (lpartno(64), litem_code(64), lremark(16), linq_refno(64)), ALGORITHM=INPLACE, LOCK=NONE', 'DO 0');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
