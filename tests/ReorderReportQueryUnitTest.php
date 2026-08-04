<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Repositories/ReorderReportRepository.php');
if ($source === false) {
    fwrite(STDERR, "FAIL unable to read reorder report repository\n");
    exit(1);
}

$expectedGroup = 'GROUP BY pi.litem_code, pi.lrefno, po.lpurchaseno, po.ltransaction_status, po.ldate';
if (!str_contains($source, $expectedGroup)) {
    fwrite(STDERR, "FAIL receiving status must be grouped in the reorder report query\n");
    exit(1);
}

echo "  PASS receiving status is included in the grouped reorder report query\n";
echo "Results: 1 passed, 0 failed\n";
