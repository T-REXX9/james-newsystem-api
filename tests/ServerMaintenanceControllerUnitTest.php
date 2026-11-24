<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Env.php';
require __DIR__ . '/../src/Support/Exceptions/HttpException.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Http/Response.php';
require __DIR__ . '/../src/Services/DatabaseBackupService.php';
require __DIR__ . '/../src/Services/AutomaticBackupSettings.php';
require __DIR__ . '/../src/Services/AutomaticBackupSettingsStore.php';
require __DIR__ . '/../src/Services/AutomaticBackupOrganizer.php';
require __DIR__ . '/../src/Services/AutomaticBackupRunner.php';
require __DIR__ . '/../src/Services/BackupDestinationLister.php';
require __DIR__ . '/../src/Services/CorporateDumpImportService.php';
require __DIR__ . '/../src/Services/CorporateDumpUploadStore.php';
require __DIR__ . '/../src/Services/FreightChargesBackupService.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Controllers/ServerMaintenanceController.php';

use App\Config;
use App\Controllers\ServerMaintenanceController;
use App\Services\AutomaticBackupRunner;
use App\Services\AutomaticBackupSettingsStore;
use App\Services\BackupDestinationLister;
use App\Services\CorporateDumpImportService;
use App\Services\CorporateDumpUploadStore;
use App\Services\DatabaseBackupService;
use App\Services\FreightChargesBackupService;
use App\Database;
use App\Support\Exceptions\HttpException;

$config = new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'demo_db', 'user', 'pass');
$storePath = sys_get_temp_dir() . '/sm-auto-backup-' . bin2hex(random_bytes(3)) . '.json';
$store = new AutomaticBackupSettingsStore($storePath);
$backupService = new DatabaseBackupService($config);
$runner = new AutomaticBackupRunner(
    $store,
    $backupService->databaseName(),
    static fn (): array => ['path' => '', 'filename' => '', 'bytes' => 0],
    static function (): void {},
    static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
);
$controller = new ServerMaintenanceController(
    $backupService,
    sys_get_temp_dir(),
    $store,
    new BackupDestinationLister([]),
    $runner,
    new CorporateDumpImportService($config),
    new CorporateDumpUploadStore(sys_get_temp_dir() . '/sm-corporate-import-' . bin2hex(random_bytes(3))),
    new FreightChargesBackupService(new Database($config)),
    new CorporateDumpUploadStore(sys_get_temp_dir() . '/sm-freight-import-' . bin2hex(random_bytes(3)), ['.jsonl.gz'])
);

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

$freightDownloadDenied = false;
try {
    $controller->downloadFreightChargesBackup([], [], ['__auth_claims' => ['user_type' => '2']]);
} catch (HttpException $e) {
    $freightDownloadDenied = $e->statusCode() === 403;
}
if (!$freightDownloadDenied) {
    fwrite(STDERR, "Expected non-master Freight Charges backup call to be forbidden\n");
    exit(1);
}

$freightUploadDenied = false;
try {
    $controller->createFreightChargesDumpUpload([], [], [
        '__auth_claims' => ['user_type' => '2'],
        'filename' => 'freight.jsonl.gz',
        'bytes' => 1,
    ]);
} catch (HttpException $e) {
    $freightUploadDenied = $e->statusCode() === 403;
}

$freightRestoreDenied = false;
try {
    $controller->restoreFreightChargesDumpUpload(['uploadId' => str_repeat('a', 32)], [], ['__auth_claims' => ['user_type' => '2']]);
} catch (HttpException $e) {
    $freightRestoreDenied = $e->statusCode() === 403;
}
if (!$freightRestoreDenied) {
    fwrite(STDERR, "Expected non-master Freight Charges restore call to be forbidden\n");
    exit(1);
}
if (!$freightUploadDenied) {
    fwrite(STDERR, "Expected non-master Freight Charges restore call to be forbidden\n");
    exit(1);
}

$settingsDenied = false;
try {
    $controller->getAutomaticBackup([], [], ['__auth_claims' => ['user_type' => '2']]);
} catch (HttpException $e) {
    $settingsDenied = $e->statusCode() === 403;
}
if (!$settingsDenied) {
    fwrite(STDERR, "Expected non-master automatic-backup call to be forbidden\n");
    exit(1);
}

$destDenied = false;
try {
    $controller->listBackupDestinations([], [], ['__auth_claims' => ['user_type' => '2']]);
} catch (HttpException $e) {
    $destDenied = $e->statusCode() === 403;
}
if (!$destDenied) {
    fwrite(STDERR, "Expected non-master destinations call to be forbidden\n");
    exit(1);
}

$enableDenied = false;
try {
    $controller->updateAutomaticBackup([], [], [
        '__auth_claims' => ['user_type' => '1', 'sub' => 1],
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '02:00',
        'destination_path' => '',
        'retention_count' => 14,
    ]);
} catch (HttpException $e) {
    $enableDenied = $e->statusCode() === 422;
}
if (!$enableDenied) {
    fwrite(STDERR, "Expected enable without destination to be rejected\n");
    exit(1);
}

$status = $controller->status([], [], ['__auth_claims' => ['user_type' => '1', 'sub' => 1]]);
if (($status['database_name'] ?? null) !== 'demo_db' || ($status['backup_available'] ?? false) !== true) {
    fwrite(STDERR, "Unexpected status payload: " . json_encode($status) . "\n");
    exit(1);
}
if (!isset($status['automatic_backup']) || !is_array($status['automatic_backup'])) {
    fwrite(STDERR, "Expected automatic_backup on status payload\n");
    exit(1);
}
if (($status['freight_charges_backup']['supported_extension'] ?? null) !== '.jsonl.gz') {
    fwrite(STDERR, "Expected Freight Charges backup format on status payload\n");
    exit(1);
}

@unlink($storePath);
fwrite(STDOUT, "ServerMaintenanceControllerUnitTest passed\n");
