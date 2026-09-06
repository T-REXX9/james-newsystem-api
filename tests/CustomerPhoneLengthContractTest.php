<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'customers' => $root . '/src/Repositories/CustomerDatabaseRepository.php',
    'requests' => $root . '/src/Repositories/CustomerRequestRepository.php',
];
foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL missing {$name}\n");
        exit(1);
    }
}

$source = fn(string $name): string => (string) file_get_contents($files[$name]);
$checks = [
    'customer phone checks work without mbstring' => !str_contains($source('customers'), 'mb_strlen('),
    'customer-request phone checks work without mbstring' => !str_contains($source('requests'), 'mb_strlen('),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? '  PASS ' : '  FAIL ') . $name . "\n";
    $failed += $passed ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
