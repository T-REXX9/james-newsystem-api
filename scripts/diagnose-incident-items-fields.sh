#!/usr/bin/env bash
# Feedback loop: Incident Items Report / warehouse detail must surface DB values for
# customer_name, product_id, and related_transactions (related documents).
# Usage: api/scripts/diagnose-incident-items-fields.sh
# Exit 0 = API matches seeded DB; Exit 1 = field mismatch / missing.

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

SEED_ID="DIAG-IIF-$(php -r 'echo bin2hex(random_bytes(4));')"
CONTACT_ID="DIAG-CONTACT-$(php -r 'echo bin2hex(random_bytes(3));')"
PATIENT_LID="$(php -r 'echo random_int(900000000, 999999999);')"
CUSTOMER_NAME="Diag Customer Corp"
PRODUCT_ID="diag-product-42"
ITEM_CODE="DIAG-ITEM-42"
PART_NO="DIAG-PN-42"
SUPPLIER_ID="diag-supplier-1"
SUPPLIER_NAME="Diag Supplier"
RELATED_JSON='[{"transaction_type":"invoice","transaction_id":"inv-diag-1","transaction_number":"INV-DIAG-1001","transaction_date":"2026-09-01"}]'

cleanup() {
  mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "
    DELETE FROM incident_report_items WHERE incident_report_id='${SEED_ID}';
    DELETE FROM incident_reports WHERE id='${SEED_ID}';
    DELETE FROM tblpatient WHERE lsessionid='${CONTACT_ID}';
  " >/dev/null 2>&1 || true
}
trap cleanup EXIT

cleanup

mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" "$DB_NAME" -e "
INSERT INTO tblpatient (lid, lmain_id, lsessionid, lcompany, lstatus, ldeleted)
VALUES (${PATIENT_LID}, 1, '${CONTACT_ID}', '${CUSTOMER_NAME}', 1, 0);
INSERT INTO incident_reports (
  id, main_id, contact_id, report_date, report_time, incident_date, incident_time,
  issue_type, description, reported_by, done_by, approval_status, related_transactions
) VALUES (
  '${SEED_ID}', 1, '${CONTACT_ID}', CURDATE(), CURTIME(), CURDATE(), CURTIME(),
  'product_quality', 'Diag leak for field loop', 'Diag', 'Diag', 'pending',
  CAST('${RELATED_JSON}' AS JSON)
);
INSERT INTO incident_report_items (
  main_id, incident_report_id, contact_id, product_id, item_code, part_no,
  description, supplier_id, supplier_name, quantity, issue_summary, match_source, confidence_score, metadata
) VALUES (
  1, '${SEED_ID}', '${CONTACT_ID}', '${PRODUCT_ID}', '${ITEM_CODE}', '${PART_NO}',
  'Diag Part Description', '${SUPPLIER_ID}', '${SUPPLIER_NAME}', 1,
  'Diag leak for field loop', 'manual', 1, JSON_OBJECT('seed','diagnose-incident-items-fields')
);
" >/dev/null

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

FAILED=0
fail() { echo "  [FAIL] $1"; FAILED=$((FAILED + 1)); }
pass() { echo "  [PASS] $1"; }

echo "== seed id=${SEED_ID} contact=${CONTACT_ID} =="
echo "== 1. Grouped report row (customer_name + product_id) =="

