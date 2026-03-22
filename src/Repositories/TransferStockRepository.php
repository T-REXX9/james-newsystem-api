<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class TransferStockRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Normalize warehouse ID to canonical label format (WH1, WH2, etc.)
     * Handles both numeric IDs (1, 2, etc.) and canonical labels (WH1, WH2, etc.)
     */
    private function normalizeWarehouseId(string $value): string
    {
        $trimmed = trim(strtoupper($value));
        if ($trimmed === '') {
            return '';
        }
        // If already in canonical format (WH1, WH2, etc.), return as-is
        if (preg_match('/^WH\d+$/', $trimmed)) {
            return $trimmed;
        }
        // If numeric, convert to canonical format
        if (preg_match('/^\d+$/', $trimmed)) {
            return 'WH' . $trimmed;
        }
        // Return as-is for any other format
        return $trimmed;
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listTransfers(
        int $mainId,
        ?int $month = null,
        ?int $year = null,
        string $status = 'all',
        string $search = '',
        string $dateFrom = '',
        string $dateTo = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['tr.lmain_id = :main_id'];
        $params = ['main_id' => (string) $mainId];

        if ($month !== null && $year !== null) {
            $where[] = 'MONTH(tr.ltimestamp) = :month';
            $where[] = 'YEAR(tr.ltimestamp) = :year';
            $params['month'] = $month;
            $params['year'] = $year;
        }

        $dateFrom = trim($dateFrom);
        if ($dateFrom !== '') {
            $where[] = 'tr.ltimestamp >= :date_from';
            $params['date_from'] = $this->normalizeDateStart($dateFrom);
        }

        $dateTo = trim($dateTo);
        if ($dateTo !== '') {
            $where[] = 'tr.ltimestamp <= :date_to';
            $params['date_to'] = $this->normalizeDateEnd($dateTo);
        }

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus !== '' && $normalizedStatus !== 'all' && $normalizedStatus !== 'active') {
            $where[] = 'LOWER(COALESCE(tr.lstatus, "")) = :status';
            $params['status'] = $normalizedStatus;
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $where[] = 'CONCAT_WS(" ", COALESCE(tr.ltransfer_no, ""), COALESCE(tr.lpartno, ""), COALESCE(tr.lrefno, "")) LIKE :search';
            $params['search'] = '%' . $trimmedSearch . '%';
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) AS total FROM tblbranchinventory_transferlist tr WHERE {$whereSql}"
        );
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    tr.lid AS id,
    COALESCE(tr.lrefno, '') AS transfer_refno,
    COALESCE(tr.ltransfer_no, '') AS transfer_no,
    COALESCE(tr.ltimestamp, '') AS transfer_datetime,
    DATE(COALESCE(tr.ltimestamp, NOW())) AS transfer_date,
    COALESCE(tr.lstatus, 'Pending') AS status,
    COALESCE(tr.lpartno, '') AS part_numbers_raw,
    COALESCE(tr.lmain_id, '') AS main_id,
    COALESCE(tr.luser_id, '') AS user_id,
    COALESCE(tr.luser_id, '') AS processed_by_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tblbranchinventory_transferlist tr
LEFT JOIN tblaccount acc
  ON acc.lid = tr.luser_id
