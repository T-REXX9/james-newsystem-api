<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Repositories/CustomerDatabaseRepository.php';

use App\Repositories\CustomerDatabaseRepository;

$passed = 0;
$failed = 0;

function identifierAssert(array $expected, array $row, string $message, int &$passed, int &$failed): void
{
    $actual = CustomerDatabaseRepository::resolveCustomerIdentifierLabels($row);
    if ($actual === $expected) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$message}: expected " . json_encode($expected) . ', got ' . json_encode($actual) . "\n";
}

echo "Customer identifier classification\n";
identifierAssert(
    ['New Customer'],
    ['first_purchase_date' => '2026-10-15', 'as_of_date' => '2026-10-31'],
    'first purchase inside the one-month window is new',
    $passed,
    $failed
);
identifierAssert(
    ['Existing Customer'],
    ['first_purchase_date' => '2020-03-10', 'last_purchase_date' => '2026-10-30', 'as_of_date' => '2026-10-31'],
    'a recent purchase does not make a long-time customer new',
    $passed,
    $failed
);
identifierAssert(
    ['Existing Customer'],
    ['first_purchase_date' => '2026-09-29', 'as_of_date' => '2026-10-31'],
    'first purchase outside the one-month window is existing',
    $passed,
    $failed
);
identifierAssert(
    ['New Customer'],
    ['first_purchase_date' => '2026-09-30', 'as_of_date' => '2026-10-31'],
    'one-month cutoff clamps to the last valid day of the previous month',
    $passed,
    $failed
);
identifierAssert(
    ['Existing Customer'],
    ['first_purchase_date' => null, 'as_of_date' => '2026-10-31'],
    'customer without a recorded purchase is not marked new',
    $passed,
    $failed
);
identifierAssert(['Verified Prospective'], ['profile_type' => 'Prospective', 'verification' => 'Verified'], 'verified prospect gets the verified label', $passed, $failed);
identifierAssert(['Unverified Prospective'], ['customer_status' => 3, 'verification' => 'Pending'], 'unverified prospect gets the unverified label', $passed, $failed);
identifierAssert(['Blacklisted'], ['customer_status' => 4, 'is_new_customer' => 1], 'blacklist overrides purchase recency', $passed, $failed);
identifierAssert(['Do Not Call'], ['do_not_call' => 1, 'is_new_customer' => 1], 'do-not-call overrides purchase recency', $passed, $failed);
identifierAssert(['Blacklisted', 'Do Not Call'], ['debt_type' => 'Bad', 'do_not_call' => 1], 'both restrictions remain visible together', $passed, $failed);

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
