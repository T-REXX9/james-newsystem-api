<?php

declare(strict_types=1);

/**
 * Staff list search must bind distinct named placeholders under native PDO prepares.
 *
 * Run: php api/tests/StaffListSearchTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\StaffRepository;

$db = new Database(app_config());
$repo = new StaffRepository($db);

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  [PASS] {$message}\n";
        return;
    }
    $failed++;
    echo "  [FAIL] {$message}\n";
};

echo "Staff list search\n";

try {
    $empty = $repo->listStaff(1, '', 1, 5);
    $assert(is_array($empty['items'] ?? null), 'empty search returns items array');
} catch (Throwable $e) {
    $assert(false, 'empty search should not throw: ' . $e->getMessage());
}

try {
    $filtered = $repo->listStaff(1, 'test', 1, 5);
    $assert(is_array($filtered['items'] ?? null), 'text search returns items array');
    $assert(isset($filtered['meta']['total']), 'text search returns total meta');
} catch (Throwable $e) {
    $assert(false, 'text search should not throw: ' . $e->getMessage());
}

echo sprintf("Staff list search: %d passed, %d failed\n", $passed, $failed);
exit($failed > 0 ? 1 : 0);
