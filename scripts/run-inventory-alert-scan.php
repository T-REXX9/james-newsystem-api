<?php

declare(strict_types=1);

/**
 * Cron entrypoint for inventory alert scanning.
 *
 * Scans for out-of-stock, critical-stock, and expired inventory items and
 * dispatches notifications to relevant recipients (Warehouse, Owner, etc.).
 *
 * Example crontab (runs every 6 hours):
 *   0 0,6,12,18 * * * php /path/to/api/scripts/run-inventory-alert-scan.php >> /path/to/api/storage/inventory-alert-scan.log 2>&1
 */

require dirname(__DIR__) . '/src/Support/Env.php';
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Repositories/NotificationsRepository.php';

use App\Config;
use App\Database;
use App\Repositories\NotificationsRepository;
use App\Support\Env;

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Asia/Manila'));

$config = new Config(
    (string) Env::get('APP_ENV', 'production'),
    filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    (string) Env::get('APP_ALLOWED_ORIGIN', '*'),
    (string) Env::get('AUTH_SECRET', (string) Env::get('APP_KEY', 'change-me-in-env')),
    (int) Env::get('AUTH_TOKEN_TTL_SECONDS', 315360000),
    (string) Env::get('DB_HOST', '127.0.0.1'),
    (int) Env::get('DB_PORT', 3306),
    (string) Env::get('DB_NAME', ''),
    (string) Env::get('DB_USER', ''),
    (string) Env::get('DB_PASS', '')
);

$db = new Database($config);
$notifications = new NotificationsRepository($db);

try {
    $result = $notifications->scanInventoryAlerts();
    fwrite(STDOUT, json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'result' => $result,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, json_encode([
        'status' => 'failed',
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
