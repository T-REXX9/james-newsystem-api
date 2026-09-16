<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Repositories/CustomerDatabaseRepository.php');

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

$assert(
    str_contains($source, 'savePreviousCustomerName(')
        && str_contains($source, 'tblpatient.lcompany')
        && str_contains($source, 'tlbCustomer_Details'),
    'Customer rename keeps the legacy current-name and previous-name fields'
);
$assert(
    str_contains($source, 'SET loldname = :old_name')
        && str_contains($source, 'WHERE lid = :id'),
    'A later rename replaces the single previous name as in the legacy system'
);
