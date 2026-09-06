<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Support/CustomerLedgerCalculator.php';
require __DIR__ . '/../src/Support/PurchasedItemMatcher.php';
require __DIR__ . '/../src/Repositories/CustomerRepository.php';

use App\Repositories\CustomerRepository;

$repo = (new ReflectionClass(CustomerRepository::class))->newInstanceWithoutConstructor();
$passed = 0;
$failed = 0;

function platinumAssert(bool $condition, string $message, int &$passed, int &$failed): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$message}\n";
}

echo "Customer platinum eligibility\n";

platinumAssert(
    $repo->resolvePlatinumEligibility('vip2', '2019-05-24') === true,
    'Gold retained since 2019 is platinum-eligible',
    $passed,
    $failed
);
platinumAssert(
    $repo->resolvePlatinumEligibility('vip2', '1970-01-01') === false,
    'Unix epoch is not a real tenure date for platinum',
    $passed,
    $failed
);
platinumAssert(
    $repo->resolvePlatinumEligibility('vip2', '') === false,
    'Missing Customer Since is not platinum-eligible',
    $passed,
    $failed
);

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
