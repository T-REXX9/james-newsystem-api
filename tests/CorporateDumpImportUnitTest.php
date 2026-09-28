<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Env.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Services/CorporateDumpImportService.php';
require __DIR__ . '/../src/Services/CorporateDumpUploadStore.php';

use App\Services\CorporateDumpImportService;
use App\Services\CorporateDumpUploadStore;
use App\Config;

$passed = 0;
$failed = 0;
$run = static function (string $name, callable $test) use (&$passed, &$failed): void {
    try {
        $test();
        $passed++;
        echo "  PASS {$name}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "  FAIL {$name}: {$error->getMessage()}\n";
    }
};

echo "CorporateDumpImportUnitTest\n";

$run('transformDumpLine rewrites CURDATE defaults', static function (): void {
    $line = '  `ldate` date DEFAULT curdate(),';
    $out = CorporateDumpImportService::transformDumpLine($line);
    if (!str_contains($out, 'DEFAULT NULL')) {
        throw new RuntimeException('expected DEFAULT NULL, got: ' . $out);
    }
});

$run('buildMergeSql inserts only new rows when shared non-primary columns exist', static function (): void {
    $plan = CorporateDumpImportService::buildMergeSql(
        'target_db',
        'source_db',
        'tblpatient',
        ['lid', 'lname', 'lphone']
    );
    if ($plan === null || $plan['mode'] !== 'insert_ignore') {
        throw new RuntimeException('expected insert_ignore plan');
    }
    if (!str_contains($plan['sql'], 'INSERT IGNORE')) {
        throw new RuntimeException('expected INSERT IGNORE SQL');
    }
    if (str_contains($plan['sql'], 'ON DUPLICATE KEY UPDATE')) {
        throw new RuntimeException('existing rows must not be updated');
    }
});

$run('buildMergeSql uses INSERT IGNORE when only primary keys are shared', static function (): void {
    $plan = CorporateDumpImportService::buildMergeSql(
        'target_db',
        'source_db',
        'tblx',
        ['lid']
    );
    if ($plan === null || $plan['mode'] !== 'insert_ignore') {
        throw new RuntimeException('expected insert_ignore');
    }
    if (!str_contains($plan['sql'], 'INSERT IGNORE')) {
        throw new RuntimeException('expected INSERT IGNORE SQL');
    }
});

$run('tblinventory_price upserts only the current price amount on collision', static function (): void {
    $plan = CorporateDumpImportService::buildMergeSql(
        'target_db',
        'source_db',
        'tblinventory_price',
        ['lid', 'lrefno', 'linv_refno', 'lprice_name', 'lprice_amt', 'lprice_amt_old'],
        ['lprice_amt']
    );
    if ($plan === null || $plan['mode'] !== 'upsert') {
        throw new RuntimeException('expected upsert plan for tblinventory_price');
    }
    if (!str_contains($plan['sql'], 'ON DUPLICATE KEY UPDATE')) {
        throw new RuntimeException('expected ON DUPLICATE KEY UPDATE');
    }
    if (!str_contains($plan['sql'], '`lprice_amt` = VALUES(`lprice_amt`)')) {
        throw new RuntimeException('expected lprice_amt to be refreshed');
    }
    // Only the current amount is refreshed -- the previous-price column stays.
    if (str_contains($plan['sql'], 'lprice_amt_old` = VALUES')) {
        throw new RuntimeException('lprice_amt_old must NOT be overwritten');
    }
    if (str_contains($plan['sql'], 'INSERT IGNORE')) {
        throw new RuntimeException('upsert must not be INSERT IGNORE');
    }
});

$run('tblinventory upserts legacy denormalized price columns on collision', static function (): void {
    $plan = CorporateDumpImportService::buildMergeSql(
        'target_db',
        'source_db',
        'tblinventory',
        ['lid', 'lsku', 'lprice', 'lsuppprice', 'lquantity'],
        ['lprice', 'lsuppprice']
    );
    if ($plan === null || $plan['mode'] !== 'upsert') {
        throw new RuntimeException('expected upsert plan for tblinventory');
    }
    if (!str_contains($plan['sql'], '`lprice` = VALUES(`lprice`)')
        || !str_contains($plan['sql'], '`lsuppprice` = VALUES(`lsuppprice`)')) {
        throw new RuntimeException('expected lprice + lsuppprice to be refreshed');
    }
    // A non-price column must never be overwritten, even on the pricing table.
    if (str_contains($plan['sql'], 'lquantity` = VALUES')) {
        throw new RuntimeException('lquantity must NOT be overwritten');
    }
});

