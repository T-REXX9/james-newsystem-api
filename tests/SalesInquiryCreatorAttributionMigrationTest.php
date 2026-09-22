<?php

declare(strict_types=1);

$migration = file_get_contents(__DIR__ . '/../migrations/053_repair_sales_inquiry_creator_attribution.sql');

if ($migration === false) {
    throw new RuntimeException('FAIL: unable to read sales inquiry attribution repair migration');
}

$checks = [
    'repairs inquiry salesperson fields from the recorded creator' =>
        str_contains($migration, 'UPDATE tblinquiry AS inquiry')
        && str_contains($migration, 'creator.lid = CAST(inquiry.luser AS UNSIGNED)')
        && str_contains($migration, 'inquiry.lsales_person_id = CAST(creator.lid AS CHAR)')
        && str_contains($migration, 'inquiry.lsalesperson = TRIM(CONCAT'),
    'does not depend on the customer assignment' =>
        !str_contains($migration, 'tblpatient'),
    'repairs linked Sales Orders from their inquiry creator' =>
        str_contains($migration, 'UPDATE tbltransaction AS sales_order')
        && str_contains($migration, 'idx_transaction_inquiry_ref_main')
        && str_contains($migration, 'inquiry.lrefno = sales_order.linquiry_refno'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
