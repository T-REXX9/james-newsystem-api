<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$repository = file_get_contents($root . '/src/Repositories/DailyCallMonitoringRepository.php');
$controller = file_get_contents($root . '/src/Controllers/DailyCallMonitoringController.php');
$bootstrap = file_get_contents($root . '/src/bootstrap.php');
$postedSales = file_get_contents($root . '/src/Support/PostedSalesDocumentSql.php');
$staffRepository = file_get_contents($root . '/src/Repositories/StaffRepository.php');

$methodStart = strpos($repository, 'public function getDailyCallSalesColorBreakdown');
$methodEnd = strpos($repository, 'public function getPurchaseMasterList', $methodStart ?: 0);
$method = $methodStart === false || $methodEnd === false
    ? ''
    : substr($repository, $methodStart, $methodEnd - $methodStart);
$agentRosterQueryEnd = strpos($method, '$postedSalesCtes =', 0);
$agentRosterQuery = $agentRosterQueryEnd === false ? '' : substr($method, 0, $agentRosterQueryEnd);

$checks = [
    'read-only endpoint is registered behind bearer authentication with claims' => str_contains(
        $bootstrap,
        '$router->get(\'/api/v1/daily-call-monitoring/sales-color-breakdown\', $requireBearerAuthWithClaims([$dailyCallMonitoringController, \'salesColorBreakdown\']))'
    ),
    'controller requires an authenticated staff identity and derives company scope from authenticated claims' => str_contains($controller, 'public function salesColorBreakdown')
        && str_contains($controller, '$this->authenticatedMainId($body, $query)')
        && str_contains($controller, '$this->authenticatedViewerUserId($body)')
        && str_contains($controller, 'getDailyCallSalesColorBreakdown($mainId)'),
    'sales agent roster matches Staff Management active status and role name without assuming a global role ID' => str_contains($agentRosterQuery, 'WHERE a.lmother_id = :main_id')
        && str_contains($agentRosterQuery, 'JOIN tblusertype agent_type')
        && str_contains($agentRosterQuery, "REGEXP_REPLACE(COALESCE(agent_type.ltype_name, ''), '[[:space:]]+', ' ')")
        && str_contains($agentRosterQuery, "IN ('sales agent', 'sales person', 'salesperson')")
        && !str_contains($agentRosterQuery, "a.ltype = '2'")
        && str_contains($agentRosterQuery, 'AND a.lstatus = 1')
        && str_contains($agentRosterQuery, "NULLIF(TRIM(a.lmname), '')")
        && str_contains($staffRepository, "\$where = ['a.lmother_id = :main_id', 'a.lstatus = 1', 'a.ltype != 1'];"),
    'tenant-scoped roster preloads every eligible agent so zero-sales agents remain in the response' => str_contains($method, '$agents[$id] = $this->emptyDailyCallSalesColorAgent($id, (string) $agent[\'name\'])')
        && str_contains($method, '$agents = array_values($agents);'),
    'legacy customer salesperson names resolve only to a unique same-tenant sales agent' => str_contains($method, 'scoped_sales_agents AS')
        && str_contains($method, 'unique_sales_agent_names AS')
        && str_contains($method, 'HAVING COUNT(*) = 1')
        && str_contains($method, 'customer.assigned_agent_id')
        && !str_contains($method, 'customer.lsales_person')
        && str_contains($method, "REGEXP_REPLACE(COALESCE(agent_type.ltype_name, ''), '[[:space:]]+', ' ')")
        && str_contains($method, "IN ('sales agent', 'sales person', 'salesperson')")
        && !str_contains($method, "a.ltype = '2'")
        && str_contains($method, "REGEXP_REPLACE(COALESCE(account_type.ltype_name, ''), '[[:space:]]+', ' ')")
        && str_contains($method, "full_normalized_name")
        && str_contains($method, "legacy_normalized_name")
        && str_contains($method, 'sales_agent_name_aliases AS')
        && str_contains($method, 'full_normalized_name')
        && str_contains($method, 'AND a.lstatus = 1')
        && str_contains($method, 'AND account.lstatus = 1')
        && str_contains($method, 'assignment_main_id'),
    'sales aggregation reuses canonical posted invoice and delivery-receipt rules' => str_contains($method, 'PostedSalesDocumentSql::currentMonthCustomerSalesCtes()')
        && str_contains($postedSales, 'posted_sales_current_month_documents AS')
        && str_contains($postedSales, 'SUM(amount) AS current_month_sales'),
    'blank-customer posted documents remain included in the company total without changing customer CTE semantics' => str_contains($method, 'currentMonthBlankCustomerSalesCte()')
        && str_contains($method, "'unclassified' AS color")
        && str_contains($method, "'unclassified'"),
    'customer status gives red precedence over green and groups missing or invalid assignments as unassigned' => str_contains($method, "THEN 'red'")
        && str_contains($method, "THEN 'green'")
        && strpos($method, "THEN 'red'") < strpos($method, "THEN 'green'")
        && str_contains($method, 'CASE WHEN account.lid IS NULL THEN NULL'),
    'all color buckets and missing posted-sales customers remain visible in group totals' => str_contains($method, "'green', 'yellow', 'purple', 'white', 'red'")
        && str_contains($method, 'missing_sales_customers AS')
        && str_contains($method, 'COALESCE(SUM(sales), 0) AS sales'),
    'blank customer sales use the same posted document filters and tax rule' => str_contains($postedSales, 'public static function currentMonthBlankCustomerSalesCte()')
        && str_contains($postedSales, "invoiceIsPosted('l')")
        && str_contains($postedSales, "deliveryReceiptIsPosted('l')")
        && str_contains($postedSales, "AND COALESCE(l.lcustomerid, '') = ''")
        && str_contains($postedSales, 'CASE WHEN LOWER(COALESCE(l.ltax_type, \'\')) = \'exclusive\' THEN 1.12 ELSE 1 END'),
    'color age uses the same latest ledger and standalone transaction dates as the Master List, with posted-sale fallback' => str_contains($method, 'purchase_date_candidates AS')
        && str_contains($method, "LOWER(TRIM(COALESCE(lg.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')")
        && str_contains($method, "COALESCE(tr.invoice_refno, '') = ''")
        && str_contains($method, "COALESCE(tr.ldr_refno, '') = ''")
        && str_contains($method, 'COALESCE(history.last_purchase_date, sales.last_sale_date)'),
    'color age purchase history is bounded to the current and prior two calendar months' => substr_count(
        $method,
        "DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 2 MONTH)"
    ) === 2,
    'no-customer-id sales are counted in the explicit unclassified total' => str_contains($method, 'if ($color === \'unclassified\')')
        && str_contains($method, "'unclassified_sales' => 0.0"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Daily Call sales color breakdown contract: ' . count($checks) . '/' . count($checks) . " passed\n";
