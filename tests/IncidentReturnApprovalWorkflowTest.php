<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Controllers\DailyCallMonitoringController;
use App\Database;
use App\Repositories\DailyCallMonitoringRepository;
use App\Support\Exceptions\HttpException;

$passed = 0;
$failed = 0;
$errors = [];

function incident_approval_assert(bool $condition, string $message): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function incident_approval_assert_eq(mixed $expected, mixed $actual, string $message): void
{
    incident_approval_assert(
        $expected === $actual,
        $message . ($expected === $actual ? '' : ' expected=' . json_encode($expected) . ' got=' . json_encode($actual))
    );
}

$vars = file_exists(__DIR__ . '/../.env') ? (parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
$dbHost = (string) ($vars['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int) ($vars['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbName = (string) ($vars['DB_NAME'] ?? getenv('DB_NAME') ?: 'topnotch_migrate');
$dbUser = (string) ($vars['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string) ($vars['DB_PASS'] ?? getenv('DB_PASS') ?: '');
$mainId = 998877;
$prefix = 'UT-incident-approval-';
$contactA = $prefix . 'client-a';
$contactB = $prefix . 'client-b';

$config = new Config('test', true, '*', 'secret', 3600, $dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$db = new Database($config);
$pdo = $db->pdo();
$repo = new DailyCallMonitoringRepository($db);
$controller = new DailyCallMonitoringController($repo);

$cleanup = static function () use ($pdo, $mainId, $prefix, $contactA, $contactB): void {
    $pdo->prepare('DELETE FROM incident_return_actions WHERE main_id = :main_id AND incident_report_id LIKE :prefix')
        ->execute(['main_id' => $mainId, 'prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM incident_report_items WHERE main_id = :main_id AND incident_report_id LIKE :prefix')
        ->execute(['main_id' => $mainId, 'prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM incident_reports WHERE main_id = :main_id AND id LIKE :prefix')
        ->execute(['main_id' => $mainId, 'prefix' => $prefix . '%']);
    $pdo->prepare('DELETE FROM tblpatient WHERE lsessionid IN (:contact_a, :contact_b)')
        ->execute(['contact_a' => $contactA, 'contact_b' => $contactB]);
};

$insertReport = static function (string $id, string $contactId, string $description) use ($pdo, $mainId): void {
    $stmt = $pdo->prepare(
        'INSERT INTO incident_reports (
            id, main_id, contact_id, report_date, incident_date, issue_type,
            description, reported_by, approval_status
        ) VALUES (
            :id, :main_id, :contact_id, CURDATE(), CURDATE(), "product_quality",
            :description, "Unit Test", "pending"
        )'
    );
    $stmt->execute([
        'id' => $id,
        'main_id' => $mainId,
        'contact_id' => $contactId,
        'description' => $description,
    ]);
};

$insertItem = static function (
    string $incidentId,
    string $contactId,
    string $productId,
    string $itemCode,
    string $partNo,
    ?string $supplierId,
    ?string $supplierName
) use ($pdo, $mainId): void {
    $stmt = $pdo->prepare(
        'INSERT INTO incident_report_items (
            main_id, incident_report_id, contact_id, product_id, item_code, part_no,
            description, supplier_id, supplier_name, quantity, issue_summary, match_source, confidence_score
        ) VALUES (
            :main_id, :incident_report_id, :contact_id, :product_id, :item_code, :part_no,
            "Injector assembly", :supplier_id, :supplier_name, 2, "Failed during calibration", "manual", 1
        )'
    );
    $stmt->execute([
        'main_id' => $mainId,
        'incident_report_id' => $incidentId,
        'contact_id' => $contactId,
        'product_id' => $productId,
        'item_code' => $itemCode,
        'part_no' => $partNo,
        'supplier_id' => $supplierId,
        'supplier_name' => $supplierName,
    ]);
};

try {
    $cleanup();

    $nextPatientId = (int) $pdo->query('SELECT COALESCE(MAX(lid), 0) + 1 FROM tblpatient')->fetchColumn();
    $patientStmt = $pdo->prepare('INSERT INTO tblpatient (lid, lmain_id, lsessionid, lcompany, lstatus, ldeleted) VALUES (:lid, :main_id, :session, :company, 1, 0)');
    $patientStmt->execute(['lid' => $nextPatientId, 'main_id' => $mainId, 'session' => $contactA, 'company' => 'Approval Test Client A']);
    $patientStmt->execute(['lid' => $nextPatientId + 1, 'main_id' => $mainId, 'session' => $contactB, 'company' => 'Approval Test Client B']);

    $approveId = $prefix . 'approve';
    $sameClientId = $prefix . 'same-client';
    $samePartOtherClientId = $prefix . 'same-part-other-client';
    $rejectId = $prefix . 'reject';
    $missingItemId = $prefix . 'missing-item';
    $factoryNoSupplierId = $prefix . 'factory-no-supplier';

    $insertReport($approveId, $contactA, 'Approved item should create an authorized sales return action.');
    $insertReport($sameClientId, $contactA, 'Second client incident for trace count.');
    $insertReport($samePartOtherClientId, $contactB, 'Same part from another client for part trace count.');
    $insertReport($rejectId, $contactA, 'Rejected item should stay as incident only.');
    $insertReport($missingItemId, $contactA, 'Missing item should not approve.');
    $insertReport($factoryNoSupplierId, $contactA, 'Factory return needs supplier.');

    $insertItem($approveId, $contactA, 'product-approval', 'IC-100', 'PN-100', 'supplier-1', 'Factory Supplier');
    $insertItem($sameClientId, $contactA, 'product-other', 'IC-200', 'PN-200', 'supplier-2', 'Other Supplier');
    $insertItem($samePartOtherClientId, $contactB, 'product-approval', 'IC-100', 'PN-100', 'supplier-1', 'Factory Supplier');
    $insertItem($rejectId, $contactA, 'product-reject', 'IC-300', 'PN-300', 'supplier-3', 'Reject Supplier');
    $insertItem($factoryNoSupplierId, $contactA, 'product-nosupplier', 'IC-400', 'PN-400', null, null);

    $approved = $repo->reviewIncidentReport($mainId, 501, 'Master User', $approveId, 'approved', 'return_to_factory', 'Return to supplier for inspection.');
    incident_approval_assert_eq('approved', $approved['approval_status'] ?? null, 'approved incident status is saved');
    incident_approval_assert_eq('return_to_factory', $approved['return_action']['disposition'] ?? null, 'approval creates a factory return authorization');
    incident_approval_assert_eq('authorized', $approved['return_action']['status'] ?? null, 'return authorization starts as authorized');
    incident_approval_assert_eq('PN-100', $approved['part_no'] ?? null, 'approved response includes affected part number');
    incident_approval_assert_eq(5, $approved['customer_incident_count'] ?? null, 'approved response traces all incidents for this client');
    incident_approval_assert_eq(2, $approved['item_incident_count'] ?? null, 'approved response traces same part across clients');

    $actionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM incident_return_actions WHERE main_id = :main_id AND incident_report_id = :id');
    $actionCountStmt->execute(['main_id' => $mainId, 'id' => $approveId]);
    incident_approval_assert_eq(1, (int) $actionCountStmt->fetchColumn(), 'approved incident creates exactly one return action');

    $rejected = $repo->reviewIncidentReport($mainId, 501, 'Master User', $rejectId, 'rejected', null, 'Not approved for return.');
    incident_approval_assert_eq('rejected', $rejected['approval_status'] ?? null, 'rejected incident status is saved');
    incident_approval_assert_eq(null, $rejected['return_action'] ?? null, 'rejected incident has no return action');
    incident_approval_assert_eq('Not approved for return.', $rejected['decision_note'] ?? null, 'rejection note is retained');
    $actionCountStmt->execute(['main_id' => $mainId, 'id' => $rejectId]);
    incident_approval_assert_eq(0, (int) $actionCountStmt->fetchColumn(), 'rejected incident creates no return action');

    try {
        $repo->reviewIncidentReport($mainId, 501, 'Master User', $missingItemId, 'approved', 'return_to_stock', '');
        incident_approval_assert(false, 'approval without an affected item is blocked');
    } catch (RuntimeException $error) {
        incident_approval_assert(str_contains($error->getMessage(), 'affected item'), 'approval without an affected item is blocked');
    }

    try {
        $repo->reviewIncidentReport($mainId, 501, 'Master User', $factoryNoSupplierId, 'approved', 'return_to_factory', '');
        incident_approval_assert(false, 'factory return without supplier is blocked');
    } catch (RuntimeException $error) {
        incident_approval_assert(str_contains($error->getMessage(), 'supplier'), 'factory return without supplier is blocked');
    }

    $statusStmt = $pdo->prepare('SELECT approval_status FROM incident_reports WHERE main_id = :main_id AND id = :id');
    $statusStmt->execute(['main_id' => $mainId, 'id' => $factoryNoSupplierId]);
    incident_approval_assert_eq('pending', (string) $statusStmt->fetchColumn(), 'blocked factory return rolls back to pending');

    try {
        $repo->reviewIncidentReport($mainId, 501, 'Master User', $approveId, 'approved', 'return_to_stock', '');
        incident_approval_assert(false, 'duplicate decision is blocked');
    } catch (RuntimeException $error) {
        incident_approval_assert(str_contains($error->getMessage(), 'already been reviewed'), 'duplicate decision is blocked');
    }
    $actionCountStmt->execute(['main_id' => $mainId, 'id' => $approveId]);
    incident_approval_assert_eq(1, (int) $actionCountStmt->fetchColumn(), 'duplicate approval does not create another return action');

    $history = $repo->getCustomerIncidentReports($mainId, $contactA);
    $historyById = [];
    foreach ($history as $row) {
        $historyById[(string) ($row['id'] ?? '')] = $row;
    }
    incident_approval_assert(isset($historyById[$approveId], $historyById[$rejectId]), 'customer incident history includes approved and rejected mock incidents');
    incident_approval_assert_eq('return_to_factory', $historyById[$approveId]['return_action']['disposition'] ?? null, 'customer incident history exposes approved return action');
    incident_approval_assert_eq(null, $historyById[$rejectId]['return_action'] ?? null, 'customer incident history keeps rejected incident action-free');

    try {
        $controller->reviewIncidentReport(
            ['reportId' => $sameClientId],
            [],
            [
                '__auth_claims' => ['sub' => 601, 'main_userid' => $mainId, 'user_type' => '2'],
                'main_id' => $mainId,
                'decision' => 'approved',
                'disposition' => 'return_to_stock',
            ]
        );
        incident_approval_assert(false, 'non-Master reviewer is blocked by controller');
    } catch (HttpException $error) {
        incident_approval_assert_eq(403, $error->statusCode(), 'non-Master reviewer is blocked by controller');
    }

    try {
        $controller->reviewIncidentReport(
            ['reportId' => $sameClientId],
            [],
            [
                '__auth_claims' => ['sub' => 501, 'main_userid' => $mainId + 1, 'user_type' => '1'],
                'main_id' => $mainId,
                'decision' => 'approved',
                'disposition' => 'return_to_stock',
            ]
        );
        incident_approval_assert(false, 'cross-account decision is blocked by controller');
    } catch (HttpException $error) {
        incident_approval_assert_eq(403, $error->statusCode(), 'cross-account decision is blocked by controller');
    }

    echo "\nIncident return approval workflow tests: {$passed} passed, {$failed} failed.\n";
} catch (Throwable $error) {
    $failed++;
    $errors[] = $error->getMessage();
    echo "  FAIL unexpected error: {$error->getMessage()}\n";
} finally {
    $cleanup();
}

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

exit(0);
