<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$source = file_get_contents(__DIR__ . '/../src/Repositories/StaffRepository.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read staff repository\n");
    exit(1);
}

$start = strpos($source, 'public function updateOwnSalesQuota');
$end = $start === false ? false : strpos($source, 'public function createStaff', $start);
$method = $start === false || $end === false ? '' : substr($source, $start, $end - $start);
$joinedUpdate = preg_match('/\$updateSql\s*=\s*"(UPDATE tblaccount a .*?)";/', $method, $matches)
    ? $matches[1]
    : '';

if ($joinedUpdate === '') {
    fwrite(STDERR, "FAIL joined sales-agent quota update query must exist\n");
    exit(1);
}
echo "  PASS joined sales-agent quota update query exists\n";

if (preg_match('/\bLIMIT\b/i', $joinedUpdate)) {
    fwrite(STDERR, "FAIL joined sales-agent quota update must not use LIMIT\n");
    exit(1);
}
echo "  PASS joined sales-agent quota update does not use LIMIT\n";

if (!str_contains($joinedUpdate, 'a.lid = :user_id')) {
    fwrite(STDERR, "FAIL joined quota update must target the authenticated account id\n");
    exit(1);
}
echo "  PASS joined quota update is scoped to the authenticated account\n";

// Run the actual joined UPDATE against temporary copies of the live schema.
$db = new \App\Database(app_config());
$pdo = $db->pdo();
$databaseName = str_replace('`', '``', app_config()->dbName);
$pdo->exec("CREATE TEMPORARY TABLE tblaccount SELECT * FROM `{$databaseName}`.tblaccount WHERE 1 = 0");
$pdo->exec("CREATE TEMPORARY TABLE tblusertype SELECT * FROM `{$databaseName}`.tblusertype WHERE 1 = 0");

$insertTemporaryRow = static function (string $table, array $values) use ($pdo): void {
    foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $name = $column['Field'];
        if (
            array_key_exists($name, $values)
            || $column['Null'] === 'YES'
            || $column['Default'] !== null
            || str_contains($column['Extra'], 'auto_increment')
        ) {
            continue;
        }
        $values[$name] = preg_match('/int|decimal|float|double|bit/', strtolower($column['Type'])) ? 0 : '';
    }
    $columns = array_keys($values);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($values));
};

$mainId = 987650001;
$agentId = 987650002;
$roleId = 987650003;
$insertTemporaryRow('tblusertype', [
    'lid' => $roleId,
    'lmain_id' => $mainId,
    'ltype_name' => 'Sales Agent',
]);
$insertTemporaryRow('tblaccount', [
    'lid' => $agentId,
    'lmother_id' => $mainId,
    'ltype' => $roleId,
    'lstatus' => 1,
    'larchieve' => 0,
    'lsales_quota' => 100,
]);

$repo = new \App\Repositories\StaffRepository($db);
$savedQuota = $repo->updateOwnSalesQuota($mainId, $agentId, '2', '25000.50');
if ($savedQuota !== 25000.5) {
    fwrite(STDERR, 'FAIL joined quota update should persist and return 25000.50; got ' . var_export($savedQuota, true) . "\n");
    exit(1);
}
echo "  PASS MySQL joined quota update persists and returns the saved amount\n";

$assertQuota = static function (?float $expected, ?float $actual, string $scenario): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$scenario}; expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "  PASS {$scenario}\n";
};

$assertQuota(0.0, $repo->updateOwnSalesQuota($mainId, $agentId, '2', '0'), 'sales agent can set quota to zero');
$assertQuota(9999999999999.99, $repo->updateOwnSalesQuota($mainId, $agentId, '2', '9999999999999.99'), 'sales agent can set maximum supported quota');

