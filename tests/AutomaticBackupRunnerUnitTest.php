<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Services/AutomaticBackupSettings.php';
require __DIR__ . '/../src/Services/AutomaticBackupOrganizer.php';
require __DIR__ . '/../src/Services/AutomaticBackupSettingsStore.php';
require __DIR__ . '/../src/Services/AutomaticBackupRunner.php';

use App\Services\AutomaticBackupRunner;
use App\Services\AutomaticBackupSettingsStore;

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

echo "AutomaticBackupRunnerUnitTest\n";

$run('records failure and notifies when destination is missing', static function (): void {
    $storePath = sys_get_temp_dir() . '/auto-backup-settings-' . bin2hex(random_bytes(4)) . '.json';
    $store = new AutomaticBackupSettingsStore($storePath);
    $store->save([
        'enabled' => true,
        'frequency' => 'daily',
        'weekly_days' => [],
        'time' => '02:00',
        'timezone' => 'Asia/Manila',
        'destination_path' => '/path/does/not/exist-' . bin2hex(random_bytes(3)),
        'retention_count' => 14,
        'last_success_at' => null,
        'last_failure_at' => null,
        'last_failure_message' => null,
        'last_run_key' => null,
    ]);

    $notified = [];
    $dumpCalled = false;
    $runner = new AutomaticBackupRunner(
        $store,
        'demo_db',
        static function () use (&$dumpCalled): array {
            $dumpCalled = true;
            throw new RuntimeException('dump should not run');
        },
        static function (string $title, string $message) use (&$notified): void {
            $notified[] = [$title, $message];
        },
        static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-09 18:00:00', new DateTimeZone('UTC'))
    );

    $result = $runner->runDue(true);
    if (($result['status'] ?? '') !== 'failed') {
        throw new RuntimeException('expected failed status');
    }
    if ($dumpCalled) {
        throw new RuntimeException('dump must not run when destination is missing');
    }
    if (count($notified) !== 1) {
        throw new RuntimeException('expected one Master notification');
    }

    $saved = $store->load();
    if (trim((string) ($saved['last_failure_message'] ?? '')) === '') {
        throw new RuntimeException('expected last_failure_message');
    }
    if (($saved['last_success_at'] ?? null) !== null) {
        throw new RuntimeException('success must not be recorded');
    }

    @unlink($storePath);
});

$run('writes organized dump, applies retention, and records success', static function (): void {
    $root = sys_get_temp_dir() . '/auto-backup-dest-' . bin2hex(random_bytes(4));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('unable to create destination');
    }

    $oldDir = $root . '/backups/2026/08';
    mkdir($oldDir, 0700, true);
    for ($i = 1; $i <= 3; $i++) {
        file_put_contents(sprintf('%s/demo_db_full_2026080%d_0200.sql.gz', $oldDir, $i), 'old');
    }

    $storePath = sys_get_temp_dir() . '/auto-backup-settings-' . bin2hex(random_bytes(4)) . '.json';
    $store = new AutomaticBackupSettingsStore($storePath);
    $store->save([
        'enabled' => true,
        'frequency' => 'daily',
        'weekly_days' => [],
        'time' => '02:00',
        'timezone' => 'Asia/Manila',
        'destination_path' => $root,
        'retention_count' => 2,
        'last_success_at' => null,
        'last_failure_at' => null,
        'last_failure_message' => null,
        'last_run_key' => null,
    ]);

    $tempDump = sys_get_temp_dir() . '/demo_dump_' . bin2hex(random_bytes(3)) . '.sql.gz';
    file_put_contents($tempDump, 'DUMPDATA');

    $runner = new AutomaticBackupRunner(
        $store,
        'demo_db',
        static fn (): array => [
            'path' => $tempDump,
            'filename' => basename($tempDump),
            'bytes' => (int) filesize($tempDump),
        ],
        static function (): void {
            throw new RuntimeException('should not notify on success');
        },
        static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-09 18:05:00', new DateTimeZone('UTC'))
    );

    $result = $runner->runDue(true);
    if (($result['status'] ?? '') !== 'success') {
        throw new RuntimeException('expected success, got ' . json_encode($result));
    }

    $expected = $root . '/backups/2026/09/demo_db_full_20260910_0205.sql.gz';
    if (!is_file($expected)) {
        throw new RuntimeException("missing organized dump at {$expected}");
    }
    if (file_get_contents($expected) !== 'DUMPDATA') {
        throw new RuntimeException('dump contents were not moved');
    }
    if (is_file($tempDump)) {
        throw new RuntimeException('temp dump should be removed after move');
    }

    $remaining = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/backups', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.sql.gz')) {
            $remaining[] = $file->getFilename();
        }
    }
    if (count($remaining) !== 2) {
        throw new RuntimeException('expected retention to leave 2 dumps, got ' . count($remaining));
    }

    $saved = $store->load();
    if (empty($saved['last_success_at'])) {
        throw new RuntimeException('expected last_success_at');
    }
    if (($saved['last_failure_message'] ?? null) !== null) {
        throw new RuntimeException('failure message should clear on success');
    }

    // cleanup
    $cleanup = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($cleanup as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
    @unlink($storePath);
});

echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
