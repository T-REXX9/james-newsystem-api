<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/PhoneNumberNormalizer.php';

use App\Support\PhoneNumberNormalizer;

function expect_phone(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect_phone(
    PhoneNumberNormalizer::equivalent('09334465638', '09166966506 / 09334465638'),
    'A call number must match either number stored in a slash-separated customer field.'
);
expect_phone(
    PhoneNumberNormalizer::equivalent('+63 933 446 5638', '09166966506, 09334465638'),
    'International call numbers must match comma-separated Philippine customer numbers.'
);
expect_phone(
    !PhoneNumberNormalizer::equivalent('09170000000', '09166966506 / 09334465638'),
    'An unrelated number must not match a multi-number customer field.'
);

echo "Phone number normalizer tests passed.\n";
