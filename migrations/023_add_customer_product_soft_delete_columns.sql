-- Additive-only soft-delete metadata for customers and products.

DELIMITER //

DROP PROCEDURE IF EXISTS add_soft_delete_column//
CREATE PROCEDURE add_soft_delete_column(
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
        SET @soft_delete_sql = CONCAT(
            'ALTER TABLE `', target_table, '` ADD COLUMN `', target_column, '` ', column_definition
        );
        PREPARE soft_delete_stmt FROM @soft_delete_sql;
        EXECUTE soft_delete_stmt;
        DEALLOCATE PREPARE soft_delete_stmt;
    END IF;
END//

CALL add_soft_delete_column('tblpatient', 'ldeleted', 'TINYINT(1) NOT NULL DEFAULT 0')//
CALL add_soft_delete_column('tblpatient', 'ldeleted_at', 'DATETIME NULL')//
CALL add_soft_delete_column('tblpatient', 'ldeleted_by', 'INT NULL')//
CALL add_soft_delete_column('tblpatient', 'ldelete_reason', 'VARCHAR(500) NULL')//

CALL add_soft_delete_column('tblinventory_item', 'ldeleted', 'TINYINT(1) NOT NULL DEFAULT 0')//
CALL add_soft_delete_column('tblinventory_item', 'ldeleted_at', 'DATETIME NULL')//
CALL add_soft_delete_column('tblinventory_item', 'ldeleted_by', 'INT NULL')//
CALL add_soft_delete_column('tblinventory_item', 'ldelete_reason', 'VARCHAR(500) NULL')//

DROP PROCEDURE add_soft_delete_column//
DELIMITER ;