WHERE {$whereSql}
ORDER BY tr.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $params['limit'] = $perPage;
        $params['offset'] = $offset;

        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->mergeTransferAggregates($rows);
        $rows = array_map(fn(array $row): array => $this->mapTransferSummaryRow($row), $rows);

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'month' => $month,
                    'year' => $year,
                    'date_from' => $dateFrom !== '' ? $dateFrom : null,
                    'date_to' => $dateTo !== '' ? $dateTo : null,
                    'status' => $normalizedStatus === '' ? 'all' : $normalizedStatus,
                    'search' => $trimmedSearch,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTransfer(int $mainId, string $transferRefno): ?array
    {
        $header = $this->getTransferHeader($mainId, $transferRefno);
        if ($header === null) {
            return null;
        }

        $items = $this->getTransferItems($transferRefno, false);
        $summary = [
            'item_count' => count($items),
            'total_transfer_qty' => 0,
        ];
        foreach ($items as $item) {
            $summary['total_transfer_qty'] += (int) ($item['transfer_qty'] ?? 0);
        }

        return [
            'transfer' => $this->mapTransferSummaryRow($header),
            'items' => $items,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createTransfer(int $mainId, int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $counter = $this->nextTransferCounter($pdo);
            $transferNo = trim((string) ($payload['transfer_no'] ?? ''));
            if ($transferNo === '') {
                $transferNo = 'TR-' . $counter;
            }

            $transferRefno = trim((string) ($payload['transfer_refno'] ?? ''));
            if ($transferRefno === '') {
                $transferRefno = date('YmdHis') . random_int(12345, 99999) . $counter;
            }

            $transferDatetime = $this->normalizeDateTime((string) ($payload['transfer_date'] ?? 'now'));
            $status = $this->normalizeStatus((string) ($payload['status'] ?? 'Pending'));
            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $partNumbers = $this->extractPartNumbers($payload, $items);

            if ($items === [] && $partNumbers === []) {
                throw new RuntimeException('items or part_numbers is required');
            }

            $insertCounter = $pdo->prepare(
                'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
                 VALUES ("Transfer Product", :max_no)'
            );
            $insertCounter->execute(['max_no' => $counter]);

            $insertHeader = $pdo->prepare(
                'INSERT INTO tblbranchinventory_transferlist
                (ltransfer_no, lrefno, lmain_id, luser_id, lpartno, ltimestamp, lstatus)
                VALUES
                (:transfer_no, :refno, :main_id, :user_id, :partno, :timestamp, :status)'
            );
            $insertHeader->execute([
                'transfer_no' => $transferNo,
                'refno' => $transferRefno,
                'main_id' => (string) $mainId,
                'user_id' => (string) $userId,
                'partno' => implode(', ', $partNumbers),
                'timestamp' => $transferDatetime,
                'status' => $status,
            ]);

            if ($items !== []) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $this->insertTransferItem($pdo, $mainId, $transferRefno, $item);
                }
            } else {
                foreach ($partNumbers as $partNo) {
                    $this->insertTransferItemFromPartNo($pdo, $mainId, $transferRefno, $partNo);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->getTransfer($mainId, $transferRefno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created transfer stock');
        }
        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateTransfer(int $mainId, string $transferRefno, array $payload): ?array
    {
        $existing = $this->getTransfer($mainId, $transferRefno);
        if ($existing === null) {
            return null;
        }

        $fields = [];
        $params = [
            'main_id' => (string) $mainId,
            'refno' => $transferRefno,
        ];

        if (array_key_exists('status', $payload)) {
            $fields[] = 'lstatus = :status';
            $params['status'] = $this->normalizeStatus((string) $payload['status']);
        }
        if (array_key_exists('transfer_date', $payload)) {
            $fields[] = 'ltimestamp = :timestamp';
            $params['timestamp'] = $this->normalizeDateTime((string) $payload['transfer_date']);
        }
        if (array_key_exists('part_numbers', $payload)) {
            $partNumbers = $this->extractPartNumbers($payload, []);
            $fields[] = 'lpartno = :partno';
            $params['partno'] = implode(', ', $partNumbers);
        }

        if ($fields !== []) {
            $sql = 'UPDATE tblbranchinventory_transferlist SET ' . implode(', ', $fields) . ' WHERE lmain_id = :main_id AND lrefno = :refno';
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);

            if (isset($params['timestamp'])) {
                $syncStmt = $this->db->pdo()->prepare(
                    'UPDATE tblinventory_logs
                     SET ldateadded = :timestamp
                     WHERE lrefno = :refno
                       AND ltransaction_type = "Transfer Product"'
                );
                $syncStmt->execute([
                    'timestamp' => $params['timestamp'],
                    'refno' => $transferRefno,
                ]);
            }
        }

        return $this->getTransfer($mainId, $transferRefno);
    }

    public function deleteTransfer(int $mainId, string $transferRefno): bool
    {
        $existing = $this->getTransfer($mainId, $transferRefno);
        if ($existing === null) {
            return false;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $deleteItems = $pdo->prepare('DELETE FROM tblbranchinventory_transferproducts WHERE lrefno = :refno');
            $deleteItems->execute(['refno' => $transferRefno]);

            $deleteLogs = $pdo->prepare(
                'DELETE FROM tblinventory_logs
                 WHERE lrefno = :refno
                   AND ltransaction_type = "Transfer Product"'
            );
            $deleteLogs->execute(['refno' => $transferRefno]);

            $deleteHeader = $pdo->prepare(
                'DELETE FROM tblbranchinventory_transferlist
                 WHERE lmain_id = :main_id
                   AND lrefno = :refno'
            );
            $deleteHeader->execute([
                'main_id' => (string) $mainId,
                'refno' => $transferRefno,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(int $mainId, string $transferRefno, array $payload): array
    {
        $transfer = $this->getTransfer($mainId, $transferRefno);
        if ($transfer === null) {
            throw new RuntimeException('Transfer stock not found');
        }

        $status = strtolower((string) ($transfer['transfer']['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'submitted'], true)) {
            throw new RuntimeException('Only pending/submitted transfers can be modified');
        }

        $this->insertTransferItem($this->db->pdo(), $mainId, $transferRefno, $payload);
        $itemId = (int) $this->db->pdo()->lastInsertId();
        $item = $this->getItemById($mainId, $itemId);
        if ($item === null) {
            throw new RuntimeException('Unable to load created transfer item');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateItem(int $mainId, int $itemId, array $payload): ?array
    {
        $existing = $this->getItemById($mainId, $itemId);
        if ($existing === null) {
            return null;
        }

        $fields = [];
        $params = ['item_id' => $itemId];

        if (array_key_exists('from_item_session', $payload)) {
            $fields[] = 'litemsession_from = :from_session';
            $params['from_session'] = trim((string) $payload['from_item_session']);
        }
        if (array_key_exists('to_item_session', $payload)) {
            $fields[] = 'litemsession_to = :to_session';
            $params['to_session'] = trim((string) $payload['to_item_session']);
        }
        if (array_key_exists('from_warehouse_id', $payload)) {
            $fields[] = 'lwarehouse_from = :from_warehouse';
            $params['from_warehouse'] = $this->normalizeWarehouseId((string) $payload['from_warehouse_id']);
        }
        if (array_key_exists('to_warehouse_id', $payload)) {
            $fields[] = 'lwarehouse_to = :to_warehouse';
            $params['to_warehouse'] = $this->normalizeWarehouseId((string) $payload['to_warehouse_id']);
        }
        if (array_key_exists('from_original_qty', $payload)) {
            $fields[] = 'loriginal_qty_from = :from_original_qty';
            $params['from_original_qty'] = max(0, (float) $payload['from_original_qty']);
        }
        if (array_key_exists('to_original_qty', $payload)) {
            $fields[] = 'loriginal_qty_to = :to_original_qty';
            $params['to_original_qty'] = max(0, (float) $payload['to_original_qty']);
        }
        if (array_key_exists('transfer_qty', $payload)) {
            $fields[] = 'ltransfer_qty = :transfer_qty';
            $fields[] = 'ledited = 1';
            $params['transfer_qty'] = max(0, (float) $payload['transfer_qty']);
        }

        if ($fields !== []) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblbranchinventory_transferproducts
                 SET ' . implode(', ', $fields) . '
                 WHERE lid = :item_id'
            );
            $stmt->execute($params);
        }

        return $this->getItemById($mainId, $itemId);
    }

    public function deleteItem(int $mainId, int $itemId): bool
    {
        $existing = $this->getItemById($mainId, $itemId);
        if ($existing === null) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tblbranchinventory_transferproducts WHERE lid = :item_id');
        $stmt->execute(['item_id' => $itemId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function applyAction(int $mainId, int $userId, string $transferRefno, string $action, array $payload = []): ?array
    {
        $existing = $this->getTransfer($mainId, $transferRefno);
        if ($existing === null) {
            return null;
        }

        $currentStatus = strtolower((string) ($existing['transfer']['status'] ?? 'pending'));
        $normalizedAction = strtolower(trim($action));

        if (in_array($normalizedAction, ['submit', 'submitrecord', 'editrecord'], true)) {
            if (!in_array($currentStatus, ['pending', 'submitted'], true)) {
                throw new RuntimeException('Only pending transfers can be submitted');
            }
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblbranchinventory_transferlist
                 SET lstatus = "Submitted"
                 WHERE lmain_id = :main_id
                   AND lrefno = :refno'
            );
            $stmt->execute([
                'main_id' => (string) $mainId,
                'refno' => $transferRefno,
            ]);
            return $this->getTransfer($mainId, $transferRefno);
        }

        if (in_array($normalizedAction, ['approve', 'approverecord'], true)) {
            if (!$this->isApprover($userId)) {
                throw new RuntimeException('Only approver accounts can approve transfer stock');
            }
            if (!in_array($currentStatus, ['submitted'], true)) {
                throw new RuntimeException('Only submitted transfers can be approved');
            }

            $this->postTransferRecord($mainId, $userId, $transferRefno);
            return $this->getTransfer($mainId, $transferRefno);
        }

        throw new RuntimeException('Unsupported action: ' . $action);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getTransferHeader(int $mainId, string $transferRefno): ?array
    {
        $sql = <<<SQL
SELECT
    tr.lid AS id,
    COALESCE(tr.lrefno, '') AS transfer_refno,
    COALESCE(tr.ltransfer_no, '') AS transfer_no,
    COALESCE(tr.ltimestamp, '') AS transfer_datetime,
    DATE(COALESCE(tr.ltimestamp, NOW())) AS transfer_date,
    COALESCE(tr.lstatus, 'Pending') AS status,
    COALESCE(tr.lpartno, '') AS part_numbers_raw,
    COALESCE(tr.lmain_id, '') AS main_id,
    COALESCE(tr.luser_id, '') AS user_id,
    COALESCE(tr.luser_id, '') AS processed_by_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tblbranchinventory_transferlist tr
LEFT JOIN tblaccount acc
  ON acc.lid = tr.luser_id
WHERE tr.lmain_id = :main_id
  AND tr.lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'refno' => $transferRefno,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTransferItems(string $transferRefno, bool $onlyEdited): array
    {
        $sql = <<<SQL
SELECT
    tp.lid AS id,
    COALESCE(tp.lrefno, '') AS transfer_id,
    COALESCE(tp.litem_id, '') AS item_id,
    COALESCE(tp.lpartno, '') AS part_no,
    COALESCE(tp.litemcode, '') AS item_code,
    COALESCE(tp.lbrand, '') AS brand,
    COALESCE(tp.ldescription, '') AS description,
    COALESCE(tp.llocation, '') AS location,
    COALESCE(tp.litemsession_from, '') AS from_item_session,
    COALESCE(tp.lwarehouse_from, '') AS from_warehouse_id,
    COALESCE(tp.loriginal_qty_from, 0) AS from_original_qty,
    COALESCE(tp.litemsession_to, '') AS to_item_session,
    COALESCE(tp.lwarehouse_to, '') AS to_warehouse_id,
    COALESCE(tp.loriginal_qty_to, 0) AS to_original_qty,
    COALESCE(tp.ltransfer_qty, 0) AS transfer_qty,
    COALESCE(tp.ledited, 0) AS edited
FROM tblbranchinventory_transferproducts tp
WHERE tp.lrefno = :refno
SQL;
        if ($onlyEdited) {
            $sql .= ' AND COALESCE(tp.ledited, 0) = 1';
        }
        $sql .= ' ORDER BY tp.lid ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $transferRefno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTransferSummaryRow(array $row): array
    {
        $partNumbers = array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            explode(',', (string) ($row['part_numbers_raw'] ?? ''))
        )));

        $status = ucfirst(strtolower((string) ($row['status'] ?? 'Pending')));

        return [
            'id' => (string) ($row['transfer_refno'] ?? ''),
            'transfer_refno' => (string) ($row['transfer_refno'] ?? ''),
            'transfer_no' => (string) ($row['transfer_no'] ?? ''),
            'transfer_date' => (string) ($row['transfer_date'] ?? ''),
            'transfer_datetime' => (string) ($row['transfer_datetime'] ?? ''),
            'status' => $status,
            'status_key' => strtolower($status),
            'part_numbers' => $partNumbers,
            'part_numbers_raw' => (string) ($row['part_numbers_raw'] ?? ''),
            'created_by' => trim((string) ($row['created_by'] ?? '')),
            'processed_by' => (string) ($row['user_id'] ?? ''),
            'processed_by_id' => (string) ($row['processed_by_id'] ?? $row['user_id'] ?? ''),
            'main_id' => (string) ($row['main_id'] ?? ''),
            'item_count' => (int) ($row['item_count'] ?? 0),
            'total_transfer_qty' => (float) ($row['total_transfer_qty'] ?? 0),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mergeTransferAggregates(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $refnos = [];
        foreach ($rows as $row) {
            $refno = trim((string) ($row['transfer_refno'] ?? ''));
            if ($refno !== '') {
                $refnos[] = $refno;
            }
        }
        $refnos = array_values(array_unique($refnos));
        if ($refnos === []) {
            return $rows;
        }

        $placeholders = [];
        $bind = [];
        foreach ($refnos as $idx => $refno) {
            $key = ':ref' . $idx;
            $placeholders[] = $key;
            $bind[$key] = $refno;
        }

        $sql = sprintf(
            'SELECT lrefno, COUNT(*) AS item_count, SUM(COALESCE(ltransfer_qty, 0)) AS total_transfer_qty
             FROM tblbranchinventory_transferproducts
             WHERE lrefno IN (%s)
             GROUP BY lrefno',
            implode(', ', $placeholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($bind as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $aggregateByRef = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $agg) {
            $refno = (string) ($agg['lrefno'] ?? '');
            if ($refno === '') {
                continue;
            }
            $aggregateByRef[$refno] = [
                'item_count' => (int) ($agg['item_count'] ?? 0),
                'total_transfer_qty' => (float) ($agg['total_transfer_qty'] ?? 0),
            ];
        }

        foreach ($rows as &$row) {
            $refno = (string) ($row['transfer_refno'] ?? '');
            $agg = $aggregateByRef[$refno] ?? ['item_count' => 0, 'total_transfer_qty' => 0.0];
            $row['item_count'] = $agg['item_count'];
            $row['total_transfer_qty'] = $agg['total_transfer_qty'];
        }
        unset($row);

        return $rows;
    }

    private function nextTransferCounter(PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) AS max_no
             FROM tblnumber_generator
             WHERE ltransaction_type = "Transfer Product"'
        );
        $stmt->execute();
        return (int) ($stmt->fetchColumn() ?: 0) + 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $items
     * @return array<int, string>
     */
    private function extractPartNumbers(array $payload, array $items): array
    {
        $parts = [];

        if (isset($payload['part_numbers']) && is_array($payload['part_numbers'])) {
            foreach ($payload['part_numbers'] as $part) {
                $candidate = strtoupper(trim((string) $part));
                if ($candidate !== '') {
                    $parts[] = $candidate;
                }
            }
        } elseif (isset($payload['part_numbers'])) {
            $raw = trim((string) $payload['part_numbers']);
            if ($raw !== '') {
                foreach (explode(',', $raw) as $part) {
                    $candidate = strtoupper(trim($part));
                    if ($candidate !== '') {
                        $parts[] = $candidate;
                    }
                }
            }
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidate = strtoupper(trim((string) ($item['part_no'] ?? '')));
            if ($candidate !== '') {
                $parts[] = $candidate;
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertTransferItem(PDO $pdo, int $mainId, string $transferRefno, array $payload): void
    {
        $item = $this->resolveInventoryItem($mainId, $payload);

        $fromWarehouse = trim((string) ($payload['from_warehouse_id'] ?? ''));
        $toWarehouse = trim((string) ($payload['to_warehouse_id'] ?? ''));
        $fromOriginalQty = max(0.0, (float) ($payload['from_original_qty'] ?? 0));
        $toOriginalQty = max(0.0, (float) ($payload['to_original_qty'] ?? 0));

        if ($fromWarehouse === '' && isset($payload['from_warehouse'])) {
            [$fromWarehouseParsed, $fromQtyParsed] = $this->parseWarehouseValue((string) $payload['from_warehouse']);
            $fromWarehouse = $fromWarehouseParsed;
            if ($fromOriginalQty <= 0 && $fromQtyParsed > 0) {
                $fromOriginalQty = $fromQtyParsed;
            }
        }
        if ($toWarehouse === '' && isset($payload['to_warehouse'])) {
            [$toWarehouseParsed, $toQtyParsed] = $this->parseWarehouseValue((string) $payload['to_warehouse']);
            $toWarehouse = $toWarehouseParsed;
            if ($toOriginalQty <= 0 && $toQtyParsed > 0) {
                $toOriginalQty = $toQtyParsed;
            }
        }

        // Normalize warehouse IDs to canonical format
        $fromWarehouse = $this->normalizeWarehouseId($fromWarehouse);
        $toWarehouse = $this->normalizeWarehouseId($toWarehouse);

        // Validate source and destination warehouses are not empty
        if ($fromWarehouse === '' || $toWarehouse === '') {
            throw new RuntimeException('Source and destination warehouses are required');
        }

        // Validate source and destination are different
        if ($fromWarehouse === $toWarehouse) {
            throw new RuntimeException('Source and destination warehouses must be different');
        }

        $transferQty = max(0.0, (float) ($payload['transfer_qty'] ?? 0));

        // Validate quantity is positive
        if ($transferQty <= 0) {
            throw new RuntimeException('Transfer quantity must be positive');
        }

        $edited = $transferQty > 0 ? 1 : (int) ($payload['edited'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO tblbranchinventory_transferproducts
            (lrefno, litem_id, lpartno, litemcode, lbrand, ldescription, llocation,
             litemsession_from, lwarehouse_from, loriginal_qty_from,
             litemsession_to, lwarehouse_to, loriginal_qty_to,
             ltransfer_qty, ledited)
            VALUES
            (:refno, :item_id, :part_no, :item_code, :brand, :description, :location,
             :from_session, :from_warehouse, :from_original_qty,
             :to_session, :to_warehouse, :to_original_qty,
             :transfer_qty, :edited)'
        );
        $stmt->execute([
            'refno' => $transferRefno,
            'item_id' => (string) ($item['id'] ?? ''),
            'part_no' => (string) ($item['part_no'] ?? ''),
            'item_code' => (string) ($item['item_code'] ?? ''),
            'brand' => (string) ($item['brand'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'location' => (string) ($item['location_name'] ?? ''),
            'from_session' => trim((string) ($payload['from_item_session'] ?? $payload['item_session_from'] ?? $item['session'] ?? '')),
            'from_warehouse' => $fromWarehouse,
            'from_original_qty' => $fromOriginalQty,
            'to_session' => trim((string) ($payload['to_item_session'] ?? $payload['item_session_to'] ?? $item['session'] ?? '')),
            'to_warehouse' => $toWarehouse,
            'to_original_qty' => $toOriginalQty,
            'transfer_qty' => $transferQty,
            'edited' => $edited,
        ]);
    }

    private function insertTransferItemFromPartNo(PDO $pdo, int $mainId, string $transferRefno, string $partNo): void
    {
        $stmt = $pdo->prepare(
            'SELECT
                itm.lid AS id,
                itm.lsession AS session,
                itm.lpartno AS part_no,
                itm.litemcode AS item_code,
                itm.lbrand AS brand,
                itm.ldescription AS description,
                COALESCE(loc.lname, "") AS location_name
             FROM tblinventory_item itm
             LEFT JOIN tblitem_location loc ON loc.lid = itm.llocation
             WHERE itm.lmain_id = :main_id
               AND itm.lstatus = 1
               AND itm.lpartno = :part_no
             ORDER BY itm.litemcode ASC
             LIMIT 1'
        );
        $stmt->execute([
            'main_id' => $mainId,
            'part_no' => $partNo,
        ]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($item === false) {
            throw new RuntimeException('Inventory item not found for part_no: ' . $partNo);
        }

        $insert = $pdo->prepare(
            'INSERT INTO tblbranchinventory_transferproducts
            (lrefno, litem_id, lpartno, litemcode, lbrand, ldescription, llocation)
            VALUES
            (:refno, :item_id, :part_no, :item_code, :brand, :description, :location)'
        );
        $insert->execute([
            'refno' => $transferRefno,
            'item_id' => (string) ($item['id'] ?? ''),
            'part_no' => (string) ($item['part_no'] ?? ''),
            'item_code' => (string) ($item['item_code'] ?? ''),
            'brand' => (string) ($item['brand'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'location' => (string) ($item['location_name'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveInventoryItem(int $mainId, array $payload): array
    {
        $itemId = trim((string) ($payload['item_id'] ?? ''));
        $itemSession = trim((string) ($payload['item_session'] ?? $payload['item_refno'] ?? ''));
        $partNo = trim((string) ($payload['part_no'] ?? ''));
        $itemCode = trim((string) ($payload['item_code'] ?? ''));

        if ($itemId === '' && $itemSession === '' && $partNo === '' && $itemCode === '') {
            throw new RuntimeException('item_id, item_session, part_no, or item_code is required');
        }

        $sql = <<<SQL
SELECT
    itm.lid AS id,
    itm.lsession AS session,
    itm.lpartno AS part_no,
    itm.litemcode AS item_code,
    itm.lbrand AS brand,
    itm.ldescription AS description,
    COALESCE(loc.lname, '') AS location_name
FROM tblinventory_item itm
LEFT JOIN tblitem_location loc ON loc.lid = itm.llocation
WHERE itm.lmain_id = :main_id
  AND itm.lstatus = 1
  AND %s
ORDER BY itm.litemcode ASC
LIMIT 1
SQL;

        $lookups = [];
        if ($itemId !== '') {
            $lookups[] = [
                'where' => 'itm.lid = :lookup_value',
                'value' => $itemId,
            ];
            // Some migrated UIs send legacy session as item_id. Try that too.
            $lookups[] = [
                'where' => 'itm.lsession = :lookup_value',
                'value' => $itemId,
            ];
        }
        if ($itemSession !== '') {
            $lookups[] = [
                'where' => 'itm.lsession = :lookup_value',
                'value' => $itemSession,
            ];
        }
        if ($partNo !== '') {
            $lookups[] = [
                'where' => 'TRIM(itm.lpartno) = TRIM(:lookup_value)',
                'value' => $partNo,
            ];
        }
        if ($itemCode !== '') {
            $lookups[] = [
                'where' => 'TRIM(itm.litemcode) = TRIM(:lookup_value)',
                'value' => $itemCode,
            ];
        }

        foreach ($lookups as $lookup) {
            $stmt = $this->db->pdo()->prepare(sprintf($sql, (string) $lookup['where']));
            $stmt->execute([
                'main_id' => $mainId,
                'lookup_value' => (string) $lookup['value'],
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row !== false) {
                return $row;
            }
        }

        throw new RuntimeException('Inventory item not found');
    }

    private function postTransferRecord(int $mainId, int $userId, string $transferRefno): void
    {
        $transfer = $this->getTransferHeader($mainId, $transferRefno);
        if ($transfer === null) {
            throw new RuntimeException('Transfer stock not found');
        }

        $items = $this->getTransferItems($transferRefno, false);
        $timestamp = $this->normalizeDateTime((string) ($transfer['transfer_datetime'] ?? 'now'));
        $transferNo = (string) ($transfer['transfer_no'] ?? '');

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($items as $row) {
                $qty = max(0.0, (float) ($row['transfer_qty'] ?? 0));
                if ($qty <= 0) {
                    continue;
                }

                // Normalize warehouse IDs to canonical format
                $fromWarehouse = $this->normalizeWarehouseId((string) ($row['from_warehouse_id'] ?? ''));
                $toWarehouse = $this->normalizeWarehouseId((string) ($row['to_warehouse_id'] ?? ''));

                // Validate source and destination warehouses are not empty
                if ($fromWarehouse === '' || $toWarehouse === '') {
                    throw new RuntimeException('Source and destination warehouses are required for all items');
                }

                // Validate source and destination are different
                if ($fromWarehouse === $toWarehouse) {
                    throw new RuntimeException('Source and destination warehouses must be different for all items');
                }

                // Re-check availability within the approval transaction
                $itemSession = (string) ($row['from_item_session'] ?? '');
                if ($itemSession !== '') {
                    $availStmt = $pdo->prepare(
                        'SELECT COALESCE(SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0)), 0) AS available_qty
                         FROM tblinventory_logs lg
                         WHERE lg.linvent_id = :item_session
                           AND lg.lwarehouse = :warehouse'
                    );
                    $availStmt->execute([
                        'item_session' => $itemSession,
                        'warehouse' => $fromWarehouse,
                    ]);
                    $availRow = $availStmt->fetch(PDO::FETCH_ASSOC);
                    $availableQty = max(0.0, (float) ($availRow['available_qty'] ?? 0));

                    if ($qty > $availableQty) {
                        throw new RuntimeException(
                            'Insufficient stock for transfer. Item has only ' . $availableQty . ' units available in ' . $fromWarehouse
                        );
                    }
                }

                $insertIn = $pdo->prepare(
                    'INSERT INTO tblinventory_logs
                    (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id, lrefno, llocation, lwarehouse, ltransaction_type)
                    VALUES
                    (:invent_id, :lin, 0, :ltotal, :dateadded, :process_by, "+", :note, :inventory_id, :refno, "", :warehouse, "Transfer Product")'
                );
                $insertIn->execute([
                    'invent_id' => (string) ($row['to_item_session'] ?? ''),
                    'lin' => $qty,
                    'ltotal' => $qty,
                    'dateadded' => $timestamp,
                    'process_by' => $transferNo,
                    'note' => 'Stock Transfer IN from ' . $fromWarehouse . ' to ' . $toWarehouse . ' (Item Code: ' . (string) ($row['item_code'] ?? '') . ')',
                    'inventory_id' => (string) ($row['item_id'] ?? ''),
                    'refno' => $transferRefno,
                    'warehouse' => $toWarehouse,
                ]);

                $insertOut = $pdo->prepare(
                    'INSERT INTO tblinventory_logs
                    (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id, lrefno, llocation, lwarehouse, ltransaction_type)
                    VALUES
                    (:invent_id, 0, :lout, :ltotal, :dateadded, :process_by, "-", :note, :inventory_id, :refno, "", :warehouse, "Transfer Product")'
                );
                $insertOut->execute([
                    'invent_id' => (string) ($row['from_item_session'] ?? ''),
                    'lout' => $qty,
                    'ltotal' => $qty,
                    'dateadded' => $timestamp,
                    'process_by' => $transferNo,
                    'note' => 'Stock Transfer OUT from ' . $fromWarehouse . ' to ' . $toWarehouse . ' (Item Code: ' . (string) ($row['item_code'] ?? '') . ')',
                    'inventory_id' => (string) ($row['item_id'] ?? ''),
                    'refno' => $transferRefno,
                    'warehouse' => $fromWarehouse,
                ]);
            }

            $updateStatus = $pdo->prepare(
                'UPDATE tblbranchinventory_transferlist
                 SET lstatus = "Approved"
                 WHERE lmain_id = :main_id
                   AND lrefno = :refno'
            );
            $updateStatus->execute([
                'main_id' => (string) $mainId,
                'refno' => $transferRefno,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function isApprover(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
             FROM tblapprover
             WHERE lstaff_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => (string) $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array{0:string,1:float}
     */
    private function parseWarehouseValue(string $warehouseValue): array
    {
        $parts = explode(':::', $warehouseValue);
        $warehouse = trim((string) ($parts[0] ?? ''));
        $qty = isset($parts[1]) ? (float) $parts[1] : 0.0;
        return [$warehouse, max(0.0, $qty)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getItemById(int $mainId, int $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    tp.lid AS id,
    COALESCE(tp.lrefno, '') AS transfer_id,
    COALESCE(tp.litem_id, '') AS item_id,
    COALESCE(tp.lpartno, '') AS part_no,
    COALESCE(tp.litemcode, '') AS item_code,
    COALESCE(tp.lbrand, '') AS brand,
    COALESCE(tp.ldescription, '') AS description,
    COALESCE(tp.llocation, '') AS location,
    COALESCE(tp.litemsession_from, '') AS from_item_session,
    COALESCE(tp.lwarehouse_from, '') AS from_warehouse_id,
    COALESCE(tp.loriginal_qty_from, 0) AS from_original_qty,
    COALESCE(tp.litemsession_to, '') AS to_item_session,
    COALESCE(tp.lwarehouse_to, '') AS to_warehouse_id,
    COALESCE(tp.loriginal_qty_to, 0) AS to_original_qty,
    COALESCE(tp.ltransfer_qty, 0) AS transfer_qty,
    COALESCE(tp.ledited, 0) AS edited
FROM tblbranchinventory_transferproducts tp
INNER JOIN tblbranchinventory_transferlist tr
    ON tr.lrefno = tp.lrefno
WHERE tr.lmain_id = :main_id
  AND tp.lid = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'item_id' => $itemId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function normalizeDateStart(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new RuntimeException('Invalid date_from value');
        }
        return date('Y-m-d 00:00:00', $ts);
    }

    private function normalizeDateEnd(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new RuntimeException('Invalid date_to value');
        }
        return date('Y-m-d 23:59:59', $ts);
    }

    private function normalizeDateTime(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            $trimmed = 'now';
        }

        // Date-only transfer inputs should keep the actual processing time
        // instead of defaulting to midnight, which makes stock movement logs
        // look like they happened at 12:00 AM.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            $trimmed .= ' ' . date('H:i:s');
        }

        $ts = strtotime($trimmed);
        if ($ts === false) {
            throw new RuntimeException('Invalid datetime value');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'cancelled', 'canceled' => 'Cancelled',
            default => 'Pending',
        };
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params, bool $withPagination): void
    {
        foreach ($params as $key => $value) {
            if (!$withPagination && ($key === 'limit' || $key === 'offset')) {
                continue;
            }
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        if ($withPagination && array_key_exists('limit', $params)) {
            $stmt->bindValue('limit', (int) $params['limit'], PDO::PARAM_INT);
        }
        if ($withPagination && array_key_exists('offset', $params)) {
            $stmt->bindValue('offset', (int) $params['offset'], PDO::PARAM_INT);
        }
    }
}
