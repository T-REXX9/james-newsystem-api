<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$customers = new CustomerDatabaseRepository($db);
$mainId = 1;
$userId = 1;
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$existingId = "OVERRIDE-EXISTING-{$stamp}";
$newId = "OVERRIDE-NEW-{$stamp}";

try {
    $customers->createCustomer($mainId, $userId, [
        'session_id' => $existingId,
        'company' => "Existing Customer {$stamp}",
        'phone' => '09171234567',
        'mobile' => '09171234567',
        'status' => 1,
        'profile_type' => 'Old',
    ]);

    try {
        $customers->createCustomer($mainId, $userId, [
            'session_id' => $newId,
            'company' => "Different Branch {$stamp}",
            'phone' => '09171234567',
            'mobile' => '09171234567',
            'status' => 3,
            'profile_type' => 'Prospect',
            'duplicate_override_confirmed' => false,
        ]);
        throw new RuntimeException('Expected a matching phone number to require an override reason.');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'matching or similar')) throw $e;
    }

    $created = $customers->createCustomer($mainId, $userId, [
        'session_id' => $newId,
        'company' => "Different Branch {$stamp}",
        'phone' => '09171234567',
        'mobile' => '09171234567',
        'status' => 3,
        'profile_type' => 'Prospect',
        'duplicate_override_confirmed' => true,
        'duplicate_override_reason' => 'Different contact number for the branch',
    ]);

    if (($created['duplicate_override_reason'] ?? '') !== 'Different contact number for the branch') {
        throw new RuntimeException('Override reason was not persisted.');
    }

    echo "PASS: duplicate-looking create requires and persists an override reason\n";
} finally {
    $pdo->prepare('DELETE FROM tblaudit_trail WHERE lrefno IN (:existing_id, :new_id)')->execute(['existing_id' => $existingId, 'new_id' => $newId]);
    foreach ([$existingId, $newId] as $sessionId) {
        $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id')->execute(['session_id' => $sessionId]);
        $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid = :session_id AND lmain_id = :main_id')->execute(['session_id' => $sessionId, 'main_id' => $mainId]);
    }
}
