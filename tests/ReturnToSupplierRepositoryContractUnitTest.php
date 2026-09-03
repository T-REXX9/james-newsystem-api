<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Repositories/ReturnToSupplierRepository.php');
if ($source === false) exit(1);

$checks = [
    'new returns use centralized inventory' => "'warehouse' => 'CENTRALIZED'",
    'posting aggregates duplicate inventory lines' => 'aggregateByInventoryItem',
    'posting calculates current centralized stock' => 'SUM(COALESCE(lin, 0) - COALESCE(lout, 0))',
    'posting rejects insufficient stock' => 'assertAvailable',
    'posting writes an inventory out movement' => "'lout' => \$qty",
    'posting identifies the movement type' => '"Return to Supplier"',
    'unposting removes the stock movement' => 'DELETE FROM tblinventory_logs WHERE lrefno = :refno AND ltransaction_type = "Return to Supplier"',
    'items must exist on the linked receiving report' => 'assertItemReceivedOnRr',
];

$failed = 0;
foreach ($checks as $name => $needle) {
    if (str_contains($source, $needle)) echo "  PASS {$name}\n";
    else { echo "  FAIL {$name}\n"; $failed++; }
}
exit($failed === 0 ? 0 : 1);
