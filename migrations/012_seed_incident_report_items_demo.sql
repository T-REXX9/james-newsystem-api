-- ============================================================================
-- Seed demo incident report item rows from real product/supplier cost records
-- Migration: 012_seed_incident_report_items_demo.sql
-- Safe to run multiple times; refreshes only rows marked with this seed.
-- ============================================================================

DELETE FROM incident_report_items
WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.seed')) = 'incident-items-demo';

INSERT INTO incident_report_items (
  main_id,
  incident_report_id,
  contact_id,
  product_id,
  item_code,
  part_no,
  description,
  supplier_id,
  supplier_name,
  quantity,
  issue_summary,
  match_source,
  confidence_score,
  metadata,
  created_by_user_id,
  created_at,
  updated_at
)
SELECT
  1 AS main_id,
  CONCAT('DEMO-INC-', LPAD(real_products.rn, 2, '0'), '-', LPAD(seq.n, 2, '0')) AS incident_report_id,
  NULL AS contact_id,
  real_products.product_id,
  real_products.item_code,
  real_products.part_no,
  real_products.description,
  real_products.supplier_id,
  real_products.supplier_name,
  1 AS quantity,
  CASE
    WHEN seq.n % 3 = 0 THEN 'Returned by customer due to recurring fitment and quality concern.'
    WHEN seq.n % 2 = 0 THEN 'Warehouse inspection found inconsistent finish before release.'
    ELSE 'Customer reported repeated product issue during installation.'
  END AS issue_summary,
  CASE
    WHEN seq.n % 3 = 0 THEN 'related_transaction'
    WHEN seq.n % 2 = 0 THEN 'description_match'
    ELSE 'manual'
  END AS match_source,
  CASE
    WHEN seq.n % 3 = 0 THEN 0.9800
    WHEN seq.n % 2 = 0 THEN 0.8200
    ELSE 1.0000
  END AS confidence_score,
  JSON_OBJECT(
    'seed', 'incident-items-demo',
    'source_table', 'tblsupplier_cost',
    'source_supplier_cost_id', real_products.supplier_cost_id
  ) AS metadata,
  1 AS created_by_user_id,
  DATE_SUB(CURRENT_TIMESTAMP(3), INTERVAL ((real_products.rn * 2) + seq.n) DAY) AS created_at,
  DATE_SUB(CURRENT_TIMESTAMP(3), INTERVAL ((real_products.rn * 2) + seq.n) DAY) AS updated_at
FROM (
  SELECT *
  FROM (
    SELECT
      ROW_NUMBER() OVER (ORDER BY supplier_rank ASC, supplier_id ASC, supplier_cost_id ASC) AS rn,
      ranked_by_supplier.*
    FROM (
      SELECT
        sc.lid AS supplier_cost_id,
        COALESCE(NULLIF(sc.litemsession, ''), itm.lsession) AS product_id,
        COALESCE(NULLIF(sc.litemcode, ''), itm.litemcode, '') AS item_code,
        COALESCE(NULLIF(sc.lpartno, ''), itm.lpartno, '') AS part_no,
        COALESCE(NULLIF(itm.ldescription, ''), NULLIF(sc.lpartno, ''), NULLIF(sc.litemcode, ''), 'Product from inventory database') AS description,
        COALESCE(NULLIF(sc.lsupplier_id, ''), 'unassigned') AS supplier_id,
        COALESCE(NULLIF(sc.lsupplier_name, ''), sup.lname, 'Unassigned Supplier') AS supplier_name,
        ROW_NUMBER() OVER (PARTITION BY COALESCE(NULLIF(sc.lsupplier_id, ''), 'unassigned') ORDER BY sc.lid ASC) AS supplier_rank
      FROM tblsupplier_cost sc
      INNER JOIN tblinventory_item itm
        ON itm.lsession = sc.litemsession
       AND CAST(COALESCE(itm.lmain_id, 0) AS SIGNED) = 1
       AND COALESCE(itm.lstatus, 1) = 1
      LEFT JOIN tblsupplier sup
        ON CAST(sup.lid AS CHAR) = CAST(sc.lsupplier_id AS CHAR)
      WHERE CAST(COALESCE(sc.lmainid, 0) AS SIGNED) = 1
        AND COALESCE(sc.litemsession, '') <> ''
    ) ranked_by_supplier
    WHERE ranked_by_supplier.supplier_rank <= 2
  ) ranked_products
  ORDER BY ranked_products.supplier_rank ASC, ranked_products.supplier_id ASC
  LIMIT 10
) real_products
INNER JOIN (
  SELECT 1 AS n
  UNION ALL SELECT 2
  UNION ALL SELECT 3
  UNION ALL SELECT 4
  UNION ALL SELECT 5
) seq
  ON seq.n <= CASE
    WHEN real_products.rn = 1 THEN 5
    WHEN real_products.rn = 2 THEN 4
    WHEN real_products.rn = 3 THEN 4
    WHEN real_products.rn <= 5 THEN 3
    ELSE 2
  END;
