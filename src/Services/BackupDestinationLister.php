<?php

declare(strict_types=1);

namespace App\Services;

final class BackupDestinationLister
{
    /**
     * @param list<string> $candidateRoots Directories whose immediate children are treated as volumes.
     */
    public function __construct(private readonly array $candidateRoots)
    {
    }

    public static function defaultRoots(): array
    {
        $roots = [];
        if (is_dir('/Volumes')) {
            $roots[] = '/Volumes';
        }
        if (is_dir('/media')) {
            $roots[] = '/media';
        }
        if (is_dir('/mnt')) {
            $roots[] = '/mnt';
        }
        // Allow an explicit override for deployments that mount drives elsewhere.
        $extra = trim((string) (\App\Support\Env::get('BACKUP_DESTINATION_ROOTS', '')));
        if ($extra !== '') {
            foreach (explode(',', $extra) as $piece) {
                $path = trim($piece);
                if ($path !== '') {
                    $roots[] = $path;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @return list<array{id: string, label: string, path: string}>
     */
    public function listWritableDestinations(): array
    {
        $items = [];
        $seen = [];

        foreach ($this->candidateRoots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $entries = @scandir($root);
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                // Skip macOS system volume noise.
                if (in_array($entry, ['Macintosh HD', 'Macintosh HD - Data', '.timemachine', '.Spotlight-V100'], true)) {
                    continue;
                }
                $path = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $entry;
                $this->maybeAdd($path, $entry, $items, $seen);
            }
        }

        usort($items, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));
        return $items;
    }

    /**
     * @param list<array{id: string, label: string, path: string}> $items
     * @param array<string, true> $seen
     */
    private function maybeAdd(string $path, string $label, array &$items, array &$seen): void
    {
        if (!is_dir($path) || !is_writable($path)) {
            return;
        }
        $real = realpath($path) ?: $path;
        if (isset($seen[$real])) {
            return;
        }
        $seen[$real] = true;
        $items[] = [
            'id' => $real,
            'label' => $label,
            'path' => $real,
        ];
    }
}
