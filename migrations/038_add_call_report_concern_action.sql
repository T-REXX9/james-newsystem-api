-- Store Concern and Action as distinct fields on Application call reports.
-- Replaces the single notes blob so the Master User Call Records page can
-- show structured documentation alongside each Phone record.
ALTER TABLE `call_report_threads`
  ADD COLUMN `concern` TEXT NULL AFTER `report_body`,
  ADD COLUMN `action` TEXT NULL AFTER `concern`;
