-- Indexes for the Reorder Report list query used by Select All.
-- Covers remaining inventory-item filters that the purchasing-control
-- indexes do not: not-deleted and visibility status.
-- Safe to run multiple times. Existing idx_reorder_* names are unchanged.

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_item' AND INDEX_NAME = 'idx_reorder_inventory_main_deleted_status');
SET @idx_sql := IF(@idx_exists = 0, 'ALTER TABLE tblinventory_item ADD INDEX idx_reorder_inventory_main_deleted_status (lmain_id, ldeleted, lsession(64), lstatus, lid)', 'SELECT 1');
PREPARE idx_stmt FROM @idx_sql; EXECUTE idx_stmt; DEALLOCATE PREPARE idx_stmt;
