<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\CustomerDatabaseRepository;
use App\Repositories\StatementOfAccountRepository;

function customer_picker_expect(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lpatient_code TEXT, lcompany TEXT, lstatus INTEGER, ldeleted INTEGER)');
$pdo->exec('CREATE TABLE tlbCustomer_Details (lid INTEGER PRIMARY KEY, lsessionid TEXT, loldname TEXT, ldate TEXT)');
$pdo->exec("INSERT INTO tblpatient VALUES
    (1, 1, 'cust-z', 'Z-1', 'Zulu', 1, 0),
    (2, 1, 'cust-a', 'A-1', 'Alpha', 1, 0),
    (3, 1, 'cust-b', 'B-1', 'Bravo', 1, 0),
    (4, 1, 'cust-deleted', 'D-1', 'Deleted', 0, 1),
    (5, 2, 'cust-other', 'O-1', 'Other Company', 1, 0)");

$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$customerDatabase = new CustomerDatabaseRepository($db);
$picker = $customerDatabase->listCustomers(1, '', 'all', 1, 0, 'picker');
customer_picker_expect(count($picker['items']) === 3, 'customer database picker returns every active customer when per_page is zero');
customer_picker_expect(array_column($picker['items'], 'company') === ['Alpha', 'Bravo', 'Zulu'], 'customer database picker keeps the complete list in A-Z order');
customer_picker_expect(($picker['meta']['per_page'] ?? null) === 3, 'customer database picker reports all returned customers');

$statementOfAccount = new StatementOfAccountRepository($db);
$statementCustomers = $statementOfAccount->listCustomers(1, '', 0, '2');
customer_picker_expect(count($statementCustomers) === 3, 'statement customer picker returns every active customer when limit is zero');
customer_picker_expect(array_column($statementCustomers, 'company') === ['Alpha', 'Bravo', 'Zulu'], 'statement customer picker keeps the complete list in A-Z order');

echo "Tests passed.\n";
