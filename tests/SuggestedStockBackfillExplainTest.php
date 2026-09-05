<?php

declare(strict_types=1);

/**
 * Suggested-stock backfill migrations must not use the OR-join that hung
 * productionupdate (billions of estimated rows, no progress after 031).
 *
 * Run: php api/tests/SuggestedStockBackfillExplainTest.php
 */

$ROOT = dirname(__DIR__);
$vars = [];
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string) ($vars['DB_HOST'] ?? '127.0.0.1'),
        (int) ($vars['DB_PORT'] ?? 3306),
        (string) ($vars['DB_NAME'] ?? 'topnotch_migrate')
    ),
    (string) ($vars['DB_USER'] ?? 'root'),
    (string) ($vars['DB_PASS'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$passed = 0;
$failed = 0;
$errors = [];

function explain_assert(bool $ok, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($ok) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

$itemCodePlan = (string) $pdo->query(<<<'SQL'
EXPLAIN FORMAT=TREE
UPDATE tblinquiry_item i
INNER JOIN tblinquiry tr ON tr.lrefno = i.linq_refno
INNER JOIN tblinventory_item product
  ON CAST(product.lmain_id AS CHAR) = tr.lmain_id
 AND product.litemcode = i.litem_code
 AND IFNULL(product.lnot_inventory, 0) = 0
 AND IFNULL(product.ldeleted, 0) = 0
SET i.lremark = 'ProductCreated'
WHERE i.lremark = 'Listed' AND i.litem_code <> ''
SQL)->fetchColumn();

$addedToPrPlan = (string) $pdo->query(<<<'SQL'
EXPLAIN FORMAT=TREE
UPDATE tblinquiry_item i
SET i.lremark = 'ProductCreated'
WHERE i.lremark = 'AddedToPR'
  AND NOT EXISTS (
    SELECT 1 FROM tblpr_item pri
    INNER JOIN tblpr_list pr ON pr.lrefno = pri.lrefno
    WHERE pr.ldeleted = 0
      AND pri.lpart_no = i.lpartno
      AND pri.ldesc = i.ldesc
      AND pri.litem_code = i.litem_code
  )
SQL)->fetchColumn();

echo "==========================================================\n";
echo " Suggested Stock Backfill EXPLAIN Guard\n";
echo "==========================================================\n\n";

explain_assert(
    !preg_match('/rows=\d+\.\d+e\+[89]/', $itemCodePlan),
    'Listed item-code backfill is not a billion-row nested loop',
    $passed,
    $failed,
    $errors
);
explain_assert(
    str_contains($itemCodePlan, 'idx_suggested_inquiry_item_remark_ref'),
    'Listed backfill uses the remark index',
    $passed,
    $failed,
    $errors
);
explain_assert(
    !preg_match('/rows=\d+\.\d+e\+[89]/', $addedToPrPlan),
    'AddedToPR restore is not a billion-row nested loop',
    $passed,
    $failed,
    $errors
);
explain_assert(
    str_contains($addedToPrPlan, 'idx_suggested_inquiry_item_remark_ref'),
    'AddedToPR restore uses the remark index',
    $passed,
    $failed,
    $errors
);

echo "\nPassed: {$passed} | Failed: {$failed}\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    echo "item-code plan:\n{$itemCodePlan}\n";
    echo "AddedToPR plan:\n{$addedToPrPlan}\n";
    exit(1);
}

echo "Backfill plans stay index-driven.\n";
exit(0);
