<?php

declare(strict_types=1);

namespace App\Support;

final class SalesDocumentNumberReuse
{
    public static function remember(string $number, string $documentType): string
    {
        $number = trim($number);
        $type = self::normalizeType($documentType);
        if ($number === '' || $type === '') {
            return '';
        }

        // Never bless a mismatched visible number just because the caller knows
        // which document table it came from. Historical cross-type leaks can
        // otherwise be tagged as valid and reused again after unposting.
        if (!self::matchesTypeFormat($number, $type)) {
            return '';
        }

        return $type . '|' . $number;
    }

    public static function forTarget(string $storedNumber, string $documentType): string
    {
        $storedNumber = trim($storedNumber);
        $targetType = self::normalizeType($documentType);
        if ($storedNumber === '' || $targetType === '') {
            return '';
        }

        if (str_contains($storedNumber, '|')) {
            [$storedType, $number] = explode('|', $storedNumber, 2);
            $number = trim($number);
            return self::normalizeType($storedType) === $targetType && self::matchesTypeFormat($number, $targetType)
                ? $number
                : '';
        }

        // Older rows stored only the visible number. N-D is the legacy Order Slip
        // prefix; older untagged values with other prefixes were invoice numbers.
        $legacyType = preg_match('/^N-D\d+$/i', $storedNumber) === 1 ? 'OS' : 'INV';
        return $legacyType === $targetType ? $storedNumber : '';
    }

    public static function inferLegacyType(string $number): string
    {
        return preg_match('/^N-D\d+$/i', trim($number)) === 1 ? 'Order Slip' : 'Invoice';
    }

    private static function normalizeType(string $documentType): string
    {
        $type = strtolower(trim($documentType));
        return match ($type) {
            'order slip', 'orderslip', 'order-slip', 'order_slip', 'os' => 'OS',
            'invoice', 'inv' => 'INV',
            default => '',
        };
    }

    private static function matchesTypeFormat(string $number, string $type): bool
    {
        $isOrderSlipNumber = preg_match('/^N-D\d+$/i', $number) === 1;
        return $type === 'OS' ? $isOrderSlipNumber : ($type === 'INV' && !$isOrderSlipNumber);
    }
}
