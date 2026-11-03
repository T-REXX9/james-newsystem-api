<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\CallReportRepository;

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = class_exists(\Pdo\Sqlite::class)
    ? new \Pdo\Sqlite('sqlite::memory:')
    : new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$replaceWhitespace = static fn (?string $value, string $pattern, string $replacement): string => (string) preg_replace('/' . trim($pattern, '/') . '/u', $replacement, (string) $value);
if ($pdo instanceof \Pdo\Sqlite) {
    $pdo->createFunction('REGEXP_REPLACE', $replaceWhitespace, 3);
} else {
    $pdo->sqliteCreateFunction('REGEXP_REPLACE', $replaceWhitespace, 3);
}
$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lsales_team INTEGER, ldeleted INTEGER)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lmother_id INTEGER, lstatus INTEGER, larchieve INTEGER, lteam INTEGER, ltype INTEGER)');
$pdo->exec('CREATE TABLE tblusertype (lid INTEGER PRIMARY KEY, ltype_name TEXT)');
$pdo->exec('CREATE TABLE tblteamstaff (lid INTEGER, lmain_id INTEGER, lstatus INTEGER)');

$dbProperty = (new ReflectionClass($db))->getProperty('pdo');
$dbProperty->setValue($db, $pdo);

$pdo->exec("INSERT INTO tblusertype VALUES (2, 'Sales Agent'), (3, 'Accountant'), (4, 'Sales Person')");
$pdo->exec('INSERT INTO tblteamstaff VALUES (10, 1, 1), (20, 1, 1), (10, 2, 1)');
$pdo->exec('INSERT INTO tblpatient VALUES (100, 1, "customer-alpha", 10, 0), (200, 1, "customer-beta", 20, 0), (300, 2, "other-company", 10, 0)');
$pdo->exec('INSERT INTO tblaccount VALUES (101, 1, 1, 0, 10, 2), (102, 1, 1, 0, 20, 2), (103, 1, 1, 0, 10, 3), (104, 1, 0, 0, 10, 2), (105, 2, 1, 0, 10, 2), (106, 1, 1, 0, 10, 4)');

$repository = new CallReportRepository($db);
$canContribute = (new ReflectionClass($repository))->getMethod('agentIsAssignedToContactTeam');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

$assert($canContribute->invoke($repository, 1, 'customer-alpha', 101), 'active Sales Agent can reply to a customer assigned to their team');
$assert($canContribute->invoke($repository, 1, 'customer-alpha', 106), 'Sales Person alias can reply to a customer assigned to their team');
$assert(!$canContribute->invoke($repository, 1, 'customer-alpha', 102), 'Sales Agent from another team is denied');
$assert(!$canContribute->invoke($repository, 1, 'customer-alpha', 103), 'non-sales account in the assigned team is denied');
$assert(!$canContribute->invoke($repository, 1, 'customer-alpha', 104), 'inactive Sales Agent is denied');
$assert(!$canContribute->invoke($repository, 1, 'other-company', 101), 'cross-company access is denied');

echo "Results: 6 passed, 0 failed\n";
