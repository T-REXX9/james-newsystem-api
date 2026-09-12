<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class InvoiceNumberSequence
{
    /**
     * @return array{prefix: string, pad_width: int, next_number: int}
     */
    public static function defaults(): array
    {
        return [
            'prefix' => 'T-',
            'pad_width' => 0,
            'next_number' => 1,
        ];
    }

    /**
     * @return array{prefix: string, number: int, pad_width: int}
     */
    public static function parse(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Invoice number start is required');
        }

        if (preg_match('/^(.*?)(\d+)$/', $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException('Invoice number start must end with a numeric sequence');
        }

        $prefix = (string) ($matches[1] ?? '');
        $digits = (string) ($matches[2] ?? '');
        $number = (int) $digits;
        if ($number < 1) {
            throw new InvalidArgumentException('Invoice number sequence must be at least 1');
        }

        return [
            'prefix' => $prefix,
            'number' => $number,
            'pad_width' => strlen($digits),
        ];
    }

    public static function format(string $prefix, int $number, int $padWidth): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException('Invoice number sequence must be at least 1');
        }

        $numeric = $padWidth > 0
            ? str_pad((string) $number, $padWidth, '0', STR_PAD_LEFT)
            : (string) $number;

        return $prefix . $numeric;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{prefix: string, pad_width: int, next_number: int, next_invoice_no: string}
     */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $prefix = array_key_exists('prefix', $settings)
            ? (string) $settings['prefix']
            : $defaults['prefix'];
        $padWidth = max(0, (int) ($settings['pad_width'] ?? $defaults['pad_width']));
        $nextNumber = max(1, (int) ($settings['next_number'] ?? $defaults['next_number']));

        return [
            'prefix' => $prefix,
            'pad_width' => $padWidth,
            'next_number' => $nextNumber,
            'next_invoice_no' => self::format($prefix, $nextNumber, $padWidth),
        ];
    }

    /**
     * @return array{prefix: string, pad_width: int, next_number: int, next_invoice_no: string}
     */
    public static function fromStartValue(string $startValue): array
    {
        $parsed = self::parse($startValue);
        return self::normalize([
            'prefix' => $parsed['prefix'],
            'pad_width' => $parsed['pad_width'],
            'next_number' => $parsed['number'],
        ]);
    }
}
