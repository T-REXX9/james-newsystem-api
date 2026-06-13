<?php

declare(strict_types=1);

/**
 * Daily Call Monitoring date formatter regression test.
 *
 * Run: php api/tests/DailyCallMonitoringDateFormatterTest.php
 */

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repositories/DailyCallMonitoringRepository.php';

use App\Repositories\DailyCallMonitoringRepository;

$passed = 0;
$failed = 0;
$errors = [];

function assert_eq(mixed $expected, mixed $actual, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($expected === $actual) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }

    $failed++;
    $errors[] = "{$message} expected=" . json_encode($expected) . ' actual=' . json_encode($actual);
    echo "  FAIL {$message}\n";
}

$repo = (new ReflectionClass(DailyCallMonitoringRepository::class))->newInstanceWithoutConstructor();
$formatDateText = new ReflectionMethod(DailyCallMonitoringRepository::class, 'formatDateText');

echo "==========================================================\n";
echo " Daily Call Monitoring Date Formatter Test\n";
echo "==========================================================\n\n";

assert_eq('—', $formatDateText->invoke($repo, '1970-01-01'), 'Suppresses legacy Unix epoch sentinel date', $passed, $failed, $errors);
assert_eq('—', $formatDateText->invoke($repo, '0000-00-00'), 'Suppresses MySQL zero date sentinel', $passed, $failed, $errors);
assert_eq('Apr 21, 2026', $formatDateText->invoke($repo, '2026-04-21'), 'Formats a valid dealer date', $passed, $failed, $errors);

echo "\n==========================================================\n";
echo " Results: {$passed} passed, {$failed} failed\n";
echo "==========================================================\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

exit(0);
