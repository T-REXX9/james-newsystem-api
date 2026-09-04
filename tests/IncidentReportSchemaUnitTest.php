<?php

declare(strict_types=1);

/**
 * Guards the incident_reports schema required by create/list APIs.
 *
 * Run: php api/tests/IncidentReportSchemaUnitTest.php
 */

$vars = file_exists(__DIR__ . '/../.env') ? (parse_ini_file(__DIR__ . '/../.env') ?: []) : [];
$dbHost = (string) ($vars['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1');
$dbPort = (int) ($vars['DB_PORT'] ?? getenv('DB_PORT') ?: 3306);
$dbName = (string) ($vars['DB_NAME'] ?? getenv('DB_NAME') ?: 'topnotch_migrate');
$dbUser = (string) ($vars['DB_USER'] ?? getenv('DB_USER') ?: 'root');
$dbPass = (string) ($vars['DB_PASS'] ?? getenv('DB_PASS') ?: '');

$passed = 0;
$failed = 0;
$errors = [];

function schema_assert(bool $ok, string $message): void
{
    global $passed, $failed, $errors;
    if ($ok) {
        $passed++;
        echo "  PASS {$message}\n";
        return;
    }
    $failed++;
    $errors[] = $message;
    echo "  FAIL {$message}\n";
}

echo "==========================================================\n";
echo " Incident Report Schema Guard\n";
echo "==========================================================\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Throwable $error) {
    echo "  FAIL could not connect: {$error->getMessage()}\n";
    exit(1);
}

$required = ['report_time', 'incident_time', 'done_by', 'decision_note', 'related_transactions'];
$placeholders = implode(',', array_fill(0, count($required), '?'));
$stmt = $pdo->prepare(
    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'incident_reports'
       AND COLUMN_NAME IN ({$placeholders})"
);
$stmt->execute($required);
$found = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

foreach ($required as $column) {
    schema_assert(in_array($column, $found, true), "incident_reports.{$column} exists");
}

echo "\nPassed: {$passed} | Failed: {$failed}\n";
if ($failed > 0) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo " - {$error}\n";
    }
    echo "Hint: run api/migrations/026_add_incident_report_times.sql (and earlier incident migrations).\n";
    exit(1);
}

echo "All incident report schema checks passed.\n";
exit(0);
