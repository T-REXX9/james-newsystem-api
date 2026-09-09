<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AutomaticBackupOrganizer
{
    public const TIMEZONE = 'Asia/Manila';

    public static function organizedDumpPath(string $destinationRoot, string $databaseName, ?DateTimeImmutable $at = null): string
    {
        $at = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(self::TIMEZONE));

        $safeDb = self::safeFilenamePart($databaseName);
        $relative = sprintf(
            'backups/%s/%s/%s_full_%s.sql.gz',
            $at->format('Y'),
            $at->format('m'),
            $safeDb,
            $at->format('Ymd_Hi')
        );

        return rtrim($destinationRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Keep the newest $keepCount Automatic Backup dumps for $databaseName under the destination.
     *
     * @return list<string> removed absolute paths
     */
    public static function applyRetention(string $destinationRoot, string $databaseName, int $keepCount): array
    {
        if ($keepCount < 1) {
            throw new RuntimeException('Retention keep count must be at least 1');
        }

        $safeDb = self::safeFilenamePart($databaseName);
        $pattern = '/^' . preg_quote($safeDb, '/') . '_full_\d{8}_\d{4}\.sql\.gz$/';
        $backupsRoot = rtrim($destinationRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($backupsRoot)) {
            return [];
        }

        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupsRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $name = $fileInfo->getFilename();
            if (!preg_match($pattern, $name)) {
                continue;
            }
            $matches[] = $fileInfo->getPathname();
        }

        rsort($matches, SORT_STRING);

        $removed = [];
        foreach (array_slice($matches, $keepCount) as $path) {
            if (@unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }

    private static function safeFilenamePart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?? 'database';
        $trimmed = trim($safe, '._-');
        return $trimmed !== '' ? $trimmed : 'database';
    }
}
