<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class CustomerLedgerCalculator
{
    /**
     * @return array{0:string,1:?string,2:?string}
     */
    public static function resolveDateRange(
        string $dateType,
        ?string $dateFrom,
        ?string $dateTo,
        ?DateTimeImmutable $today = null
    ): array {
        $type = strtolower(trim($dateType));
        if ($type === '') {
            $type = 'all';
        }

        $today ??= new DateTimeImmutable('today');

        return match ($type) {
            'today' => ['today', $today->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => [
                'week',
                $today->modify('monday this week')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'month' => [
                'month',
                $today->modify('first day of this month')->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'year' => [
                'year',
                $today->setDate((int) $today->format('Y'), 1, 1)->format('Y-m-d'),
                $today->format('Y-m-d'),
            ],
            'custom' => ['custom', self::normalizeDate((string) $dateFrom), self::normalizeDate((string) $dateTo)],
            default => ['all', null, null],
        };
    }

    public static function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0000-00-00' || $trimmed === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($trimmed);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * @param list<array<string,mixed>> $rawRows
     * @return array{rows:list<array<string,mixed>>,totals:array{debit:float,credit:float,pdc:float,balance:float,row_count:int}}
     */
    public static function buildDetailedReport(array $rawRows, string $today, float $openingBalance = 0.0): array
    {
        $runningBalance = $openingBalance;
        $rows = [];
        $totals = [
            'debit' => 0.0,
            'credit' => 0.0,
            'pdc' => 0.0,
            'balance' => $openingBalance,
            'row_count' => 0,
        ];

        if ($openingBalance !== 0.0) {
            $rows[] = self::openingDetailedRow($openingBalance);
        }

        foreach ($rawRows as $row) {
            $line = self::mapDetailedRow($row, $today, $runningBalance);
            $rows[] = $line;
            $totals['debit'] += $line['debit'];
            $totals['credit'] += $line['credit'];
            $totals['pdc'] += $line['pdc'];
            $totals['balance'] = $line['balance'];
        }

        $totals['row_count'] = count($rows);
        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param list<array<string,mixed>> $rawGroups
     * @return array{rows:list<array<string,mixed>>,totals:array{debit:float,credit:float,pdc:float,balance:float,row_count:int}}
     */
    public static function buildSummaryReport(array $rawGroups, float $openingBalance = 0.0): array
    {
        $runningBalance = $openingBalance;
        $rows = [];
        $totals = [
            'debit' => 0.0,
            'credit' => 0.0,
            'pdc' => 0.0,
            'balance' => $openingBalance,
            'row_count' => 0,
        ];

        if ($openingBalance !== 0.0) {
            $rows[] = [
                'year' => 0,
                'month' => 0,
                'month_name' => 'Opening',
                'debit' => 0.0,
                'credit' => 0.0,
                'balance' => $openingBalance,
            ];
        }

        foreach ($rawGroups as $row) {
            $debit = (float) ($row['debit'] ?? 0);
            $credit = (float) ($row['credit'] ?? 0);
            $month = (int) ($row['month'] ?? 0);
            $runningBalance += $debit - $credit;

            $rows[] = [
                'year' => (int) ($row['year'] ?? 0),
                'month' => $month,
                'month_name' => date('F', mktime(0, 0, 0, max(1, $month), 1)),
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
            ];
            $totals['debit'] += $debit;
            $totals['credit'] += $credit;
            $totals['balance'] = $runningBalance;
        }

        $totals['row_count'] = count($rows);
        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * Applies effective credits to the oldest debits first, then ages the unpaid balance.
     * A credit balance is reported in Current, matching the old report.
     *
     * @param list<array<string,mixed>> $rawRows
     * @return array{current:float,days_31_60:float,days_61_90:float,days_91_120:float,days_121_150:float,over_150:float}
     */
    public static function buildAgingBuckets(array $rawRows, string $today): array
    {
        $buckets = self::emptyAgingBuckets();
        $debits = [];
        $creditPool = 0.0;
        $unusedBalance = 0.0;

        foreach ($rawRows as $row) {
            $line = self::mapDetailedRow($row, $today, $unusedBalance);
            $debit = (float) $line['debit'] + abs(min(0.0, (float) $line['pdc']));
            $creditPool += (float) $line['credit'] + max(0.0, (float) $line['pdc']);

            if ($debit > 0.0) {
                $debits[] = ['date' => $line['date'], 'amount' => $debit];
            }
        }

        foreach ($debits as $debit) {
            $paid = min($creditPool, (float) $debit['amount']);
            $remaining = (float) $debit['amount'] - $paid;
            $creditPool -= $paid;
            if ($remaining <= 0.0) {
                continue;
            }

            $bucket = self::agingBucketForDate((string) ($debit['date'] ?? ''), $today);
            $buckets[$bucket] += $remaining;
        }

        if ($creditPool > 0.0) {
            $buckets['current'] -= $creditPool;
        }

        return $buckets;
    }

    /** @return array<string,mixed> */
    private static function mapDetailedRow(array $row, string $today, float &$runningBalance): array
    {
        $isCredit = strcasecmp((string) ($row['ltype'] ?? ''), 'Credit') === 0;
        $effectiveCheckDate = self::normalizeDate((string) ($row['lcheckdate'] ?? ''));
        $isFutureCheck = $effectiveCheckDate !== null && $effectiveCheckDate > $today;

        $lineCredit = 0.0;
        $lineDebit = 0.0;
        $linePdc = 0.0;

        if ($isCredit) {
            $amount = (float) (($row['lcredit'] ?? 0) ?: ($row['lpdc'] ?? 0));
            $isFutureCheck ? $linePdc = $amount : $lineCredit = $amount;
        } else {
            $amount = (float) (($row['ldebit'] ?? 0) ?: ($row['lpdc'] ?? 0));
            $isFutureCheck ? $linePdc = 0.0 - $amount : $lineDebit = $amount;
        }

        $runningBalance += $lineDebit - max($linePdc, 0.0) + abs(min($linePdc, 0.0)) - $lineCredit;

        return [
            'id' => (int) ($row['lid'] ?? 0),
            'date' => self::normalizeDate((string) ($row['ldatetime'] ?? '')),
            'datetime' => (string) ($row['ldatetime'] ?? ''),
            'reference' => strtoupper((string) ($row['lmesssage'] ?? '')),
            'ref_no' => (string) ($row['lrefno'] ?? ''),
            'ref_type' => (string) ($row['lref_name'] ?? ''),
            'check_no' => (string) ($row['lcheck_no'] ?? ''),
            'check_date' => $effectiveCheckDate === '1970-01-01' ? null : $effectiveCheckDate,
            'dcr' => preg_replace('/^DCR-/i', '', (string) ($row['ldcr'] ?? '')) ?? '',
            'debit' => $lineDebit,
            'credit' => $lineCredit,
            'pdc' => $linePdc,
            'balance' => $runningBalance,
            'remarks' => strtoupper((string) ($row['lremarks'] ?? '')),
            'promise_to_pay' => strtoupper((string) ($row['promisetopay'] ?? '')),
        ];
    }

    /** @return array<string,mixed> */
    private static function openingDetailedRow(float $openingBalance): array
    {
        return [
            'id' => 0,
            'date' => null,
            'datetime' => '',
            'reference' => 'OPENING BALANCE',
            'ref_no' => '',
            'ref_type' => '',
            'check_no' => '',
            'check_date' => null,
            'dcr' => '',
            'debit' => 0.0,
            'credit' => 0.0,
            'pdc' => 0.0,
            'balance' => $openingBalance,
            'remarks' => '',
            'promise_to_pay' => '',
        ];
    }

    private static function agingBucketForDate(string $date, string $today): string
    {
        $normalized = self::normalizeDate($date);
        if ($normalized === null) {
            return 'current';
        }

        $age = max(0, (int) (new DateTimeImmutable($normalized))->diff(new DateTimeImmutable($today))->format('%r%a'));
        return match (true) {
            $age <= 30 => 'current',
            $age <= 60 => 'days_31_60',
            $age <= 90 => 'days_61_90',
            $age <= 120 => 'days_91_120',
            $age <= 150 => 'days_121_150',
            default => 'over_150',
        };
    }

    /** @return array{current:float,days_31_60:float,days_61_90:float,days_91_120:float,days_121_150:float,over_150:float} */
    private static function emptyAgingBuckets(): array
    {
        return [
            'current' => 0.0,
            'days_31_60' => 0.0,
            'days_61_90' => 0.0,
            'days_91_120' => 0.0,
            'days_121_150' => 0.0,
            'over_150' => 0.0,
        ];
    }
}
