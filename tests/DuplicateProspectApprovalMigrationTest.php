<?php

declare(strict_types=1);

/**
 * Run: php tests/DuplicateProspectApprovalMigrationTest.php
 */

$migration = file_get_contents(__DIR__ . '/../migrations/053_add_duplicate_prospect_customer_request.sql');
if ($migration === false
    || !str_contains($migration, 'duplicate_prospect')
    || !str_contains($migration, 'ALTER TABLE customer_requests')) {
    throw new RuntimeException('Duplicate prospect approval migration is incomplete.');
}

echo "PASS: duplicate prospect requests are supported by the customer approval queue\n";
