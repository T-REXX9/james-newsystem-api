<?php

declare(strict_types=1);

/**
 * Opening one Purchase Request detail must stay fast.
 * Local budget: median getPurchaseRequest under 100ms for the heaviest PR.
 * Enrichment (SR/IR/supplier) must still populate.
 *
 * Run: php api/tests/PurchaseRequestDetailPerfTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\PurchaseRequestRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$repo = new PurchaseRequestRepository($db);

$heaviest = $pdo->query(
    'SELECT pr.lrefno, COUNT(itm.lid) AS item_count
     FROM tblpr_list pr
     INNER JOIN tblpr_item itm ON itm.lrefno = pr.lrefno
     WHERE COALESCE(pr.ldeleted, 0) = 0
     GROUP BY pr.lrefno
     ORDER BY item_count DESC
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);

if ($heaviest === false) {
    fwrite(STDERR, "No purchase requests with items found.\n");
    exit(1);
}

$refno = (string) $heaviest['lrefno'];
$itemCount = (int) $heaviest['item_count'];
$mainId = 1;
$budgetMs = 100.0;
$runs = 3;
$times = [];
$detail = null;

for ($i = 0; $i < $runs; $i++) {
    $started = microtime(true);
    $detail = $repo->getPurchaseRequest($mainId, $refno);
    $times[] = (microtime(true) - $started) * 1000;
    if (!is_array($detail) || count($detail['items'] ?? []) !== $itemCount) {
        fwrite(STDERR, "Detail load returned unexpected item count.\n");
        exit(1);
    }
}

sort($times);
$medianMs = $times[(int) floor(count($times) / 2)];
$firstItem = $detail['items'][0] ?? [];
$enriched = array_key_exists('recommendation', $firstItem) && array_key_exists('sr_cases', $firstItem);

echo "==========================================================\n";
echo " Purchase Request Detail Perf\n";
echo "==========================================================\n\n";
echo "  refno={$refno} items={$itemCount}\n";
echo '  runs=' . implode(', ', array_map(static fn (float $ms): string => sprintf('%.1fms', $ms), $times)) . "\n";
echo sprintf("  median=%.1fms budget=%.1fms\n", $medianMs, $budgetMs);
echo '  enrichment=' . ($enriched ? 'ok' : 'missing') . "\n\n";

$failed = false;
if (!$enriched) {
    echo "FAIL enrichment fields missing on detail items\n";
    $failed = true;
}
if ($medianMs > $budgetMs) {
    echo "FAIL detail load is too slow (median {$medianMs}ms > {$budgetMs}ms)\n";
    $failed = true;
}

if ($failed) {
    exit(1);
}

echo "PASS detail load median is within budget with enrichment\n";
exit(0);