$nonSalesRoleId = 987650004;
$inactiveAgentId = 987650005;
$archivedAgentId = 987650006;
$otherTenantAgentId = 987650007;
$insertTemporaryRow('tblusertype', [
    'lid' => $nonSalesRoleId,
    'lmain_id' => $mainId,
    'ltype_name' => 'Accountant',
]);
$insertTemporaryRow('tblaccount', [
    'lid' => $nonSalesRoleId + 100,
    'lmother_id' => $mainId,
    'ltype' => $nonSalesRoleId,
    'lstatus' => 1,
    'larchieve' => 0,
    'lsales_quota' => 100,
]);
foreach ([
    [$inactiveAgentId, 0, 0, $roleId, $mainId],
    [$archivedAgentId, 1, 1, $roleId, $mainId],
    [$otherTenantAgentId, 1, 0, $roleId, $mainId + 1],
] as [$id, $status, $archived, $role, $tenantId]) {
    $insertTemporaryRow('tblaccount', [
        'lid' => $id,
        'lmother_id' => $tenantId,
        'ltype' => $role,
        'lstatus' => $status,
        'larchieve' => $archived,
        'lsales_quota' => 100,
    ]);
}
$assertQuota(null, $repo->updateOwnSalesQuota($mainId, $nonSalesRoleId + 100, '2', '500'), 'non-sales account cannot update quota');
$assertQuota(null, $repo->updateOwnSalesQuota($mainId, $inactiveAgentId, '2', '500'), 'inactive sales agent cannot update quota');
$assertQuota(null, $repo->updateOwnSalesQuota($mainId, $archivedAgentId, '2', '500'), 'archived sales agent cannot update quota');
$assertQuota(null, $repo->updateOwnSalesQuota($mainId, $otherTenantAgentId, '2', '500'), 'sales agent from another tenant cannot update quota');

$ownerId = 987650008;
$insertTemporaryRow('tblaccount', [
    'lid' => $ownerId,
    'lmother_id' => $ownerId,
    'ltype' => 1,
    'lstatus' => 1,
    'lsales_quota' => 0,
]);
$assertQuota(1234.56, $repo->updateOwnSalesQuota($ownerId, $ownerId, '1', '1234.56'), 'master user can update own quota');
$assertQuota(null, $repo->updateOwnSalesQuota($ownerId, $ownerId + 1, '1', '1234.56'), 'master user cannot update another account quota');

$controller = new \App\Controllers\StaffController($repo);
$claims = ['sub' => $agentId, 'main_userid' => $mainId, 'user_type' => '2'];
$response = $controller->updateOwnSalesQuota([], [], [
    '__auth_claims' => $claims,
    'sales_quota' => '321.45',
]);
$assertQuota(321.45, (float) ($response['monthly_quota'] ?? NAN), 'quota endpoint returns saved monthly quota');

foreach ([
    [[], 'A valid sales_quota is required'],
    [['sales_quota' => 'not-a-number'], 'A valid sales_quota is required'],
    [['sales_quota' => -1], 'sales_quota must be between 0 and 9999999999999.99'],
    [['sales_quota' => 10000000000000], 'sales_quota must be between 0 and 9999999999999.99'],
] as [$input, $expectedMessage]) {
    try {
        $controller->updateOwnSalesQuota([], [], ['__auth_claims' => $claims, ...$input]);
        fwrite(STDERR, "FAIL invalid quota input should be rejected: {$expectedMessage}\n");
        exit(1);
    } catch (\App\Support\Exceptions\HttpException $exception) {
        if ($exception->getMessage() !== $expectedMessage) {
            fwrite(STDERR, 'FAIL invalid quota input returned unexpected error: ' . $exception->getMessage() . "\n");
            exit(1);
        }
    }
}
echo "  PASS missing, nonnumeric, negative, and over-limit values are rejected\n";

try {
    $controller->updateOwnSalesQuota([], [], [
        '__auth_claims' => ['sub' => $agentId, 'user_type' => '2'],
        'sales_quota' => 1,
    ]);
    fwrite(STDERR, "FAIL controller rejects agent account without required main user claim\n");
    exit(1);
} catch (\App\Support\Exceptions\HttpException $exception) {
    if ($exception->statusCode() !== 403) {
        fwrite(STDERR, 'FAIL missing account scope should return 403; got ' . $exception->statusCode() . "\n");
        exit(1);
    }
}
echo "  PASS missing authenticated account scope is rejected\n";
