<?php

declare(strict_types=1);

/**
 * One-time backfill: for existing prospects/customers that have an agent
 * assigned (lsales_person) but no assignment date recorded (ldate_assigned),
 * set the assignment date to the staff-creation date (ldatereg, falling back
 * to ldatetime). This makes the Daily Call master list show a meaningful
 * assignment date for records created before the create-path fix (PR #25).
 *
 * SAFE:
 *   - Only touches rows with an assigned agent AND a blank/NULL/zero
 *     assignment date. Already-correct rows are never modified.
 *   - Idempotent: re-running changes nothing further.
 *   - Skips deleted rows; scoped to a single main_id.
 *
 * USAGE:
 *   php scripts/backfill-prospect-assignment-date.php            # DRY RUN (default) - reports, changes nothing
 *   php scripts/backfill-prospect-assignment-date.php --apply    # performs the UPDATE
 *
 * Run against PRODUCTION (the local DB is stale/incomplete). Back up
 * tblpatient first if your process requires it.
 */

require __DIR__ . '/../src/bootstrap.php';

use App\Database;

$apply = in_array('--apply', $argv, true);
$mainId = 1;

$db = new Database(app_config());
$pdo = $db->pdo();

// Selection predicate shared by the count, the sample, and the UPDATE.
$where = "
    p.lmain_id = :main_id
    AND COALESCE(p.ldeleted, 0) = 0
    AND TRIM(COALESCE(p.lsales_person, '')) <> ''
    AND (
        p.ldate_assigned IS NULL
        OR TRIM(p.ldate_assigned) = ''
        OR TRIM(p.ldate_assigned) LIKE '0000-00-00%'
    )
    AND (
        (TRIM(COALESCE(p.ldatereg, '')) <> '' AND TRIM(p.ldatereg) NOT LIKE '0000-00-00%')
        OR (TRIM(COALESCE(p.ldatetime, '')) <> '' AND TRIM(p.ldatetime) NOT LIKE '0000-00-00%')
    )
";

$count = $pdo->prepare("SELECT COUNT(*) FROM tblpatient p WHERE {$where}");
$count->execute(['main_id' => $mainId]);
$affected = (int) $count->fetchColumn();

echo ($apply ? "APPLY" : "DRY RUN") . " — backfill assignment date from creation date (main_id={$mainId})\n";
echo "Rows to update: {$affected}\n\n";

$sample = $pdo->prepare("
    SELECT p.lsessionid, TRIM(p.lcompany) AS company, p.lsales_person,
           DATE(COALESCE(NULLIF(TRIM(p.ldatereg), '0000-00-00 00:00:00'), p.ldatetime)) AS new_assigned
    FROM tblpatient p
    WHERE {$where}
    ORDER BY p.ldatereg DESC
    LIMIT 10
");
$sample->execute(['main_id' => $mainId]);
$rows = $sample->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    echo "Sample of what will be set:\n";
    foreach ($rows as $r) {
        echo sprintf(
            "  %-22s | %-28s | agent=%s | ldate_assigned <- %s\n",
            (string) $r['lsessionid'],
            substr((string) $r['company'], 0, 28),
            (string) $r['lsales_person'],
            (string) $r['new_assigned']
        );
    }
    echo "\n";
}

if (!$apply) {
    echo "No changes made. Re-run with --apply to perform the update.\n";
    exit(0);
}

if ($affected === 0) {
    echo "Nothing to update.\n";
    exit(0);
}

// Backfill: assignment date = date part of the staff-creation timestamp.
// Prefer ldatereg; fall back to ldatetime when ldatereg is empty/zero.
$update = $pdo->prepare("
    UPDATE tblpatient p
    SET p.ldate_assigned = DATE(
        CASE
            WHEN TRIM(COALESCE(p.ldatereg, '')) <> '' AND TRIM(p.ldatereg) NOT LIKE '0000-00-00%'
                THEN p.ldatereg
            ELSE p.ldatetime
        END
    )
    WHERE {$where}
");
$update->execute(['main_id' => $mainId]);
echo "Updated {$update->rowCount()} row(s). Backfill complete.\n";
