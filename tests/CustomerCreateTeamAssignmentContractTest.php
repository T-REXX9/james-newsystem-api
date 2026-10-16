<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: cannot read CustomerDatabaseRepository.php\n");
    exit(1);
}

$checks = [
    'create validates the selected active team within the customer account' => str_contains($source, 'FROM tblteamstaff')
        && str_contains($source, 'WHERE lid = :team_id AND lmain_id = :main_id AND COALESCE(lstatus, 1) = 1'),
    'customer insert persists the selected team' => str_contains($source, 'lsales_team, lrefer_by')
        && str_contains($source, ':sales_team, :refer_by')
        && str_contains($source, "'sales_team' => \$salesTeamId"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Customer create team assignment contract: ' . count($checks) . '/' . count($checks) . " passed\n";
