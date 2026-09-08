<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Env.php';
require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Http/Response.php';
require __DIR__ . '/../src/Services/DatabaseBackupService.php';
require __DIR__ . '/../src/Controllers/ServerMaintenanceController.php';

use App\Config;
use App\Controllers\ServerMaintenanceController;
use App\Services\DatabaseBackupService;
use App\Support\Exceptions\HttpException;

$config = new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'demo_db', 'user', 'pass');
$controller = new ServerMaintenanceController(new DatabaseBackupService($config), sys_get_temp_dir());

$denied = false;
try {
    $controller->status([], [], ['__auth_claims' => ['user_type' => '2']]);
} catch (HttpException $e) {
    $denied = $e->statusCode() === 403;
}
if (!$denied) {
    fwrite(STDERR, "Expected non-master status call to be forbidden\n");
    exit(1);
}

$downloadDenied = false;
try {
    $controller->downloadDatabaseBackup([], [], ['__auth_claims' => ['user_type' => '2']]);
} catch (HttpException $e) {
    $downloadDenied = $e->statusCode() === 403;
}
if (!$downloadDenied) {
    fwrite(STDERR, "Expected non-master download call to be forbidden\n");
    exit(1);
}

$status = $controller->status([], [], ['__auth_claims' => ['user_type' => '1', 'sub' => 1]]);
if (($status['database_name'] ?? null) !== 'demo_db' || ($status['backup_available'] ?? false) !== true) {
    fwrite(STDERR, "Unexpected status payload: " . json_encode($status) . "\n");
    exit(1);
}

fwrite(STDOUT, "ServerMaintenanceControllerUnitTest passed\n");
