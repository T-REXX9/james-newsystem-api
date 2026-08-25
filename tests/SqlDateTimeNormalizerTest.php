<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/SqlDateTimeNormalizer.php';

use App\Support\SqlDateTimeNormalizer;
function expect_datetime(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}

$manila = new \DateTimeZone('Asia/Manila');
expect_datetime(
    '2026-08-25 20:46:30',
    SqlDateTimeNormalizer::normalize('2026-08-25T12:46:30.838Z', $manila),
    'ISO UTC timestamps must be converted to a MySQL DATETIME in the application timezone.'
);
expect_datetime(
    '2026-08-25 12:46:30',
    SqlDateTimeNormalizer::normalize('2026-08-25 12:46:30', $manila),
    'Existing MySQL DATETIME values must remain unchanged.'
);

echo "SQL datetime normalizer tests passed.\n";
