<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\AuditTrailWriter;
use PDO;
use RuntimeException;

final class OrderSlipRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listOrderSlips(
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

        $params = [
            'main_id' => (string) $mainId,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = [
            'dr.lmain_id = :main_id',
        ];

        if ($month !== null && $year !== null) {
            $params['month'] = $month;
            $params['year'] = $year;
            $where[] = 'MONTH(dr.ldate) = :month';
            $where[] = 'YEAR(dr.ldate) = :year';
        }

        if ($dateFrom !== '') {
            $where[] = 'dr.ldate >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'dr.ldate <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus === '' || $normalizedStatus === 'active') {
            $where[] = "(COALESCE(dr.lcancel, 0) = 0 AND LOWER(COALESCE(dr.lstatus, '')) <> 'cancelled')";
        } elseif ($normalizedStatus === 'cancelled' || $normalizedStatus === 'canceled') {
            $where[] = "(COALESCE(dr.lcancel, 0) = 1 OR LOWER(COALESCE(dr.lstatus, '')) = 'cancelled')";
        } elseif ($normalizedStatus === 'pending') {
            $where[] = "(LOWER(COALESCE(dr.lstatus, '')) = 'pending')";
        } elseif ($normalizedStatus === 'posted') {
            $where[] = "(LOWER(COALESCE(dr.lstatus, '')) = 'posted')";
        } elseif ($normalizedStatus !== 'all') {
            $where[] = "(COALESCE(dr.lcancel, 0) = 0 AND LOWER(COALESCE(dr.lstatus, '')) <> 'cancelled')";
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_no'] = '%' . $trimmedSearch . '%';
            $params['search_ref'] = '%' . $trimmedSearch . '%';
            $params['search_cust'] = '%' . $trimmedSearch . '%';
            $params['search_contact'] = '%' . $trimmedSearch . '%';
            $params['search_so'] = '%' . $trimmedSearch . '%';
            $params['search_item'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    COALESCE(dr.linvoice_no, '') LIKE :search_no
    OR COALESCE(dr.lrefno, '') LIKE :search_ref
    OR COALESCE(dr.lcustomer_name, '') LIKE :search_cust
    OR COALESCE(dr.lcustomerid, '') LIKE :search_contact
    OR COALESCE(dr.lsales_no, '') LIKE :search_so
    OR EXISTS (
        SELECT 1
        FROM tbldelivery_receipt_items dri_search
        WHERE dri_search.lor_refno = dr.lrefno
          AND CONCAT_WS(' ', COALESCE(dri_search.lpartno, ''), COALESCE(dri_search.litemcode, ''), COALESCE(dri_search.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) AS total FROM tbldelivery_receipt dr WHERE {$whereSql}");
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    dr.lid AS id,
    COALESCE(dr.lrefno, '') AS order_slip_refno,
    COALESCE(dr.linvoice_no, '') AS slip_no,
    COALESCE(dr.lsales_refno, '') AS order_id,
    COALESCE(dr.lsales_no, '') AS sales_no,
    COALESCE(dr.lcustomerid, '') AS contact_id,
    COALESCE(dr.lcustomer_name, '') AS customer_name,
    COALESCE(dr.ldate, '') AS sales_date,
    COALESCE(dr.lsales_person, '') AS sales_person,
    COALESCE(dr.lsales_person_id, '') AS sales_person_id,
    COALESCE(dr.lsales_address, '') AS delivery_address,
    COALESCE(dr.lmy_refno, '') AS reference_no,
    COALESCE(dr.lyour_refno, '') AS customer_reference,
    COALESCE(dr.lshipped, '') AS send_by,
    COALESCE(dr.ldel_to, '') AS delivered_to,
    COALESCE(dr.lprod_type, '') AS product_type,
    COALESCE(dr.lprice_group, '') AS price_group,
    COALESCE(dr.lcredit_limit, 0) AS credit_limit,
    COALESCE(dr.lterms, '') AS terms,
    COALESCE(dr.lpromisorry_note, '') AS promise_to_pay,
    COALESCE(dr.lpo_no, '') AS po_number,
    COALESCE(dr.lnote, '') AS remarks,
    COALESCE(dr.ldm_trackingno, '') AS tracking_no,
    COALESCE((
        SELECT dm.ldm_no
        FROM tbldebit_memo dm
        WHERE dm.ltrans_refno = dr.lrefno
        ORDER BY dm.lid DESC
        LIMIT 1
    ), '') AS debit_memo_no,
    COALESCE(dr.lstatus, 'Pending') AS status,
    COALESCE(dr.IsPrinted, 0) AS is_printed,
    COALESCE(dr.lcancel, 0) AS is_cancelled,
    COALESCE(dr.lcancel_reason, '') AS cancel_reason,
    COALESCE(dr.ldatetime, '') AS created_at,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tbldelivery_receipt dr
LEFT JOIN tblaccount acc
    ON acc.lid = dr.luser
WHERE {$whereSql}
ORDER BY dr.lid DESC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $refnos = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['order_slip_refno'] ?? ''),
            $items
        )));
        $aggregateByRef = $this->fetchListAggregatesByRefnos($refnos);
        foreach ($items as &$row) {
            $ref = (string) ($row['order_slip_refno'] ?? '');
            $agg = $aggregateByRef[$ref] ?? ['item_count' => 0, 'grand_total' => 0.0];
            $row['item_count'] = (int) ($agg['item_count'] ?? 0);
            $row['grand_total'] = (float) ($agg['grand_total'] ?? 0);
        }
        unset($row);

        return [
            'items' => $items,
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
     * @return array{month:int, year:int}
     */
    private function resolveLatestPeriod(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                YEAR(ldate) AS y,
                MONTH(ldate) AS m,
                COUNT(*) AS cnt
             FROM tbldelivery_receipt
             WHERE lmain_id = :main_id
               AND ldate IS NOT NULL
             GROUP BY YEAR(ldate), MONTH(ldate)
             ORDER BY (COUNT(*) >= 10) DESC, YEAR(ldate) DESC, MONTH(ldate) DESC
             LIMIT 1'
        );
        $stmt->execute(['main_id' => (string) $mainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [
                'month' => (int) date('m'),
                'year' => (int) date('Y'),
            ];
        }

        return [
            'month' => max(1, min(12, (int) ($row['m'] ?? date('m')))),
            'year' => (int) ($row['y'] ?? date('Y')),
        ];
    }

    /**
     * @param array<int, string> $refnos
     * @return array<string, array{item_count:int, grand_total:float}>
     */
    private function fetchListAggregatesByRefnos(array $refnos): array
    {
        if ($refnos === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [];
        foreach ($refnos as $idx => $refno) {
            $key = 'ref' . $idx;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $refno;
        }

        $sql = <<<SQL
SELECT
    dri.lor_refno AS order_slip_refno,
    COUNT(*) AS item_count,
    SUM(COALESCE(dri.lqty, 0) * COALESCE(dri.lprice, 0)) AS grand_total
FROM tbldelivery_receipt_items dri
WHERE dri.lor_refno IN (
    %s
)
GROUP BY dri.lor_refno
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(', ', $placeholders)));
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['order_slip_refno'] ?? '');
            if ($ref === '') {
                continue;
            }
            $mapped[$ref] = [
                'item_count' => (int) ($row['item_count'] ?? 0),
                'grand_total' => (float) ($row['grand_total'] ?? 0),
            ];
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOrderSlip(int $mainId, string $orderSlipRefno): ?array
    {
        $sql = <<<SQL
SELECT
    dr.lid AS id,
    COALESCE(dr.lrefno, '') AS order_slip_refno,
    COALESCE(dr.linvoice_no, '') AS slip_no,
    COALESCE(dr.lsales_refno, '') AS order_id,
    COALESCE(dr.lsales_no, '') AS sales_no,
    COALESCE(dr.lcustomerid, '') AS contact_id,
    COALESCE(dr.lcustomer_name, '') AS customer_name,
    COALESCE(dr.ldate, '') AS sales_date,
    COALESCE(dr.lsales_person, '') AS sales_person,
    COALESCE(dr.lsales_person_id, '') AS sales_person_id,
    COALESCE(dr.lsales_address, '') AS delivery_address,
    COALESCE(dr.lmy_refno, '') AS reference_no,
    COALESCE(dr.lyour_refno, '') AS customer_reference,
    COALESCE(dr.lshipped, '') AS send_by,
    COALESCE(dr.ldel_to, '') AS delivered_to,
    COALESCE(dr.lprod_type, '') AS product_type,
    COALESCE(dr.lprice_group, '') AS price_group,
    COALESCE(dr.lcredit_limit, 0) AS credit_limit,
    COALESCE(dr.lterms, '') AS terms,
    COALESCE(dr.lpromisorry_note, '') AS promise_to_pay,
    COALESCE(dr.lpo_no, '') AS po_number,
    COALESCE(dr.lnote, '') AS remarks,
    COALESCE(dr.ldm_trackingno, '') AS tracking_no,
    COALESCE((
        SELECT dm.ldm_no
        FROM tbldebit_memo dm
        WHERE dm.ltrans_refno = dr.lrefno
        ORDER BY dm.lid DESC
        LIMIT 1
    ), '') AS debit_memo_no,
    COALESCE(dr.lstatus, 'Pending') AS status,
    COALESCE(dr.IsPrinted, 0) AS is_printed,
    COALESCE(dr.lcancel, 0) AS is_cancelled,
    COALESCE(dr.lcancel_reason, '') AS cancel_reason,
    COALESCE(dr.ldatetime, '') AS created_at,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tbldelivery_receipt dr
LEFT JOIN tblaccount acc
    ON acc.lid = dr.luser
WHERE dr.lmain_id = :main_id
  AND dr.lrefno = :order_slip_refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'order_slip_refno' => $orderSlipRefno,
        ]);
        $orderSlip = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($orderSlip === false) {
            return null;
        }

        $items = $this->listItems($orderSlipRefno);
        $summary = [
            'item_count' => count($items),
            'total_qty' => 0,
            'grand_total' => 0.0,
        ];
        foreach ($items as $item) {
            $summary['total_qty'] += (int) ($item['qty'] ?? 0);
            $summary['grand_total'] += (float) ($item['amount'] ?? 0);
        }

        $trackingOptions = $this->listTrackingOptions((string) ($orderSlip['contact_id'] ?? ''));

        return [
            'order_slip' => $orderSlip,
            'items' => $items,
            'summary' => $summary,
            'tracking_options' => $trackingOptions,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function listTrackingOptions(string $contactId): array
    {
        $contactId = trim($contactId);
        if ($contactId === '') {
            return [];
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(ltrackingno, "") AS tracking_no
             FROM tbldebit_memo
             WHERE lcustomer = :customer_id
               AND ltrans_refno IS NULL
               AND COALESCE(ltrackingno, "") <> ""
             GROUP BY COALESCE(ltrackingno, "")
             ORDER BY MAX(lid) DESC'
        );
        $stmt->execute([
            'customer_id' => $contactId,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            $rows
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function createOrderSlip(int $mainId, int $userId, array $payload): array
    {
        $contactId = trim((string) ($payload['contact_id'] ?? ''));
        if ($contactId === '') {
            throw new RuntimeException('contact_id is required');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $orderSlipRefno = trim((string) ($payload['order_slip_refno'] ?? ''));
            if ($orderSlipRefno === '') {
                $orderSlipRefno = date('YmdHis') . random_int(10000, 99999);
            }

            $slipNo = trim((string) ($payload['slip_no'] ?? ''));
            if ($slipNo === '') {
                $nextNo = $this->nextNumber('Order Slip');
                $slipNo = 'N-D' . $nextNo;
                $this->insertNumberGenerator('Order Slip', $nextNo);
            }

            $salesRefno = trim((string) ($payload['order_id'] ?? $payload['sales_refno'] ?? ''));
            $salesNo = trim((string) ($payload['sales_no'] ?? ''));
            $salesDate = trim((string) ($payload['sales_date'] ?? date('Y-m-d')));
            $status = $this->normalizeStatus((string) ($payload['status'] ?? 'Posted'));

            $customerName = $this->resolveCustomerCompany($mainId, $contactId);

            $stmt = $pdo->prepare(
                'INSERT INTO tbldelivery_receipt (
                    linvoice_no, lmain_id, luser, lrefno, lstatus,
                    lsales_refno, lsales_no, lcustomerid, lcustomer_name,
                    ldate, ldatetime, lsales_person, lsales_person_id,
                    lsales_address, lmy_refno, lyour_refno, lshipped,
                    lprice_group, lcredit_limit, lterms, lpromisorry_note,
                    lpo_no, lnote, IsPrinted, lcancel
                ) VALUES (
                    :linvoice_no, :lmain_id, :luser, :lrefno, :lstatus,
                    :lsales_refno, :lsales_no, :lcustomerid, :lcustomer_name,
                    :ldate, :ldatetime, :lsales_person, :lsales_person_id,
                    :lsales_address, :lmy_refno, :lyour_refno, :lshipped,
                    :lprice_group, :lcredit_limit, :lterms, :lpromisorry_note,
                    :lpo_no, :lnote, :is_printed, 0
                )'
            );
            $stmt->execute([
                'linvoice_no' => $slipNo,
                'lmain_id' => (string) $mainId,
                'luser' => (string) $userId,
                'lrefno' => $orderSlipRefno,
                'lstatus' => $status,
                'lsales_refno' => $salesRefno,
                'lsales_no' => $salesNo,
                'lcustomerid' => $contactId,
                'lcustomer_name' => $customerName,
                'ldate' => $salesDate,
                'ldatetime' => $salesDate . ' ' . date('H:i:s'),
                'lsales_person' => (string) ($payload['sales_person'] ?? ''),
                'lsales_person_id' => (string) ($payload['sales_person_id'] ?? ''),
                'lsales_address' => (string) ($payload['delivery_address'] ?? ''),
                'lmy_refno' => (string) ($payload['reference_no'] ?? ''),
                'lyour_refno' => (string) ($payload['customer_reference'] ?? ''),
                'lshipped' => (string) ($payload['send_by'] ?? ''),
                'lprice_group' => (string) ($payload['price_group'] ?? ''),
                'lcredit_limit' => (float) ($payload['credit_limit'] ?? 0),
                'lterms' => (string) ($payload['terms'] ?? ''),
                'lpromisorry_note' => (string) ($payload['promise_to_pay'] ?? ''),
                'lpo_no' => (string) ($payload['po_number'] ?? ''),
                'lnote' => (string) ($payload['remarks'] ?? ''),
                'is_printed' => (int) ($payload['is_printed'] ?? 0),
            ]);

            $this->replaceItems($orderSlipRefno, $userId, $salesRefno, $payload['items'] ?? []);
            $this->syncInventoryStockLogs($pdo, [
                'order_slip_refno' => $orderSlipRefno,
                'slip_no' => $slipNo,
                'sales_date' => $salesDate,
                'created_at' => $salesDate . ' ' . date('H:i:s'),
                'customer_name' => $customerName,
                'contact_id' => $contactId,
                'status' => $status,
                'is_cancelled' => 0,
            ], $payload['items'] ?? []);
            (new AuditTrailWriter($pdo))->write($mainId, $userId, 'Order Slip', 'Create', $orderSlipRefno);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->getOrderSlip($mainId, $orderSlipRefno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created order slip');
        }
        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateOrderSlip(int $mainId, string $orderSlipRefno, array $payload): ?array
    {
        $existing = $this->getOrderSlip($mainId, $orderSlipRefno);
        if ($existing === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $fields = [];
            $params = [
                'main_id' => (string) $mainId,
                'order_slip_refno' => $orderSlipRefno,
            ];

            $fieldMap = [
                'sales_date' => 'ldate',
                'sales_person' => 'lsales_person',
                'sales_person_id' => 'lsales_person_id',
                'delivery_address' => 'lsales_address',
                'reference_no' => 'lmy_refno',
                'customer_reference' => 'lyour_refno',
                'send_by' => 'lshipped',
                'price_group' => 'lprice_group',
                'credit_limit' => 'lcredit_limit',
                'terms' => 'lterms',
                'promise_to_pay' => 'lpromisorry_note',
                'po_number' => 'lpo_no',
                'remarks' => 'lnote',
                'status' => 'lstatus',
                'is_printed' => 'IsPrinted',
                'tracking_no' => 'ldm_trackingno',
            ];

            foreach ($fieldMap as $inputKey => $column) {
                if (!array_key_exists($inputKey, $payload)) {
                    continue;
                }

                $value = $payload[$inputKey];
                if ($inputKey === 'status') {
                    $value = $this->normalizeStatus((string) $value);
                }
                $paramKey = 'v_' . $column;
                $fields[] = "{$column} = :{$paramKey}";
                $params[$paramKey] = $value;
            }

            if (array_key_exists('contact_id', $payload)) {
                $contactId = trim((string) $payload['contact_id']);
                if ($contactId === '') {
                    throw new RuntimeException('contact_id cannot be empty');
                }
                $fields[] = 'lcustomerid = :v_contact';
                $fields[] = 'lcustomer_name = :v_contact_name';
                $params['v_contact'] = $contactId;
                $params['v_contact_name'] = $this->resolveCustomerCompany($mainId, $contactId);
            }

            if ($fields !== []) {
                $sql = 'UPDATE tbldelivery_receipt SET ' . implode(', ', $fields) . ' WHERE lmain_id = :main_id AND lrefno = :order_slip_refno LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            if (array_key_exists('items', $payload) && is_array($payload['items'])) {
                $salesRefno = (string) ($payload['order_id'] ?? $existing['order_slip']['order_id'] ?? '');
                $userId = (int) ($payload['user_id'] ?? 1);
                $this->replaceItems($orderSlipRefno, $userId, $salesRefno, $payload['items']);
            }

            $reloaded = $this->getOrderSlip($mainId, $orderSlipRefno);
            if ($reloaded !== null) {
                $orderSlipRecord = is_array($reloaded['order_slip'] ?? null) ? $reloaded['order_slip'] : [];
                $orderSlipItems = is_array($reloaded['items'] ?? null) ? $reloaded['items'] : [];
                $this->syncInventoryStockLogs($pdo, $orderSlipRecord, $orderSlipItems);
            }
            $auditUserId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
            (new AuditTrailWriter($pdo))->write($mainId, $auditUserId, 'Order Slip', 'Update', $orderSlipRefno);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->getOrderSlip($mainId, $orderSlipRefno);
    }

    public function cancelOrderSlip(int $mainId, string $orderSlipRefno, string $reason = ''): bool
    {
        $existing = $this->getOrderSlip($mainId, $orderSlipRefno);
        if ($existing === null) {
            return false;
        }

        $orderSlip = is_array($existing['order_slip'] ?? null) ? $existing['order_slip'] : [];
        $items = is_array($existing['items'] ?? null) ? $existing['items'] : [];
        if ((int) ($orderSlip['is_cancelled'] ?? 0) === 1 || strtolower(trim((string) ($orderSlip['status'] ?? ''))) === 'cancelled') {
            return true;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE tbldelivery_receipt
                 SET lcancel = 1, lstatus = :status, lcancel_reason = :reason
                 WHERE lmain_id = :main_id AND lrefno = :order_slip_refno
                 LIMIT 1'
            );
            $stmt->execute([
                'status' => 'Cancelled',
                'reason' => trim($reason),
                'main_id' => (string) $mainId,
                'order_slip_refno' => $orderSlipRefno,
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            $this->syncLinkedSalesCancellation($pdo, (string) ($orderSlip['order_id'] ?? ''), trim($reason));
            $this->insertCancellationInventoryRestoreLogs($pdo, $orderSlip, $items, trim($reason));
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(int $mainId, int $userId, string $orderSlipRefno, array $payload): array
    {
        if ($this->getOrderSlip($mainId, $orderSlipRefno) === null) {
            throw new RuntimeException('Order slip not found');
        }

        $data = $this->normalizeItemPayload($payload);
        $salesRefno = trim((string) ($payload['sales_refno'] ?? ''));

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tbldelivery_receipt_items (
                lrefno, lor_refno, lsales_refno, litemid, litem_refno, linv_refno,
                litemcode, lpartno, ldesc, llocation, lqty, lprice, lremark, luser
             ) VALUES (
                :lrefno, :lor_refno, :lsales_refno, :litemid, :litem_refno, :linv_refno,
                :litemcode, :lpartno, :ldesc, :llocation, :lqty, :lprice, :lremark, :luser
             )'
        );
        $stmt->execute([
            'lrefno' => $salesRefno,
            'lor_refno' => $orderSlipRefno,
            'lsales_refno' => $salesRefno,
            'litemid' => $data['item_id'],
            'litem_refno' => $data['item_refno'],
            'linv_refno' => $data['inv_refno'],
            'litemcode' => $data['item_code'],
            'lpartno' => $data['part_no'],
            'ldesc' => $data['description'],
            'llocation' => $data['location'],
            'lqty' => $data['qty'],
            'lprice' => $data['unit_price'],
            'lremark' => $data['remark'],
            'luser' => (string) $userId,
        ]);

        $itemId = (int) $this->db->pdo()->lastInsertId();
        $item = $this->findItem($mainId, $itemId);
        if ($item === null) {
            throw new RuntimeException('Failed to load created order slip item');
        }
        return $item;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateItem(int $mainId, int $itemId, array $payload): ?array
    {
        $existing = $this->findItem($mainId, $itemId);
        if ($existing === null) {
            return null;
        }

        $set = [];
        $params = ['item_id' => $itemId, 'main_id' => (string) $mainId];
        $map = [
            'item_code' => 'litemcode',
            'part_no' => 'lpartno',
            'description' => 'ldesc',
            'location' => 'llocation',
            'qty' => 'lqty',
            'unit_price' => 'lprice',
            'remark' => 'lremark',
        ];
        foreach ($map as $input => $col) {
            if (!array_key_exists($input, $payload)) {
                continue;
            }
            $key = 'v_' . $col;
            $set[] = "{$col} = :{$key}";
            $params[$key] = $payload[$input];
        }

        if (array_key_exists('item_id', $payload)) {
            $set[] = 'litemid = :v_litemid';
            $set[] = 'litem_refno = :v_litemref';
            $set[] = 'linv_refno = :v_linvref';
            $params['v_litemid'] = (string) $payload['item_id'];
            $params['v_litemref'] = (string) $payload['item_id'];
            $params['v_linvref'] = (string) $payload['item_id'];
        }

        if ($set !== []) {
            $sql = <<<SQL
UPDATE tbldelivery_receipt_items dri
INNER JOIN tbldelivery_receipt dr
    ON dr.lrefno = dri.lor_refno
SET %s
WHERE dri.lid = :item_id
  AND dr.lmain_id = :main_id
SQL;
            $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(', ', $set)));
            $stmt->execute($params);
        }

        return $this->findItem($mainId, $itemId);
    }

    public function deleteItem(int $mainId, int $itemId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE dri
             FROM tbldelivery_receipt_items dri
             INNER JOIN tbldelivery_receipt dr
                 ON dr.lrefno = dri.lor_refno
             WHERE dri.lid = :item_id
               AND dr.lmain_id = :main_id'
        );
        $stmt->execute([
            'item_id' => $itemId,
            'main_id' => (string) $mainId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function applyAction(int $mainId, string $orderSlipRefno, string $action, array $payload): ?array
    {
        $normalized = strtolower(trim($action));
        if ($normalized === 'cancel' || $normalized === 'cancelled') {
            $this->cancelOrderSlip($mainId, $orderSlipRefno, (string) ($payload['reason'] ?? ''));
            return $this->getOrderSlip($mainId, $orderSlipRefno);
        }

        if ($normalized === 'post' || $normalized === 'finalize') {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tbldelivery_receipt
                 SET lstatus = :status, lcancel = 0
                 WHERE lmain_id = :main_id AND lrefno = :order_slip_refno
                 LIMIT 1'
            );
            $stmt->execute([
                'status' => 'Posted',
                'main_id' => (string) $mainId,
                'order_slip_refno' => $orderSlipRefno,
            ]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            return $this->getOrderSlip($mainId, $orderSlipRefno);
        }

        if ($normalized === 'unpost') {
            $record = $this->getOrderSlip($mainId, $orderSlipRefno);
            if ($record === null) {
                return null;
            }

            $salesRefno = trim((string) (($record['order_slip']['order_id'] ?? '')));
            if ($salesRefno === '') {
                throw new RuntimeException('Unpost action is unavailable for order slips without a linked sales order');
            }

            $salesOrderRepo = new SalesOrderRepository($this->db);
            $result = $salesOrderRepo->applyAction(
                $mainId,
                $salesRefno,
                'unpost',
                ['user_id' => (int) ($payload['user_id'] ?? 0)]
            );
            if ($result === null) {
                throw new RuntimeException('Linked sales order not found');
            }

            return $result;
        }

        if ($normalized === 'print' || $normalized === 'printed') {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tbldelivery_receipt
                 SET IsPrinted = 1
                 WHERE lmain_id = :main_id AND lrefno = :order_slip_refno
                 LIMIT 1'
            );
            $stmt->execute([
                'main_id' => (string) $mainId,
                'order_slip_refno' => $orderSlipRefno,
            ]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            return $this->getOrderSlip($mainId, $orderSlipRefno);
        }

        throw new RuntimeException('Unsupported action: ' . $action);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listItems(string $orderSlipRefno): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                dri.lid AS id,
                COALESCE(dri.lor_refno, \'\') AS order_slip_refno,
                COALESCE(dri.litemid, \'\') AS item_id,
                COALESCE(dri.litem_refno, \'\') AS item_refno,
                COALESCE(dri.linv_refno, \'\') AS inv_refno,
                COALESCE(dri.litemcode, \'\') AS item_code,
                COALESCE(dri.lpartno, \'\') AS part_no,
                COALESCE(dri.ldesc, \'\') AS description,
                COALESCE(dri.llocation, \'\') AS location,
                COALESCE(dri.lqty, 0) AS qty,
                COALESCE(dri.lprice, 0) AS unit_price,
                (COALESCE(dri.lqty, 0) * COALESCE(dri.lprice, 0)) AS amount,
                COALESCE(dri.lremark, \'\') AS remark
             FROM tbldelivery_receipt_items dri
             WHERE dri.lor_refno = :order_slip_refno
             ORDER BY dri.lid ASC'
        );
        $stmt->execute(['order_slip_refno' => $orderSlipRefno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $orderSlip
     * @param array<int, array<string, mixed>> $items
     */
    private function insertCancellationInventoryRestoreLogs(PDO $pdo, array $orderSlip, array $items, string $reason = ''): void
    {
        if ($items === []) {
            return;
        }

        $orderSlipRefno = trim((string) ($orderSlip['order_slip_refno'] ?? ''));
        if ($orderSlipRefno === '') {
            return;
        }

        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM tblinventory_logs
             WHERE lrefno = :refno
               AND ltransaction_type = :transaction_type
               AND lstatus_logs = "+"
               AND lout = 0'
        );
        $check->execute([
            'refno' => $orderSlipRefno,
            'transaction_type' => 'Order Slip',
        ]);
        if ((int) ($check->fetchColumn() ?: 0) > 0) {
            return;
        }

        $note = trim($reason) !== '' ? 'CANCELLED ' . trim($reason) : (string) ($orderSlip['customer_name'] ?? '');
        $createdAt = trim((string) ($orderSlip['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = date('Y-m-d H:i:s');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tblinventory_logs
            (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id, lprice, lrefno, lcustomer_id, llocation, lwarehouse, ltransaction_type)
            VALUES
            (:linvent_id, :lin, :lout, :ltotal, :ldateadded, :lprocess_by, :lstatus_logs, :lnote, :linventory_id, :lprice, :lrefno, :lcustomer_id, :llocation, :lwarehouse, :ltransaction_type)'
        );

        foreach ($items as $item) {
            $inventoryLogRef = trim((string) ($item['inv_refno'] ?? ''));
            if ($inventoryLogRef === '') {
                $inventoryLogRef = trim((string) ($item['item_refno'] ?? ''));
            }

            $inventoryItemId = trim((string) ($item['item_id'] ?? ''));
            if ($inventoryLogRef === '' || $inventoryItemId === '') {
                continue;
            }

            $qty = max(0, (int) ($item['qty'] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $insert->execute([
                'linvent_id' => $inventoryLogRef,
                'lin' => $qty,
                'lout' => 0,
                'ltotal' => $qty,
                'ldateadded' => $createdAt,
                'lprocess_by' => 'DR ' . (string) ($orderSlip['slip_no'] ?? ''),
                'lstatus_logs' => '+',
                'lnote' => $note,
                'linventory_id' => $inventoryItemId,
                'lprice' => (float) ($item['unit_price'] ?? 0),
                'lrefno' => $orderSlipRefno,
                'lcustomer_id' => (string) ($orderSlip['contact_id'] ?? ''),
                'llocation' => (string) ($item['location'] ?? ''),
                'lwarehouse' => 'WH1',
                'ltransaction_type' => 'Order Slip',
            ]);
        }
    }

    private function syncLinkedSalesCancellation(PDO $pdo, string $salesRefno, string $reason = ''): void
    {
        $salesRefno = trim($salesRefno);
        if ($salesRefno === '') {
            return;
        }

        $pdo->prepare(
            'UPDATE tbltransaction
             SET lcancel = 1,
                 lsubmitstat = "Cancelled",
                 ltransaction_status = "Cancelled",
                 lcancel_reason = :reason
             WHERE lrefno = :sales_refno'
        )->execute([
            'reason' => $reason,
            'sales_refno' => $salesRefno,
        ]);

        $stmt = $pdo->prepare('SELECT linquiry_refno FROM tbltransaction WHERE lrefno = :sales_refno LIMIT 1');
        $stmt->execute(['sales_refno' => $salesRefno]);
        $inquiryRefno = trim((string) ($stmt->fetchColumn() ?: ''));
        if ($inquiryRefno === '') {
            return;
        }

        $pdo->prepare(
            'UPDATE tblinquiry
             SET IsCancel = 1, lsubmitstat = "Cancelled"
             WHERE lrefno = :inquiry_refno'
        )->execute([
            'inquiry_refno' => $inquiryRefno,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findItem(int $mainId, int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                dri.lid AS id,
                COALESCE(dri.lor_refno, \'\') AS order_slip_refno,
                COALESCE(dri.litemid, \'\') AS item_id,
                COALESCE(dri.litem_refno, \'\') AS item_refno,
                COALESCE(dri.linv_refno, \'\') AS inv_refno,
                COALESCE(dri.litemcode, \'\') AS item_code,
                COALESCE(dri.lpartno, \'\') AS part_no,
                COALESCE(dri.ldesc, \'\') AS description,
                COALESCE(dri.llocation, \'\') AS location,
                COALESCE(dri.lqty, 0) AS qty,
                COALESCE(dri.lprice, 0) AS unit_price,
                (COALESCE(dri.lqty, 0) * COALESCE(dri.lprice, 0)) AS amount,
                COALESCE(dri.lremark, \'\') AS remark
             FROM tbldelivery_receipt_items dri
             INNER JOIN tbldelivery_receipt dr
                 ON dr.lrefno = dri.lor_refno
             WHERE dri.lid = :item_id
               AND dr.lmain_id = :main_id
             LIMIT 1'
        );
        $stmt->execute([
            'item_id' => $itemId,
            'main_id' => (string) $mainId,
        ]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        return $item === false ? null : $item;
    }

    /**
     * @param array<int, mixed> $items
     */
    private function replaceItems(string $orderSlipRefno, int $userId, string $salesRefno, array $items): void
    {
        $deleteStmt = $this->db->pdo()->prepare('DELETE FROM tbldelivery_receipt_items WHERE lor_refno = :order_slip_refno');
        $deleteStmt->execute(['order_slip_refno' => $orderSlipRefno]);

        if ($items === []) {
            return;
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO tbldelivery_receipt_items (
                lrefno, lor_refno, lsales_refno, litemid, litem_refno, linv_refno,
                litemcode, lpartno, ldesc, llocation, lqty, lprice, lremark, luser
             ) VALUES (
                :lrefno, :lor_refno, :lsales_refno, :litemid, :litem_refno, :linv_refno,
                :litemcode, :lpartno, :ldesc, :llocation, :lqty, :lprice, :lremark, :luser
             )'
        );
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $data = $this->normalizeItemPayload($row);
            $insert->execute([
                'lrefno' => $salesRefno,
                'lor_refno' => $orderSlipRefno,
                'lsales_refno' => $salesRefno,
                'litemid' => $data['item_id'],
                'litem_refno' => $data['item_refno'],
                'linv_refno' => $data['inv_refno'],
                'litemcode' => $data['item_code'],
                'lpartno' => $data['part_no'],
                'ldesc' => $data['description'],
                'llocation' => $data['location'],
                'lqty' => $data['qty'],
                'lprice' => $data['unit_price'],
                'lremark' => $data['remark'],
                'luser' => (string) $userId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $orderSlip
     * @param array<int, array<string, mixed>> $items
     */
    private function syncInventoryStockLogs(PDO $pdo, array $orderSlip, array $items): void
    {
        $orderSlipRefno = trim((string) ($orderSlip['order_slip_refno'] ?? ''));
        if ($orderSlipRefno === '') {
            return;
        }

        $delete = $pdo->prepare(
            'DELETE FROM tblinventory_logs
             WHERE lrefno = :refno
               AND ltransaction_type = :transaction_type
               AND lstatus_logs = "-"'
        );
        $delete->execute([
            'refno' => $orderSlipRefno,
            'transaction_type' => 'Order Slip',
        ]);

        if ($items === []) {
            return;
        }

        if ((int) ($orderSlip['is_cancelled'] ?? 0) === 1) {
            return;
        }

        $status = strtolower(trim((string) ($orderSlip['status'] ?? 'Posted')));
        if ($status === 'cancelled' || $status === 'canceled') {
            return;
        }

        $salesDate = trim((string) ($orderSlip['sales_date'] ?? ''));
        if ($salesDate === '') {
            $salesDate = date('Y-m-d');
        }
        $createdAt = trim((string) ($orderSlip['created_at'] ?? ''));
        if ($createdAt === '') {
            $createdAt = $salesDate . ' ' . date('H:i:s');
        } elseif (strlen($createdAt) <= 10) {
            $createdAt .= ' ' . date('H:i:s');
        }

        $insert = $pdo->prepare(
            'INSERT INTO tblinventory_logs
            (linvent_id, lin, lout, ltotal, ldateadded, lprocess_by, lstatus_logs, lnote, linventory_id, lprice, lrefno, lcustomer_id, llocation, lwarehouse, ltransaction_type)
            VALUES
            (:linvent_id, :lin, :lout, :ltotal, :ldateadded, :lprocess_by, :lstatus_logs, :lnote, :linventory_id, :lprice, :lrefno, :lcustomer_id, :llocation, :lwarehouse, :ltransaction_type)'
        );

        foreach ($items as $item) {
            $inventoryLogRef = trim((string) ($item['inv_refno'] ?? ''));
            if ($inventoryLogRef === '') {
                $inventoryLogRef = trim((string) ($item['item_refno'] ?? ''));
            }
            $inventoryItemId = trim((string) ($item['item_id'] ?? ''));
            $qty = max(0, (int) ($item['qty'] ?? 0));
            if ($inventoryLogRef === '' || $inventoryItemId === '' || $qty <= 0) {
                continue;
            }

            $insert->execute([
                'linvent_id' => $inventoryLogRef,
                'lin' => 0,
                'lout' => $qty,
                'ltotal' => $qty,
                'ldateadded' => $createdAt,
                'lprocess_by' => 'DR ' . (string) ($orderSlip['slip_no'] ?? ''),
                'lstatus_logs' => '-',
                'lnote' => (string) ($orderSlip['customer_name'] ?? ''),
                'linventory_id' => $inventoryItemId,
                'lprice' => (float) ($item['unit_price'] ?? 0),
                'lrefno' => $orderSlipRefno,
                'lcustomer_id' => (string) ($orderSlip['contact_id'] ?? ''),
                'llocation' => (string) ($item['location'] ?? ''),
                'lwarehouse' => 'WH1',
                'ltransaction_type' => 'Order Slip',
            ]);
        }
    }

    /**
     * @return array{item_id:string,item_refno:string,inv_refno:string,item_code:string,part_no:string,description:string,location:string,qty:int,unit_price:float,remark:string}
     */
    private function normalizeItemPayload(array $payload): array
    {
        $itemId = trim((string) ($payload['item_id'] ?? ''));
        $itemRefno = trim((string) ($payload['item_refno'] ?? $payload['inv_refno'] ?? $itemId));
        $invRefno = trim((string) ($payload['inv_refno'] ?? $payload['item_refno'] ?? $itemRefno));

        if ($itemId === '') {
            $itemId = $itemRefno !== '' ? $itemRefno : $invRefno;
        }

        return [
            'item_id' => $itemId,
            'item_refno' => $itemRefno,
            'inv_refno' => $invRefno,
            'item_code' => trim((string) ($payload['item_code'] ?? '')),
            'part_no' => trim((string) ($payload['part_no'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'location' => trim((string) ($payload['location'] ?? '')),
            'qty' => max(0, (int) ($payload['qty'] ?? 0)),
            'unit_price' => (float) ($payload['unit_price'] ?? 0),
            'remark' => trim((string) ($payload['remark'] ?? 'OnStock')),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'cancelled' || $normalized === 'canceled') {
            return 'Cancelled';
        }
        if ($normalized === 'posted' || $normalized === 'finalized') {
            return 'Posted';
        }
        return 'Pending';
    }

    private function resolveCustomerCompany(int $mainId, string $contactId): string
    {
        if ($contactId === '') {
            return '';
        }
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(lcompany, \'\') AS lcompany
             FROM tblpatient
             WHERE lmain_id = :main_id
               AND lsessionid = :contact_id
             LIMIT 1'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'contact_id' => $contactId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return '';
        }
        return (string) ($row['lcompany'] ?? '');
    }

    private function nextNumber(string $type): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) AS max_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :type'
        );
        $stmt->execute(['type' => $type]);
        $max = (int) ($stmt->fetchColumn() ?: 0);
        return $max + 1;
    }

    private function insertNumberGenerator(string $type, int $number): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
             VALUES (:type, :number)'
        );
        $stmt->execute([
            'type' => $type,
            'number' => $number,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params, bool $includeLimit): void
    {
        foreach ($params as $key => $value) {
            if (($key === 'limit' || $key === 'offset') && !$includeLimit) {
                continue;
            }
            if (($key === 'limit' || $key === 'offset') && $includeLimit) {
                $stmt->bindValue(':' . $key, (int) $value, PDO::PARAM_INT);
                continue;
            }

            if (is_int($value)) {
                $stmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue(':' . $key, (string) $value, PDO::PARAM_STR);
            }
        }
    }
}
