-- Store optimized, authenticated record-image data for customer/prospect and product records.
SET @add_patient_record_image := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'lrecord_image') = 0,
    'ALTER TABLE tblpatient ADD COLUMN lrecord_image LONGTEXT NULL',
    'SELECT 1'
);
PREPARE stmt_patient_record_image FROM @add_patient_record_image;
EXECUTE stmt_patient_record_image;
DEALLOCATE PREPARE stmt_patient_record_image;

SET @add_patient_record_image_position := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'lrecord_image_position') = 0,
    'ALTER TABLE tblpatient ADD COLUMN lrecord_image_position VARCHAR(16) NULL',
    'SELECT 1'
);
PREPARE stmt_patient_record_image_position FROM @add_patient_record_image_position;
EXECUTE stmt_patient_record_image_position;
DEALLOCATE PREPARE stmt_patient_record_image_position;

SET @add_product_record_image := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_item' AND COLUMN_NAME = 'lrecord_image') = 0,
    'ALTER TABLE tblinventory_item ADD COLUMN lrecord_image LONGTEXT NULL',
    'SELECT 1'
);
PREPARE stmt_product_record_image FROM @add_product_record_image;
EXECUTE stmt_product_record_image;
DEALLOCATE PREPARE stmt_product_record_image;

SET @add_product_record_image_position := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblinventory_item' AND COLUMN_NAME = 'lrecord_image_position') = 0,
    'ALTER TABLE tblinventory_item ADD COLUMN lrecord_image_position VARCHAR(16) NULL',
    'SELECT 1'
);
PREPARE stmt_product_record_image_position FROM @add_product_record_image_position;
EXECUTE stmt_product_record_image_position;
DEALLOCATE PREPARE stmt_product_record_image_position;
