<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SqlDateTimeNormalizer
{
    public static function normalize(mixed $value, ?DateTimeZone $timezone = null): string
    {
        $timezone ??= new DateTimeZone(date_default_timezone_get());
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
        }

        try {
            $parsed = new DateTimeImmutable($raw, $timezone);
        } catch (\Exception $error) {
            throw new InvalidArgumentException('occurred_at must be a valid date/time', 0, $error);
        }

        return $parsed->setTimezone($timezone)->format('Y-m-d H:i:s');
    }
}
