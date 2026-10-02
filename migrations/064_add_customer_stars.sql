CREATE TABLE IF NOT EXISTS tblpatient_stars (
    lmain_id INT NOT NULL,
    lsessionid VARCHAR(64) NOT NULL,
    lis_starred TINYINT(1) NOT NULL DEFAULT 0,
    lupdated_by INT NOT NULL DEFAULT 0,
    lupdated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (lmain_id, lsessionid)
);
