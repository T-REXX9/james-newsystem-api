<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Response;
use App\Services\AutomaticBackupRunner;
use App\Services\AutomaticBackupSettings;
use App\Services\AutomaticBackupSettingsStore;
use App\Services\BackupDestinationLister;
use App\Services\CorporateDumpImportService;
use App\Services\CorporateDumpUploadStore;
use App\Services\DatabaseBackupService;
use App\Support\Exceptions\HttpException;

final class ServerMaintenanceController
{
    public function __construct(
        private readonly DatabaseBackupService $backupService,
        private readonly string $backupDirectory,
        private readonly AutomaticBackupSettingsStore $automaticBackupStore,
        private readonly BackupDestinationLister $destinationLister,
        private readonly AutomaticBackupRunner $automaticBackupRunner,
        private readonly CorporateDumpImportService $corporateDumpImportService,
        private readonly CorporateDumpUploadStore $corporateDumpUploadStore
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
            'automatic_backup' => $this->automaticBackupStore->load(),
            'corporate_dump_import' => [
                'supported_extensions' => ['.sql', '.sql.gz'],
                'max_bytes' => CorporateDumpUploadStore::MAX_BYTES,
                'chunk_max_bytes' => CorporateDumpUploadStore::CHUNK_MAX_BYTES,
                'safety' => 'Never drops, truncates, or deletes live data. Inserts new rows using shared columns; local-only tables and columns stay untouched. The only overwrite is the current product price (tblinventory_price / tblinventory) so updated prices from the imported system win.',
            ],
        ];
    }

    public function createCorporateDumpUpload(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);

        $filename = trim((string) ($body['filename'] ?? ''));
        $bytes = (int) ($body['bytes'] ?? 0);
        if ($filename === '') {
            throw new HttpException(422, 'filename is required');
        }

        try {
            return $this->corporateDumpUploadStore->createSession($filename, $bytes);
        } catch (\Throwable $error) {
            throw new HttpException(422, $error->getMessage());
        }
    }

    public function appendCorporateDumpChunk(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);

        $uploadId = trim((string) ($params['uploadId'] ?? ''));
        $chunk = $body['__raw_body'] ?? null;
        if (!is_string($chunk) || $chunk === '') {
            throw new HttpException(422, 'Chunk body is required');
        }

        try {
            return $this->corporateDumpUploadStore->appendChunk($uploadId, $chunk);
        } catch (\Throwable $error) {
            throw new HttpException(422, $error->getMessage());
        }
    }

    public function importCorporateDumpUpload(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);

        $uploadId = trim((string) ($params['uploadId'] ?? ''));
        $filename = null;
        $bytes = null;
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $finalized = $this->corporateDumpUploadStore->finalize($uploadId);
            $filename = $finalized['filename'];
            $bytes = $finalized['bytes'];
            $report = $this->corporateDumpImportService->importDumpFile($finalized['path']);
            $this->corporateDumpUploadStore->deleteSession($uploadId);
            return [
                'filename' => $finalized['filename'],
                'bytes' => $finalized['bytes'],
                'import' => $report,
            ];
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable $error) {
            error_log(json_encode([
                'event' => 'corporate_dump_import_failed',
                'upload_id' => $uploadId,
                'filename' => $filename,
                'bytes' => $bytes,
                'error_class' => $error::class,
                'error_message' => $error->getMessage(),
            ], JSON_UNESCAPED_SLASHES));
            throw new HttpException(500, $error->getMessage());
        }
    }

    public function getAutomaticBackup(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);
        return $this->automaticBackupStore->load();
    }

    public function updateAutomaticBackup(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);

        $existing = $this->automaticBackupStore->load();
        $normalized = AutomaticBackupSettings::normalizeAndValidate(array_merge($existing, $body, [
            // Preserve run metadata unless explicitly cleared by the runner.
            'last_success_at' => $existing['last_success_at'] ?? null,
            'last_failure_at' => $existing['last_failure_at'] ?? null,
            'last_failure_message' => $existing['last_failure_message'] ?? null,
            'last_run_key' => $existing['last_run_key'] ?? null,
        ]));

        $this->automaticBackupStore->save($normalized);
        return $normalized;
    }

    public function listBackupDestinations(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);
        return [
            'items' => $this->destinationLister->listWritableDestinations(),
        ];
    }

    public function runAutomaticBackup(array $params = [], array $query = [], array $body = []): array
    {
        $this->assertMasterUser($body);
        $force = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $this->automaticBackupRunner->runDue($force);
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
            throw new HttpException(403, 'Only the Master User can access server maintenance.');
        }
    }
}
