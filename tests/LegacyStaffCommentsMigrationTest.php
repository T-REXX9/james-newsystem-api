<?php

declare(strict_types=1);

$migration = file_get_contents(__DIR__ . '/../migrations/054_migrate_legacy_staff_comments_to_agent_sales_reports.sql');
$repository = file_get_contents(__DIR__ . '/../src/Repositories/CallReportRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(str_contains($migration, 'INSERT INTO call_report_threads'), 'migration creates direct conversation threads');
$assert(str_contains($migration, 'INSERT INTO call_report_messages'), 'migration creates unified conversation messages');
$assert(str_contains($migration, "'Old System'"), 'migration identifies the legacy sender as Old System');
$assert(str_contains($migration, "CONCAT('legacy-staff-comment:', p.lid)"), 'migration has a stable legacy-comment reference');
$assert(substr_count($migration, 'NOT EXISTS') === 2, 'migration is safe to re-run without duplicate threads or messages');
$assert(!str_contains($repository, "p.lnotes AS report_body"), 'runtime no longer injects unmigrated prospect notes into conversations');

echo "Legacy staff-comment migration contract passed.\n";
