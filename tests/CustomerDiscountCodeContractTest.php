<?php

declare(strict_types=1);

$customerRepository = file_get_contents(__DIR__ . '/../src/Repositories/CustomerRepository.php');
$customerDatabaseRepository = file_get_contents(__DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php');
$migration = file_get_contents(__DIR__ . '/../migrations/035_add_customer_discount_code.sql');

$checks = [
    'customer database selects discount code' => str_contains($customerDatabaseRepository, 'AS discount_code'),
    'customer database inserts discount code when the column exists' => str_contains($customerDatabaseRepository, '$discountCodeInsertColumn') && str_contains($customerDatabaseRepository, '$insertParams[\'discount_code\']'),
    'customer database updates discount code' => str_contains($customerDatabaseRepository, 'ldiscount_code = :discount_code'),
    'customer database bulk updates discount code when the column exists' => str_contains($customerDatabaseRepository, '$fieldMap[\'discount_code\']') && str_contains($customerDatabaseRepository, "'column' => 'ldiscount_code'"),
    'customer repository exposes persisted discount code' => str_contains($customerRepository, 'p.ldiscount_code AS discount_code'),
    'customer repository records qualified discount code from benefit-month sales' => str_contains($customerRepository, 'function recordQualifiedDiscountCode') && str_contains($customerRepository, 'SET ldiscount_code = :discount_code') && str_contains($customerRepository, '$lastMonthSales'),
    'customer repository normalizes vip silver' => str_contains($customerRepository, "'vip silver'"),
    'migration adds nullable discount code column' => str_contains($migration, 'ADD COLUMN ldiscount_code'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Customer discount code contract: ' . count($checks) . '/' . count($checks) . " passed\n";