$run('pricing table falls back to INSERT IGNORE when the price column is absent', static function (): void {
    // If the dump/target do not share the price column, nothing is updatable
    // and the row stays strictly add-only.
    $plan = CorporateDumpImportService::buildMergeSql(
        'target_db',
        'source_db',
        'tblinventory_price',
        ['lid', 'lrefno', 'linv_refno', 'lprice_name'],
        ['lprice_amt']
    );
    if ($plan === null || $plan['mode'] !== 'insert_ignore') {
        throw new RuntimeException('expected insert_ignore when price column not shared');
    }
});

$run('non-pricing tables never receive updatable columns and stay add-only', static function (): void {
    $plan = CorporateDumpImportService::buildMergeSql(
        'target_db',
        'source_db',
        'tblpatient',
        ['lid', 'lname', 'lprice_amt'],
        [] // merge loop passes [] for every table not in PRICE_UPSERT_COLUMNS
    );
    if ($plan === null || $plan['mode'] !== 'insert_ignore') {
        throw new RuntimeException('expected insert_ignore for non-pricing table');
    }
    if (str_contains($plan['sql'], 'ON DUPLICATE KEY UPDATE')) {
        throw new RuntimeException('non-pricing tables must never upsert');
    }
});

$run('staging import does not require privileged server-variable changes', static function (): void {
    $source = file_get_contents(__DIR__ . '/../src/Services/CorporateDumpImportService.php');
    if ($source === false) {
        throw new RuntimeException('unable to read import service');
    }
    if (str_contains($source, 'sql_log_bin=0') || str_contains($source, 'innodb_strict_mode=0')) {
        throw new RuntimeException('staging import must not change privileged server variables');
    }
    if (!str_contains($source, 'foreign_key_checks=0') || !str_contains($source, 'unique_checks=0')) {
        throw new RuntimeException('expected safe staging session checks');
    }
});

$run('uses application database credentials when no import account is configured', static function (): void {
    putenv('CORPORATE_IMPORT_MYSQL_USER');
    putenv('CORPORATE_IMPORT_MYSQL_PASS');
    $config = new Config('test', false, '*', 'test', 3600, '127.0.0.1', 3306, 'app_db', 'app_user', 'app_pass');
    $service = new CorporateDumpImportService($config);
    $method = new ReflectionMethod($service, 'importDatabaseCredentials');
    $credentials = $method->invoke($service);
    if ($credentials !== ['app_user', 'app_pass']) {
        throw new RuntimeException('expected the application database credentials');
    }
});

$run('uses an explicitly configured import account when present', static function (): void {
    putenv('CORPORATE_IMPORT_MYSQL_USER=import_user');
    putenv('CORPORATE_IMPORT_MYSQL_PASS=import_pass');
    $config = new Config('test', false, '*', 'test', 3600, '127.0.0.1', 3306, 'app_db', 'app_user', 'app_pass');
    $service = new CorporateDumpImportService($config);
    $method = new ReflectionMethod($service, 'importDatabaseCredentials');
    $credentials = $method->invoke($service);
    if ($credentials !== ['import_user', 'import_pass']) {
        throw new RuntimeException('expected the configured import credentials');
    }
    putenv('CORPORATE_IMPORT_MYSQL_USER');
    putenv('CORPORATE_IMPORT_MYSQL_PASS');
});

$run('upload store rejects non-sql filenames', static function (): void {
    $dir = sys_get_temp_dir() . '/corp-dump-test-' . bin2hex(random_bytes(4));
    $store = new CorporateDumpUploadStore($dir);
    $denied = false;
    try {
        $store->createSession('notes.txt', 10);
    } catch (Throwable) {
        $denied = true;
    }
    if (!$denied) {
        throw new RuntimeException('expected rejection for txt');
    }
    @rmdir($dir);
});

$run('upload store accepts chunked sql.gz and finalizes', static function (): void {
    $dir = sys_get_temp_dir() . '/corp-dump-test-' . bin2hex(random_bytes(4));
    $store = new CorporateDumpUploadStore($dir);
    $session = $store->createSession('corp.sql.gz', 5);
    $progress = $store->appendChunk($session['upload_id'], 'hello');
    if ($progress['complete'] !== true) {
        throw new RuntimeException('expected complete after exact bytes');
    }
    $final = $store->finalize($session['upload_id']);
    if ($final['bytes'] !== 5) {
        throw new RuntimeException('expected 5 bytes');
    }
    $store->deleteSession($session['upload_id']);
    @rmdir($dir);
});

echo "Passed: {$passed}\nFailed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
