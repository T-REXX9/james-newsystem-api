-- Additional delivery destinations for a customer.  tblpatient.ldelivery_address
-- remains the primary address for legacy reports and documents.
CREATE TABLE IF NOT EXISTS tblpatient_delivery_address (
    lid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lmain_id BIGINT UNSIGNED NOT NULL,
    lsessionid VARCHAR(100) NOT NULL,
    laddress TEXT NOT NULL,
    lsequence INT UNSIGNED NOT NULL DEFAULT 0,
    lcreated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (lid),
    KEY idx_patient_delivery_address_customer (lmain_id, lsessionid, lsequence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
