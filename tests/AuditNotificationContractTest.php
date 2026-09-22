<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/AuditTrailWriter.php';

use App\Support\AuditTrailWriter;

$pdo = new \Pdo\Sqlite('sqlite::memory:');
$pdo->createFunction('NOW', static fn (): string => '2026-09-22 00:00:00');
$pdo->exec('CREATE TABLE tblaudit_trail (lmain_id INTEGER, luser_id INTEGER, lpage TEXT, laction TEXT, lrefno TEXT, lreason TEXT, lold_status TEXT, lnew_status TEXT, ldatetime TEXT)');
$pdo->exec('CREATE TABLE tblnotifications (lid INTEGER PRIMARY KEY AUTOINCREMENT, ltitle TEXT, lmessage TEXT, ldatetime TEXT, lstatus INTEGER, lmain_id TEXT, linv_session TEXT, lout_status TEXT, ltype TEXT, luserid TEXT, lrefno TEXT)');
$pdo->exec("CREATE TABLE tblaccount (lid INTEGER PRIMARY KEY, lfname TEXT, llname TEXT)");
$pdo->exec("INSERT INTO tblaccount VALUES (7, 'Test', 'User')");

$writer = new AuditTrailWriter($pdo);
$writer->write(1, 7, 'Sales Order', 'Create', 'order-1');
$writer->write(1, 7, 'Sales Order', 'Create', 'order-1'); // retry
$writer->write(1, 7, 'Sales Order', 'Update', 'order-1');
$writer->write(1, 7, 'Sales Order', 'Post', 'order-1');

$notifications = $pdo->query('SELECT lrefno, linv_session FROM tblnotifications ORDER BY lid')->fetchAll(PDO::FETCH_ASSOC);
$refs = array_column($notifications, 'lrefno');
$metadata = json_decode((string) ($notifications[0]['linv_session'] ?? ''), true);

$checks = [
    'one notification per distinct audit action' => count($notifications) === 3,
    'retry is idempotent' => $refs === ['sales_order:order-1:create', 'sales_order:order-1:update', 'sales_order:order-1:post'],
    'navigation metadata includes entity, record, and route' => is_array($metadata)
        && ($metadata['e'] ?? '') === 'sales_order'
        && ($metadata['i'] ?? '') === 'order-1'
        && ($metadata['u'] ?? '') === 'sales-transaction-sales-order',
];

$writer->write(1, 7, 'Agent Sales Report', 'Reply', 'call_report_message:4');
$checks['call report audit does not create a generic duplicate notification'] = (int) $pdo->query('SELECT COUNT(*) FROM tblnotifications')->fetchColumn() === 3;

$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? '  PASS ' : '  FAIL ') . $name . "\n";
    $failed += $passed ? 0 : 1;
}
exit($failed === 0 ? 0 : 1);
