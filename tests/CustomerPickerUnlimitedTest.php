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
$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lpatient_code TEXT, lcompany TEXT, lstatus INTEGER, ldeleted INTEGER, lemail TEXT, lphone TEXT, lmobile TEXT, lsales_person INTEGER, laddress TEXT, ldelivery_address TEXT, larea TEXT, lcity TEXT, lprovince TEXT, ltin TEXT, lprice_group TEXT, lbusiness_line TEXT, lterms TEXT, ltransaction_type TEXT, lvat_type TEXT, lvat_percent REAL, lsince TEXT, ldealer_since TEXT, ldealer_quota REAL, lcredit REAL, ldebt_type TEXT, lpreferred_brand TEXT, lprofile_type TEXT)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lstatus INTEGER, lfname TEXT, llname TEXT)');
$pdo->exec('CREATE TABLE tblpatient_stars (lmain_id INTEGER, lsessionid TEXT, lis_starred INTEGER)');
$pdo->exec('CREATE TABLE tblinvoice_list (lmain_id INTEGER, lrefno TEXT, lcustomerid TEXT, lcustomer_name TEXT, lcancel TEXT, lcancel_invoice INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tblinvoice_itemrec (linvoice_refno TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt (lmain_id INTEGER, lrefno TEXT, lcustomerid TEXT, lcustomer_name TEXT, lcancel INTEGER, lstatus TEXT)');
$pdo->exec('CREATE TABLE tbldelivery_receipt_items (lor_refno TEXT)');
$pdo->exec('CREATE TABLE tlbCustomer_Details (lid INTEGER PRIMARY KEY, lsessionid TEXT, loldname TEXT, ldate TEXT)');
$pdo->exec("INSERT INTO tblpatient (lid, lmain_id, lsessionid, lpatient_code, lcompany, lstatus, ldeleted) VALUES
    (1, 1, 'cust-z', 'Z-1', 'Zulu', 1, 0),
    (2, 1, 'cust-a', 'A-1', 'Alpha', 1, 0),
    (3, 1, 'cust-b', 'B-1', 'Bravo', 1, 0),
    (4, 1, 'cust-deleted', 'D-1', 'Deleted', 0, 1),
    (5, 2, 'cust-other', 'O-1', 'Other Company', 1, 0)");
$pdo->exec("INSERT INTO tblinvoice_list VALUES (1, 'orphan-invoice', 'orphan-session', 'Orphan Business', NULL, 0, 'Posted')");
$pdo->exec("INSERT INTO tblinvoice_itemrec VALUES ('orphan-invoice')");

$reflection = new ReflectionClass($db);
$property = $reflection->getProperty('pdo');
$property->setValue($db, $pdo);

$customerDatabase = new CustomerDatabaseRepository($db);
$picker = $customerDatabase->listCustomers(1, '', 'all', 1, 0, 'picker');
customer_picker_expect(count($picker['items']) === 4, 'customer database picker includes active customers and posted-sales identities without a master row');
customer_picker_expect(array_column($picker['items'], 'company') === ['Alpha', 'Bravo', 'Unlinked: Orphan Business', 'Zulu'], 'customer database picker sorts the complete list, including labeled unlinked identities');
customer_picker_expect(($picker['meta']['per_page'] ?? null) === 4, 'customer database picker reports all returned customers and unlinked identities');
customer_picker_expect(($picker['items'][2]['session_id'] ?? null) === 'orphan-session', 'unlinked posted-sales identity keeps its customer session ID');

$statementOfAccount = new StatementOfAccountRepository($db);
$statementCustomers = $statementOfAccount->listCustomers(1, '', 0, '2');
customer_picker_expect(count($statementCustomers) === 3, 'statement customer picker returns every active customer when limit is zero');
customer_picker_expect(array_column($statementCustomers, 'company') === ['Alpha', 'Bravo', 'Zulu'], 'statement customer picker keeps the complete list in A-Z order');

echo "Tests passed.\n";
