<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/InvoiceNumberSequence.php';

use App\Support\InvoiceNumberSequence;

$passed = 0;
$failed = 0;
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "  PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}: {$error->getMessage()}\n";
    }
};

echo "InvoiceNumberSequenceUnitTest\n";

$run('parses booklet-style start TT-01500', static function (): void {
    $parsed = InvoiceNumberSequence::parse('TT-01500');
    if ($parsed['prefix'] !== 'TT-') {
        throw new RuntimeException('expected prefix TT-');
    }
    if ($parsed['number'] !== 1500) {
        throw new RuntimeException('expected number 1500');
    }
    if ($parsed['pad_width'] !== 5) {
        throw new RuntimeException('expected pad_width 5');
    }
});

$run('formats next number with prefix and padding', static function (): void {
    $formatted = InvoiceNumberSequence::format('TT-', 1500, 5);
    if ($formatted !== 'TT-01500') {
        throw new RuntimeException('expected TT-01500 got ' . $formatted);
    }
});

$run('formats legacy T- style without padding', static function (): void {
    $formatted = InvoiceNumberSequence::format('T-', 1542, 0);
    if ($formatted !== 'T-1542') {
        throw new RuntimeException('expected T-1542 got ' . $formatted);
    }
});

$run('skips every occupied invoice number until it finds an available number', static function (): void {
    $checked = [];
    $next = InvoiceNumberSequence::nextAvailable(
        ['prefix' => 'T-', 'pad_width' => 0, 'next_number' => 12929],
        static function (string $invoiceNo) use (&$checked): bool {
            $checked[] = $invoiceNo;
            return in_array($invoiceNo, ['T-12929', 'T-12930'], true);
        }
    );

    if ($next['next_invoice_no'] !== 'T-12931' || $next['next_number'] !== 12931) {
        throw new RuntimeException('expected the next available invoice to be T-12931');
    }
    if ($checked !== ['T-12929', 'T-12930', 'T-12931']) {
        throw new RuntimeException('expected every candidate through T-12931 to be checked');
    }
});

$run('preserves booklet padding while skipping occupied numbers', static function (): void {
    $next = InvoiceNumberSequence::nextAvailable(
        ['prefix' => 'TT-', 'pad_width' => 5, 'next_number' => 1500],
        static fn (string $invoiceNo): bool => $invoiceNo === 'TT-01500'
    );

    if ($next['next_invoice_no'] !== 'TT-01501') {
        throw new RuntimeException('expected TT-01501');
    }
});

$run('rejects empty start values', static function (): void {
    try {
        InvoiceNumberSequence::parse('   ');
        throw new RuntimeException('expected parse to throw');
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === '') {
            throw new RuntimeException('expected error message');
        }
    }
});

$run('defaults keep T- prefix and no padding', static function (): void {
    $defaults = InvoiceNumberSequence::defaults();
    if ($defaults['prefix'] !== 'T-' || $defaults['pad_width'] !== 0 || $defaults['next_number'] !== 1) {
        throw new RuntimeException('unexpected defaults');
    }
});

echo "InvoiceNumberSequenceUnitTest: {$passed} passed, {$failed} failed\n";
if ($failed > 0) {
    exit(1);
}
