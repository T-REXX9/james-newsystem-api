<?php

declare(strict_types=1);

/**
 * Non-destructively merge a corporate snapshot into the James Newsystem DB.
 *
 * Shared source columns are inserted/updated by each table's unique key.
 * Target-only tables, target-only columns, and target rows absent from the
 * corporate snapshot are preserved. Keyless tables must already match; the
 * script refuses to guess how to identify their rows.
 *
 * Usage:
 *   php merge-corporate-database.php --source=source_db --target=target_db
 *   php merge-corporate-database.php --source=source_db --target=target_db --apply
 */

$options = getopt('', ['source:', 'target:', 'host::', 'port::', 'user::', 'password::', 'apply', 'analyze']);
$sourceDatabase = trim((string) ($options['source'] ?? ''));
$targetDatabase = trim((string) ($options['target'] ?? ''));
$host = trim((string) ($options['host'] ?? '127.0.0.1'));
$port = (int) ($options['port'] ?? 3306);
$user = (string) ($options['user'] ?? 'root');
$password = (string) ($options['password'] ?? '');
$apply = array_key_exists('apply', $options);
$analyze = array_key_exists('analyze', $options);

foreach (['source' => $sourceDatabase, 'target' => $targetDatabase] as $label => $database) {
    if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
        fwrite(STDERR, "Invalid or missing --{$label} database name.\n");
        exit(2);
    }
}

