-- Recovery snapshots for explicit customer/product deletions from this release.
CREATE TABLE IF NOT EXISTS local_recycle_bin (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  main_id INT NOT NULL,
  item_type ENUM('contact','product') NOT NULL,
  item_id VARCHAR(128) NOT NULL,
  label VARCHAR(500) NOT NULL,
  snapshot JSON NOT NULL,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_local_recycle_item (main_id, item_type, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
