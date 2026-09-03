-- Focused indexes for Item Suggested for Stock Report (NotListed + catalog match).
-- Safe to run multiple times.

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinquiry_item' AND INDEX_NAME = 'idx_suggested_inquiry_item_remark_ref');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinquiry_item ADD INDEX idx_suggested_inquiry_item_remark_ref (lremark(16), linq_refno(64))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinquiry' AND INDEX_NAME = 'idx_suggested_inquiry_main_date_ref');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinquiry ADD INDEX idx_suggested_inquiry_main_date_ref (lmain_id(32), ldate, lrefno(64))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_item' AND INDEX_NAME = 'idx_suggested_inventory_main_itemcode');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinventory_item ADD INDEX idx_suggested_inventory_main_itemcode (lmain_id, litemcode(64), lnot_inventory, ldeleted)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_item' AND INDEX_NAME = 'idx_suggested_inventory_main_partno');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinventory_item ADD INDEX idx_suggested_inventory_main_partno (lmain_id, lpartno(64), lnot_inventory, ldeleted)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
