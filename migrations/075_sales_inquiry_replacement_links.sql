-- Track one-to-one replacement relationships without changing the legacy inquiry key.
ALTER TABLE tblinquiry
  ADD COLUMN lreplacement_of_refno VARCHAR(64) NULL,
  ADD COLUMN lreplaced_by_refno VARCHAR(64) NULL,
  ADD KEY idx_inquiry_replacement_source (lmain_id, lreplacement_of_refno),
  ADD KEY idx_inquiry_replacement_target (lmain_id, lreplaced_by_refno);
