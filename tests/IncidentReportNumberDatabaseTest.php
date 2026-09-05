<?php

declare(strict_types=1);

/**
 * Incident Report Number is assigned like Purchase Request numbers and is searchable.
 *
 * Run:
 *   php api/tests/IncidentReportNumberDatabaseTest.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Config;
use App\Database;
use App\Repositories\DailyCallMonitoringRepository;
use App\Repositories\IncidentItemsReportRepository;

$ROOT = dirname(__DIR__);
$vars = [];
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $vars[trim($key)] = trim($value);
}

$dbHost = (string) ($vars['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int) ($vars['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbName = (string) ($vars['DB_NAME'] ?? getenv('DB_NAME') ?: 'topnotch_migrate');
$dbUser = (string) ($vars['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string) ($vars['DB_PASS'] ?? getenv('DB_PASS') ?: '');
$MAIN_ID = 1;
$USER_ID = 1;

$seed = 'UT-IRNUM-' . date('YmdHis') . '-' . random_int(1000, 9999);
$year = date('y');
$contactId = $seed . '-contact';
$firstId = $seed . '-a';
$secondId = $seed . '-b';
$backfillId = $seed . '-backfill';
$description = $seed . ' nozzle leak';

$passed = 0;
$failed = 0;
$errors = [];

function irn_assert(bool $condition, string $message, int &$passed, int &$failed, array &$errors): void
{
    if ($condition) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

function irn_parse(string $number): array
{
    if (!preg_match('/^IR-(\d{2})(\d+)$/', $number, $matches)) {
        return ['year' => '', 'seq' => 0];
    }
    return ['year' => $matches[1], 'seq' => (int) $matches[2]];
}

$config = new Config('test', true, '*', 'secret', 3600, $dbHost, $dbPort, $dbName, $dbUser, $dbPass);
$db = new Database($config);
$pdo = $db->pdo();
$dailyCall = new DailyCallMonitoringRepository($db);
$itemsReport = new IncidentItemsReportRepository($db);

$cleanup = static function () use ($pdo, $firstId, $secondId, $backfillId): void {
    foreach ([$firstId, $secondId, $backfillId] as $id) {
        $pdo->prepare('DELETE FROM incident_report_items WHERE incident_report_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM incident_reports WHERE id = ?')->execute([$id]);
    }
};

$createPayload = static function (string $id, string $contactId, string $description): array {
    return [
        'id' => $id,
        'contact_id' => $contactId,
        'report_date' => date('Y-m-d'),
        'report_time' => date('H:i:s'),
        'incident_date' => date('Y-m-d'),
        'incident_time' => date('H:i:s'),
        'issue_type' => 'other',
        'description' => $description,
        'reported_by' => 'Triage Tester',
        'done_by' => 'Triage Tester',
        'attachments' => [],
        'related_transactions' => [],
        'notes' => null,
    ];
};

echo "==========================================================\n";
echo " Incident Report Number Database Tests\n";
echo "==========================================================\n\n";

$cleanup();

try {
    $first = $dailyCall->createIncidentReport($MAIN_ID, $USER_ID, $createPayload($firstId, $contactId, $description . ' first'));
    $firstNumber = (string) ($first['ir_number'] ?? '');
    $firstParsed = irn_parse($firstNumber);
    irn_assert($firstParsed['year'] === $year, "create assigns IR-{$year} sequence number", $passed, $failed, $errors);
    irn_assert($firstParsed['seq'] >= 1, 'create sequence is at least 1', $passed, $failed, $errors);
    irn_assert($first['id'] === $firstId, 'create keeps Incident Report ID', $passed, $failed, $errors);

    $retry = $dailyCall->createIncidentReport($MAIN_ID, $USER_ID, $createPayload($firstId, $contactId, $description . ' retry'));
    irn_assert(
        (string) ($retry['ir_number'] ?? '') === $firstNumber,
        'retry warehouse sync keeps the original Incident Report Number',
        $passed,
        $failed,
        $errors
    );

    $second = $dailyCall->createIncidentReport($MAIN_ID, $USER_ID, $createPayload($secondId, $contactId, $description . ' second'));
    $secondNumber = (string) ($second['ir_number'] ?? '');
    $secondParsed = irn_parse($secondNumber);
    irn_assert($secondParsed['year'] === $year, 'second create uses assignment year prefix', $passed, $failed, $errors);
    irn_assert(
        $secondParsed['seq'] === $firstParsed['seq'] + 1,
        'second create receives the next sequence',
        $passed,
        $failed,
        $errors
    );

    $pdo->prepare(
        'INSERT INTO incident_reports (
            id, main_id, contact_id, report_date, report_time, incident_date, incident_time,
            issue_type, description, reported_by, done_by, approval_status
        ) VALUES (
            :id, :main_id, :contact_id, CURDATE(), CURTIME(), CURDATE(), CURTIME(),
            "other", :description, "Triage Tester", "Triage Tester", "pending"
        )'
    )->execute([
        'id' => $backfillId,
        'main_id' => $MAIN_ID,
        'contact_id' => $contactId,
        'description' => $description . ' backfill',
    ]);

    $dailyCall->backfillMissingIncidentReportNumbers();
    $backfillStmt = $pdo->prepare('SELECT ir_number FROM incident_reports WHERE id = ?');
    $backfillStmt->execute([$backfillId]);
    $backfillNumber = (string) ($backfillStmt->fetchColumn() ?: '');
    $backfillParsed = irn_parse($backfillNumber);
    irn_assert($backfillParsed['year'] === $year, 'backfill assigns an Incident Report Number', $passed, $failed, $errors);

    $dailyCall->backfillMissingIncidentReportNumbers();
    $backfillStmt->execute([$backfillId]);
    irn_assert(
        (string) ($backfillStmt->fetchColumn() ?: '') === $backfillNumber,
        'second backfill leaves an already-numbered report unchanged',
        $passed,
        $failed,
        $errors
    );

    $itemsReport->create($MAIN_ID, $USER_ID, [
        'incident_report_id' => $firstId,
        'contact_id' => $contactId,
        'description' => $description,
        'issue_summary' => $description,
        'issue_type' => 'other',
        'report_date' => date('Y-m-d'),
    ]);

    $byNumber = $itemsReport->report($MAIN_ID, ['search' => $firstNumber], 1, 20);
    $numberHits = array_values(array_filter(
        $byNumber['items'] ?? [],
        static fn(array $row): bool => (string) ($row['description'] ?? '') === $description
    ));
    irn_assert($numberHits !== [], 'Incident Items Report search by Incident Report Number returns the item', $passed, $failed, $errors);
    $recentNumbers = array_column($numberHits[0]['recent_incidents'] ?? [], 'ir_number');
    irn_assert(
        in_array($firstNumber, $recentNumbers, true),
        'recent incidents include the Incident Report Number',
        $passed,
        $failed,
        $errors
    );

    $byId = $itemsReport->report($MAIN_ID, ['search' => $firstId], 1, 20);
    $idHits = array_values(array_filter(
        $byId['items'] ?? [],
        static fn(array $row): bool => (string) ($row['description'] ?? '') === $description
    ));
    irn_assert($idHits !== [], 'Incident Items Report search by Incident Report ID returns the item', $passed, $failed, $errors);

    $byPart = $itemsReport->report($MAIN_ID, ['search' => $description], 1, 20);
    $partHits = array_values(array_filter(
        $byPart['items'] ?? [],
        static fn(array $row): bool => (string) ($row['description'] ?? '') === $description
    ));
    irn_assert($partHits !== [], 'existing description search still returns the item', $passed, $failed, $errors);

    $detail = $itemsReport->getIncidentReport($MAIN_ID, $firstId);
    irn_assert(
        (string) ($detail['ir_number'] ?? '') === $firstNumber,
        'warehouse Incident Report payload includes the Incident Report Number',
        $passed,
        $failed,
        $errors
    );

    $listed = $dailyCall->getCustomerIncidentReports($MAIN_ID, $contactId);
    $listedFirst = null;
    foreach ($listed as $row) {
        if ((string) ($row['id'] ?? '') === $firstId) {
            $listedFirst = $row;
            break;
        }
    }
    irn_assert(
        is_array($listedFirst) && (string) ($listedFirst['ir_number'] ?? '') === $firstNumber,
        'customer Incident Report list includes the Incident Report Number',
        $passed,
        $failed,
        $errors
    );

    $incidents = $itemsReport->listItemIncidents($MAIN_ID, [
        'supplier_id' => 'unassigned',
        'supplier_name' => 'Unassigned Supplier',
        'product_id' => '',
        'item_code' => '',
        'part_no' => '',
        'description' => $description,
    ]);
    $dialogNumbers = array_column($incidents['incidents'] ?? [], 'ir_number');
    irn_assert(
        in_array($firstNumber, $dialogNumbers, true),
        'Incident Item incidents list includes the Incident Report Number',
        $passed,
        $failed,
        $errors
    );
} catch (Throwable $error) {
    irn_assert(false, 'suite error: ' . $error->getMessage(), $passed, $failed, $errors);
} finally {
    $cleanup();
}

echo "\nPassed: {$passed} | Failed: {$failed}\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    exit(1);
}

echo "All Incident Report Number checks passed.\n";
exit(0);
