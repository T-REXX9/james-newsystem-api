<?php

declare(strict_types=1);

require __DIR__ . '/../src/Services/AutomaticBackupOrganizer.php';

use App\Services\AutomaticBackupOrganizer;

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

echo "AutomaticBackupOrganizerUnitTest\n";

$run('builds Asia/Manila organized path under destination', static function (): void {
    $at = new DateTimeImmutable('2026-09-09 14:05:00', new DateTimeZone('UTC'));
    $path = AutomaticBackupOrganizer::organizedDumpPath('/Volumes/USB', 'demo_db', $at);
    $expected = '/Volumes/USB/backups/2026/09/demo_db_full_20260909_2205.sql.gz';
    if ($path !== $expected) {
        throw new RuntimeException("got {$path}, expected {$expected}");
    }
});

$run('keeps only the newest N dumps matching the dump pattern', static function (): void {
    $root = sys_get_temp_dir() . '/auto-backup-org-' . bin2hex(random_bytes(4));
    $dir = $root . '/backups/2026/09';
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('unable to create temp dir');
    }

    $files = [
        $dir . '/demo_db_full_20260901_0200.sql.gz',
        $dir . '/demo_db_full_20260902_0200.sql.gz',
        $dir . '/demo_db_full_20260903_0200.sql.gz',
        $dir . '/demo_db_full_20260904_0200.sql.gz',
        $dir . '/notes.txt',
    ];
    foreach ($files as $file) {
        file_put_contents($file, 'x');
    }

    $removed = AutomaticBackupOrganizer::applyRetention($root, 'demo_db', 2);
    if (count($removed) !== 2) {
        throw new RuntimeException('expected 2 removals, got ' . count($removed));
    }
    if (!is_file($dir . '/demo_db_full_20260904_0200.sql.gz') || !is_file($dir . '/demo_db_full_20260903_0200.sql.gz')) {
        throw new RuntimeException('newest two dumps should remain');
    }
    if (is_file($dir . '/demo_db_full_20260901_0200.sql.gz') || is_file($dir . '/demo_db_full_20260902_0200.sql.gz')) {
        throw new RuntimeException('older dumps should be removed');
    }
    if (!is_file($dir . '/notes.txt')) {
        throw new RuntimeException('non-dump files must be left alone');
    }

    // cleanup
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    @rmdir(dirname($dir));
    @rmdir($root);
});

echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
