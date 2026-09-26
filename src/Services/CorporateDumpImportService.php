<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Support\Env;
use PDO;
use RuntimeException;

/**
 * Safely merge a corporate SQL dump into the live application database.
 *
 * Guarantees against the live (target) database:
 * - Never DROP / TRUNCATE / DELETE tables or rows
 * - Writes via INSERT IGNORE for every table, so existing rows are never
 *   modified -- EXCEPT the product pricing tables (see PRICE_UPSERT_COLUMNS),
 *   where the imported CURRENT price amount overwrites the matching row via
 *   INSERT ... ON DUPLICATE KEY UPDATE so an updated price migrates across.
 * - Only shared columns that already exist on the target are written
 * - Tables present only in the dump are skipped (not created)
 * - Target-only tables and columns are left untouched
 *
 * The dump is loaded into a temporary staging database first (DROPs there are fine).
 */
final class CorporateDumpImportService
{
    private const STAGING_DB_PREFIX = 'corp_import_stg_';
    private const MAX_DUMP_BYTES = 2147483648; // 2 GiB
    private const MYSQL_STAGING_INIT_COMMAND = 'SET SESSION foreign_key_checks=0; SET SESSION unique_checks=0;';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{
     *   staging_database: string,
     *   tables_merged: int,
     *   tables_skipped_missing: list<string>,
     *   tables_skipped_keyless: list<string>,
     *   tables_skipped_no_shared_columns: list<string>,
     *   affected_rows: int,
     *   details: list<string>
     * }
     */
    public function importDumpFile(string $dumpPath): array
    {
        if (!is_file($dumpPath) || !is_readable($dumpPath)) {
            throw new RuntimeException('Dump file is missing or unreadable');
        }

        $bytes = filesize($dumpPath);
        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('Dump file is empty');
        }
        if ($bytes > self::MAX_DUMP_BYTES) {
            throw new RuntimeException('Dump file exceeds the 2 GiB import limit');
        }

        $targetDb = trim($this->config->dbName);
        if ($targetDb === '' || !preg_match('/^[A-Za-z0-9_]+$/', $targetDb)) {
            throw new RuntimeException('Target database name is not configured');
        }

        $stagingDb = self::STAGING_DB_PREFIX . date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $admin = $this->adminPdo();

