<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/SalesInquiryRepository.php');

$checks = [
    'sync source selects inquiry send_by' => str_contains($repository, 'COALESCE(lshipped, "") AS send_by'),
    'sync writes sales order send_by' => str_contains($repository, 'lshipped = :lshipped'),
    'sync reads the source send_by field' => str_contains($repository, "'lshipped' => (string) (\$inquiry['send_by'] ?? '')"),
    'sync writes inquiry address and customer reference' => str_contains($repository, 'lsales_address = :lsales_address')
        && str_contains($repository, 'lyour_refno = :lyour_refno'),
    'sync writes inquiry commercial fields' => str_contains($repository, 'lprice_group = :lprice_group')
        && str_contains($repository, 'lcredit_limit = :lcredit_limit')
        && str_contains($repository, 'lterms = :lterms')
        && str_contains($repository, 'lpo_no = :lpo_no')
        && str_contains($repository, 'lnote = :lnote'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Sales Inquiry → Sales Order sync contract: ' . count($checks) . '/' . count($checks) . " passed\n";
