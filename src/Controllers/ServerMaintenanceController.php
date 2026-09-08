<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Services\DatabaseBackupService;
use App\Support\Exceptions\HttpException;

final class ServerMaintenanceController
{
    public function __construct(
        private readonly DatabaseBackupService $backupService,
        private readonly string $backupDirectory
    ) {
    }

    public function status(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);

        return [
            'database_name' => $this->backupService->databaseName(),
            'backup_available' => $this->backupService->databaseName() !== '',
            'format' => 'sql.gz',
            'description' => 'Full logical dump of the application database (schema, data, routines, triggers, and events).',
        ];
    }

    /**
     * Builds a full dump, then streams it as a download. Returns null after
     * headers are sent so the router does not wrap the binary response as JSON.
     */
    public function downloadDatabaseBackup(array $params = [], array $query = [], array $body = []): ?array
    {
        $this->assertMasterUser($body);

        if ($this->backupService->databaseName() === '') {
            throw new HttpException(500, 'Database name is not configured');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $dump = $this->backupService->createFullDumpGzipFile($this->backupDirectory);
        } catch (\Throwable $error) {
            throw new HttpException(500, $error->getMessage());
        }

        $path = $dump['path'];
        $filename = $dump['filename'];
        $bytes = $dump['bytes'];

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            @unlink($path);
            throw new HttpException(500, 'Unable to read generated database backup');
        }

        http_response_code(200);
        header('Content-Type: application/gzip');
        header('Content-Length: ' . (string) $bytes);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

        Response::flush();

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 64);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                Response::flush();
            }
        } finally {
            fclose($handle);
            @unlink($path);
        }

        return null;
    }

    private function assertMasterUser(array $body): void
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        if ((string) ($claims['user_type'] ?? '') !== '1') {
            throw new HttpException(403, 'Only the Master User can access server maintenance backups.');
        }
    }
}