        try {
            $admin->exec(
                'CREATE DATABASE `' . str_replace('`', '``', $stagingDb) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $this->loadDumpIntoDatabase($dumpPath, $stagingDb);
            $report = $this->mergeStagingIntoTarget($admin, $stagingDb, $targetDb);
            $report['staging_database'] = $stagingDb;
            return $report;
        } finally {
            try {
                $admin->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $stagingDb) . '`');
            } catch (\Throwable) {
                // Best-effort cleanup; original error (if any) already thrown.
            }
        }
    }

    /**
     * Normalize MariaDB / MySQL dump quirks so the dump can load into the local server.
     */
    public static function transformDumpLine(string $line): string
    {
        $line = preg_replace('/DEFAULT\s+CURRENT_DATE(\(\))?/i', 'DEFAULT NULL', $line) ?? $line;
        $line = preg_replace('/DEFAULT\s+\(?\s*CURDATE\s*\(\s*\)\s*\)?/i', 'DEFAULT NULL', $line) ?? $line;
        $line = preg_replace('/ON\s+UPDATE\s+CURRENT_DATE(\(\))?/i', '', $line) ?? $line;
        $line = str_ireplace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci', $line);
        $line = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/', '', $line) ?? $line;
        return $line;
    }

    /**
     * Per-table columns the corporate import is allowed to OVERWRITE on an
     * existing row (key collision). Every table not listed here stays strictly
     * add-only (INSERT IGNORE) so live data is never modified.
     *
     * Scope: product pricing only -- the imported price should win. Prices live
     * in tblinventory_price (lprice_amt is the current amount the app reads via
     * "latest row wins"), with tblinventory carrying the legacy denormalized
     * price columns. Only the CURRENT price amount is refreshed.
     *
     * @var array<string, list<string>>
     */
    private const PRICE_UPSERT_COLUMNS = [
        'tblinventory_price' => ['lprice_amt'],
        'tblinventory' => ['lprice', 'lsuppprice'],
    ];

    /**
     * @param list<string> $updatableColumns Columns allowed to be overwritten on
     *        key collision. Empty (the default) keeps the strict add-only
     *        INSERT IGNORE behaviour for every non-pricing table.
     * @return array{sql: string, mode: string}|null
     */
    public static function buildMergeSql(
        string $targetDb,
        string $sourceDb,
        string $table,
        array $sharedColumns,
        array $updatableColumns = []
    ): ?array {
        if ($sharedColumns === []) {
            return null;
        }

        $quote = static fn (string $identifier): string => '`' . str_replace('`', '``', $identifier) . '`';
        $qualified = static fn (string $database, string $tableName): string => sprintf(
            '%s.%s',
            $quote($database),
            $quote($tableName)
        );

        $columnList = implode(', ', array_map($quote, $sharedColumns));

        // Only refresh columns that are both allowed AND actually present in the
        // shared column set for this dump/target pair.
        $sharedSet = array_fill_keys($sharedColumns, true);
        $updates = [];
        foreach ($updatableColumns as $column) {
            if (isset($sharedSet[$column])) {
                $updates[] = sprintf('%s = VALUES(%s)', $quote($column), $quote($column));
            }
        }

        if ($updates !== []) {
            return [
                'mode' => 'upsert',
                'sql' => sprintf(
                    'INSERT INTO %s (%s) SELECT %s FROM %s ON DUPLICATE KEY UPDATE %s',
                    $qualified($targetDb, $table),
                    $columnList,
                    $columnList,
                    $qualified($sourceDb, $table),
                    implode(', ', $updates)
                ),
            ];
        }

        return [
            'mode' => 'insert_ignore',
            'sql' => sprintf(
                'INSERT IGNORE INTO %s (%s) SELECT %s FROM %s',
                $qualified($targetDb, $table),
                $columnList,
                $columnList,
                $qualified($sourceDb, $table)
            ),
        ];
    }

    private function adminPdo(): PDO
    {
        [$user, $pass] = $this->importDatabaseCredentials();

        try {
            return new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->config->dbHost, $this->config->dbPort),
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (\PDOException $error) {
            throw new RuntimeException(
                'Unable to connect as the corporate-import MySQL admin user'
                . ' (set CORPORATE_IMPORT_MYSQL_USER / CORPORATE_IMPORT_MYSQL_PASS). '
                . $error->getMessage()
            );
        }
    }

    private function loadDumpIntoDatabase(string $dumpPath, string $database): void
    {
        $mysql = $this->resolveMysqlBinary();
        [$user, $pass] = $this->importDatabaseCredentials();

        $isGzip = str_ends_with(strtolower($dumpPath), '.gz');
        $command = [
            $mysql,
            '--host=' . $this->config->dbHost,
            '--port=' . (string) $this->config->dbPort,
            '--user=' . $user,
            '--default-character-set=utf8mb4',
            '--max_allowed_packet=1G',
            '--init-command=' . self::MYSQL_STAGING_INIT_COMMAND,
            $database,
        ];

        $env = $this->buildProcessEnv($pass);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start mysql for corporate dump import');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $lineBuffer = '';
        $streamError = null;

        try {
            $source = $isGzip
                ? popen('gzip -dc ' . escapeshellarg($dumpPath), 'rb')
                : fopen($dumpPath, 'rb');
            if ($source === false) {
                throw new RuntimeException('Unable to open dump file for reading');
            }

            try {
                while (!feof($source)) {
                    $chunk = fread($source, 1024 * 256);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $lineBuffer .= $chunk;
                    while (($newline = strpos($lineBuffer, "\n")) !== false) {
                        $line = substr($lineBuffer, 0, $newline + 1);
                        $lineBuffer = substr($lineBuffer, $newline + 1);
                        $this->writeToMysqlPipe($pipes[0], self::transformDumpLine($line), $pipes[1], $pipes[2], $stdout, $stderr);
                    }
                }
                if ($lineBuffer !== '') {
                    $this->writeToMysqlPipe($pipes[0], self::transformDumpLine($lineBuffer), $pipes[1], $pipes[2], $stdout, $stderr);
                }
            } finally {
                if ($isGzip) {
                    pclose($source);
                } else {
                    fclose($source);
                }
            }
        } catch (\Throwable $error) {
            // The mysql process can exit before stdin finishes. Preserve its
            // stderr below instead of losing the actionable SQL/MySQL error.
            $streamError = $error;
        } finally {
            fclose($pipes[0]);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
        }

        if ($streamError !== null) {
            throw new RuntimeException(
                'Loading corporate dump into staging stopped while streaming'
                . ': ' . $streamError->getMessage()
                . ($stderr !== '' ? ' | mysql stderr: ' . trim($stderr) : '')
                . ($stdout !== '' ? ' | mysql stdout: ' . trim($stdout) : '')
            );
        }

        if ($exit !== 0) {
            throw new RuntimeException(
                'Loading corporate dump into staging failed'
                . ($stderr !== '' ? ': ' . trim($stderr) : '')
                . ($stdout !== '' ? ' | ' . trim($stdout) : '')
            );
        }
    }

    /**
     * @param resource $stdin
     * @param resource $stdoutPipe
     * @param resource $stderrPipe
     */
    private function writeToMysqlPipe($stdin, string $payload, $stdoutPipe, $stderrPipe, string &$stdout, string &$stderr): void
    {
        $written = 0;
        $toWrite = strlen($payload);
        while ($written < $toWrite) {
            $bytes = fwrite($stdin, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('mysql stopped accepting dump data');
            }
            $written += $bytes;
            $stdout .= (string) fread($stdoutPipe, 8192);
            $stderr .= (string) fread($stderrPipe, 8192);
        }
    }

    /**
     * @return array{
     *   tables_merged: int,
     *   tables_skipped_missing: list<string>,
     *   tables_skipped_keyless: list<string>,
     *   tables_skipped_no_shared_columns: list<string>,
     *   affected_rows: int,
     *   details: list<string>
     * }
     */
    private function mergeStagingIntoTarget(PDO $pdo, string $sourceDb, string $targetDb): array
    {
        $sourceTables = $this->listBaseTables($pdo, $sourceDb);
        $targetTables = $this->listBaseTables($pdo, $targetDb);
        $targetSet = array_fill_keys($targetTables, true);

        $skippedMissing = [];
        $skippedKeyless = [];
        $skippedNoShared = [];
        $details = [];
        $affectedRows = 0;
        $merged = 0;

        $pdo->exec('SET SESSION foreign_key_checks = 0');
        $pdo->exec('SET SESSION unique_checks = 0');
        $pdo->exec("SET SESSION sql_mode = ''");

        foreach ($sourceTables as $table) {
            if (!isset($targetSet[$table])) {
                $skippedMissing[] = $table;
                continue;
            }

            $sourceColumns = $this->listColumns($pdo, $sourceDb, $table);
            $targetColumns = $this->listColumns($pdo, $targetDb, $table);
            $targetColumnMap = [];
            foreach ($targetColumns as $column) {
                $targetColumnMap[$column['name']] = $column['extra'];
            }

            $shared = [];
            foreach ($sourceColumns as $column) {
                $name = $column['name'];
                if (!isset($targetColumnMap[$name])) {
                    continue;
                }
                if (str_contains(strtoupper($targetColumnMap[$name]), 'GENERATED')) {
                    continue;
                }
                $shared[] = $name;
            }

            if ($shared === []) {
                $skippedNoShared[] = $table;
                continue;
            }

            $primaryKey = $this->listPrimaryKeyColumns($pdo, $targetDb, $table);
            if ($primaryKey === [] && !$this->hasUniqueKey($pdo, $targetDb, $table)) {
                $skippedKeyless[] = $table;
                continue;
            }

            $plan = self::buildMergeSql(
                $targetDb,
                $sourceDb,
                $table,
                $shared,
                self::PRICE_UPSERT_COLUMNS[$table] ?? []
            );
            if ($plan === null) {
                $skippedNoShared[] = $table;
                continue;
            }

            $affected = $pdo->exec($plan['sql']);
            if ($affected === false) {
                throw new RuntimeException('Merge failed for table ' . $table);
            }

            $affectedRows += (int) $affected;
            $merged++;
            if ((int) $affected > 0) {
                $details[] = sprintf('%s: %s affected=%d', $table, $plan['mode'], (int) $affected);
            }
        }

        $pdo->exec('SET SESSION foreign_key_checks = 1');
        $pdo->exec('SET SESSION unique_checks = 1');

        return [
            'tables_merged' => $merged,
            'tables_skipped_missing' => $skippedMissing,
            'tables_skipped_keyless' => $skippedKeyless,
            'tables_skipped_no_shared_columns' => $skippedNoShared,
            'affected_rows' => $affectedRows,
            'details' => $details,
        ];
    }

    /**
     * @return list<string>
     */
    private function listBaseTables(PDO $pdo, string $database): array
    {
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = :database AND TABLE_TYPE = \'BASE TABLE\'
             ORDER BY TABLE_NAME'
        );
        $stmt->execute([':database' => $database]);
        return array_map(static fn (array $row): string => (string) $row['TABLE_NAME'], $stmt->fetchAll());
    }

    /**
     * @return list<array{name: string, extra: string}>
     */
    private function listColumns(PDO $pdo, string $database, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME, EXTRA
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([':database' => $database, ':table' => $table]);
        return array_map(
            static fn (array $row): array => [
                'name' => (string) $row['COLUMN_NAME'],
                'extra' => (string) ($row['EXTRA'] ?? ''),
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * @return list<string>
     */
    private function listPrimaryKeyColumns(PDO $pdo, string $database, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = :database
               AND TABLE_NAME = :table
               AND CONSTRAINT_NAME = \'PRIMARY\'
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([':database' => $database, ':table' => $table]);
        return array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $stmt->fetchAll());
    }

    private function hasUniqueKey(PDO $pdo, string $database, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = :database
               AND TABLE_NAME = :table
               AND CONSTRAINT_TYPE IN (\'PRIMARY KEY\', \'UNIQUE\')'
        );
        $stmt->execute([':database' => $database, ':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function resolveMysqlBinary(): string
    {
        $configured = trim((string) Env::get('MYSQL_PATH', ''));
        return $configured !== '' ? $configured : 'mysql';
    }

    /**
     * Use an explicitly configured import account when provided. Otherwise the
     * importer must use the same working database account as the application,
     * never an implicit root@localhost connection.
     *
     * @return array{0: string, 1: string}
     */
    private function importDatabaseCredentials(): array
    {
        $configuredUser = trim((string) Env::get('CORPORATE_IMPORT_MYSQL_USER', ''));
        if ($configuredUser !== '') {
            return [$configuredUser, (string) Env::get('CORPORATE_IMPORT_MYSQL_PASS', '')];
        }

        $applicationUser = trim($this->config->dbUser);
        if ($applicationUser === '') {
            throw new RuntimeException(
                'Corporate dump import requires DB_USER / DB_PASS or CORPORATE_IMPORT_MYSQL_USER / CORPORATE_IMPORT_MYSQL_PASS.'
            );
        }

        return [$applicationUser, $this->config->dbPass];
    }

    /**
     * @return array<string, string>
     */
    private function buildProcessEnv(string $password): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $env[$key] = (string) $value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value)) && !array_key_exists($key, $env)) {
                $env[$key] = (string) $value;
            }
        }
        $env['MYSQL_PWD'] = $password;
        $env['PATH'] = $env['PATH'] ?? (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin');
        return $env;
    }
}
