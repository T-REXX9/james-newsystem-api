<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/CustomerLedgerCalculator.php';

use App\Support\CustomerLedgerCalculator;

$passed = 0;
$failed = 0;

function ledgerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "  PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}: {$error->getMessage()}\n";
    }
};

/** @return list<array<string,mixed>> */
function ledgerRows(): array
{
    return [
        [
            'lid' => 1,
            'lrefno' => 'INV-001',
            'lmesssage' => 'Invoice #001',
            'ldatetime' => '2026-07-15 10:30:00',
            'ltype' => 'Debit',
            'lcredit' => 0,
            'ldebit' => 5000,
            'lcheckdate' => null,
            'lcheck_no' => '',
            'ldcr' => 'DCR-01',
            'lpdc' => 0,
            'lremarks' => 'Invoice',
            'lref_name' => 'Invoice',
            'promisetopay' => '',
        ],
        [
            'lid' => 2,
            'lrefno' => 'PAY-001',
            'lmesssage' => 'Payment #001',
            'ldatetime' => '2026-07-16 14:00:00',
            'ltype' => 'Credit',
            'lcredit' => 2000,
            'ldebit' => 0,
            'lcheckdate' => '2026-07-16',
            'lcheck_no' => 'CHK-100',
            'ldcr' => 'DCR-02',
            'lpdc' => 0,
            'lremarks' => 'Payment',
            'lref_name' => 'Collection',
            'promisetopay' => '',
        ],
        [
            'lid' => 3,
            'lrefno' => 'PDC-001',
            'lmesssage' => 'PDC #001',
            'ldatetime' => '2026-07-17 09:00:00',
            'ltype' => 'Credit',
            'lcredit' => 0,
            'ldebit' => 0,
            'lcheckdate' => '2027-01-01',
            'lcheck_no' => 'CHK-200',
            'ldcr' => '',
            'lpdc' => 1000,
            'lremarks' => 'PDC',
            'lref_name' => 'Collection',
            'promisetopay' => '',
        ],
        [
            'lid' => 4,
            'lrefno' => 'INV-002',
            'lmesssage' => 'Invoice #002',
            'ldatetime' => '2026-08-01 08:00:00',
            'ltype' => 'Debit',
            'lcredit' => 0,
            'ldebit' => 3000,
            'lcheckdate' => null,
            'lcheck_no' => '',
            'ldcr' => 'DCR-03',
            'lpdc' => 0,
            'lremarks' => 'Invoice',
            'lref_name' => 'Invoice',
            'promisetopay' => '2026-08-15',
        ],
    ];
}

$run('maps rows and computes debit, credit, PDC, and final balance', static function (): void {
    $report = CustomerLedgerCalculator::buildDetailedReport(ledgerRows(), '2026-08-06');

    ledgerAssert($report['totals']['debit'] === 8000.0, 'Debit total mismatch');
    ledgerAssert($report['totals']['credit'] === 2000.0, 'Credit total mismatch');
    ledgerAssert($report['totals']['pdc'] === 1000.0, 'PDC total mismatch');
    ledgerAssert($report['totals']['balance'] === 5000.0, 'Final balance mismatch');
    ledgerAssert($report['rows'][0]['dcr'] === '01', 'DCR prefix was not removed exactly');
});

$run('classifies only future-dated checks as PDC', static function (): void {
    $report = CustomerLedgerCalculator::buildDetailedReport(ledgerRows(), '2026-08-06');
    $pdc = $report['rows'][2];

    ledgerAssert($pdc['pdc'] === 1000.0, 'Future check was not classified as PDC');
    ledgerAssert($pdc['credit'] === 0.0, 'Future check must not be ordinary credit');
    ledgerAssert($report['rows'][1]['credit'] === 2000.0, 'Mature check must be ordinary credit');
});

$run('adds an opening balance without changing period debit and credit totals', static function (): void {
    $report = CustomerLedgerCalculator::buildDetailedReport(ledgerRows(), '2026-08-06', 750.0);

    ledgerAssert($report['rows'][0]['reference'] === 'OPENING BALANCE', 'Opening row missing');
    ledgerAssert($report['rows'][0]['balance'] === 750.0, 'Opening balance mismatch');
    ledgerAssert($report['totals']['debit'] === 8000.0, 'Opening balance changed debit total');
    ledgerAssert($report['totals']['balance'] === 5750.0, 'Opening balance not carried forward');
});