if ($sourceDatabase === $targetDatabase) {
    fwrite(STDERR, "Source and target databases must be different.\n");
    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$quoteIdentifier = static fn (string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
$qualifiedTable = static fn (string $database, string $table): string => sprintf(
    '`%s`.`%s`',
    str_replace('`', '``', $database),
    str_replace('`', '``', $table)
);

$databaseExists = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :database');
foreach ([$sourceDatabase, $targetDatabase] as $database) {
    $databaseExists->execute([':database' => $database]);
    if ((int) $databaseExists->fetchColumn() !== 1) {
        fwrite(STDERR, "Database does not exist: {$database}\n");
        exit(2);
    }
}

$tableStatement = $pdo->prepare(
    'SELECT TABLE_NAME, ENGINE
     FROM INFORMATION_SCHEMA.TABLES
     WHERE TABLE_SCHEMA = :database
       AND TABLE_TYPE = \'BASE TABLE\'
     ORDER BY TABLE_NAME'
);
$tableStatement->execute([':database' => $sourceDatabase]);
$sourceTables = array_map(static fn (array $row): string => (string) $row['TABLE_NAME'], $tableStatement->fetchAll());
$tableStatement->execute([':database' => $targetDatabase]);
$targetTables = [];
foreach ($tableStatement->fetchAll() as $row) {
    $targetTables[(string) $row['TABLE_NAME']] = strtoupper((string) ($row['ENGINE'] ?? ''));
}

$columnStatement = $pdo->prepare(
    'SELECT COLUMN_NAME, EXTRA
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = :database
       AND TABLE_NAME = :table
     ORDER BY ORDINAL_POSITION'
);
$primaryKeyStatement = $pdo->prepare(
    'SELECT COLUMN_NAME
     FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = :database
       AND TABLE_NAME = :table
       AND CONSTRAINT_NAME = \'PRIMARY\'
     ORDER BY ORDINAL_POSITION'
);
$uniqueConstraintStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = :database
       AND TABLE_NAME = :table
     AND CONSTRAINT_TYPE IN (\'PRIMARY KEY\', \'UNIQUE\')'
);
$identityIndexStatement = $pdo->prepare(
    'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS INDEX_COLUMNS
     FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = :database
       AND TABLE_NAME = :table
       AND NON_UNIQUE = 0
     GROUP BY INDEX_NAME
     ORDER BY (INDEX_NAME = \'PRIMARY\') DESC, INDEX_NAME ASC
     LIMIT 1'
);

$getColumns = static function (string $database, string $table) use ($columnStatement): array {
    $columnStatement->execute([':database' => $database, ':table' => $table]);
    return $columnStatement->fetchAll();
};
$getPrimaryKey = static function (string $database, string $table) use ($primaryKeyStatement): array {
    $primaryKeyStatement->execute([':database' => $database, ':table' => $table]);
    return array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $primaryKeyStatement->fetchAll());
};
$getIdentityColumns = static function (string $database, string $table) use ($identityIndexStatement): array {
    $identityIndexStatement->execute([':database' => $database, ':table' => $table]);
    $row = $identityIndexStatement->fetch();
    if (!is_array($row) || trim((string) ($row['INDEX_COLUMNS'] ?? '')) === '') {
        return [];
    }
    return array_map('trim', explode(',', (string) $row['INDEX_COLUMNS']));
};
$countRows = static function (string $database, string $table) use ($pdo, $qualifiedTable): int {
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $qualifiedTable($database, $table))->fetchColumn();
};

$plans = [];
$sourceOnlyTables = [];
$keylessTables = [];
$targetOnlyColumnCount = 0;

foreach ($sourceTables as $table) {
    if (!isset($targetTables[$table])) {
        $sourceOnlyTables[] = $table;
        continue;
    }

    $sourceColumns = $getColumns($sourceDatabase, $table);
    $targetColumns = $getColumns($targetDatabase, $table);
    $sourceColumnNames = array_column($sourceColumns, 'COLUMN_NAME');
    $targetColumnMap = [];
    foreach ($targetColumns as $column) {
        $targetColumnMap[(string) $column['COLUMN_NAME']] = (string) $column['EXTRA'];
    }

    $sharedColumns = array_values(array_filter(
        $sourceColumnNames,
        static fn (string $column): bool => isset($targetColumnMap[$column])
            && !str_contains(strtoupper($targetColumnMap[$column]), 'GENERATED')
    ));
    $targetOnlyColumns = array_values(array_diff(array_keys($targetColumnMap), $sourceColumnNames));
    $targetOnlyColumnCount += count($targetOnlyColumns);

    $uniqueConstraintStatement->execute([':database' => $targetDatabase, ':table' => $table]);
    $hasUniqueKey = (int) $uniqueConstraintStatement->fetchColumn() > 0;
    if (!$hasUniqueKey) {
        $keylessTables[] = $table;
        continue;
    }

    $primaryKey = $getPrimaryKey($targetDatabase, $table);
    $identityColumns = $primaryKey !== [] ? $primaryKey : $getIdentityColumns($targetDatabase, $table);
    $updatableColumns = array_values(array_diff($sharedColumns, $primaryKey));
    $quotedColumns = array_map($quoteIdentifier, $sharedColumns);
    $columnList = implode(', ', $quotedColumns);

    if ($updatableColumns === []) {
        $sql = sprintf(
            'INSERT IGNORE INTO %s (%s) SELECT %s FROM %s AS source_rows',
            $qualifiedTable($targetDatabase, $table),
            $columnList,
            $columnList,
            $qualifiedTable($sourceDatabase, $table)
        );
    } else {
        $updates = array_map(
            static fn (string $column): string => sprintf(
                '%s = VALUES(%s)',
                $quoteIdentifier($column),
                $quoteIdentifier($column)
            ),
            $updatableColumns
        );
        $sql = sprintf(
            'INSERT INTO %s (%s) SELECT %s FROM %s AS source_rows WHERE TRUE ON DUPLICATE KEY UPDATE %s',
            $qualifiedTable($targetDatabase, $table),
            $columnList,
            $columnList,
            $qualifiedTable($sourceDatabase, $table),
            implode(', ', $updates)
        );
    }

    $plans[] = [
        'table' => $table,
        'sql' => $sql,
        'source_rows' => $countRows($sourceDatabase, $table),
        'target_rows_before' => $countRows($targetDatabase, $table),
        'target_only_columns' => $targetOnlyColumns,
        'transactional' => $targetTables[$table] === 'INNODB',
        'shared_columns' => $sharedColumns,
        'identity_columns' => $identityColumns,
    ];
}

if ($sourceOnlyTables !== []) {
    fwrite(STDERR, 'Source tables missing from target: ' . implode(', ', $sourceOnlyTables) . "\n");
    exit(3);
}

foreach ($keylessTables as $table) {
    $sourceRows = $pdo->query('SELECT * FROM ' . $qualifiedTable($sourceDatabase, $table))->fetchAll();
    $targetRows = $pdo->query('SELECT * FROM ' . $qualifiedTable($targetDatabase, $table))->fetchAll();
    $normalize = static function (array $rows): array {
        $encoded = array_map(
            static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            $rows
        );
        sort($encoded, SORT_STRING);
        return $encoded;
    };
    if ($normalize($sourceRows) !== $normalize($targetRows)) {
        fwrite(STDERR, "Keyless table differs and cannot be merged safely: {$table}\n");
        exit(3);
    }
}

printf(
    "Corporate merge plan: %d shared keyed tables, %d identical keyless tables, %d target-only tables, %d preserved target-only columns.\n",
    count($plans),
    count($keylessTables),
    count(array_diff(array_keys($targetTables), $sourceTables)),
    $targetOnlyColumnCount
);

if ($analyze) {
    $newRows = 0;
    $updatedRows = 0;
    $changedTableDetails = [];

    foreach ($plans as $plan) {
        $identityPredicates = array_map(
            static fn (string $column): string => sprintf(
                'source_rows.%s <=> target_rows.%s',
                $quoteIdentifier($column),
                $quoteIdentifier($column)
            ),
            $plan['identity_columns']
        );
        $exactValuePredicates = array_map(
            static fn (string $column): string => sprintf(
                '((source_rows.%1$s IS NULL AND target_rows.%1$s IS NULL)
                  OR (source_rows.%1$s IS NOT NULL AND target_rows.%1$s IS NOT NULL
                      AND BINARY source_rows.%1$s = BINARY target_rows.%1$s))',
                $quoteIdentifier($column)
            ),
            $plan['shared_columns']
        );
        $identitySql = implode(' AND ', $identityPredicates);
        $exactValueSql = implode(' AND ', $exactValuePredicates);
        $sourceTable = $qualifiedTable($sourceDatabase, $plan['table']);
        $targetTable = $qualifiedTable($targetDatabase, $plan['table']);

        $newCount = (int) $pdo->query(sprintf(
            'SELECT COUNT(*) FROM %s AS source_rows
             WHERE NOT EXISTS (
                SELECT 1 FROM %s AS target_rows WHERE %s
             )',
            $sourceTable,
            $targetTable,
            $identitySql
        ))->fetchColumn();
        $updatedCount = (int) $pdo->query(sprintf(
            'SELECT COUNT(*) FROM %s AS source_rows
             WHERE EXISTS (
                SELECT 1 FROM %s AS target_rows WHERE %s
             )
               AND NOT EXISTS (
                SELECT 1 FROM %s AS target_rows WHERE %s AND %s
             )',
            $sourceTable,
            $targetTable,
            $identitySql,
            $targetTable,
            $identitySql,
            $exactValueSql
        ))->fetchColumn();

        $newRows += $newCount;
        $updatedRows += $updatedCount;
        if ($newCount > 0 || $updatedCount > 0) {
            $changedTableDetails[] = sprintf('%s: new=%d updated=%d', $plan['table'], $newCount, $updatedCount);
        }
    }

    printf(
        "Corporate data differences: %d new rows, %d updated rows, %d total changed rows across %d tables.\n",
        $newRows,
        $updatedRows,
        $newRows + $updatedRows,
        count($changedTableDetails)
    );
    foreach ($changedTableDetails as $detail) {
        echo $detail . "\n";
    }
}

