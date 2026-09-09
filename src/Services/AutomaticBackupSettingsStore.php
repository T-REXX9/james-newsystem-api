<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class AutomaticBackupSettingsStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!is_file($this->path)) {
            return AutomaticBackupSettings::defaults();
        }

        $json = file_get_contents($this->path);
        if ($json === false || trim($json) === '') {
            return AutomaticBackupSettings::defaults();
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return AutomaticBackupSettings::defaults();
        }

        return array_merge(AutomaticBackupSettings::defaults(), $decoded, [
            'timezone' => AutomaticBackupSettings::TIMEZONE,
        ]);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create Automatic Backup settings directory');
        }

        $payload = array_merge(AutomaticBackupSettings::defaults(), $settings, [
            'timezone' => AutomaticBackupSettings::TIMEZONE,
        ]);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode Automatic Backup settings');
        }

        $tmp = $this->path . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Unable to write Automatic Backup settings');
        }
        rename($tmp, $this->path);
    }
}
