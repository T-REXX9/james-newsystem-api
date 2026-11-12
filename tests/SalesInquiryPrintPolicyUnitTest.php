<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/SalesInquiryPrintPolicy.php';

use App\Support\SalesInquiryPrintPolicy;
use PHPUnit\Framework\TestCase;

final class SalesInquiryPrintPolicyUnitTest extends TestCase
{
    public function testStaffCanPrintOnlyOnceUnlessMasterOrApprovedRequest(): void
    {
        self::assertTrue(SalesInquiryPrintPolicy::canPrint(false, 0, false));
        self::assertFalse(SalesInquiryPrintPolicy::canPrint(false, 1, false));
        self::assertTrue(SalesInquiryPrintPolicy::canPrint(false, 1, true));
        self::assertTrue(SalesInquiryPrintPolicy::canPrint(true, 99, false));
    }
}