if (!$apply) {
    echo "Dry run only. Add --apply to execute the merge.\n";
    exit(0);
}

$pdo->exec("SET SESSION sql_mode = ''");
$pdo->exec('SET SESSION foreign_key_checks = 0');
$pdo->exec('SET SESSION unique_checks = 1');

$changedTables = 0;
$affectedRows = 0;
$changedTableDetails = [];
$executePlan = static function (array $plan) use ($pdo, &$changedTables, &$affectedRows, &$changedTableDetails): void {
    $affected = $pdo->exec($plan['sql']);
    if ($affected === false) {
        throw new RuntimeException('Merge failed for table ' . $plan['table']);
    }
    $affectedRows += $affected;
    if ($affected > 0) {
        $changedTables++;
        $changedTableDetails[] = sprintf('%s=%d', $plan['table'], $affected);
    }
};

try {
    $pdo->beginTransaction();
    foreach ($plans as $plan) {
        if ($plan['transactional']) {
            $executePlan($plan);
        }
    }
    $pdo->commit();

    // MyISAM changes cannot participate in a transaction when GTID consistency
    // is enabled. They are applied only after the transactional validation set
    // succeeds; the required pre-merge backup remains the rollback mechanism.
    foreach ($plans as $plan) {
        if (!$plan['transactional']) {
            $executePlan($plan);
        }
    }
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Merge failed: ' . $error->getMessage() . "\n");
    exit(4);
} finally {
    $pdo->exec('SET SESSION foreign_key_checks = 1');
}

