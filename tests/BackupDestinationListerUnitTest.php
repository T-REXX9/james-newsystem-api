<?php

declare(strict_types=1);

require __DIR__ . '/../src/Services/BackupDestinationLister.php';

use App\Services\BackupDestinationLister;

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

echo "BackupDestinationListerUnitTest\n";

$run('returns empty list when no candidate roots exist', static function (): void {
    $lister = new BackupDestinationLister(['/definitely/missing-' . bin2hex(random_bytes(3))]);
    $items = $lister->listWritableDestinations();
    if ($items !== []) {
        throw new RuntimeException('expected empty list');
    }
});

$run('lists writable directories under candidate roots', static function (): void {
    $root = sys_get_temp_dir() . '/backup-dest-root-' . bin2hex(random_bytes(3));
    $driveA = $root . '/USB_A';
    $driveB = $root . '/HDD_B';
    mkdir($driveA, 0700, true);
    mkdir($driveB, 0700, true);

    $lister = new BackupDestinationLister([$root]);
    $items = $lister->listWritableDestinations();
    $paths = array_column($items, 'path');
    sort($paths);

    if ($paths !== [$driveA, $driveB] && $paths !== [realpath($driveA), realpath($driveB)]) {
        // Accept either absolute form as long as both drives are present by basename.
        $labels = array_column($items, 'label');
        sort($labels);
        if ($labels !== ['HDD_B', 'USB_A']) {
            throw new RuntimeException('unexpected destinations: ' . json_encode($items));
        }
    }

    @rmdir($driveA);
    @rmdir($driveB);
    @rmdir($root);
});

echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
