<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use RuntimeException;

final class DatabaseBackupService
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Create a full logical MySQL dump (schema + data) as a gzipped SQL file.
     *
     * @return array{path: string, filename: string, bytes: int}
     */
    public function createFullDumpGzipFile(?string $directory = null): array
    {
        $dbName = trim($this->config->dbName);
        if ($dbName === '') {
            throw new RuntimeException('Database name is not configured');
        }

        $directory = $directory ?: sys_get_temp_dir();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create backup directory');
        }

        $filename = sprintf('%s_full_%s.sql.gz', $this->safeFilenamePart($dbName), date('Ymd_His'));
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $command = [
            $this->resolveMysqldumpBinary(),
            '--host=' . $this->config->dbHost,
            '--port=' . (string) $this->config->dbPort,
            '--user=' . $this->config->dbUser,
            '--single-transaction',
            '--skip-lock-tables',
            '--no-tablespaces',
            '--set-gtid-purged=OFF',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            $dbName,
        ];

        $env = $this->buildProcessEnv();
        $dumpProcess = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $dumpPipes,
            null,
            $env
        );
        if (!is_resource($dumpProcess)) {
            throw new RuntimeException('Unable to start mysqldump');
        }

        fclose($dumpPipes[0]);

        $gzipProcess = proc_open(
            ['gzip', '-c'],
            [
                0 => $dumpPipes[1],
                1 => ['file', $path, 'wb'],
                2 => ['pipe', 'w'],
            ],
            $gzipPipes,
            null,
            $env
        );
        if (!is_resource($gzipProcess)) {
            fclose($dumpPipes[1]);
            fclose($dumpPipes[2]);
            proc_close($dumpProcess);
            throw new RuntimeException('Unable to start gzip for database backup');
        }

        stream_set_blocking($dumpPipes[2], false);
        stream_set_blocking($gzipPipes[2], false);

        $dumpStderr = '';
        $gzipStderr = '';
        $dumpExit = null;
        $gzipExit = null;

        do {
            $dumpStatus = proc_get_status($dumpProcess);
            $gzipStatus = proc_get_status($gzipProcess);

            if (!$dumpStatus['running'] && $dumpExit === null) {
                $dumpExit = (int) $dumpStatus['exitcode'];
            }
            if (!$gzipStatus['running'] && $gzipExit === null) {
                $gzipExit = (int) $gzipStatus['exitcode'];
            }

            $dumpChunk = fread($dumpPipes[2], 8192);
            if (is_string($dumpChunk) && $dumpChunk !== '') {
                $dumpStderr .= $dumpChunk;
            }
            $gzipChunk = fread($gzipPipes[2], 8192);
            if (is_string($gzipChunk) && $gzipChunk !== '') {
                $gzipStderr .= $gzipChunk;
            }

            if ($dumpStatus['running'] || $gzipStatus['running']) {
                usleep(20000);
            }
        } while ($dumpStatus['running'] || $gzipStatus['running']);

        $remainingDumpErr = stream_get_contents($dumpPipes[2]);
        if (is_string($remainingDumpErr) && $remainingDumpErr !== '') {
            $dumpStderr .= $remainingDumpErr;
        }
        fclose($dumpPipes[2]);

        $remainingGzipErr = stream_get_contents($gzipPipes[2]);
        if (is_string($remainingGzipErr) && $remainingGzipErr !== '') {
            $gzipStderr .= $remainingGzipErr;
        }
        fclose($gzipPipes[2]);

        proc_close($gzipProcess);
        proc_close($dumpProcess);

        if (($dumpExit ?? 1) !== 0 || ($gzipExit ?? 1) !== 0 || !is_file($path)) {
            @unlink($path);
            throw new RuntimeException(
                'Database dump failed'
                . ($dumpStderr !== '' ? ': ' . trim($dumpStderr) : '')
                . ($gzipStderr !== '' ? ' | gzip: ' . trim($gzipStderr) : '')
            );
        }

        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0) {
            @unlink($path);
            throw new RuntimeException('Database dump produced no data');
        }

        return [
            'path' => $path,
            'filename' => $filename,
            'bytes' => (int) $bytes,
        ];
    }

    public function databaseName(): string
    {
        return trim($this->config->dbName);
    }

    private function resolveMysqldumpBinary(): string
    {
        $configured = trim((string) (\App\Support\Env::get('MYSQLDUMP_PATH', '')));
        if ($configured !== '') {
            return $configured;
        }

        return 'mysqldump';
    }

    /**
     * @return array<string, string>
     */
    private function buildProcessEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $env[$key] = (string) $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value)) && !array_key_exists($key, $env)) {
                $env[$key] = (string) $value;
            }
        }

        // Prefer MYSQL_PWD so the password is not exposed on the process argv.
        $env['MYSQL_PWD'] = $this->config->dbPass;
        $env['PATH'] = $env['PATH'] ?? (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin');

        return $env;
    }

    private function safeFilenamePart(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value) ?? 'database';
        $trimmed = trim($safe, '._-');
        return $trimmed !== '' ? $trimmed : 'database';
    }
}
