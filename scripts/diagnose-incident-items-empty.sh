#!/usr/bin/env bash
# Feedback loop: Incident Items Report empty despite existing rows.
# Reproduces UTC dateTo cutoff vs local calendar dateTo.
# Usage: api/scripts/diagnose-incident-items-empty.sh
# Exit 0 = local window returns rows when data exists; Exit 1 = empty-page symptom.

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

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

ITEM_COUNT=$(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -N -e \
  "SELECT COUNT(*) FROM incident_report_items WHERE main_id=1;")
echo "== db incident_report_items main_id=1 count=${ITEM_COUNT} =="
if [[ "${ITEM_COUNT}" -lt 1 ]]; then
  echo "RED no incident item rows to display"
  exit 1
fi

TOKEN=$(php -r '
require "src/Support/Env.php";
App\Support\Env::load(".env");
require "src/Security/TokenService.php";
$t = new App\Security\TokenService(
  (string) App\Support\Env::get("AUTH_SECRET", (string) App\Support\Env::get("APP_KEY", "change-me-in-env")),
  3600
);
echo $t->issue(["sub" => 1, "main_userid" => 1, "user_type" => "1"]);
')

UTC_TODAY=$(php -r 'echo gmdate("Y-m-d");')
UTC_FROM=$(php -r '$d=new DateTimeImmutable("now", new DateTimeZone("UTC")); echo $d->modify("-30 days")->format("Y-m-d");')
LOCAL_TODAY=$(php -r 'echo (new DateTimeImmutable("now"))->format("Y-m-d");')
# Browser in Asia/Manila: local calendar, not PHP server TZ.
PH_TODAY=$(php -r 'echo (new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")))->format("Y-m-d");')
PH_FROM=$(php -r '$d=new DateTimeImmutable("now", new DateTimeZone("Asia/Manila")); echo $d->modify("-30 days")->format("Y-m-d");')

count_items() {
  local from="$1" to="$2"
  curl -sS -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/json" \
    "${API_BASE}/api/v1/incident-items-report?main_id=1&date_from=${from}&date_to=${to}&per_page=100" \
    | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo count($j["data"]["items"]??[]);'
}

UTC_ITEMS=$(count_items "$UTC_FROM" "$UTC_TODAY")
PH_ITEMS=$(count_items "$PH_FROM" "$PH_TODAY")
LOCAL_ITEMS=$(count_items "$PH_FROM" "$PH_TODAY")

echo "== UTC window ${UTC_FROM}..${UTC_TODAY} items=${UTC_ITEMS} =="
echo "== PH window ${PH_FROM}..${PH_TODAY} items=${PH_ITEMS} =="
echo "== server-local today=${LOCAL_TODAY} =="

# The page must show records when they exist under the PH business calendar.
if [[ "${PH_ITEMS}" -lt 1 ]]; then
  echo "VERDICT: RED (Incident Items Report empty for Asia/Manila date window)"
  exit 1
fi

echo "VERDICT: GREEN (page date window returns ${PH_ITEMS} row(s))"
# Soft signal: if UTC window is empty while PH is not, the old frontend bug would hide rows.
if [[ "${UTC_ITEMS}" -lt 1 && "${PH_ITEMS}" -gt 0 ]]; then
  echo "NOTE: UTC dateTo alone would empty the page; local dates are required."
fi
exit 0
