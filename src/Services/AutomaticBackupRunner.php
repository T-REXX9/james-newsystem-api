<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AutomaticBackupRunner
{
    /**
     * @param callable(): array{path: string, filename: string, bytes: int} $createDump
     * @param callable(string, string): void $notifyMaster
     * @param callable(): DateTimeImmutable $now
     */
    public function __construct(
        private readonly AutomaticBackupSettingsStore $store,
        private readonly string $databaseName,
        private readonly \Closure $createDump,
        private readonly \Closure $notifyMaster,
        private readonly \Closure $now
    ) {
    }

    /**
     * @return array{status: string, message?: string, path?: string}
     */
    public function runDue(bool $force = false): array
    {
        $settings = $this->store->load();
        if (!$force && !($settings['enabled'] ?? false)) {
            return ['status' => 'skipped', 'message' => 'Automatic Backup is disabled'];
        }

        $now = ($this->now)()->setTimezone(new DateTimeZone(AutomaticBackupSettings::TIMEZONE));
        if (!$force && !$this->isDue($settings, $now)) {
            return ['status' => 'skipped', 'message' => 'Automatic Backup is not due'];
        }

        $runKey = $now->format('Y-m-d') . 'T' . (string) ($settings['time'] ?? '02:00');
        if (!$force && ($settings['last_run_key'] ?? null) === $runKey) {
            return ['status' => 'skipped', 'message' => 'Automatic Backup already ran for this slot'];
        }

        $destination = trim((string) ($settings['destination_path'] ?? ''));
        if ($destination === '' || !is_dir($destination) || !is_writable($destination)) {
            return $this->fail(
                $settings,
                $runKey,
                'Backup Destination is missing, unmounted, or not writable.',
                $now,
                false
            );
        }

        $tempPath = null;
        try {
            $dump = ($this->createDump)();
            $tempPath = (string) ($dump['path'] ?? '');
            if ($tempPath === '' || !is_file($tempPath)) {
                throw new RuntimeException('Database dump produced no file');
            }

            $target = AutomaticBackupOrganizer::organizedDumpPath($destination, $this->databaseName, $now);
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Unable to create organized backup directory');
            }

            if (!@rename($tempPath, $target)) {
                if (!@copy($tempPath, $target)) {
                    throw new RuntimeException('Unable to write dump to Backup Destination');
                }
                @unlink($tempPath);
            }
            $tempPath = null;

            AutomaticBackupOrganizer::applyRetention(
                $destination,
                $this->databaseName,
                (int) ($settings['retention_count'] ?? 14)
            );

            $settings['last_success_at'] = $now->format('c');
            $settings['last_failure_at'] = null;
            $settings['last_failure_message'] = null;
            $settings['last_run_key'] = $runKey;
            $this->store->save($settings);

            return ['status' => 'success', 'path' => $target];
        } catch (\Throwable $error) {
            if (is_string($tempPath) && $tempPath !== '' && is_file($tempPath)) {
                @unlink($tempPath);
            }
            return $this->fail($settings, $runKey, $error->getMessage(), $now, true);
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{status: string, message: string}
     */
    private function fail(
        array $settings,
        string $runKey,
        string $message,
        DateTimeImmutable $now,
        bool $consumeRunSlot
    ): array {
        $settings['last_failure_at'] = $now->format('c');
        $settings['last_failure_message'] = $message;
        // Missing destination should remain retryable in the same schedule slot.
        if ($consumeRunSlot) {
            $settings['last_run_key'] = $runKey;
        }
        $this->store->save($settings);

        ($this->notifyMaster)(
            'Automatic Backup failed',
            $message
        );

        return ['status' => 'failed', 'message' => $message];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isDue(array $settings, DateTimeImmutable $now): bool
    {
        $time = (string) ($settings['time'] ?? '02:00');
        if ($now->format('H:i') !== $time) {
            return false;
        }

        $frequency = (string) ($settings['frequency'] ?? 'daily');
        if ($frequency === 'daily') {
            return true;
        }

        $isoDay = (int) $now->format('N'); // 1=Mon ... 7=Sun
        $days = is_array($settings['weekly_days'] ?? null) ? $settings['weekly_days'] : [];
        foreach ($days as $day) {
            if ((int) $day === $isoDay) {
                return true;
            }
        }

        return false;
    }
}
