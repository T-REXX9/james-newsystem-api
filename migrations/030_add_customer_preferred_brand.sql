-- Preferred brand on customer records (Ishinomoto | Others).

DELIMITER //

DROP PROCEDURE IF EXISTS add_customer_preferred_brand_column//
CREATE PROCEDURE add_customer_preferred_brand_column()
BEGIN
    SET @column_exists = (
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'tblpatient'
          AND COLUMN_NAME = 'lpreferred_brand'
    );

    IF @column_exists = 0 THEN
        ALTER TABLE `tblpatient`
            ADD COLUMN `lpreferred_brand` VARCHAR(32) NULL DEFAULT NULL
            AFTER `ldebt_type`;
    END IF;
END//

CALL add_customer_preferred_brand_column()//
DROP PROCEDURE add_customer_preferred_brand_column//
DELIMITER ;
