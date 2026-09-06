<?php

declare(strict_types=1);

$customerRepository = file_get_contents(__DIR__ . '/../src/Repositories/CustomerRepository.php');
$customerDatabaseRepository = file_get_contents(__DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php');
$migration = file_get_contents(__DIR__ . '/../migrations/036_clear_epoch_dealer_since.sql');

$checks = [
    'customer database selects relationship start as since' => str_contains($customerDatabaseRepository, 'CAST(p.lsince AS CHAR)')
        && str_contains($customerDatabaseRepository, 'AS since'),
    'customer database treats epoch dealer-since as empty' => str_contains($customerDatabaseRepository, "CAST(p.ldealer_since AS CHAR), '1970-01-01'"),
    'customer database persists since onto lsince' => str_contains($customerDatabaseRepository, 'lsince = :since_date')
        && str_contains($customerDatabaseRepository, "'since' => ['column' => 'lsince'"),
    'session customer_since comes from lsince, not dealer-since' => str_contains($customerRepository, 'CAST(p.lsince AS CHAR)')
        && str_contains($customerRepository, 'AS customer_since')
        && !str_contains($customerRepository, 'COALESCE(p.ldealer_since, p.ldatereg'),
    'migration clears stored unix-epoch dealer-since' => is_string($migration)
        && str_contains($migration, "ldealer_since = '1970-01-01'")
        && str_contains($migration, 'ldealer_since = NULL'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Customer since contract: ' . count($checks) . '/' . count($checks) . " passed\n";
