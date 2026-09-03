<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/PurchasedItemMatcher.php';

use App\Support\PurchasedItemMatcher;

$failed = 0;

if (PurchasedItemMatcher::valuesMatch('Dlla147p788', 'P-DLLA147P788')) {
    echo "  PASS part numbers match with P- prefix and mixed case\n";
} else {
    echo "  FAIL part numbers match with P- prefix and mixed case\n";
    $failed++;
}

if (PurchasedItemMatcher::identifiersMatch('QK2-1091', '', 'QK2-1091', 'P-DLLA147P0752')) {
    echo "  PASS item code match is enough\n";
} else {
    echo "  FAIL item code match is enough\n";
    $failed++;
}

if (!PurchasedItemMatcher::identifiersMatch('', 'DLLA147P788', 'QK2-110', 'P-DLLA147P0752')) {
    echo "  PASS unpurchased part does not match history rows\n";
} else {
    echo "  FAIL unpurchased part does not match history rows\n";
    $failed++;
}

$message = PurchasedItemMatcher::notPurchasedMessage('DLLA147P788');
if (str_contains($message, 'DLLA147P788') && str_contains($message, 'purchase history')) {
    echo "  PASS rejection message names the part\n";
} else {
    echo "  FAIL rejection message names the part\n";
    $failed++;
}

exit($failed === 0 ? 0 : 1);
