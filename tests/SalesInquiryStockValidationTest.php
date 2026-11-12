<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\SalesInquiryRepository;
function stock_test_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tblinventory_item (lsession TEXT, lmain_id INTEGER)');
$pdo->exec('CREATE TABLE tblinventory_logs (linvent_id TEXT, lin REAL, lout REAL)');
$pdo->exec("INSERT INTO tblinventory_item VALUES ('part-1', 1), ('part-2', 1), ('other-tenant-part', 2)");
$pdo->exec("INSERT INTO tblinventory_logs VALUES ('part-1', 8, 3), ('part-2', 0, 0), ('other-tenant-part', 100, 0)");

$property = (new ReflectionClass($db))->getProperty('pdo');
$property->setValue($db, $pdo);
$repository = new SalesInquiryRepository($db);
$guard = (new ReflectionClass($repository))->getMethod('assertApprovedItemsHaveSufficientStock');

$invoke = static function (array $items) use ($guard, $repository): void {
    $guard->invoke($repository, 1, $items);
};

$invoke([['item_id' => 'part-1', 'qty' => 5, 'remark' => 'OnStock']]);
stock_test_expect(true, 'Conversion allows requested quantities within net stock');

foreach ([
    'requested quantity exceeds stock' => [['item_id' => 'part-1', 'qty' => 6, 'remark' => 'OnStock']],
    'zero stock' => [['item_id' => 'part-2', 'qty' => 1, 'remark' => 'OutStock']],
    'stock belongs to another tenant' => [['item_id' => 'other-tenant-part', 'qty' => 1, 'remark' => 'OnStock']],
    'combined duplicate lines exceed stock' => [['item_id' => 'part-1', 'qty' => 3, 'remark' => 'OnStock'], ['item_id' => 'part-1', 'qty' => 3, 'remark' => 'OnStock']],
] as $scenario => $items) {
    $blocked = false;
    try {
        $invoke($items);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'Insufficient stock')
            && str_contains($exception->getMessage(), 'Transfer stock');
    }
    stock_test_expect($blocked, 'Conversion blocks when ' . $scenario);
}

$invoke([['item_id' => 'missing-catalog-item', 'qty' => 4, 'remark' => 'NotListed']]);
stock_test_expect(true, 'Approved NotListed rows remain excluded from stock validation');
