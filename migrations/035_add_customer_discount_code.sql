ALTER TABLE tblpatient
  ADD COLUMN ldiscount_code varchar(50) NULL DEFAULT NULL AFTER lprice_group;
