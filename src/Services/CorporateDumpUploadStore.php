<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Stores chunked corporate dump uploads under a private storage directory.
 */
final class CorporateDumpUploadStore
{
    public const MAX_BYTES = 2147483648; // 2 GiB
    public const CHUNK_MAX_BYTES = 2 * 1024 * 1024; // 2 MiB

    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create corporate dump upload directory');
        }
    }

    /**
     * @return array{upload_id: string, filename: string, bytes_expected: int, path: string}
     */
    public function createSession(string $filename, int $bytesExpected): array
    {
        if ($bytesExpected <= 0 || $bytesExpected > self::MAX_BYTES) {
            throw new RuntimeException('Dump size must be between 1 byte and 2 GiB');
        }

        $safeName = $this->safeFilename($filename);
        if (!preg_match('/\.(sql|sql\.gz)$/i', $safeName)) {
            throw new RuntimeException('Only .sql or .sql.gz dumps are supported');
        }

        $uploadId = bin2hex(random_bytes(16));
        $path = $this->pathFor($uploadId, $safeName);
        $metaPath = $this->metaPathFor($uploadId);

        if (file_put_contents($path, '') === false) {
            throw new RuntimeException('Unable to create upload file');
        }
        $meta = [
            'upload_id' => $uploadId,
            'filename' => $safeName,
            'bytes_expected' => $bytesExpected,
            'bytes_received' => 0,
            'created_at' => date('c'),
            'path' => $path,
        ];
        if (file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES)) === false) {
            @unlink($path);
            throw new RuntimeException('Unable to create upload metadata');
        }

        return [
            'upload_id' => $uploadId,
            'filename' => $safeName,
            'bytes_expected' => $bytesExpected,
            'path' => $path,
        ];
    }

    /**
     * @return array{upload_id: string, bytes_received: int, bytes_expected: int, complete: bool}
     */
    public function appendChunk(string $uploadId, string $chunk): array
    {
        $meta = $this->readMeta($uploadId);
        $chunkBytes = strlen($chunk);
        if ($chunkBytes <= 0 || $chunkBytes > self::CHUNK_MAX_BYTES) {
            throw new RuntimeException('Each chunk must be between 1 byte and 2 MiB');
        }

        $nextTotal = (int) $meta['bytes_received'] + $chunkBytes;
        if ($nextTotal > (int) $meta['bytes_expected']) {
            throw new RuntimeException('Upload exceeded the declared dump size');
        }

        $path = (string) $meta['path'];
        $written = file_put_contents($path, $chunk, FILE_APPEND);
        if ($written === false || $written !== $chunkBytes) {
            throw new RuntimeException('Unable to write upload chunk');
        }

        $meta['bytes_received'] = $nextTotal;
        $this->writeMeta($uploadId, $meta);

        return [
            'upload_id' => $uploadId,
            'bytes_received' => $nextTotal,
            'bytes_expected' => (int) $meta['bytes_expected'],
            'complete' => $nextTotal === (int) $meta['bytes_expected'],
        ];
    }

    /**
     * @return array{upload_id: string, filename: string, path: string, bytes: int}
     */
    public function finalize(string $uploadId): array
    {
        $meta = $this->readMeta($uploadId);
        $path = (string) $meta['path'];
        if (!is_file($path)) {
            throw new RuntimeException('Upload file is missing');
        }
        $size = filesize($path);
        if ($size === false || $size !== (int) $meta['bytes_expected']) {
            throw new RuntimeException('Upload is incomplete');
        }

        return [
            'upload_id' => $uploadId,
            'filename' => (string) $meta['filename'],
            'path' => $path,
            'bytes' => (int) $size,
        ];
    }

    public function deleteSession(string $uploadId): void
    {
        $metaPath = $this->metaPathFor($uploadId);
        if (is_file($metaPath)) {
            $meta = $this->readMeta($uploadId);
            $path = (string) ($meta['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            @unlink($metaPath);
            return;
        }
    }

    private function pathFor(string $uploadId, string $filename): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $uploadId
            . '_'
            . $filename;
    }

    private function metaPathFor(string $uploadId): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $uploadId
            . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readMeta(string $uploadId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            throw new RuntimeException('Invalid upload id');
        }
        $metaPath = $this->metaPathFor($uploadId);
        if (!is_file($metaPath)) {
            throw new RuntimeException('Upload session not found');
        }
        $decoded = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Upload metadata is corrupt');
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeMeta(string $uploadId, array $meta): void
    {
        $metaPath = $this->metaPathFor($uploadId);
        if (file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Unable to update upload metadata');
        }
    }

    private function safeFilename(string $filename): string
    {
        $base = basename(str_replace(["\0", '\\'], '', $filename));
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?? 'dump.sql';
        $trimmed = trim($safe, '._-');
        return $trimmed !== '' ? $trimmed : 'dump.sql';
    }
}
