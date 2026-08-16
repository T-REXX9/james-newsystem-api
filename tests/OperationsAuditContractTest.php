<?php

declare(strict_types=1);

$activity = file_get_contents(__DIR__ . '/../src/Repositories/ActivityLogRepository.php');
$collection = file_get_contents(__DIR__ . '/../src/Repositories/CollectionRepository.php');
$salesReturn = file_get_contents(__DIR__ . '/../src/Repositories/SalesReturnRepository.php');

$checks = [
    'activity type filter is applied server-side' => str_contains($activity, 'log.laction = :action'),
    'collection create is consistently named' => str_contains($collection, "'Create Collection Entry'"),
    'collection posting is consistently named' => str_contains($collection, "'Post Collection Entry'"),
    'sales return create is consistently named' => str_contains($salesReturn, "'Create Sales Return Entry'"),
    'sales return posting is consistently named' => str_contains($salesReturn, "'Post Sales Return Entry'"),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Operations audit contract: ' . count($checks) . "/" . count($checks) . " passed\n";
