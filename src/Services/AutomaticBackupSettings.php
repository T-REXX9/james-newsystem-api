<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Exceptions\HttpException;

final class AutomaticBackupSettings
{
    public const TIMEZONE = 'Asia/Manila';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'frequency' => 'daily',
            'weekly_days' => [],
            'time' => '02:00',
            'timezone' => self::TIMEZONE,
            'destination_path' => '',
            'retention_count' => 14,
            'last_success_at' => null,
            'last_failure_at' => null,
            'last_failure_message' => null,
            'last_run_key' => null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function normalizeAndValidate(array $input): array
    {
        $base = self::defaults();
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            $enabled = (bool) ($input['enabled'] ?? false);
        }

        $frequency = strtolower(trim((string) ($input['frequency'] ?? $base['frequency'])));
        if (!in_array($frequency, ['daily', 'weekly'], true)) {
            throw new HttpException(422, 'Automatic Backup frequency must be daily or weekly.');
        }

        $time = trim((string) ($input['time'] ?? $base['time']));
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            throw new HttpException(422, 'Automatic Backup time must be HH:MM in 24-hour format.');
        }

        $destination = trim((string) ($input['destination_path'] ?? ''));
        if ($enabled && $destination === '') {
            throw new HttpException(422, 'A Backup Destination is required before enabling Automatic Backup.');
        }

        $retention = (int) ($input['retention_count'] ?? $base['retention_count']);
        if ($retention < 1 || $retention > 365) {
            throw new HttpException(422, 'Retention must be between 1 and 365 backups.');
        }

        $weeklyDays = [];
        if (isset($input['weekly_days']) && is_array($input['weekly_days'])) {
            foreach ($input['weekly_days'] as $day) {
                $n = (int) $day;
                if ($n < 1 || $n > 7) {
                    throw new HttpException(422, 'Weekly days must be integers from 1 (Monday) to 7 (Sunday).');
                }
                $weeklyDays[] = $n;
            }
            $weeklyDays = array_values(array_unique($weeklyDays));
            sort($weeklyDays);
        }

        if ($frequency === 'weekly' && $weeklyDays === []) {
            throw new HttpException(422, 'Select at least one weekday for a weekly Automatic Backup.');
        }

        return [
            'enabled' => $enabled,
            'frequency' => $frequency,
            'weekly_days' => $frequency === 'weekly' ? $weeklyDays : [],
            'time' => $time,
            'timezone' => self::TIMEZONE,
            'destination_path' => $destination,
            'retention_count' => $retention,
            'last_success_at' => $input['last_success_at'] ?? $base['last_success_at'],
            'last_failure_at' => $input['last_failure_at'] ?? $base['last_failure_at'],
            'last_failure_message' => $input['last_failure_message'] ?? $base['last_failure_message'],
            'last_run_key' => $input['last_run_key'] ?? $base['last_run_key'],
        ];
    }
}