$run('builds summary rows with opening balance carried forward', static function (): void {
    $report = CustomerLedgerCalculator::buildSummaryReport([
        ['year' => 2026, 'month' => 7, 'debit' => 5000, 'credit' => 2000],
        ['year' => 2026, 'month' => 8, 'debit' => 3000, 'credit' => 0],
    ], 1000.0);

    ledgerAssert($report['rows'][0]['month_name'] === 'Opening', 'Opening summary row missing');
    ledgerAssert($report['rows'][1]['balance'] === 4000.0, 'July balance mismatch');
    ledgerAssert($report['rows'][2]['balance'] === 7000.0, 'August balance mismatch');
    ledgerAssert($report['totals']['row_count'] === 3, 'Summary row count mismatch');
});

$run('uses calendar week, month, and year boundaries', static function (): void {
    $today = new DateTimeImmutable('2026-08-06');
    [, $weekFrom, $weekTo] = CustomerLedgerCalculator::resolveDateRange('week', null, null, $today);
    [, $monthFrom, $monthTo] = CustomerLedgerCalculator::resolveDateRange('month', null, null, $today);
    [, $yearFrom, $yearTo] = CustomerLedgerCalculator::resolveDateRange('year', null, null, $today);

    ledgerAssert($weekFrom === '2026-08-03' && $weekTo === '2026-08-06', 'Week boundary mismatch');
    ledgerAssert($monthFrom === '2026-08-01' && $monthTo === '2026-08-06', 'Month boundary mismatch');
    ledgerAssert($yearFrom === '2026-01-01' && $yearTo === '2026-08-06', 'Year boundary mismatch');
});

$run('normalizes custom dates and rejects invalid dates', static function (): void {
    [, $from, $to] = CustomerLedgerCalculator::resolveDateRange(
        'custom',
        '2026-07-01',
        'not-a-date',
        new DateTimeImmutable('2026-08-06')
    );

    ledgerAssert($from === '2026-07-01', 'Custom from date mismatch');
    ledgerAssert($to === null, 'Invalid custom date must normalize to null');
});

$run('ages unpaid debits after applying credits oldest-first', static function (): void {
    $rows = [
        [
            'lid' => 1, 'lmesssage' => 'Old invoice', 'ldatetime' => '2026-01-01',
            'ltype' => 'Debit', 'ldebit' => 1000, 'lcredit' => 0, 'lpdc' => 0,
        ],
        [
            'lid' => 2, 'lmesssage' => 'Current invoice', 'ldatetime' => '2026-07-20',
            'ltype' => 'Debit', 'ldebit' => 500, 'lcredit' => 0, 'lpdc' => 0,
        ],
        [
            'lid' => 3, 'lmesssage' => 'Payment', 'ldatetime' => '2026-08-01',
            'ltype' => 'Credit', 'ldebit' => 0, 'lcredit' => 600, 'lpdc' => 0,
        ],
    ];
    $aging = CustomerLedgerCalculator::buildAgingBuckets($rows, '2026-08-06');

    ledgerAssert($aging['over_150'] === 400.0, 'Old balance bucket mismatch');
    ledgerAssert($aging['current'] === 500.0, 'Current balance bucket mismatch');
    ledgerAssert(array_sum($aging) === 900.0, 'Aging total must equal ledger balance');
});

$run('reports an overpayment as a negative current balance', static function (): void {
    $rows = [
        [
            'lid' => 1, 'lmesssage' => 'Invoice', 'ldatetime' => '2026-08-01',
            'ltype' => 'Debit', 'ldebit' => 500, 'lcredit' => 0, 'lpdc' => 0,
        ],
        [
            'lid' => 2, 'lmesssage' => 'Payment', 'ldatetime' => '2026-08-02',
            'ltype' => 'Credit', 'ldebit' => 0, 'lcredit' => 585, 'lpdc' => 0,
        ],
    ];
    $aging = CustomerLedgerCalculator::buildAgingBuckets($rows, '2026-08-06');

    ledgerAssert($aging['current'] === -85.0, 'Credit balance must appear in Current');
    ledgerAssert(array_sum($aging) === -85.0, 'Aging total mismatch for credit balance');
});

echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
