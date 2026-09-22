<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($repository, 'sales_report_activity AS ('), 'master list builds a unified sales-report activity source');
$assert(str_contains($repository, 'FROM call_report_threads t'), 'master list includes report bodies');
$assert(str_contains($repository, 'FROM call_report_messages m'), 'master list includes conversation replies');
$assert(str_contains($repository, 'latest_sales_report AS ('), 'master list selects the latest sales-report activity');
$assert(str_contains($repository, 'latest_sales_report_message'), 'master list exposes the latest sales-report message');
$assert(!str_contains($repository, "COALESCE(p.lnotes, '') AS prospect_comment"), 'master list no longer exposes prospect notes as staff comments');

echo "Daily call master-list sales-report message contract passed.\n";
