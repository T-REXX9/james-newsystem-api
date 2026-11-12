<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\SalesInquiryRepository;

function current_agent_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

$db = new Database(new Config('test', true, '*', 'secret', 3600, '127.0.0.1', 3306, 'test_db', 'test', 'test'));
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
@$pdo->sqliteCreateFunction('CONCAT', static fn(...$parts): string => implode('', array_map(static fn($part): string => (string) ($part ?? ''), $parts)));
$pdo->exec('CREATE TABLE tblinquiry (
    lid INTEGER PRIMARY KEY, linqno TEXT, lrefno TEXT, ldate TEXT, ltime TEXT, lcustomerid TEXT,
    lmain_id INTEGER, lcompany TEXT, lsalesperson TEXT, lsales_person_id TEXT, luser TEXT,
    lsales_address TEXT, lyour_refno TEXT, lshipped TEXT, lprice_group TEXT, lcredit_limit REAL,
    lterms TEXT, lpromissory_note TEXT, lpo_no TEXT, lnote TEXT, lsource TEXT, lurgency TEXT,
    lurgency_date TEXT, lsubmitstat TEXT, IsCancel INTEGER, lso_no TEXT, lso_refno TEXT,
    ltransaction_status TEXT, lvat_type TEXT, lvat_percent REAL
)');
$pdo->exec('CREATE TABLE tblpatient (lid INTEGER PRIMARY KEY, lmain_id INTEGER, lsessionid TEXT, lsales_person TEXT, ldeleted INTEGER)');
$pdo->exec('CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT, lstatus INTEGER)');
$pdo->exec('CREATE TABLE tbltransaction (lmain_id INTEGER, lrefno TEXT, lcancel INTEGER, linquiry_refno TEXT, invoice_refno TEXT, invoice_no TEXT, ldr_refno TEXT, ldr_no TEXT)');
$pdo->exec('CREATE TABLE tblinquiry_item (
    lid INTEGER PRIMARY KEY, linq_refno TEXT, linq_no TEXT, litem_id TEXT, litem_refno TEXT,
    lpartno TEXT, litem_code TEXT, lbrand TEXT, ldesc TEXT, llocation TEXT, lqty REAL,
    lprice REAL, lremark TEXT, lapproved INTEGER, linquiry_date TEXT
)');
$pdo->exec('CREATE TABLE tblvip_document_discount (
    lid INTEGER PRIMARY KEY, lmain_id INTEGER, ldocument_type TEXT, ldocument_refno TEXT,
    lcustomerid TEXT, lsales_date TEXT, lapplied INTEGER, ltier TEXT,
    lpercentage REAL, ldiscount_amount REAL, ltotal_to_pay REAL
)');
$pdo->exec("INSERT INTO tblaccount VALUES (1, 'Creator', 'User', 1), (19, 'Apostol', 'Ella', 0), (20, 'Jane', 'Agent', 1)");
$pdo->exec("INSERT INTO tblpatient VALUES (1, 7, 'customer-1', '20', 0)");
$pdo->exec("INSERT INTO tblinquiry (lid, linqno, lrefno, ldate, ltime, lcustomerid, lmain_id, lcompany, lsalesperson, lsales_person_id, luser, IsCancel) VALUES (1, 'INQ-1', 'ref-1', '2026-10-01', '09:00:00', 'customer-1', 7, 'Example', 'Apostol Ella', '19', '1', 0)");

$property = (new ReflectionClass($db))->getProperty('pdo');
$property->setValue($db, $pdo);
$repository = new SalesInquiryRepository($db);

$detail = $repository->getInquiry(7, 'ref-1');
current_agent_expect(($detail['sales_person'] ?? null) === 'Jane Agent', 'detail returns the current active assigned agent, not the saved inquiry name');
current_agent_expect(($detail['sales_person_id'] ?? null) === '20', 'detail returns the current assigned agent ID');

$list = $repository->listInquiries(7);
current_agent_expect(($list['items'][0]['sales_person'] ?? null) === 'Jane Agent', 'list returns the current active assigned agent, not the saved inquiry name');
current_agent_expect(($list['items'][0]['sales_person_id'] ?? null) === '20', 'list returns the current assigned agent ID');

$search = $repository->listInquiries(7, 'Jane Agent');
current_agent_expect(($search['meta']['total'] ?? 0) === 1, 'search matches the current assigned agent name');

echo "PASS: Sales Inquiry detail, list, and search use the active customer's assigned Sales Agent\n";
