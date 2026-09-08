<?php

declare(strict_types=1);

// Company Owner (and other core groups) must refuse delete with a clear error.
// Deleting then reloading used to look successful, then recreate the group via
// ensureCoreAccessGroups() — the user's "I can't delete Company Owner" symptom.
require __DIR__ . '/../src/bootstrap.php';

use App\Controllers\AccessGroupController;
use App\Database;
use App\Repositories\AccessGroupRepository;
use App\Support\Exceptions\HttpException;

$db = new Database(app_config());
$repo = new AccessGroupRepository($db);
$controller = new AccessGroupController($repo);
$mainId = 1;

$groups = $repo->listGroups($mainId);
$owner = null;
foreach ($groups as $group) {
    if (($group['name'] ?? '') === 'Company Owner') {
        $owner = $group;
        break;
    }
}

if ($owner === null) {
    throw new RuntimeException('FAIL: Company Owner core group was not present after listGroups');
}

if (($owner['is_core'] ?? false) !== true) {
    throw new RuntimeException('FAIL: Company Owner should be marked is_core=true');
}

$ownerId = (int) $owner['id'];

try {
    $controller->delete(['id' => $ownerId], ['main_id' => $mainId], []);
    throw new RuntimeException('FAIL: delete of Company Owner should have been rejected');
} catch (HttpException $exception) {
    if ($exception->statusCode() !== 409) {
        throw new RuntimeException('FAIL: expected HTTP 409, got ' . $exception->statusCode());
    }
    if (!str_contains($exception->getMessage(), 'cannot be deleted')) {
        throw new RuntimeException('FAIL: unexpected message: ' . $exception->getMessage());
    }
}

$after = $repo->getGroupById($mainId, $ownerId);
if ($after === null || ($after['name'] ?? '') !== 'Company Owner') {
    throw new RuntimeException('FAIL: Company Owner row should still exist after rejected delete');
}

echo "PASS: Company Owner delete is rejected as a built-in system group\n";
