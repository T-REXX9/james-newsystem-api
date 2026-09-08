<?php

declare(strict_types=1);

namespace App\Support;

final class CallRecordReportMatcher
{
    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $reports
     * @return array<int, array<string, mixed>>
     */
    public static function attach(array $records, array $reports): array
    {
        $usedReports = [];
        return array_map(static function (array $record) use ($reports, &$usedReports): array {
            $record['concern'] = null;
            $record['action'] = null;
            $record['report_body'] = null;

            $matches = array_values(array_filter(
                $reports,
                static fn(array $report): bool => !isset($usedReports[self::reportKey($report)])
                    && self::matchesIdentity($record, $report)
                    && self::isWithinWindow($record, $report)
            ));
            usort($matches, static function (array $left, array $right) use ($record): int {
                $distance = self::distanceFromRecord($record);
                $leftDistance = abs($distance - self::timestamp($left));
                $rightDistance = abs($distance - self::timestamp($right));
                return $leftDistance <=> $rightDistance;
            });

            $report = $matches[0] ?? null;
            if ($report !== null) {
                $usedReports[self::reportKey($report)] = true;
                $record['concern'] = self::firstValue($report['concern'] ?? null, $report['report_body'] ?? null, 'Concern');
                $record['action'] = self::firstValue($report['action'] ?? null, $report['report_body'] ?? null, 'Action');
                $record['report_body'] = trim((string) ($report['report_body'] ?? '')) ?: null;
            }

            return $record;
        }, $records);
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $report */
    private static function matchesIdentity(array $record, array $report): bool
    {
        $contactId = trim((string) ($report['contact_id'] ?? ''));
        $customerLid = trim((string) ($record['lcustomer_id'] ?? ''));
        $customerSession = trim((string) ($record['customer_session_id'] ?? ''));
        if ($contactId !== '' && ($contactId === $customerLid || $contactId === $customerSession)) {
            return true;
        }

        return $customerLid === ''
            && PhoneNumberNormalizer::equivalent(
                (string) ($record['lphone_number'] ?? ''),
                (string) ($report['phone_number'] ?? '')
            );
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $report */
    private static function isWithinWindow(array $record, array $report): bool
    {
        $recordTime = self::timestamp($record);
        $reportTime = self::timestamp($report);
        if ($recordTime <= 0 || $reportTime <= 0) {
            return false;
        }

        if ((int) ($report['legacy'] ?? 0) === 1) {
            return date('Y-m-d', $recordTime) === date('Y-m-d', $reportTime);
        }

        return abs($recordTime - $reportTime) <= 30 * 60;
    }

    /** @param array<string, mixed> $row */
    private static function timestamp(array $row): int
    {
        $value = trim((string) ($row['lcall_timestamp'] ?? $row['created_at'] ?? ''));
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp === false ? 0 : $timestamp;
    }

    /** @param mixed $structured @param mixed $body */
    private static function firstValue(mixed $structured, mixed $body, string $label): ?string
    {
        $value = trim((string) ($structured ?? ''));
        if ($value !== '') {
            return $value;
        }

        $bodyText = trim((string) ($body ?? ''));
        if ($bodyText === '') {
            return null;
        }

        $pattern = '/(?:^|\\R)\\s*' . preg_quote($label, '/') . '\\s*:\\s*(.+?)(?=\\R|$)/i';
        return preg_match($pattern, $bodyText, $matches) === 1
            ? trim($matches[1]) ?: null
            : null;
    }

    /** @param array<string, mixed> $report */
    private static function reportKey(array $report): string
    {
        return (string) ($report['report_key'] ?? spl_object_id((object) $report));
    }
}
