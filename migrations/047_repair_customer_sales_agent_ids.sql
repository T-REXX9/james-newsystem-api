-- Repair customer-agent assignments created by clients that wrote an account
-- display name into tblpatient.lsales_person instead of the staff account ID.
-- Ambiguous duplicate names are intentionally left untouched for manual review.
UPDATE tblpatient AS customer
INNER JOIN (
    SELECT
        scoped_account.main_id,
        scoped_account.normalized_name,
        MIN(scoped_account.account_id) AS account_id
    FROM (
        SELECT
            CASE
                WHEN COALESCE(account.lmother_id, 0) > 0 THEN account.lmother_id
                ELSE account.lid
            END AS main_id,
            LOWER(TRIM(CONCAT_WS(' ', NULLIF(TRIM(account.lfname), ''), NULLIF(TRIM(account.llname), '')))) AS normalized_name,
            account.lid AS account_id
        FROM tblaccount AS account
        WHERE COALESCE(account.lstatus, 0) = 1
    ) AS scoped_account
    WHERE scoped_account.normalized_name <> ''
    GROUP BY scoped_account.main_id, scoped_account.normalized_name
    HAVING COUNT(*) = 1
) AS unique_account
    ON unique_account.main_id = customer.lmain_id
   AND unique_account.normalized_name = LOWER(TRIM(customer.lsales_person))
SET customer.lsales_person = CAST(unique_account.account_id AS CHAR)
WHERE TRIM(COALESCE(customer.lsales_person, '')) <> ''
  AND TRIM(customer.lsales_person) NOT REGEXP '^[0-9]+$';
