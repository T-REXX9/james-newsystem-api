#!/usr/bin/env bash
# Tight feedback loop for: daily-call incident-reports 500 / Unknown column 'report_time'
# Usage: api/scripts/diagnose-incident-report-500.sh [contact_id]
# Exit 0 = healthy; Exit 1 = bug symptom present (or loop infrastructure failed).

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

CONTACT="${1:-81293402720200807132647}"
MAIN_ID="${MAIN_ID:-1}"

eval "$(php -r '
require "src/Support/Env.php";
App\Support\Env::load(".env");
foreach (["DB_HOST","DB_PORT","DB_NAME","DB_USER","DB_PASS","APP_URL","AUTH_SECRET","APP_KEY"] as $k) {
  $v = (string) App\Support\Env::get($k, $k === "DB_HOST" ? "127.0.0.1" : ($k === "DB_PORT" ? "3306" : ($k === "APP_URL" ? "http://127.0.0.1:8081" : "")));
  echo $k . "=" . escapeshellarg($v) . "\n";
}
')"

API_BASE="${APP_URL%/}"
export MYSQL_PWD="$DB_PASS"

echo "== schema =="
MISSING=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -N -e "
SELECT GROUP_CONCAT(required.col ORDER BY required.col)
FROM (
  SELECT 'report_time' AS col UNION ALL SELECT 'incident_time' UNION ALL SELECT 'done_by'
) required
LEFT JOIN INFORMATION_SCHEMA.COLUMNS c
  ON c.TABLE_SCHEMA = DATABASE()
 AND c.TABLE_NAME = 'incident_reports'
 AND c.COLUMN_NAME = required.col
WHERE c.COLUMN_NAME IS NULL;
" || true)
if [[ -n "${MISSING}" && "${MISSING}" != "NULL" ]]; then
  echo "RED schema missing columns: ${MISSING}"
  SCHEMA_RED=1
else
  echo "GREEN schema has report_time, incident_time, done_by"
  SCHEMA_RED=0
fi

TOKEN=$(php -r '
require "src/Support/Env.php";
App\Support\Env::load(".env");
require "src/Security/TokenService.php";
$t = new App\Security\TokenService(
  (string) App\Support\Env::get("AUTH_SECRET", (string) App\Support\Env::get("APP_KEY", "change-me-in-env")),
  3600
);
echo $t->issue(["sub" => 1, "main_userid" => (int) ('"$MAIN_ID"'), "user_type" => "1"]);
')

echo "== LIST ${CONTACT} =="
LIST_RAW=$(curl -sS -w "\n__HTTP__:%{http_code}" \
  -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/json" \
  "${API_BASE}/api/v1/daily-call-monitoring/customers/${CONTACT}/incident-reports?main_id=${MAIN_ID}")
LIST_HTTP=$(printf '%s' "$LIST_RAW" | sed -n 's/^__HTTP__://p')
LIST_BODY=$(printf '%s' "$LIST_RAW" | sed '/^__HTTP__:/d')
LIST_ERR=$(printf '%s' "$LIST_BODY" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (string)($j["error"] ?? $j["message"] ?? "");')
echo "HTTP=${LIST_HTTP}"
if [[ "$LIST_HTTP" != "200" ]] || [[ "$LIST_ERR" == *"report_time"* ]] || [[ "$LIST_ERR" == *"Unknown column"* ]]; then
  echo "RED list failed: ${LIST_ERR:0:200}"
  LIST_RED=1
else
  echo "GREEN list ok"
  LIST_RED=0
fi

echo "== CREATE =="
RID=$(uuidgen | tr '[:upper:]' '[:lower:]')
CREATE_RAW=$(curl -sS -w "\n__HTTP__:%{http_code}" \
  -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{\"id\":\"${RID}\",\"main_id\":${MAIN_ID},\"contact_id\":\"${CONTACT}\",\"report_date\":\"2026-09-04\",\"report_time\":\"10:30\",\"incident_date\":\"2026-09-04\",\"incident_time\":\"09:15\",\"issue_type\":\"other\",\"description\":\"diagnose loop\",\"reported_by\":\"Debug\",\"done_by\":\"Debug\",\"attachments\":[],\"related_transactions\":[],\"notes\":\"\"}" \
  "${API_BASE}/api/v1/daily-call-monitoring/incident-reports")
CREATE_HTTP=$(printf '%s' "$CREATE_RAW" | sed -n 's/^__HTTP__://p')
CREATE_BODY=$(printf '%s' "$CREATE_RAW" | sed '/^__HTTP__:/d')
CREATE_ERR=$(printf '%s' "$CREATE_BODY" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (string)($j["error"] ?? $j["message"] ?? "");')
echo "HTTP=${CREATE_HTTP}"
if [[ "$CREATE_HTTP" != "200" ]] || [[ "$CREATE_ERR" == *"report_time"* ]] || [[ "$CREATE_ERR" == *"Unknown column"* ]]; then
  echo "RED create failed: ${CREATE_ERR:0:200}"
  CREATE_RED=1
else
  echo "GREEN create ok id=${RID}"
  CREATE_RED=0
  mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "DELETE FROM incident_reports WHERE id='${RID}'" >/dev/null 2>&1 || true
fi

if [[ "$SCHEMA_RED$LIST_RED$CREATE_RED" != "000" ]]; then
  echo "VERDICT: RED (incident report workflow broken)"
  echo "Fix: ./scripts/fix-incident-report-schema.sh"
  exit 1
fi
echo "VERDICT: GREEN"
exit 0
