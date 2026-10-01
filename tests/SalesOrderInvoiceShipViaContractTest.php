<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/SalesOrderRepository.php');
if ($repository === false) {
    fwrite(STDERR, "Unable to read SalesOrderRepository.php\n");
    exit(1);
}

$checks = [
    'sales order invoice conversion forwards Ship Via to invoice creation' => str_contains(
        substr($repository, strpos($repository, 'private function convertToInvoice(') ?: 0),
        "'send_by' => (string) (\$order['send_by'] ?? ''),"
    ),
];

$failed = [];
foreach ($checks as $description => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . ": {$description}\n";
    if (!$passed) {
        $failed[] = $description;
    }
}

if ($failed !== []) {
    exit(1);
}

echo "Sales Order invoice Ship Via contract: 1/1 passed\n";
