<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/PhoneNumberNormalizer.php';
require __DIR__ . '/../src/Support/CallRecordReportMatcher.php';

use App\Support\CallRecordReportMatcher;

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

$records = [
    [
        'lid' => 1,
        'lcustomer_id' => '101',
        'customer_session_id' => 'patient-session-101',
        'lphone_number' => '09171234567',
        'lcall_timestamp' => '2026-09-08 10:00:00',
    ],
    [
        'lid' => 2,
        'lcustomer_id' => '101',
        'customer_session_id' => 'patient-session-101',
        'lphone_number' => '09171234567',
        'lcall_timestamp' => '2026-09-08 14:00:00',
    ],
    [
        'lid' => 3,
        'lcustomer_id' => null,
        'customer_session_id' => '',
        'lphone_number' => '+63 917 123 4567',
        'lcall_timestamp' => '2026-09-08 15:00:00',
    ],
];

$reports = [
    [
        'contact_id' => 'patient-session-101',
        'agent_user_id' => 'different-web-account',
        'created_at' => '2026-09-08 10:05:00',
        'concern' => null,
        'action' => null,
        'report_body' => "Concern: Wants to buy qk2-556\nAction: Gave him the price",
    ],
    [
        'contact_id' => '',
        'phone_number' => '09171234567',
        'created_at' => '2026-09-08 15:04:00',
        'concern' => 'Phone concern',
        'action' => 'Phone action',
        'report_body' => 'Phone report body',
    ],
];

$attached = CallRecordReportMatcher::attach($records, $reports);
$assert($attached[0]['concern'] === 'Wants to buy qk2-556', 'matches patient session id and parses concern');
$assert($attached[0]['action'] === 'Gave him the price', 'parses action from report body');
$assert($attached[1]['concern'] === null && $attached[1]['action'] === null, 'does not fan one report out to a later call');
$assert($attached[2]['concern'] === 'Phone concern' && $attached[2]['action'] === 'Phone action', 'matches dialed phone when customer lid is missing');

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
