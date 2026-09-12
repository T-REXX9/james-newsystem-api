-- Seed the "See all records" Page Action Permission so behaviour is unchanged.
--
-- Daily Call Monitoring used to widen a viewer from "only my assigned
-- customers" to "every customer" based on role names held in PHP source
-- (accountant, assistant accountant). That is now the can_view_all_records
-- Page Action Permission, configurable on System Access. Accounts holding
-- those roles must keep the reach they already have, so grant it to them once.
--
-- Master Users bypass stored permissions, so they need no row here. The
-- customer-request and recycle-bin scopes were Master-User-only already, which
-- the new permission reproduces by defaulting to off.
--
-- Every migration re-runs on each deployment, so this only fills in accounts
-- that have no answer recorded yet. Once an administrator ticks or unticks the
-- box the key exists and this migration leaves it alone.

-- Give the target accounts a JSON document to write into.
UPDATE tblaccount acc
JOIN tblusertype role ON role.lid = acc.ltype
SET acc.laction_permissions = '{}'
WHERE LOWER(TRIM(COALESCE(role.ltype_name, ''))) IN ('accountant', 'assistant accountant')
  AND (
    acc.laction_permissions IS NULL
    OR TRIM(acc.laction_permissions) = ''
    OR JSON_VALID(acc.laction_permissions) = 0
  );

-- JSON_SET cannot create two missing levels at once, so build the path down.
UPDATE tblaccount acc
JOIN tblusertype role ON role.lid = acc.ltype
SET acc.laction_permissions = JSON_SET(acc.laction_permissions, '$.pages', JSON_OBJECT())
WHERE LOWER(TRIM(COALESCE(role.ltype_name, ''))) IN ('accountant', 'assistant accountant')
  AND JSON_VALID(acc.laction_permissions) = 1
  AND JSON_EXTRACT(acc.laction_permissions, '$.pages') IS NULL;

UPDATE tblaccount acc
JOIN tblusertype role ON role.lid = acc.ltype
SET acc.laction_permissions = JSON_SET(
    acc.laction_permissions,
    '$.pages."Daily Call Monitoring"',
    JSON_OBJECT()
)
WHERE LOWER(TRIM(COALESCE(role.ltype_name, ''))) IN ('accountant', 'assistant accountant')
  AND JSON_VALID(acc.laction_permissions) = 1
  AND JSON_EXTRACT(acc.laction_permissions, '$.pages."Daily Call Monitoring"') IS NULL;

-- Only fill the gap. An administrator's later choice, true or false, stays put.
UPDATE tblaccount acc
JOIN tblusertype role ON role.lid = acc.ltype
SET acc.laction_permissions = JSON_SET(
    acc.laction_permissions,
    '$.pages."Daily Call Monitoring".can_view_all_records',
    TRUE
)
WHERE LOWER(TRIM(COALESCE(role.ltype_name, ''))) IN ('accountant', 'assistant accountant')
  AND JSON_VALID(acc.laction_permissions) = 1
  AND JSON_EXTRACT(acc.laction_permissions, '$.pages."Daily Call Monitoring".can_view_all_records') IS NULL;
