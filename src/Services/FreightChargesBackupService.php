<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Exports and restores Freight Charges rows without reusing database row IDs.
 * The dump is a gzip-compressed JSONL package so restore never executes uploaded SQL.
 */
final class FreightChargesBackupService
{
    private const FORMAT = 'tnd-freight-charges';
    private const VERSION = 1;
    private const MAX_UNCOMPRESSED_BYTES = 4_294_967_296;
    private const MAX_LINE_BYTES = 8_388_608;
    private const MAX_ROWS_PER_TABLE = 2_000_000;
    private const HEADER_TABLE = 'tbldebit_memo';
    private const ITEM_TABLE = 'tbldebit_memo_items';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{path: string, filename: string, bytes: int, header_rows: int, item_rows: int} */
    public function createDumpGzipFile(string $directory): array
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Freight Charges backup directory');
        }

        $pdo = $this->db->pdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $temporaryPath = tempnam($directory, 'freight-charges-');
        if ($temporaryPath === false) {
            $pdo->rollBack();
            throw new RuntimeException('Unable to create a temporary Freight Charges dump');
        }

        $gzip = gzopen($temporaryPath, 'wb6');
        if ($gzip === false) {
            @unlink($temporaryPath);
            $pdo->rollBack();
            throw new RuntimeException('Unable to start Freight Charges dump compression');
        }

        try {
            $columns = [
                self::HEADER_TABLE => $this->tableColumns($pdo, self::HEADER_TABLE),
                self::ITEM_TABLE => $this->tableColumns($pdo, self::ITEM_TABLE),
            ];
            foreach ($columns as $table => $tableColumns) {
                if ($tableColumns === []) {
                    throw new RuntimeException("Required Freight Charges table {$table} was not found");
                }
            }

            $counts = [];
            foreach ($columns as $table => $_) {
                $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
            }
            $manifest = [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'exported_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
                'tables' => [
                    self::HEADER_TABLE => ['columns' => $columns[self::HEADER_TABLE], 'rows' => $counts[self::HEADER_TABLE]],
                    self::ITEM_TABLE => ['columns' => $columns[self::ITEM_TABLE], 'rows' => $counts[self::ITEM_TABLE]],
                ],
            ];
            $this->writeGzipLine($gzip, $manifest);

            foreach ($columns as $table => $_) {
                $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY `lid`');
                while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $this->writeGzipLine($gzip, ['table' => $table, 'row' => $row]);
                }
            }

            if (!gzclose($gzip)) {
                throw new RuntimeException('Unable to finish Freight Charges dump compression');
            }
            $gzip = false;
            $pdo->commit();

            $filename = 'freight_charges_' . (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Ymd_His') . '.jsonl.gz';
            $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            if (!rename($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Unable to save Freight Charges dump');
            }
            @chmod($path, 0600);
            $bytes = filesize($path);
            if ($bytes === false || $bytes <= 0) {
                @unlink($path);
                throw new RuntimeException('Freight Charges dump produced no data');
            }

            return [
                'path' => $path,
                'filename' => $filename,
                'bytes' => (int) $bytes,
                'header_rows' => $counts[self::HEADER_TABLE],
                'item_rows' => $counts[self::ITEM_TABLE],
            ];
        } catch (\Throwable $error) {
            if (is_resource($gzip)) {
                gzclose($gzip);
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($temporaryPath);
            throw $error;
        }
    }

    /** @return array{headers_inserted: int, headers_already_present: int, headers_conflicted: int, duplicate_source_headers: int, items_inserted: int, items_already_present: int, items_without_parent: int, items_skipped_conflict: int, row_ids_regenerated: bool} */
    public function restoreDumpGzipFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path) || !str_ends_with(strtolower($path), '.jsonl.gz')) {
            throw new RuntimeException('Select a valid Freight Charges .jsonl.gz dump');
        }
        $compressedBytes = filesize($path);
        if ($compressedBytes === false || $compressedBytes <= 0 || $compressedBytes > 2_147_483_648) {
            throw new RuntimeException('Freight Charges dump must be between 1 byte and 2 GiB');
        }

        $gzip = gzopen($path, 'rb');
        if ($gzip === false) {
            throw new RuntimeException('Unable to read the Freight Charges dump');
        }

        $uncompressedBytes = 0;
        $lineNumber = 0;
        $transactionStarted = false;
        $lockHeld = false;
        $pdo = $this->db->pdo();
        try {
            $manifest = $this->readJsonLine($gzip, $lineNumber, $uncompressedBytes);
            $this->validateManifest($manifest);

            $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
            $lockStmt->execute(['lock_name' => 'tnd-freight-charges-restore']);
            if ((int) $lockStmt->fetchColumn() !== 1) {
                throw new RuntimeException('Another Freight Charges restore is already running');
            }
            $lockHeld = true;

            $pdo->beginTransaction();
            $transactionStarted = true;
            $this->assertTransactionalTables($pdo);

            $headerColumns = $this->tableColumns($pdo, self::HEADER_TABLE);
            $itemColumns = $this->tableColumns($pdo, self::ITEM_TABLE);
            $headerInsertColumns = $this->sharedInsertColumns(
                $manifest['tables'][self::HEADER_TABLE]['columns'],
                $headerColumns,
                ['lrefno', 'lmain_id']
            );
            $itemInsertColumns = $this->sharedInsertColumns(
                $manifest['tables'][self::ITEM_TABLE]['columns'],
                $itemColumns,
                ['lrefno']
            );
            $headerInsert = $this->prepareInsert($pdo, self::HEADER_TABLE, $headerInsertColumns);
            $itemInsert = $this->prepareInsert($pdo, self::ITEM_TABLE, $itemInsertColumns);

            $existingHeadersByRef = [];
            $existingHeadersByDmNo = [];
            $headerRefnos = [];
            $existing = $pdo->query('SELECT lmain_id, lrefno, ldm_no FROM `' . self::HEADER_TABLE . '`');
            while (($row = $existing->fetch(PDO::FETCH_ASSOC)) !== false) {
                $mainId = (string) ($row['lmain_id'] ?? '');
                $refno = (string) ($row['lrefno'] ?? '');
                if ($refno !== '') {
                    $headerRefnos[$refno] = true;
                    $existingHeadersByRef[$this->key($mainId, $refno)] = true;
                }
                $dmNo = trim((string) ($row['ldm_no'] ?? ''));
                if ($mainId !== '' && $dmNo !== '') {
                    $existingHeadersByDmNo[$this->key($mainId, $dmNo)] = $refno;
                }
            }

            $existingItemKeys = [];
            $items = $pdo->query('SELECT lrefno, lgenrefno FROM `' . self::ITEM_TABLE . '`');
            while (($row = $items->fetch(PDO::FETCH_ASSOC)) !== false) {
                $refno = (string) ($row['lrefno'] ?? '');
                $genrefno = trim((string) ($row['lgenrefno'] ?? ''));
                if ($refno !== '' && $genrefno !== '') {
                    $existingItemKeys[$this->key($refno, $genrefno)] = true;
                }
            }

            $result = [
                'headers_inserted' => 0,
                'headers_already_present' => 0,
                'headers_conflicted' => 0,
                'duplicate_source_headers' => 0,
                'items_inserted' => 0,
                'items_already_present' => 0,
                'items_without_parent' => 0,
                'items_skipped_conflict' => 0,
                'row_ids_regenerated' => true,
            ];
            $sourceHeaderKeys = [];
            $blockedRefnos = [];
            $seenItemKeys = [];
            $headerRowsRead = 0;
            $itemRowsRead = 0;
            $readingItems = false;
            $sourceRowsTotal = 0;

            while (($line = gzgets($gzip, self::MAX_LINE_BYTES + 1)) !== false) {
                $lineNumber++;
                $uncompressedBytes += strlen($line);
                if ($uncompressedBytes > self::MAX_UNCOMPRESSED_BYTES || strlen($line) > self::MAX_LINE_BYTES || !str_ends_with($line, "\n")) {
                    throw new RuntimeException("Freight Charges dump line {$lineNumber} is invalid or exceeds size limits");
                }
                try {
                    $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $error) {
                    throw new RuntimeException("Freight Charges dump has invalid JSON on line {$lineNumber}", 0, $error);
                }
                if (!is_array($entry) || !is_array($entry['row'] ?? null)) {
                    throw new RuntimeException("Freight Charges dump line {$lineNumber} is not a table row");
                }

                $table = (string) ($entry['table'] ?? '');
                $row = $entry['row'];
                if ($table === self::HEADER_TABLE) {
                    if ($readingItems) {
                        throw new RuntimeException('Freight Charges dump has header rows after item rows');
                    }
                    $headerRowsRead++;
                    $sourceRowsTotal++;
                    $this->validateDumpRow($row, $manifest['tables'][$table]['columns'], $lineNumber);
                    $mainId = trim((string) ($row['lmain_id'] ?? ''));
                    $refno = trim((string) ($row['lrefno'] ?? ''));
                    $dmNo = trim((string) ($row['ldm_no'] ?? ''));
                    if ($mainId === '' || $refno === '') {
                        throw new RuntimeException("Freight Charges dump line {$lineNumber} is missing its main or record reference");
                    }

                    $refKey = $this->key($mainId, $refno);
                    if (isset($sourceHeaderKeys[$refKey])) {
                        $result['duplicate_source_headers']++;
                        continue;
                    }
                    $sourceHeaderKeys[$refKey] = true;

                    if (isset($existingHeadersByRef[$refKey])) {
                        $result['headers_already_present']++;
                        continue;
                    }
                    if (isset($headerRefnos[$refno])) {
                        $result['headers_conflicted']++;
                        $blockedRefnos[$refno] = true;
                        continue;
                    }
                    if ($dmNo !== '' && isset($existingHeadersByDmNo[$this->key($mainId, $dmNo)])) {
                        $result['headers_conflicted']++;
                        $blockedRefnos[$refno] = true;
                        continue;
                    }

                    $this->executeInsert($headerInsert, $headerInsertColumns, $row);
                    $result['headers_inserted']++;
                    $headerRefnos[$refno] = true;
                    $existingHeadersByRef[$refKey] = true;
                    if ($dmNo !== '') {
                        $existingHeadersByDmNo[$this->key($mainId, $dmNo)] = $refno;
                    }
                } elseif ($table === self::ITEM_TABLE) {
                    $readingItems = true;
                    $itemRowsRead++;
                    $sourceRowsTotal++;
                    $this->validateDumpRow($row, $manifest['tables'][$table]['columns'], $lineNumber);
                    $refno = trim((string) ($row['lrefno'] ?? ''));
                    if ($refno === '') {
                        $result['items_without_parent']++;
                        continue;
                    }
                    if (isset($blockedRefnos[$refno])) {
                        $result['items_skipped_conflict']++;
                        continue;
                    }
                    if (!isset($headerRefnos[$refno])) {
                        // Preserve orphan item rows from the source too; this table has no required parent FK.
                        $result['items_without_parent']++;
                    }

                    $genrefno = trim((string) ($row['lgenrefno'] ?? ''));
                    $itemKey = $genrefno !== '' ? $this->key($refno, $genrefno) : $this->key($refno, $this->rowHash($row));
                    if (isset($existingItemKeys[$itemKey]) || isset($seenItemKeys[$itemKey])) {
                        $result['items_already_present']++;
                        continue;
                    }
                    $this->executeInsert($itemInsert, $itemInsertColumns, $row);
                    $result['items_inserted']++;
                    $existingItemKeys[$itemKey] = true;
                    $seenItemKeys[$itemKey] = true;
                } else {
                    throw new RuntimeException("Freight Charges dump line {$lineNumber} contains unsupported table {$table}");
                }
            }

            if ($headerRowsRead !== $manifest['tables'][self::HEADER_TABLE]['rows']
                || $itemRowsRead !== $manifest['tables'][self::ITEM_TABLE]['rows']
                || $sourceRowsTotal !== $manifest['tables'][self::HEADER_TABLE]['rows'] + $manifest['tables'][self::ITEM_TABLE]['rows']) {
                throw new RuntimeException('Freight Charges dump row counts do not match its manifest');
            }
            if (gzclose($gzip) === false) {
                throw new RuntimeException('Unable to finish reading Freight Charges dump');
            }
            $gzip = false;
            $pdo->commit();
            $transactionStarted = false;

            return $result;
        } catch (\Throwable $error) {
            if (is_resource($gzip)) {
                gzclose($gzip);
            }
            if ($transactionStarted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            if ($lockHeld) {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
                $release->execute(['lock_name' => 'tnd-freight-charges-restore']);
            }
        }
    }

    /** @return list<string> */
    private function tableColumns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['table' => $table]);
        return array_map(static fn (array $row): string => (string) $row['COLUMN_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function writeGzipLine($gzip, array $value): void
    {
        $line = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        $written = gzwrite($gzip, $line);
        if ($written !== strlen($line)) {
            throw new RuntimeException('Unable to write Freight Charges dump data');
        }
    }

    /** @return array<string, mixed> */
    private function readJsonLine($gzip, int &$lineNumber, int &$uncompressedBytes): array
    {
        $line = gzgets($gzip, self::MAX_LINE_BYTES + 1);
        $lineNumber++;
        if (!is_string($line) || $line === '' || strlen($line) > self::MAX_LINE_BYTES || !str_ends_with($line, "\n")) {
            throw new RuntimeException('Freight Charges dump manifest is missing or too large');
        }
        $uncompressedBytes += strlen($line);
        try {
            $manifest = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('Freight Charges dump manifest is invalid JSON', 0, $error);
        }
        return is_array($manifest) ? $manifest : [];
    }

    private function validateManifest(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT || (int) ($manifest['version'] ?? 0) !== self::VERSION) {
            throw new RuntimeException('This is not a supported Freight Charges dump');
        }
        $tables = $manifest['tables'] ?? null;
        if (!is_array($tables) || !isset($tables[self::HEADER_TABLE], $tables[self::ITEM_TABLE])) {
            throw new RuntimeException('Freight Charges dump must contain both record and item tables');
        }
        foreach ([self::HEADER_TABLE, self::ITEM_TABLE] as $table) {
            $columns = $tables[$table]['columns'] ?? null;
            $rows = $tables[$table]['rows'] ?? null;
            $required = $table === self::HEADER_TABLE ? ['lid', 'lrefno', 'lmain_id'] : ['lid', 'lrefno'];
            if (!is_array($columns) || !is_int($rows) || $rows < 0 || $rows > self::MAX_ROWS_PER_TABLE) {
                throw new RuntimeException("Freight Charges dump metadata for {$table} is invalid");
            }
            $columns = array_values(array_unique(array_filter($columns, static fn ($column): bool => is_string($column) && preg_match('/^[A-Za-z0-9_]+$/', $column) === 1)));
            if (count($columns) !== count($tables[$table]['columns']) || array_diff($required, $columns) !== []) {
                throw new RuntimeException("Freight Charges dump columns for {$table} are invalid");
            }
        }
    }

    private function assertTransactionalTables(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME, ENGINE FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:header, :items)'
        );
        $stmt->execute(['header' => self::HEADER_TABLE, 'items' => self::ITEM_TABLE]);
        $engines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $engines[(string) $row['TABLE_NAME']] = strtoupper((string) $row['ENGINE']);
        }
        foreach ([self::HEADER_TABLE, self::ITEM_TABLE] as $table) {
            if (($engines[$table] ?? '') !== 'INNODB') {
                throw new RuntimeException("{$table} must use InnoDB before Freight Charges can be restored safely");
            }
        }
    }

    /** @param list<string> $sourceColumns @param list<string> $targetColumns @param list<string> $required @return list<string> */
    private function sharedInsertColumns(array $sourceColumns, array $targetColumns, array $required): array
    {
        $targetSet = array_fill_keys($targetColumns, true);
        $shared = array_values(array_filter($sourceColumns, static fn (string $column): bool => $column !== 'lid' && isset($targetSet[$column])));
        if (array_diff($required, $shared) !== []) {
            throw new RuntimeException('Freight Charges dump does not share the required reference columns with this server');
        }
        return $shared;
    }

    /** @param list<string> $columns */
    private function prepareInsert(PDO $pdo, string $table, array $columns): PDOStatement
    {
        $quoted = array_map(static fn (string $column): string => '`' . $column . '`', $columns);
        $placeholders = array_map(static fn (int $index): string => ':value_' . $index, array_keys($columns));
        return $pdo->prepare(sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(', ', $quoted), implode(', ', $placeholders)));
    }

    /** @param list<string> $columns @param array<string, mixed> $row */
    private function executeInsert(PDOStatement $stmt, array $columns, array $row): void
    {
        $values = [];
        foreach ($columns as $index => $column) {
            $values['value_' . $index] = $row[$column] ?? null;
        }
        $stmt->execute($values);
    }

    private function validateDumpRow(array $row, array $columns, int $lineNumber): void
    {
        if (count($row) !== count($columns) || array_diff(array_keys($row), $columns) !== [] || array_diff($columns, array_keys($row)) !== []) {
            throw new RuntimeException("Freight Charges dump row on line {$lineNumber} does not match its manifest");
        }
    }

    private function key(string $first, string $second): string
    {
        return json_encode([$first, $second], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function rowHash(array $row): string
    {
        unset($row['lid']);
        ksort($row);
        return hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
