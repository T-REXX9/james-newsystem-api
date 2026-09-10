<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class RecordImageValidator
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    public static function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') return '';
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $value, $matches)) {
            throw new InvalidArgumentException('Record images must be JPG, PNG, or WebP files.');
        }
        $decoded = base64_decode($matches[2], true);
        if ($decoded === false || strlen($decoded) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Record images must be 5 MB or smaller.');
        }
        return $value;
    }
}
