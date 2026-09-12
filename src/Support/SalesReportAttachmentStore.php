<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exceptions\HttpException;

final class SalesReportAttachmentStore
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    private const ALLOWED_MIME = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

    public static function storageDirectory(): string
    {
        return dirname(__DIR__, 2) . '/storage/call-report-attachments';
    }

    public static function ensureStorageDirectory(): string
    {
        $dir = self::storageDirectory();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new HttpException(500, 'Unable to create attachment storage.');
        }
        return $dir;
    }

    public static function sanitizeFilename(string $filename): string
    {
        $base = basename(str_replace(["\0", '\\'], '', $filename));
        if ($base === '' || $base === '.' || $base === '..') {
            throw new HttpException(422, 'Invalid attachment filename.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $base)) {
            throw new HttpException(422, 'Invalid attachment filename.');
        }
        return $base;
    }

    public static function assertBelongsToContact(string $filename, string $contactId): void
    {
        $safeContact = preg_replace('/[^a-zA-Z0-9_-]/', '', $contactId) ?: 'contact';
        $sanitized = self::sanitizeFilename($filename);
        if (!str_starts_with($sanitized, $safeContact . '_')) {
            throw new HttpException(403, 'Attachment does not belong to this customer.');
        }
    }

    /** @return array{binary: string, mime: string, ext: string} */
    public static function decodeImageData(string $imageData): array
    {
        $matches = [];
        $mime = 'image/jpeg';
        $binary = '';

        if (str_starts_with($imageData, 'data:')) {
            if (!preg_match('/^data:(image\/(jpeg|jpg|png|webp));base64,(.+)$/i', $imageData, $matches)) {
                throw new HttpException(422, 'Only JPG, PNG, or WebP pictures are allowed.');
            }
            $mime = strtolower($matches[1]);
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }
            $binary = base64_decode($matches[3], true) ?: '';
        } else {
            $binary = base64_decode($imageData, true) ?: '';
        }

        if ($binary === '') {
            throw new HttpException(422, 'Picture data is invalid.');
        }
        if (strlen($binary) > self::MAX_BYTES) {
            throw new HttpException(422, 'Pictures must be 5 MB or smaller.');
        }
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new HttpException(422, 'Only JPG, PNG, or WebP pictures are allowed.');
        }

        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        return ['binary' => $binary, 'mime' => $mime, 'ext' => $ext];
    }

    public static function assertImageMime(string $mime): void
    {
        if ($mime !== '' && !in_array(strtolower($mime), self::ALLOWED_MIME, true)) {
            throw new HttpException(422, 'Only JPG, PNG, or WebP pictures are allowed.');
        }
    }

    public static function buildApiPath(string $contactId, string $filename): string
    {
        return '/api/v1/daily-call-monitoring/customers/'
            . rawurlencode($contactId)
            . '/sales-report-attachments/'
            . rawurlencode($filename);
    }

    public static function absolutePath(string $filename): string
    {
        return self::storageDirectory() . '/' . self::sanitizeFilename($filename);
    }

    public static function mimeFromFilename(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
