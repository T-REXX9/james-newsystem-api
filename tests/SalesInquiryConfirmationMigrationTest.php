<?php

declare(strict_types=1);

$migration = file_get_contents(__DIR__ . '/../migrations/073_sales_inquiry_confirmation_and_print_approval.sql');
$controller = file_get_contents(__DIR__ . '/../src/Controllers/SalesInquiryPrintController.php');
$repository = file_get_contents(__DIR__ . '/../src/Repositories/SalesInquiryRepository.php');

foreach (['migration' => $migration, 'controller' => $controller, 'repository' => $repository] as $name => $contents) {
    if ($contents === false) {
        throw new RuntimeException("FAIL: unable to read Sales Inquiry {$name}");
    }
}

$checks = [
    'stores confirmation timestamps in a narrow sidecar table' =>
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS sales_inquiry_confirmations')
        && str_contains($migration, 'PRIMARY KEY (main_id, inquiry_refno)'),
    'does not add a column to the wide legacy inquiry table' =>
        !preg_match('/ALTER\s+TABLE\s+`?tblinquiry`?\s+ADD\s+COLUMN\s+`?lconfirmed_at`?/i', $migration),
    'copies existing confirmation timestamps when the legacy column exists' =>
        str_contains($migration, 'INSERT IGNORE INTO sales_inquiry_confirmations')
        && str_contains($migration, "COLUMN_NAME = 'lconfirmed_at'"),
    'confirmation writes and reads the sidecar timestamp' =>
        str_contains($controller, 'INSERT IGNORE INTO sales_inquiry_confirmations')
        && str_contains($controller, 'FROM sales_inquiry_confirmations'),
    'inquiry list and detail read confirmation timestamps from the sidecar' =>
        substr_count($repository, 'LEFT JOIN sales_inquiry_confirmations') === 2
        && !str_contains($repository, 'iq.lconfirmed_at'),
    'inquiry list checks linked transactions through separate indexed references' =>
        str_contains($repository, 't.linquiry_refno = iq.lrefno')
        && str_contains($repository, 't.lrefno = iq.lso_refno')
        && !str_contains($repository, 't.linquiry_refno = iq.lrefno OR t.lrefno = iq.lso_refno'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
