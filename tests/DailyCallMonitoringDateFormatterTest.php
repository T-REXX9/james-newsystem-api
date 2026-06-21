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
$buildDailyActivity = new ReflectionMethod(DailyCallMonitoringRepository::class, 'buildDailyActivity');

echo "==========================================================\n";
echo " Daily Call Monitoring Date Formatter Test\n";
echo "==========================================================\n\n";

assert_eq('—', $formatDateText->invoke($repo, '1970-01-01'), 'Suppresses legacy Unix epoch sentinel date', $passed, $failed, $errors);
assert_eq('—', $formatDateText->invoke($repo, '0000-00-00'), 'Suppresses MySQL zero date sentinel', $passed, $failed, $errors);
assert_eq('Apr 21, 2026', $formatDateText->invoke($repo, '2026-04-21'), 'Formats a valid dealer date', $passed, $failed, $errors);

$reportActivities = $buildDailyActivity->invoke($repo, [
    [
        'id' => 'report-2',
        'contact_id' => 'customer-1',
        'occurred_at' => '2026-06-21 11:00:00',
        'channel' => 'call',
        'notes' => '[Sales Agent Report] Follow-up quotation requested.',
        'agent_name' => 'Jane Doe',
    ],
    [
        'id' => 'report-1',
        'contact_id' => 'customer-1',
        'occurred_at' => '2026-06-21 09:00:00',
        'channel' => 'call',
        'notes' => '[Sales Agent Report] Customer asked about stock.',
        'agent_name' => 'John Smith',
    ],
]);

assert_eq(2, count($reportActivities), 'Keeps separate sales-agent reports submitted on the same day', $passed, $failed, $errors);
assert_eq('[Sales Agent Report] Follow-up quotation requested.', $reportActivities[0]['notes'] ?? null, 'Preserves report notes', $passed, $failed, $errors);
assert_eq('Jane Doe', $reportActivities[0]['agent_name'] ?? null, 'Preserves the reporting account name', $passed, $failed, $errors);

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
