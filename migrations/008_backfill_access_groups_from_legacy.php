#!/usr/bin/env php
<?php
/**
 * 008_backfill_access_groups_from_legacy.php
 *
 * Repeatable, idempotent data-migration that populates `access_groups` and
 * staff assignments (`tblaccount.group_id`, `laccess_rights`, `access_override`)
 * from the legacy ACL tables (`tblusertype`, `tblweb_permission`, `tblweb_pagecateg`).
 *
 * Usage:
 *   php migrations/008_backfill_access_groups_from_legacy.php
 *
 * Environment variables (or .env file in api/):
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * Safe to run multiple times — uses INSERT … ON DUPLICATE KEY UPDATE for
 * access_groups and only touches staff who have not opted out via
 * access_override = 1.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. Bootstrap: load DB credentials from .env
// ---------------------------------------------------------------------------

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($val);
        }
    }
}

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port = $_ENV['DB_PORT'] ?? '3306';
$name = $_ENV['DB_NAME'] ?? 'topnotch';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Connected to {$name}@{$host}:{$port}\n";

// ---------------------------------------------------------------------------
// 2. Mapping: legacy page names (from tblweb_pagecateg.lpage_name /
//    tblweb_pagecateg.lcateg_page) → canonical new-system module IDs
//    matching AVAILABLE_APP_MODULES in james-newsystem/constants.ts.
//
//    Keys are lowercased for case-insensitive matching.
// ---------------------------------------------------------------------------

$PAGE_NAME_TO_MODULE_IDS = [
    // Warehouse — Inventory
    'product database'      => ['warehouse-inventory-product-database'],
    'inventory'             => ['warehouse-inventory-product-database'],
    'stock movement'        => ['warehouse-inventory-stock-movement'],
    'transfer product'      => ['warehouse-inventory-transfer-stock'],
    'transfer stock'        => ['warehouse-inventory-transfer-stock'],

    // Warehouse — Purchasing
    'purchase request'      => ['warehouse-purchasing-purchase-request'],
    'purchase order'        => ['warehouse-purchasing-purchase-order'],
    'purchase order report' => ['warehouse-purchasing-purchase-order'],
    'receiving report'      => ['warehouse-purchasing-receiving-stock'],
    'receiving delivery'    => ['warehouse-purchasing-receiving-stock'],
    'return supplier'       => ['warehouse-purchasing-return-to-supplier'],

    // Warehouse — Reports
    'reorder'               => ['warehouse-reports-reorder-report'],

    // Sales — Pipeline & Database
    'prospect'              => ['sales-pipeline-board'],
    'prospect data'         => ['sales-pipeline-board'],
    'customer database'     => ['sales-database-customer-database'],

    // Sales — Transactions
    'sales inquiry'         => ['sales-transaction-sales-inquiry'],
    'sales order'           => ['sales-transaction-sales-order'],
    'order slip'            => ['sales-transaction-order-slip'],
    'sales invoice'         => ['sales-transaction-invoice'],
    'invoice'               => ['sales-transaction-invoice'],

    // Sales — Reports
    'report'                => [
        'sales-reports-inquiry-report',
        'sales-reports-sales-report',
        'sales-reports-sales-development-report',
        'warehouse-reports-inventory-report',
        'warehouse-reports-reorder-report',
        'warehouse-reports-item-suggested-for-stock-report',
        'accounting-reports-accounting-overview',
        'accounting-reports-aging-report',
        'accounting-reports-collection-report',
        'accounting-reports-sales-return-report',
        'accounting-reports-accounts-receivable-report',
        'accounting-reports-freight-charges-report',
        'accounting-reports-purchase-history',
        'accounting-reports-inactive-active-customers',
        'accounting-reports-old-new-customers',
    ],
    'analytics'             => ['sales-performance-management-dashboard'],

    // Accounting — Transactions
    'collection report'     => ['accounting-transactions-daily-collection-entry'],
    'debit memo'            => ['accounting-transactions-freight-charges-debit'],
    'freight charges'       => ['accounting-transactions-freight-charges-debit'],
    'credit memo'           => ['accounting-transactions-sales-return-credit'],
    'sales return'          => ['accounting-transactions-sales-return-credit'],
    'adjustment'            => ['accounting-transactions-adjustment-entry'],

    // Accounting — Ledger / SOA
    'statement of account'  => ['accounting-accounting-statement-of-account'],

    // Maintenance — Customer
    'customer data'         => ['maintenance-customer-customer-data'],
    'customer group'        => ['maintenance-customer-customer-group'],
    'customer'              => ['maintenance-customer-customer-data'],

    // Maintenance — Product
    'supplier record'       => ['maintenance-product-suppliers'],
    'special price'         => ['maintenance-product-special-price'],
    'category'              => ['maintenance-product-category-management'],

    // Maintenance — Profile
    'staff records'         => ['maintenance-profile-staff'],
    'team'                  => ['maintenance-profile-team'],
    'approver'              => ['maintenance-profile-approver'],
    'group access'          => ['maintenance-profile-system-access'],

    // Communication
    'sms'                   => ['communication-text-menu-text-messages'],
    'call logs'             => ['sales-transaction-daily-call-monitoring'],
    'call status'           => ['sales-transaction-daily-call-monitoring'],

    // Campaign / Promotions
    'campaign'              => ['sales-transaction-product-promotions'],
    'campaign template'     => ['sales-transaction-product-promotions'],

    // Auto Responder (maps to communication)
    'auto responder'        => ['communication-messaging-inbox'],
];

// ---------------------------------------------------------------------------
// 3. Verify prerequisite tables exist
// ---------------------------------------------------------------------------

$requiredTables = ['tblusertype', 'tblweb_permission', 'tblweb_pagecateg', 'access_groups', 'tblaccount'];
foreach ($requiredTables as $table) {
    $check = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetchColumn();
    if ((int) $check === 0) {
        echo "ERROR: Table `{$table}` does not exist. Run schema migrations 004-007 first.\n";
        exit(1);
    }
}

// Ensure access_groups has a unique index on (main_id, name) for idempotent upserts.
// Add it if missing.
$idxExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'access_groups'
       AND INDEX_NAME = 'uq_access_groups_main_name'"
)->fetchColumn();

if ($idxExists === 0) {
    echo "Adding unique index uq_access_groups_main_name on access_groups(main_id, name)...\n";
    $pdo->exec('ALTER TABLE access_groups ADD UNIQUE INDEX uq_access_groups_main_name (main_id, name)');
}

// ---------------------------------------------------------------------------
// 4. Read legacy groups from tblusertype
// ---------------------------------------------------------------------------

$legacyGroups = $pdo->query(
    "SELECT lid, ltype_name, ldesc, lmain_id FROM tblusertype ORDER BY lid ASC"
)->fetchAll();

if (empty($legacyGroups)) {
    echo "No legacy groups found in tblusertype. Nothing to backfill.\n";
    exit(0);
}

echo sprintf("Found %d legacy group(s) in tblusertype.\n", count($legacyGroups));

// ---------------------------------------------------------------------------
// 5. For each legacy group, resolve enabled pages → canonical module IDs,
//    then upsert into access_groups.
// ---------------------------------------------------------------------------

$permStmt = $pdo->prepare(
    "SELECT wp.lpageno, wp.lstatus, pc.lpage_name, pc.lcateg_page
     FROM tblweb_permission wp
     JOIN tblweb_pagecateg pc ON pc.lid = wp.lpageno
     WHERE wp.lgroup = :group_id
       AND wp.lmain_id = :main_id
       AND wp.lstatus = 1"
);

$upsertStmt = $pdo->prepare(
    "INSERT INTO access_groups (main_id, name, description, access_rights, created_at)
     VALUES (:main_id, :name, :description, :access_rights, NOW())
     ON DUPLICATE KEY UPDATE
       description   = VALUES(description),
       access_rights = VALUES(access_rights)"
);

// Track mapping: legacy group lid → new access_groups id (per main_id)
$legacyToNewGroupId = [];
$groupsProcessed = 0;

foreach ($legacyGroups as $group) {
    $groupId = (int) $group['lid'];
    $groupName = trim($group['ltype_name'] ?? '');
    $groupDesc = trim($group['ldesc'] ?? '');
    $mainId = (int) ($group['lmain_id'] ?? 0);

    if ($groupName === '' || $mainId === 0) {
        echo "  Skipping group lid={$groupId}: empty name or main_id.\n";
        continue;
    }

    // Fetch enabled pages for this group + main_id
    $permStmt->execute(['group_id' => $groupId, 'main_id' => $mainId]);
    $perms = $permStmt->fetchAll();

    // Resolve to canonical module IDs
    $moduleIds = [];
    foreach ($perms as $perm) {
        $pageName = strtolower(trim($perm['lpage_name'] ?? $perm['lcateg_page'] ?? ''));
        if ($pageName === '') {
            continue;
        }
        if (isset($PAGE_NAME_TO_MODULE_IDS[$pageName])) {
            foreach ($PAGE_NAME_TO_MODULE_IDS[$pageName] as $mid) {
                $moduleIds[$mid] = true;
            }
        }
    }

    // Always grant home/dashboard access
    $moduleIds['home'] = true;

    $accessRightsJson = json_encode(array_keys($moduleIds), JSON_UNESCAPED_SLASHES);

    $upsertStmt->execute([
        'main_id'       => $mainId,
        'name'          => $groupName,
        'description'   => $groupDesc,
        'access_rights' => $accessRightsJson,
    ]);

    // Retrieve the new access_groups.id
    $newRow = $pdo->prepare(
        "SELECT id FROM access_groups WHERE main_id = :main_id AND name = :name LIMIT 1"
    );
    $newRow->execute(['main_id' => $mainId, 'name' => $groupName]);
    $newGroupId = (int) $newRow->fetchColumn();

    $legacyToNewGroupId["{$mainId}_{$groupId}"] = $newGroupId;

    $groupsProcessed++;
    echo sprintf(
        "  Group '%s' (legacy lid=%d, main_id=%d) → access_groups.id=%d with %d module(s)\n",
        $groupName,
        $groupId,
        $mainId,
        $newGroupId,
        count($moduleIds)
    );
}

echo sprintf("Upserted %d access group(s).\n", $groupsProcessed);

// ---------------------------------------------------------------------------
// 6. Update tblaccount: assign group_id, laccess_rights, access_override
//    for staff whose legacy ltype matches a migrated group.
//    Only update staff who do NOT already have access_override = 1.
// ---------------------------------------------------------------------------

$staffStmt = $pdo->prepare(
    "SELECT lid, ltype, lmother_id
     FROM tblaccount
     WHERE lstatus = 1
       AND ltype IS NOT NULL
       AND ltype != 1
       AND (access_override IS NULL OR access_override = 0)"
);
$staffStmt->execute();
$staffRows = $staffStmt->fetchAll();

$updateStaffStmt = $pdo->prepare(
    "UPDATE tblaccount
     SET group_id       = :group_id,
         laccess_rights = :access_rights,
         access_override = 0
     WHERE lid = :staff_id"
);

$staffUpdated = 0;
foreach ($staffRows as $staff) {
    $staffId = (int) $staff['lid'];
    $legacyType = (int) $staff['ltype'];
    $motherId = (int) $staff['lmother_id'];

    $key = "{$motherId}_{$legacyType}";
    if (!isset($legacyToNewGroupId[$key])) {
        continue;
    }

    $newGroupId = $legacyToNewGroupId[$key];

    // Fetch the group's access_rights
    $grpStmt = $pdo->prepare("SELECT access_rights FROM access_groups WHERE id = :id LIMIT 1");
    $grpStmt->execute(['id' => $newGroupId]);
    $accessRights = $grpStmt->fetchColumn() ?: '[]';

    $updateStaffStmt->execute([
        'group_id'      => $newGroupId,
        'access_rights' => $accessRights,
        'staff_id'      => $staffId,
    ]);

    $staffUpdated++;
}

echo sprintf("Updated %d staff account(s) with group assignments and access rights.\n", $staffUpdated);

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------

echo "Backfill complete.\n";
