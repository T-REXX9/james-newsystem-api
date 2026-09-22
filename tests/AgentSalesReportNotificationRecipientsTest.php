<?php

declare(strict_types=1);

$repository = file_get_contents(__DIR__ . '/../src/Repositories/CallReportRepository.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(substr_count($repository, "'targetUserIds' => \$this->resolveMasterUserIds(\$mainId)") === 2, 'agent reports and follow-up messages notify all Master User accounts');
$assert(str_contains($repository, 'private function resolveMasterUserIds(int $mainId): array'), 'Master User recipient resolution is centralized');
$assert(str_contains($repository, 'AND (lid = :main_id OR lmother_id = :main_id_2)'), 'Master User recipients are scoped to the company');
$assert(str_contains($repository, 'AND COALESCE(lstatus, 0) = 1'), 'only active Master User accounts are notified');
$assert(!str_contains($repository, 'NULLIF(TRIM(lname)'), 'notification customer lookup does not use the missing tblpatient.lname column');

echo "Agent Sales Report notification recipient contract passed.\n";
