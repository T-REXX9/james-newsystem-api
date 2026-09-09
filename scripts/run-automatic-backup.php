<?php

declare(strict_types=1);

/**
 * Cron entrypoint for Automatic Backup.
 *
 * Example crontab (Asia/Manila wall clock; PHP uses APP_TIMEZONE):
 *   * * * * * php /path/to/api/scripts/run-automatic-backup.php >> /path/to/api/storage/automatic-backup.log 2>&1
 */

require dirname(__DIR__) . '/src/Support/Env.php';
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Services/DatabaseBackupService.php';
require dirname(__DIR__) . '/src/Services/AutomaticBackupSettings.php';
require dirname(__DIR__) . '/src/Services/AutomaticBackupSettingsStore.php';
require dirname(__DIR__) . '/src/Services/AutomaticBackupOrganizer.php';
require dirname(__DIR__) . '/src/Services/AutomaticBackupRunner.php';
require dirname(__DIR__) . '/src/Services/AutomaticBackupNotifier.php';
require dirname(__DIR__) . '/src/Repositories/NotificationsRepository.php';

use App\Config;
use App\Database;
use App\Services\AutomaticBackupNotifier;
use App\Services\AutomaticBackupRunner;
use App\Services\AutomaticBackupSettingsStore;
use App\Services\DatabaseBackupService;
use App\Support\Env;

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Manila'));

$config = new Config(
    (string) Env::get('APP_ENV', 'production'),
    filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    (string) Env::get('APP_ALLOWED_ORIGIN', '*'),
    (string) Env::get('AUTH_SECRET', (string) Env::get('APP_KEY', 'change-me-in-env')),
    (int) Env::get('AUTH_TOKEN_TTL_SECONDS', 28800),
    (string) Env::get('DB_HOST', '127.0.0.1'),
    (int) Env::get('DB_PORT', 3306),
    (string) Env::get('DB_NAME', ''),
    (string) Env::get('DB_USER', ''),
    (string) Env::get('DB_PASS', '')
);

$db = new Database($config);
$backupService = new DatabaseBackupService($config);
$store = new AutomaticBackupSettingsStore(dirname(__DIR__) . '/storage/automatic-backup-settings.json');
$notifier = new AutomaticBackupNotifier($db);
$tempDir = dirname(__DIR__) . '/storage/database-backups';

$runner = new AutomaticBackupRunner(
    $store,
    $backupService->databaseName(),
    static fn (): array => $backupService->createFullDumpGzipFile($tempDir),
    static function (string $title, string $message) use ($notifier): void {
        $notifier->notifyMasters($title, $message);
    },
    static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
);

$force = in_array('--force', $argv, true);
$result = $runner->runDue($force);
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit(($result['status'] ?? '') === 'failed' ? 1 : 0);
