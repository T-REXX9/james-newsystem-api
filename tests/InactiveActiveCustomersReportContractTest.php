<?php

declare(strict_types=1);

$controller = file_get_contents(__DIR__ . '/../src/Controllers/InactiveActiveCustomersReportController.php');
$repository = file_get_contents(__DIR__ . '/../src/Repositories/InactiveActiveCustomersReportRepository.php');

$checks = [
    'year range is optional and bounded' => str_contains($controller, 'isset($query[\'year_from\'])')
        && str_contains($controller, 'isset($query[\'year_to\'])')
        && str_contains($controller, '$year < 2000 || $year > $currentYear'),
    'reversed ranges are rejected' => str_contains($controller, '$yearFrom > $yearTo')
        && str_contains($controller, 'year_from must be less than or equal to year_to'),
    'year filters use an inclusive year start and exclusive following-year start' => str_contains($repository, 'sprintf(\'%04d-01-01\', $yearFrom)')
        && str_contains($repository, 'sprintf(\'%04d-01-01\', $yearTo + 1)')
        && str_contains($repository, '>= :year_from_date')
        && str_contains($repository, '< :year_to_date'),
    'no-purchase customers remain in the report and count as inactive' => str_contains($repository, 'OR ledger_purchase.last_purchase IS NULL'),
    'three-month cutoff has no overlap at the boundary' => str_contains($repository, 'THEN 1 ELSE 0 END) AS active_count')
        && str_contains($repository, 'THEN 1 ELSE 0 END) AS inactive_count')
        && str_contains($repository, 'ledger_purchase.last_purchase >= :cutoff_date_active')
        && str_contains($repository, 'ledger_purchase.last_purchase < :cutoff_date_inactive'),
    'classification and last-purchase date use only the same customer ledger purchases' => str_contains($repository, 'SELECT l.lmainid, l.lcustomerid, MAX(DATE(l.ldatetime)) AS last_purchase')
        && str_contains($repository, 'WHERE l.lmainid = :ledger_main_id')
        && str_contains($repository, 'ON ledger_purchase.lmainid = p.lmain_id')
        && str_contains($repository, "ledger_purchase.lcustomerid = p.lsessionid")
        && str_contains($repository, 'l.ldatetime < :ledger_date_exclusive_end')
        && str_contains($repository, "LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')")
        && !str_contains($repository, 'tbltransaction tr')
        && !str_contains($repository, 'tblinvoice_list inv')
        && !str_contains($repository, 'p.llast_transaction'),
    'deleted and unidentified customer records are excluded from report rows and totals' => str_contains($repository, '(COALESCE(p.ldeleted, 0) = 0)')
        && str_contains($repository, 'TRIM(COALESCE(p.lsessionid,')
        && str_contains($repository, '<>'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Inactive/Active Customers report contract: ' . count($checks) . '/' . count($checks) . " passed\n";
