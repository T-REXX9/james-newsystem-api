<?php

declare(strict_types=1);

/**
 * Call Records page reads phone logs joined to Application report Concern/Action.
 *
 * Run: php api/tests/CallRecordsDatabaseTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CallSystemRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$repo = new CallSystemRepository($db);
$passed = 0;
$failed = 0;

$assert = static function (bool $ok, string $label) use (&$passed, &$failed): void {
    if ($ok) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

$threw = null;
$records = [];
try {
    $records = $repo->listCallRecords(1, 1, true, [
        'month' => 9,
        'year' => 2026,
    ]);
} catch (Throwable $error) {
    $threw = $error;
}

$assert($threw === null, 'listCallRecords does not throw for September 2026');
if ($threw !== null) {
    echo '  ' . $threw->getMessage() . "\n";
}
$assert(is_array($records), 'listCallRecords returns a list');

foreach (array_slice($records, 0, 5) as $index => $row) {
    $assert(array_key_exists('concern', $row), "row {$index} exposes concern");
    $assert(array_key_exists('action', $row), "row {$index} exposes action");
}

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
