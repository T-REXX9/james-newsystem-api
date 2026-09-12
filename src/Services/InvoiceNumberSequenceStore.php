<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\InvoiceNumberSequence;
use RuntimeException;

final class InvoiceNumberSequenceStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array{prefix: string, pad_width: int, next_number: int, next_invoice_no: string}
     */
    public function loadForMain(int $mainId): array
    {
        $all = $this->loadAll();
        $key = (string) $mainId;
        $row = is_array($all[$key] ?? null) ? $all[$key] : [];
        return InvoiceNumberSequence::normalize($row);
    }

    public function hasMain(int $mainId): bool
    {
        $all = $this->loadAll();
        $row = $all[(string) $mainId] ?? null;
        return is_array($row);
    }

    /**
     * @param array{prefix: string, pad_width: int, next_number: int} $settings
     * @return array{prefix: string, pad_width: int, next_number: int, next_invoice_no: string}
     */
    public function saveForMain(int $mainId, array $settings): array
    {
        $normalized = InvoiceNumberSequence::normalize($settings);
        $all = $this->loadAll();
        $all[(string) $mainId] = [
            'prefix' => $normalized['prefix'],
            'pad_width' => $normalized['pad_width'],
            'next_number' => $normalized['next_number'],
        ];
        $this->persist($all);
        return $normalized;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadAll(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $json = file_get_contents($this->path);
        if ($json === false || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, mixed>> $all
     */
    private function persist(array $all): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create invoice number sequence directory');
        }

        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode invoice number sequence settings');
        }

        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Unable to write invoice number sequence settings');
        }
        if (!rename($tmp, $this->path)) {
            throw new RuntimeException('Unable to publish invoice number sequence settings');
        }
    }
}