$verificationFailures = [];
foreach ($plans as $plan) {
    $targetRowsAfter = $countRows($targetDatabase, $plan['table']);
    if ($targetRowsAfter < $plan['source_rows']) {
        $verificationFailures[] = sprintf(
            '%s (source=%d target=%d)',
            $plan['table'],
            $plan['source_rows'],
            $targetRowsAfter
        );
        continue;
    }

    $sourceAlias = 'source_rows';
    $targetAlias = 'target_rows';
    $identityPredicates = array_map(
        static fn (string $column): string => sprintf(
            '%s.%s <=> %s.%s',
            $sourceAlias,
            $quoteIdentifier($column),
            $targetAlias,
            $quoteIdentifier($column)
        ),
        $plan['identity_columns']
    );
    $valuePredicates = array_map(
        static fn (string $column): string => sprintf(
            '%s.%s <=> %s.%s',
            $sourceAlias,
            $quoteIdentifier($column),
            $targetAlias,
            $quoteIdentifier($column)
        ),
        $plan['shared_columns']
    );
    $verificationSql = sprintf(
        'SELECT COUNT(*) FROM %s AS %s WHERE NOT EXISTS (
            SELECT 1 FROM %s AS %s WHERE %s AND %s
         )',
        $qualifiedTable($sourceDatabase, $plan['table']),
        $sourceAlias,
        $qualifiedTable($targetDatabase, $plan['table']),
        $targetAlias,
        implode(' AND ', $identityPredicates),
        implode(' AND ', $valuePredicates)
    );
    $mismatchCount = (int) $pdo->query($verificationSql)->fetchColumn();
    if ($mismatchCount > 0) {
        $verificationFailures[] = sprintf('%s (%d source rows differ or are missing)', $plan['table'], $mismatchCount);
    }
}

if ($verificationFailures !== []) {
    fwrite(STDERR, "Post-merge data validation failed:\n  " . implode("\n  ", $verificationFailures) . "\n");
    exit(5);
}

printf(
    "Merge complete: %d tables changed, %d affected-row operations, all source rows and shared values verified.\n",
    $changedTables,
    $affectedRows
);
if ($changedTableDetails !== []) {
    echo 'Changed table operations: ' . implode(', ', $changedTableDetails) . "\n";
}
