-- Remove the incident-item demo rows from live databases.
-- Safe to run repeatedly: only rows explicitly marked by the demo seed are removed.
-- Real incident items are not affected.

DELETE FROM incident_report_items
WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.seed')) = 'incident-items-demo';
