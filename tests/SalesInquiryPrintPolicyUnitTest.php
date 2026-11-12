<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/SalesInquiryPrintPolicy.php';

use App\Support\SalesInquiryPrintPolicy;

$checks = [
    'staff can print before using the one-print quota' => SalesInquiryPrintPolicy::canPrint(false, 0, false),
    'staff cannot reprint without an approved request' => !SalesInquiryPrintPolicy::canPrint(false, 1, false),
    'approved request permits the additional print' => SalesInquiryPrintPolicy::canPrint(false, 1, true),
    'Master User can print regardless of the count' => SalesInquiryPrintPolicy::canPrint(true, 99, false),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}