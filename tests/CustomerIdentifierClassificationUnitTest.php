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
identifierAssert(['Existing Customer'], ['profile_type' => 'Prospective', 'verification' => 'Verified', 'first_purchase_date' => '2025-04-10'], 'purchase history overrides stale verified prospect classification', $passed, $failed);
identifierAssert(['Unverified Prospective'], ['customer_status' => 3, 'verification' => 'Pending'], 'unverified prospect gets the unverified label', $passed, $failed);
identifierAssert(['Blacklisted', 'New Customer'], ['customer_status' => 4, 'first_purchase_date' => '2026-10-15', 'as_of_date' => '2026-10-31'], 'blacklist is shown alongside a new-customer identity', $passed, $failed);
identifierAssert(['Do Not Call', 'New Customer'], ['do_not_call' => 1, 'first_purchase_date' => '2026-10-15', 'as_of_date' => '2026-10-31'], 'do-not-call is shown alongside a new-customer identity', $passed, $failed);
identifierAssert(['Do Not Call', 'Existing Customer'], ['do_not_call' => 1, 'first_purchase_date' => '2020-03-10', 'as_of_date' => '2026-10-31'], 'do-not-call is shown alongside an existing-customer identity', $passed, $failed);
identifierAssert(['Do Not Call', 'Verified Prospective'], ['do_not_call' => 1, 'profile_type' => 'Prospective', 'verification' => 'Verified'], 'do-not-call is shown alongside verified prospective identity', $passed, $failed);
identifierAssert(['Do Not Call', 'Unverified Prospective'], ['do_not_call' => 1, 'customer_status' => 3], 'do-not-call is shown alongside unverified prospective identity', $passed, $failed);
identifierAssert(['Blacklisted', 'Do Not Call', 'New Customer'], ['customer_status' => 4, 'do_not_call' => 1, 'first_purchase_date' => '2026-10-15', 'as_of_date' => '2026-10-31'], 'both restrictions remain visible alongside customer identity', $passed, $failed);
identifierAssert(['New Customer'], ['do_not_call' => 0, 'first_purchase_date' => '2026-10-15', 'as_of_date' => '2026-10-31'], 'removing do-not-call retains the customer identity', $passed, $failed);

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
