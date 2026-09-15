<?php

declare(strict_types=1);

require __DIR__ . '/../src/Support/Env.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/Services/CorporateDumpImportService.php';
require __DIR__ . '/../src/Services/CorporateDumpUploadStore.php';

use App\Services\CorporateDumpImportService;
use App\Services\CorporateDumpUploadStore;

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
