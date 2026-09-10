-- Allow Daily Call Monitoring to assign a customer to a team independently
-- of an optional individual sales-agent assignment.
SET @add_daily_call_team := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND COLUMN_NAME = 'lsales_team') = 0,
    'ALTER TABLE tblpatient ADD COLUMN lsales_team INT NULL',
    'SELECT 1'
);
PREPARE stmt_daily_call_team FROM @add_daily_call_team;
EXECUTE stmt_daily_call_team;
DEALLOCATE PREPARE stmt_daily_call_team;

SET @add_daily_call_team_index := IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblpatient' AND INDEX_NAME = 'idx_tblpatient_lsales_team') = 0,
    'ALTER TABLE tblpatient ADD INDEX idx_tblpatient_lsales_team (lmain_id, lsales_team)',
    'SELECT 1'
);
PREPARE stmt_daily_call_team_index FROM @add_daily_call_team_index;
EXECUTE stmt_daily_call_team_index;
DEALLOCATE PREPARE stmt_daily_call_team_index;
