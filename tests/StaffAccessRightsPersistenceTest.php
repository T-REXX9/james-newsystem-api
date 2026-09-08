<?php

declare(strict_types=1);

// Verifies that an explicitly empty access-rights list survives the staff API
// read-after-write path without modifying persistent tables.
require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\StaffRepository;

$db = new Database(app_config());
$pdo = $db->pdo();

foreach (['tblaccount', 'tblusertype', 'tblweb_permission', 'tblweb_pagecateg'] as $table) {
    $ddl = $pdo->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
    $pdo->exec(preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $ddl));
}

if (!$pdo->query("SHOW COLUMNS FROM tblaccount LIKE 'laccess_rights'")->fetch()) {
    $pdo->exec('ALTER TABLE tblaccount ADD COLUMN laccess_rights TEXT NULL');
}

$insert = static function (string $table, array $values) use ($pdo): void {
    foreach ($pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll() as $column) {
        $name = $column['Field'];
        if (array_key_exists($name, $values) || $column['Null'] === 'YES' || $column['Default'] !== null || str_contains($column['Extra'], 'auto_increment')) {
            continue;
        }
        $type = strtolower($column['Type']);
        $values[$name] = preg_match('/int|decimal|float|double|bit/', $type) ? 0 : '';
    }
    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($values));
};

$mainId = 980001;
$staffId = 980002;
$groupId = 980003;
$insert('tblaccount', ['lid' => $mainId, 'ltype' => 1, 'lstatus' => 1, 'lfname' => 'Test', 'llname' => 'Owner']);
$insert('tblusertype', ['lid' => $groupId, 'lmain_id' => $mainId, 'ltype_name' => 'Accountant']);
$insert('tblaccount', ['lid' => $staffId, 'lmother_id' => $mainId, 'ltype' => $groupId, 'lstatus' => 1, 'lfname' => 'Test', 'llname' => 'Accountant']);

$repo = new StaffRepository($db);
$before = $repo->getStaffById($mainId, $staffId);
if (($before['access_rights'] ?? []) !== ['home']) {
    throw new RuntimeException('FAIL: unset access rights should inherit the group default');
}

$updated = $repo->updateStaff($mainId, $staffId, ['access_rights' => []]);
if (($updated['access_rights'] ?? null) !== []) {
    throw new RuntimeException('FAIL: empty access rights were not returned after update: ' . json_encode($updated));
}

$afterRefresh = $repo->getStaffById($mainId, $staffId);
if (($afterRefresh['access_rights'] ?? null) !== []) {
    throw new RuntimeException('FAIL: empty access rights were replaced after refresh');
}

echo "PASS: explicit empty staff access rights persist across refresh\n";
