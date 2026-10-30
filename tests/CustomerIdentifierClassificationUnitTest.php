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
identifierAssert(['New Customer'], ['is_new_customer' => 1], 'purchase inside the one-month window is new', $passed, $failed);
identifierAssert(['Existing Customer'], ['is_new_customer' => 0], 'purchase outside the one-month window is existing', $passed, $failed);
identifierAssert(['Verified Prospective'], ['profile_type' => 'Prospective', 'verification' => 'Verified'], 'verified prospect gets the verified label', $passed, $failed);
identifierAssert(['Unverified Prospective'], ['customer_status' => 3, 'verification' => 'Pending'], 'unverified prospect gets the unverified label', $passed, $failed);
identifierAssert(['Blacklisted'], ['customer_status' => 4, 'is_new_customer' => 1], 'blacklist overrides purchase recency', $passed, $failed);
identifierAssert(['Do Not Call'], ['do_not_call' => 1, 'is_new_customer' => 1], 'do-not-call overrides purchase recency', $passed, $failed);
identifierAssert(['Blacklisted', 'Do Not Call'], ['debt_type' => 'Bad', 'do_not_call' => 1], 'both restrictions remain visible together', $passed, $failed);

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
