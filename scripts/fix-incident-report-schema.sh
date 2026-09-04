#!/usr/bin/env bash
# Apply the schema required by daily-call / warehouse incident-report APIs.
# Fixes: SQLSTATE[42S22] Unknown column 'report_time' (HTTP 500 on list/create).
#
# Usage: api/scripts/fix-incident-report-schema.sh
# Exit 0 when schema is healthy (and diagnose loop is green when API is up).

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

eval "$(php -r '
require "src/Support/Env.php";
App\Support\Env::load(".env");
foreach (["DB_HOST","DB_PORT","DB_NAME","DB_USER","DB_PASS"] as $k) {
  $v = (string) App\Support\Env::get($k, $k === "DB_HOST" ? "127.0.0.1" : ($k === "DB_PORT" ? "3306" : ""));
  echo $k . "=" . escapeshellarg($v) . "\n";
}
')"

export MYSQL_PWD="$DB_PASS"

migrations=(
  "migrations/016_create_incident_reports.sql"
  "migrations/022_add_lbc_rto_to_incident_reports.sql"
  "migrations/024_create_incident_return_approval_workflow.sql"
  "migrations/026_add_incident_report_times.sql"
)

echo "Applying incident_reports migrations on ${DB_NAME}@${DB_HOST}:${DB_PORT}..."
for migration in "${migrations[@]}"; do
  if [[ ! -f "$migration" ]]; then
    echo "Missing migration file: $migration" >&2
    exit 1
  fi
  echo "  -> $migration"
  mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" < "$migration"
done

echo "Running schema guard..."
php tests/IncidentReportSchemaUnitTest.php

if [[ -x scripts/diagnose-incident-report-500.sh ]]; then
  echo "Running HTTP diagnose loop..."
  ./scripts/diagnose-incident-report-500.sh || {
    echo "Schema is applied but HTTP diagnose failed (is the API server running on APP_URL?)." >&2
    exit 1
  }
fi

echo "FIXED: incident report schema is ready."
