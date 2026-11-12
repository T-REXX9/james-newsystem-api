<?php

declare(strict_types=1);

namespace App\Support;

final class SalesInquiryPrintPolicy
{
    public static function canPrint(bool $isMasterUser, int $printCount, bool $hasApprovedRequest): bool
    {
        return $isMasterUser || $printCount < 1 || $hasApprovedRequest;
    }
}