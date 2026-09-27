-- Phase 1: Create duplicate-prospect request approval workflow table
CREATE TABLE IF NOT EXISTS tblpatient_duplicate_request (
    lid INT AUTO_INCREMENT PRIMARY KEY,
    lmain_id INT NOT NULL,
    lsessionid VARCHAR(64) NOT NULL,
    lexisting_prospect_id INT NOT NULL,
    lnew_prospect_id INT NOT NULL,
    lcompany_name VARCHAR(255) NOT NULL DEFAULT '',
    lcontact_person VARCHAR(255) NOT NULL DEFAULT '',
    lphone VARCHAR(20) NOT NULL DEFAULT '',
    lmatching_fields JSON,
    lstatus ENUM('pending', 'approved', 'rejected', 'snoozed') DEFAULT 'pending',
    lsubmitted_by INT NOT NULL DEFAULT 0,
    lsubmitted_by_name VARCHAR(255) NOT NULL DEFAULT '',
    lapproved_by INT,
    lapproved_by_name VARCHAR(255),
    lapproved_at TIMESTAMP NULL,
    lsnoozed_until TIMESTAMP NULL,
    lcreated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    lupdated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_duplicate_request_status (lstatus),
    KEY idx_duplicate_request_main_id (lmain_id),
    KEY idx_duplicate_request_pending (lstatus, lcreated_at),
    KEY idx_duplicate_request_snoozed (lsnoozed_until),
    UNIQUE KEY uniq_duplicate_request (lexisting_prospect_id, lnew_prospect_id, lstatus)
);

-- Notification batching support: track grouped duplicate notifications per master user
CREATE TABLE IF NOT EXISTS tblduplicates_notification_batch (
    lid INT AUTO_INCREMENT PRIMARY KEY,
    lmaster_user_id INT NOT NULL,
    lpending_count INT DEFAULT 1,
    llast_notified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    lbatch_window_expires_at TIMESTAMP NULL,
    KEY idx_batch_master_user (lmaster_user_id),
    KEY idx_batch_window_expires (lbatch_window_expires_at),
    UNIQUE KEY uniq_batch_per_master (lmaster_user_id)
);
