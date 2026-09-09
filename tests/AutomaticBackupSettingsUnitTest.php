<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Services/AutomaticBackupSettings.php';

use App\Services\AutomaticBackupSettings;
use App\Support\Exceptions\HttpException;

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

echo "AutomaticBackupSettingsUnitTest\n";

$run('defaults are daily 02:00 Asia/Manila with retention 14 and disabled', static function (): void {
    $settings = AutomaticBackupSettings::defaults();
    if ($settings['enabled'] !== false) {
        throw new RuntimeException('expected enabled=false');
    }
    if ($settings['frequency'] !== 'daily') {
        throw new RuntimeException('expected daily');
    }
    if ($settings['time'] !== '02:00') {
        throw new RuntimeException('expected 02:00');
    }
    if ($settings['retention_count'] !== 14) {
        throw new RuntimeException('expected retention 14');
    }
    if (($settings['timezone'] ?? null) !== 'Asia/Manila') {
        throw new RuntimeException('expected Asia/Manila timezone');
    }
    if (($settings['destination_path'] ?? null) !== '') {
        throw new RuntimeException('expected empty destination');
    }
});

$run('rejects enable without a Backup Destination', static function (): void {
    $denied = false;
    try {
        AutomaticBackupSettings::normalizeAndValidate([
            'enabled' => true,
            'frequency' => 'daily',
            'time' => '02:00',
            'destination_path' => '',
            'retention_count' => 14,
        ]);
    } catch (HttpException $e) {
        $denied = $e->statusCode() === 422;
    }
    if (!$denied) {
        throw new RuntimeException('expected 422 when enabling without destination');
    }
});

$run('accepts enabled daily schedule with destination and custom retention', static function (): void {
    $normalized = AutomaticBackupSettings::normalizeAndValidate([
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '03:30',
        'destination_path' => '/Volumes/BackupDrive',
        'retention_count' => 7,
    ]);
    if ($normalized['enabled'] !== true) {
        throw new RuntimeException('expected enabled');
    }
    if ($normalized['time'] !== '03:30') {
        throw new RuntimeException('expected 03:30');
    }
    if ($normalized['destination_path'] !== '/Volumes/BackupDrive') {
        throw new RuntimeException('expected destination path');
    }
    if ($normalized['retention_count'] !== 7) {
        throw new RuntimeException('expected retention 7');
    }
    if ($normalized['timezone'] !== 'Asia/Manila') {
        throw new RuntimeException('timezone must stay Asia/Manila');
    }
});

$run('requires weekly_days when frequency is weekly', static function (): void {
    $denied = false;
    try {
        AutomaticBackupSettings::normalizeAndValidate([
            'enabled' => true,
            'frequency' => 'weekly',
            'weekly_days' => [],
            'time' => '02:00',
            'destination_path' => '/Volumes/USB',
            'retention_count' => 14,
        ]);
    } catch (HttpException $e) {
        $denied = $e->statusCode() === 422;
    }
    if (!$denied) {
        throw new RuntimeException('expected 422 for weekly without days');
    }
});

$run('accepts weekly schedule with days', static function (): void {
    $normalized = AutomaticBackupSettings::normalizeAndValidate([
        'enabled' => true,
        'frequency' => 'weekly',
        'weekly_days' => [1, 5],
        'time' => '02:00',
        'destination_path' => '/Volumes/USB',
        'retention_count' => 14,
    ]);
    if ($normalized['weekly_days'] !== [1, 5]) {
        throw new RuntimeException('expected weekly_days [1,5]');
    }
});

echo "Passed: {$passed}, Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
