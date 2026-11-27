<?php

declare(strict_types=1);

use App\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

/**
 * Safely remove duplicate Freight Charges debit ledger rows.
 *
 * Dry-run is the default. Apply mode requires an explicit archive path; on a
 * production environment it also requires --confirm-production.
 */

function usage(): never
{
    fwrite(STDOUT, <<<TXT
Usage:
  php scripts/repair-duplicate-freight-ledger.php [--apply] [--archive=/secure/path/archive.json] [--confirm-production]

By default this command only reports eligible duplicate groups. Apply mode
archives every row it will delete before changing the database.

TXT);
    exit(0);
}

function amountCents(mixed $value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    return (int) round((float) $value * 100);
}

function memoDate(array $memo): string
{
    $value = trim((string) ($memo['ldate'] ?? ''));
    if ($value === '') {
        $value = trim((string) ($memo['ldatetime'] ?? ''));
    }

    return substr($value, 0, 10);
}

/** @return array{eligible: bool, reason: string, memo?: array, keep_lid?: int, delete_rows?: array} */
function assessGroup(PDO $pdo, array $key, bool $lock): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $memoStmt = $pdo->prepare(
        "SELECT lid, lrefno, lmain_id, lcustomer, ldm_no, lamt, ldate, ldatetime, lstatus
         FROM tbldebit_memo
         WHERE lrefno = :refno
           AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id
           AND lcustomer = :customer_id
           AND LOWER(COALESCE(lstatus, '')) = 'posted'" . $suffix
    );
    $memoStmt->execute([
        'refno' => $key['lrefno'],
        'main_id' => (int) $key['lmainid'],
        'customer_id' => $key['lcustomerid'],
    ]);
    $memos = $memoStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($memos) !== 1) {
        return ['eligible' => false, 'reason' => 'no unique posted matching memo'];
    }
    $memo = $memos[0];

    $ledgerStmt = $pdo->prepare(
        "SELECT * FROM tblledger
         WHERE lrefno = :refno
           AND lcustomerid = :customer_id
           AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id
           AND lref_name = 'Freight Charges'
           AND ltype = 'Debit'
         ORDER BY lid ASC" . $suffix
    );
    $ledgerStmt->execute([
        'refno' => $key['lrefno'],
        'customer_id' => $key['lcustomerid'],
        'main_id' => (int) $key['lmainid'],
    ]);
    $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($ledgerRows) < 2) {
        return ['eligible' => false, 'reason' => 'duplicate no longer exists'];
    }

    $memoAmount = amountCents($memo['lamt'] ?? null);
    if ($memoAmount === null) {
        return ['eligible' => false, 'reason' => 'memo amount is not numeric'];
    }

    $dateMatches = [];
    foreach ($ledgerRows as $row) {
        $rowAmount = amountCents($row['lamt'] ?? null);
        $debitAmount = amountCents($row['ldebit'] ?? null);
        $creditAmount = amountCents($row['lcredit'] ?? null);
        if ($rowAmount !== $memoAmount || $debitAmount !== $memoAmount || $creditAmount !== 0) {
            return ['eligible' => false, 'reason' => 'ledger financial values do not exactly match memo'];
        }
        if (substr((string) ($row['ldatetime'] ?? ''), 0, 10) === memoDate($memo)) {
            $dateMatches[] = $row;
        }
    }

    if ($dateMatches === []) {
        return ['eligible' => false, 'reason' => 'no ledger row matches memo date'];
    }

    // Preserve the newest valid row when multiple copies share the memo date.
    usort($dateMatches, static fn (array $a, array $b): int => (int) $b['lid'] <=> (int) $a['lid']);
    $keepLid = (int) $dateMatches[0]['lid'];
    $deleteRows = array_values(array_filter(
        $ledgerRows,
        static fn (array $row): bool => (int) $row['lid'] !== $keepLid
    ));

    return [
        'eligible' => true,
        'reason' => 'exact duplicate entries for one posted memo',
        'memo' => $memo,
        'keep_lid' => $keepLid,
        'delete_rows' => $deleteRows,
    ];
}

function discoverGroups(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT lmainid, lcustomerid, lrefno, COUNT(*) AS row_count
         FROM tblledger
         WHERE lref_name = 'Freight Charges'
           AND ltype = 'Debit'
           AND COALESCE(lrefno, '') <> ''
           AND COALESCE(lcustomerid, '') <> ''
         GROUP BY lmainid, lcustomerid, lrefno
         HAVING COUNT(*) > 1
         ORDER BY lmainid, lcustomerid, lrefno"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$apply = false;
$confirmProduction = false;
$archivePath = '';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        usage();
    }
    if ($argument === '--apply') {
        $apply = true;
        continue;
    }
    if ($argument === '--confirm-production') {
        $confirmProduction = true;
        continue;
    }
    if (str_starts_with($argument, '--archive=')) {
        $archivePath = substr($argument, strlen('--archive='));
        continue;
    }
    fwrite(STDERR, "Unknown argument: {$argument}\n");
    usage();
}

$config = app_config();
if ($apply && $archivePath === '') {
    fwrite(STDERR, "Apply mode requires --archive=/secure/path/archive.json\n");
    exit(2);
}
if ($apply && strtolower($config->appEnv) === 'production' && !$confirmProduction) {
    fwrite(STDERR, "Production apply requires the explicit --confirm-production flag.\n");
    exit(2);
}
if ($apply && file_exists($archivePath)) {
    fwrite(STDERR, "Archive path already exists; refusing to overwrite it.\n");
    exit(2);
}

