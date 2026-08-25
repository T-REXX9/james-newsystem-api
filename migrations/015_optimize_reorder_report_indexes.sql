-- Focused indexes for the live Reorder Report purchasing-control query.
-- Safe to run multiple times. Historical wide legacy indexes are preserved.

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_item' AND INDEX_NAME = 'idx_reorder_inventory_main_session');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinventory_item ADD INDEX idx_reorder_inventory_main_session (lmain_id, lsession(64), lid)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_logs' AND INDEX_NAME = 'idx_reorder_logs_inventory_qty');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinventory_logs ADD INDEX idx_reorder_logs_inventory_qty (linvent_id, lin, lout)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbltransaction' AND INDEX_NAME = 'idx_reorder_transaction_ref_main');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tbltransaction ADD INDEX idx_reorder_transaction_ref_main (lrefno(64), lmain_id(32), lcancel)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tbltransaction_item' AND INDEX_NAME = 'idx_reorder_sales_ref_cancel_item');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tbltransaction_item ADD INDEX idx_reorder_sales_ref_cancel_item (lrefno(64), lcancel, litem_refno(64), linv_refno(64))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpr_item' AND INDEX_NAME = 'idx_reorder_pr_item_session');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpr_item ADD INDEX idx_reorder_pr_item_session (litem_refno(64), lrefno(64), lpo_refno(64))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpr_item' AND INDEX_NAME = 'idx_reorder_pr_item_code');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpr_item ADD INDEX idx_reorder_pr_item_code (litem_code(64), lrefno(64), lpo_refno(64))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpr_list' AND INDEX_NAME = 'idx_reorder_pr_list_ref');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpr_list ADD INDEX idx_reorder_pr_list_ref (lrefno(64), lstatus(32), lapproval(32))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpo_itemlist' AND INDEX_NAME = 'idx_reorder_po_item_session');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpo_itemlist ADD INDEX idx_reorder_po_item_session (litem_refno(64), lrefno(64), lqty, lreceiving_qty)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpo_itemlist' AND INDEX_NAME = 'idx_reorder_po_item_code');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpo_itemlist ADD INDEX idx_reorder_po_item_code (litem_code(64), lrefno(64), lqty, lreceiving_qty)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpo_list' AND INDEX_NAME = 'idx_reorder_po_list_ref_main');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpo_list ADD INDEX idx_reorder_po_list_ref_main (lrefno(64), lmain_id(32), ltransaction_status(32))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpurchase_item' AND INDEX_NAME = 'idx_reorder_rr_item_session');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpurchase_item ADD INDEX idx_reorder_rr_item_session (litem_refno(64), lrefno(64), litem_code(64), lqty)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpurchase_item' AND INDEX_NAME = 'idx_reorder_rr_item_ref');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpurchase_item ADD INDEX idx_reorder_rr_item_ref (lrefno(64), litem_refno(64), litem_code(64), lqty)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpurchase_order' AND INDEX_NAME = 'idx_reorder_rr_po_ref');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblpurchase_order ADD INDEX idx_reorder_rr_po_ref (lpo_refno(64), lrefno(64), ltransaction_status(32))', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblsupplier_cost' AND INDEX_NAME = 'idx_reorder_supplier_item');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblsupplier_cost ADD INDEX idx_reorder_supplier_item (lmainid(32), litemsession(64), lid)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcredit_return_item' AND INDEX_NAME = 'idx_reorder_return_item_session');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblcredit_return_item ADD INDEX idx_reorder_return_item_session (litem_refno(64), lqty)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblcredit_return_item' AND INDEX_NAME = 'idx_reorder_return_inventory_session');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblcredit_return_item ADD INDEX idx_reorder_return_inventory_session (linv_refno(64), lqty)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