REPORT_JSON=$(curl -sS -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/json" \
  "${API_BASE}/api/v1/incident-items-report?main_id=1&page=1&per_page=50&search=${ITEM_CODE}")

export EXPECTED_CUSTOMER="$CUSTOMER_NAME" EXPECTED_PRODUCT="$PRODUCT_ID" SEED_ID
echo "$REPORT_JSON" | php -r '
$j = json_decode(stream_get_contents(STDIN), true);
$expectedCustomer = getenv("EXPECTED_CUSTOMER");
$expectedProduct = getenv("EXPECTED_PRODUCT");
$seedId = getenv("SEED_ID");
$items = $j["data"]["items"] ?? [];
$row = null;
foreach ($items as $item) {
  foreach (($item["recent_incidents"] ?? []) as $inc) {
    if (($inc["incident_report_id"] ?? "") === $seedId) { $row = $item; break 2; }
  }
  if (($item["product_id"] ?? "") === $expectedProduct) { $row = $item; break; }
}
if ($row === null) {
  fwrite(STDERR, "NO_ROW http_ok=" . json_encode($j["ok"] ?? null) . " item_count=" . count($items) . "\n");
  exit(2);
}
$productOk = (($row["product_id"] ?? "") === $expectedProduct);
$customer = "";
foreach (($row["recent_incidents"] ?? []) as $inc) {
  if (($inc["incident_report_id"] ?? "") === $seedId) {
    $customer = (string) ($inc["customer_name"] ?? "");
    break;
  }
}
if ($customer === "" && !empty($row["recent_incidents"][0]["customer_name"])) {
  $customer = (string) $row["recent_incidents"][0]["customer_name"];
}
$customerOk = ($customer === $expectedCustomer);
echo json_encode([
  "product_id" => $row["product_id"] ?? null,
  "customer_name" => $customer,
  "product_ok" => $productOk,
  "customer_ok" => $customerOk,
], JSON_UNESCAPED_SLASHES) . "\n";
exit(($productOk && $customerOk) ? 0 : 1);
'
REPORT_RC=$?
if [[ "$REPORT_RC" -eq 2 ]]; then
  fail "Grouped report did not return seeded row for ${ITEM_CODE}"
elif [[ "$REPORT_RC" -ne 0 ]]; then
  fail "Grouped report product_id/customer_name mismatch vs DB"
else
  pass "Grouped report product_id and recent_incidents.customer_name match DB"
fi

echo "== 2. Item incident list customer_name =="
LIST_JSON=$(curl -sS -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/json" \
  --get "${API_BASE}/api/v1/incident-items-report/incidents" \
  --data-urlencode "main_id=1" \
  --data-urlencode "supplier_id=${SUPPLIER_ID}" \
  --data-urlencode "supplier_name=${SUPPLIER_NAME}" \
  --data-urlencode "product_id=${PRODUCT_ID}" \
  --data-urlencode "item_code=${ITEM_CODE}" \
  --data-urlencode "part_no=${PART_NO}" \
  --data-urlencode "description=Diag Part Description")

echo "$LIST_JSON" | php -r '
$j = json_decode(stream_get_contents(STDIN), true);
$seedId = getenv("SEED_ID");
$expected = getenv("EXPECTED_CUSTOMER");
$hit = null;
foreach (($j["data"]["incidents"] ?? []) as $inc) {
  if (($inc["incident_report_id"] ?? "") === $seedId) { $hit = $inc; break; }
}
if ($hit === null) {
  fwrite(STDERR, "NO_INCIDENT count=" . count($j["data"]["incidents"] ?? []) . "\n");
  exit(2);
}
$name = (string) ($hit["customer_name"] ?? "");
echo json_encode(["customer_name" => $name, "ok" => $name === $expected], JSON_UNESCAPED_SLASHES) . "\n";
exit($name === $expected ? 0 : 1);
'
LIST_RC=$?
if [[ "$LIST_RC" -eq 2 ]]; then
  fail "Item incident list missing seeded incident"
elif [[ "$LIST_RC" -ne 0 ]]; then
  fail "Item incident list customer_name mismatch vs DB"
else
  pass "Item incident list customer_name matches DB"
fi

echo "== 3. Warehouse detail: customer_name, product_id, related_transactions =="
DETAIL_URL="${API_BASE}/api/v1/incident-items-report/incidents/$(php -r 'echo rawurlencode(getenv("SEED_ID"));')?main_id=1"
DETAIL_JSON=$(curl -sS -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/json" "$DETAIL_URL")

export EXPECTED_TXN="INV-DIAG-1001"
echo "$DETAIL_JSON" | php -r '
$j = json_decode(stream_get_contents(STDIN), true);
$d = $j["data"] ?? [];
$customerOk = (($d["customer_name"] ?? "") === getenv("EXPECTED_CUSTOMER"));
$productOk = (($d["product_id"] ?? "") === getenv("EXPECTED_PRODUCT"));
$txns = $d["related_transactions"] ?? null;
$relatedOk = is_array($txns) && count($txns) === 1
  && (($txns[0]["transaction_number"] ?? "") === getenv("EXPECTED_TXN"))
  && (($txns[0]["transaction_type"] ?? "") === "invoice");
echo json_encode([
  "customer_name" => $d["customer_name"] ?? null,
  "product_id" => $d["product_id"] ?? null,
  "related_transactions" => $txns,
  "customer_ok" => $customerOk,
  "product_ok" => $productOk,
  "related_ok" => $relatedOk,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
exit(($customerOk && $productOk && $relatedOk) ? 0 : 1);
'
DETAIL_RC=$?
if [[ "$DETAIL_RC" -ne 0 ]]; then
  fail "Detail customer_name / product_id / related_transactions mismatch vs DB"
else
  pass "Detail customer_name, product_id, related_transactions match DB"
fi

echo
if [[ "$FAILED" -gt 0 ]]; then
  echo "VERDICT: RED (${FAILED} assertion(s) failed — page/API does not correctly read these DB fields)"
  exit 1
fi
echo "VERDICT: GREEN (API returns customer_name, product_id, related_transactions from DB)"
exit 0
