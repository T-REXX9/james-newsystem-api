<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/DailyCallClaimPolicy.php';

use App\Support\DailyCallClaimPolicy;

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  PASS {$label}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$label}\n";
};

$now = new DateTimeImmutable('2026-06-21 10:00:00');
$assert(DailyCallClaimPolicy::canAcquire(null, 2, $now), 'allows an unclaimed customer');
$assert(DailyCallClaimPolicy::canAcquire([
    'status' => 'in_progress', 'agent_user_id' => 2, 'expires_at' => '2026-06-21 10:15:00',
], 2, $now), 'allows the owning agent to resume an active claim');
$assert(!DailyCallClaimPolicy::canAcquire([
    'status' => 'in_progress', 'agent_user_id' => 1, 'expires_at' => '2026-06-21 10:15:00',
], 2, $now), 'blocks another agent while a claim is active');
$assert(DailyCallClaimPolicy::canAcquire([
    'status' => 'in_progress', 'agent_user_id' => 1, 'expires_at' => '2026-06-21 09:59:59',
], 2, $now), 'allows takeover after an abandoned claim expires');
$assert(!DailyCallClaimPolicy::canAcquire([
    'status' => 'completed', 'agent_user_id' => 1, 'expires_at' => '2026-06-21 10:15:00',
], 2, $now), 'blocks a customer already called today');

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
