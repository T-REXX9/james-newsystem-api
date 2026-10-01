<?php

declare(strict_types=1);

/**
 * Live MySQL regression test for near-identical company-name detection.
 *
 * Run with: php tests/CustomerDuplicateDetectionIntegrationTest.php
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;
use App\Repositories\CustomerDatabaseRepository;

$db = new Database(app_config());
$pdo = $db->pdo();
$repository = new CustomerDatabaseRepository($db);
$mainId = 1;
$stamp = date('YmdHis') . '-' . random_int(1000, 9999);
$source = "2J DAGS MOTORSHOP {$stamp}";
$nearDuplicate = "2J DAGS MOTOSHOP {$stamp}";
$sourceSession = "DUP-S-{$stamp}";
$duplicateSession = "DUP-D-{$stamp}";

$assert = static function (bool $condition, string $label): void {
    if (!$condition) {
        throw new RuntimeException("FAIL: {$label}");
    }
    echo "PASS: {$label}\n";
};

try {
    $insert = $pdo->prepare(
        'INSERT INTO tblpatient (lmain_id, lsessionid, lcompany, lstatus, lprofile_type, lverification, ldeleted)
         VALUES (:main_id, :session_id, :company, 1, \'Old\', \'Verified\', 0)'
    );
    $insert->execute(['main_id' => $mainId, 'session_id' => $sourceSession, 'company' => $source]);
    $insert->execute(['main_id' => $mainId, 'session_id' => $duplicateSession, 'company' => $nearDuplicate]);

    $matches = $repository->findSimilarCustomers($mainId, ['company' => $source], $sourceSession);
    $match = array_values(array_filter($matches, static fn (array $row): bool => (string) ($row['session_id'] ?? '') === $duplicateSession));
    $assert(count($match) === 1, 'near-identical company names are detected');
    $assert(in_array('company_similar', $match[0]['matched_fields'] ?? [], true), 'near-identical names report company similarity');
} finally {
    $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid IN (:source, :duplicate)')->execute([
        'main_id' => $mainId,
        'source' => $sourceSession,
        'duplicate' => $duplicateSession,
    ]);
}

echo "All duplicate detection checks passed.\n";
