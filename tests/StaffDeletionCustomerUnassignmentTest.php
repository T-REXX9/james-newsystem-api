<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\StaffRepository;

$db = new Database(app_config());
$pdo = $db->pdo();

foreach (['tblaccount', 'tblpatient', 'tblusertype'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(\PDO::FETCH_NUM)[1];
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}

$insert = static function (string $table, array $values) use ($pdo): void {
    foreach ($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $column) {
        $name = $column['Field'];
        if (array_key_exists($name, $values)
            || $column['Null'] === 'YES'
            || $column['Default'] !== null
            || str_contains($column['Extra'], 'auto_increment')) {
            continue;
        }
        $values[$name] = preg_match('/int|decimal|float|double|bit/', strtolower($column['Type'])) ? 0 : '';
    }
    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($values)) . '`) VALUES ('
        . implode(',', array_fill(0, count($values), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($values));
};

$mainId = 980101;
$staffId = 980102;
$otherStaffId = 980103;
$salesAgentGroupId = 980104;

$insert('tblusertype', ['lid' => $salesAgentGroupId, 'lmain_id' => $mainId, 'ltype_name' => 'Sales Agent', 'ldefault' => 0]);
$insert('tblaccount', ['lid' => $mainId, 'ltype' => 1, 'lstatus' => 1, 'lfname' => 'Test', 'llname' => 'Owner']);
$insert('tblaccount', ['lid' => $staffId, 'lmother_id' => $mainId, 'ltype' => $salesAgentGroupId, 'lstatus' => 1, 'lfname' => 'Deleted', 'llname' => 'Agent']);
$insert('tblaccount', ['lid' => $otherStaffId, 'lmother_id' => $mainId, 'ltype' => $salesAgentGroupId, 'lstatus' => 1, 'lfname' => 'Active', 'llname' => 'Agent']);
$insert('tblpatient', ['lmain_id' => $mainId, 'lsessionid' => 'delete-assigned', 'lcompany' => 'Direct Assignment', 'lsales_person' => (string) $staffId, 'lsales_team' => 5, 'ldate_assigned' => '2026-09-18']);
$insert('tblpatient', ['lmain_id' => $mainId, 'lsessionid' => 'keep-assigned', 'lcompany' => 'Other Assignment', 'lsales_person' => (string) $otherStaffId, 'lsales_team' => 5, 'ldate_assigned' => '2026-09-18']);

$repo = new StaffRepository($db);
if (!$repo->deleteStaff($mainId, $staffId)) {
    throw new RuntimeException('FAIL: staff deactivation failed');
}

$deactivatedStatus = $pdo->prepare('SELECT lstatus FROM tblaccount WHERE lid = :staff_id');
$deactivatedStatus->execute(['staff_id' => $staffId]);
if ((int) $deactivatedStatus->fetchColumn() !== 0) {
    throw new RuntimeException('FAIL: staff account was not deactivated');
}

$customers = $pdo->prepare(
    'SELECT lsessionid, lsales_person, lsales_team, ldate_assigned
     FROM tblpatient
     WHERE lmain_id = :main_id
     ORDER BY lsessionid'
);
$customers->execute(['main_id' => $mainId]);
$rows = $customers->fetchAll(\PDO::FETCH_ASSOC);
$bySession = array_column($rows, null, 'lsessionid');

$removedAssignment = $bySession['delete-assigned'] ?? [];
if ((string) ($removedAssignment['lsales_person'] ?? '') !== ''
    || (int) ($removedAssignment['lsales_team'] ?? 0) !== 5
    || ($removedAssignment['ldate_assigned'] ?? null) !== null) {
    throw new RuntimeException('FAIL: deleted agent customers were not unassigned while retaining their team');
}

if ((string) ($bySession['keep-assigned']['lsales_person'] ?? '') !== (string) $otherStaffId) {
    throw new RuntimeException('FAIL: another agent customer assignment was changed');
}

echo "PASS: deactivating an agent unassigns only that agent's customers\n";