$pdo = (new Database($config))->pdo();
$groups = discoverGroups($pdo);
$eligible = [];
$skipped = [];
foreach ($groups as $group) {
    $assessment = assessGroup($pdo, $group, false);
    if ($assessment['eligible']) {
        $eligible[] = ['key' => $group, 'assessment' => $assessment];
    } else {
        $skipped[] = ['key' => $group, 'reason' => $assessment['reason']];
    }
}

$deletionCount = array_sum(array_map(
    static fn (array $item): int => count($item['assessment']['delete_rows']),
    $eligible
));

fwrite(STDOUT, sprintf(
    "Environment: %s | Database: %s\nDuplicate groups found: %d\nEligible groups: %d | Rows to archive/delete: %d\n",
    $config->appEnv,
    $config->dbName,
    count($groups),
    count($eligible),
    $deletionCount
));

foreach ($eligible as $item) {
    $key = $item['key'];
    $assessment = $item['assessment'];
    $removedIds = implode(',', array_map(static fn (array $row): string => (string) $row['lid'], $assessment['delete_rows']));
    fwrite(STDOUT, sprintf(
        "ELIGIBLE tenant=%s customer=%s ref=%s memo=%s keep_lid=%d delete_lids=%s\n",
        $key['lmainid'],
        $key['lcustomerid'],
        $key['lrefno'],
        $assessment['memo']['ldm_no'],
        $assessment['keep_lid'],
        $removedIds
    ));
}
if ($skipped !== []) {
    $skippedByReason = [];
    foreach ($skipped as $item) {
        $skippedByReason[$item['reason']] = ($skippedByReason[$item['reason']] ?? 0) + 1;
    }
    foreach ($skippedByReason as $reason => $count) {
        fwrite(STDOUT, "SKIPPED {$count} group(s): {$reason}\n");
    }
    foreach ($skipped as $item) {
        $key = $item['key'];
        fwrite(STDOUT, sprintf(
            "SKIP tenant=%s customer=%s ref=%s reason=%s\n",
            $key['lmainid'],
            $key['lcustomerid'],
            $key['lrefno'],
            $item['reason']
        ));
    }
}

if (!$apply || $eligible === []) {
    fwrite(STDOUT, $apply ? "Nothing eligible; no changes made.\n" : "Dry-run only; no changes made.\n");
    exit(0);
}

$pdo->beginTransaction();
try {
    $archiveRows = [];
    $lockedGroups = [];
    foreach ($eligible as $item) {
        $locked = assessGroup($pdo, $item['key'], true);
        if (!$locked['eligible']) {
            throw new RuntimeException('Eligibility changed while preparing apply; no rows were removed.');
        }
        $lockedGroups[] = ['key' => $item['key'], 'assessment' => $locked];
        foreach ($locked['delete_rows'] as $row) {
            $row['_repair_customer_id'] = $item['key']['lcustomerid'];
            $row['_repair_main_id'] = $item['key']['lmainid'];
            $row['_repair_refno'] = $item['key']['lrefno'];
            $row['_repair_memo_id'] = $locked['memo']['lid'];
            $row['_repair_memo_no'] = $locked['memo']['ldm_no'];
            $row['_repair_memo_amount'] = $locked['memo']['lamt'];
            $row['_repair_memo_date'] = memoDate($locked['memo']);
            $row['_repair_keep_lid'] = $locked['keep_lid'];
            $archiveRows[] = $row;
        }
    }

    $archive = [
        'database' => $config->dbName,
        'environment' => $config->appEnv,
        'archived_at' => gmdate(DATE_ATOM),
        'deleted_row_count' => count($archiveRows),
        'rows' => $archiveRows,
    ];
    $json = json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $handle = @fopen($archivePath, 'x');
    if ($handle === false) {
        throw new RuntimeException('Cannot create archive file exclusively at the requested path.');
    }
    try {
        if (fwrite($handle, $json) !== strlen($json)) {
            throw new RuntimeException('Could not fully write the archive file.');
        }
        fflush($handle);
    } finally {
        fclose($handle);
    }
    chmod($archivePath, 0600);

    $delete = $pdo->prepare(
        "DELETE FROM tblledger
         WHERE lid = :lid
           AND lcustomerid = :customer_id
           AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id
           AND lrefno = :refno
           AND lref_name = 'Freight Charges'
           AND ltype = 'Debit'"
    );
    $deleted = 0;
    foreach ($lockedGroups as $item) {
        foreach ($item['assessment']['delete_rows'] as $row) {
            $delete->execute([
                'lid' => (int) $row['lid'],
                'customer_id' => $item['key']['lcustomerid'],
                'main_id' => (int) $item['key']['lmainid'],
                'refno' => $item['key']['lrefno'],
            ]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('A ledger row changed during cleanup; transaction rolled back.');
            }
            $deleted++;
        }
    }
    $pdo->commit();
    fwrite(STDOUT, "Applied successfully: {$deleted} rows removed. Archive: {$archivePath}\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Cleanup failed safely: ' . $error->getMessage() . "\n");
    exit(1);
}
