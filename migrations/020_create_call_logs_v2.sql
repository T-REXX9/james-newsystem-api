-- Hardware and manual call activity used by Daily Call Monitoring.
-- Kept independent of legacy call-log tables so fresh deployments work too.
CREATE TABLE IF NOT EXISTS `tblcall_logs_v2` (
  `lid` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lagent_id` int NOT NULL,
  `ldevice_id` varchar(255) NOT NULL,
  `lcustomer_id` int DEFAULT NULL,
  `lphone_number` varchar(50) NOT NULL,
  `ldirection` enum('inbound','outbound','missed') NOT NULL,
  `lduration_seconds` int NOT NULL DEFAULT 0,
  `lcall_timestamp` datetime NOT NULL,
  `lsource` enum('hardware','manual') NOT NULL DEFAULT 'hardware',
  `lcreated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`lid`),
  KEY `idx_lagent_id` (`lagent_id`),
  KEY `idx_lcustomer_id` (`lcustomer_id`),
  KEY `idx_lphone_number` (`lphone_number`),
  KEY `idx_ldirection` (`ldirection`),
  KEY `idx_lcall_timestamp` (`lcall_timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
