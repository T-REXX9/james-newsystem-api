-- Additive-only procurement recovery metadata.
-- Compatible with MySQL/MariaDB versions that do not support
-- ALTER TABLE ... ADD COLUMN IF NOT EXISTS.

DELIMITER //

DROP PROCEDURE IF EXISTS add_procurement_recovery_column//
CREATE PROCEDURE add_procurement_recovery_column(
    IN target_table VARCHAR(64),
    IN target_column VARCHAR(64),
    IN column_definition TEXT
)
BEGIN
    SET @column_exists = (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = target_table
          AND COLUMN_NAME = target_column
    );

    IF @column_exists = 0 THEN
        SET @recovery_sql = CONCAT(
            'ALTER TABLE `', target_table, '` ADD COLUMN `', target_column, '` ', column_definition
        );
        PREPARE recovery_stmt FROM @recovery_sql;
        EXECUTE recovery_stmt;
        DEALLOCATE PREPARE recovery_stmt;
    END IF;
END//

CALL add_procurement_recovery_column('tblpr_list', 'ldeleted', 'TINYINT(1) NOT NULL DEFAULT 0')//
CALL add_procurement_recovery_column('tblpr_list', 'ldeleted_at', 'DATETIME NULL')//
CALL add_procurement_recovery_column('tblpr_list', 'ldeleted_by', 'INT NULL')//
CALL add_procurement_recovery_column('tblpr_list', 'ldelete_reason', 'VARCHAR(500) NULL')//
CALL add_procurement_recovery_column('tblpr_list', 'lunposted_at', 'DATETIME NULL')//
CALL add_procurement_recovery_column('tblpr_list', 'lunposted_by', 'INT NULL')//
CALL add_procurement_recovery_column('tblpr_list', 'lunpost_reason', 'VARCHAR(500) NULL')//

CALL add_procurement_recovery_column('tblpo_list', 'ldeleted', 'TINYINT(1) NOT NULL DEFAULT 0')//
CALL add_procurement_recovery_column('tblpo_list', 'ldeleted_at', 'DATETIME NULL')//
CALL add_procurement_recovery_column('tblpo_list', 'ldeleted_by', 'INT NULL')//
CALL add_procurement_recovery_column('tblpo_list', 'ldelete_reason', 'VARCHAR(500) NULL')//
CALL add_procurement_recovery_column('tblpo_list', 'lunposted_at', 'DATETIME NULL')//
CALL add_procurement_recovery_column('tblpo_list', 'lunposted_by', 'INT NULL')//
CALL add_procurement_recovery_column('tblpo_list', 'lunpost_reason', 'VARCHAR(500) NULL')//

CALL add_procurement_recovery_column('tblpurchase_order', 'ldeleted', 'TINYINT(1) NOT NULL DEFAULT 0')//
CALL add_procurement_recovery_column('tblpurchase_order', 'ldeleted_at', 'DATETIME NULL')//
CALL add_procurement_recovery_column('tblpurchase_order', 'ldeleted_by', 'INT NULL')//
CALL add_procurement_recovery_column('tblpurchase_order', 'ldelete_reason', 'VARCHAR(500) NULL')//
CALL add_procurement_recovery_column('tblpurchase_order', 'lunposted_at', 'DATETIME NULL')//
CALL add_procurement_recovery_column('tblpurchase_order', 'lunposted_by', 'INT NULL')//
CALL add_procurement_recovery_column('tblpurchase_order', 'lunpost_reason', 'VARCHAR(500) NULL')//

CALL add_procurement_recovery_column('tblaudit_trail', 'lreason', 'VARCHAR(500) NULL')//
CALL add_procurement_recovery_column('tblaudit_trail', 'lold_status', 'VARCHAR(64) NULL')//
CALL add_procurement_recovery_column('tblaudit_trail', 'lnew_status', 'VARCHAR(64) NULL')//

DROP PROCEDURE add_procurement_recovery_column//
DELIMITER ;
