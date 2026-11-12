<?php

declare(strict_types=1);

namespace App\Support;

final class SalesInquiryEditPolicy
{
    public static function canEditConfirmedInquiry(bool $isMasterUser, bool $hasInquirySpecificApproval): bool
    {
        return $isMasterUser || $hasInquirySpecificApproval;
    }
}
