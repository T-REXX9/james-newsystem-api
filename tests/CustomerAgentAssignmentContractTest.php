<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$customerDatabase = file_get_contents($root . '/src/Repositories/CustomerDatabaseRepository.php');
$dailyCall = file_get_contents($root . '/src/Repositories/DailyCallMonitoringRepository.php');
$repairMigration = file_get_contents($root . '/migrations/047_repair_customer_sales_agent_ids.sql');

$checks = [
    'customer assignments validate and store staff account IDs' =>
        str_contains($customerDatabase, 'normalizeSalesPersonIdForAssignment')
        && str_contains($customerDatabase, 'Sales agent assignment must use a valid staff account ID'),
    'bulk reassignment rejects a partial customer selection' =>
        str_contains($customerDatabase, 'Not all selected customers belong to this account'),
    'agent snapshot customer query fetches the complete assigned result' =>
        str_contains($dailyCall, 'ORDER BY p.lcompany ASC')
        && !str_contains($dailyCall, "\$sql .= ' LIMIT")
        && str_contains($dailyCall, '$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);'),
    'agent snapshot uses the same category source as the master user list' =>
        str_contains($dailyCall, "\$masterList = \$this->getPurchaseMasterList(\$mainId, '2025-10-01', '', \$viewerUserId);")
        && str_contains($dailyCall, "'master_list' => \$masterList['items'] ?? []"),
    'legacy name assignments are repaired only when the account name is unique' =>
        is_string($repairMigration)
        && str_contains($repairMigration, 'HAVING COUNT(*) = 1')
        && str_contains($repairMigration, "NOT REGEXP '^[0-9]+$'"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
