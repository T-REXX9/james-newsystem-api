<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\AuditTrailWriter;
use PDO;
use RuntimeException;

final class InvoiceRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listInvoices(
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
            'inv.lmain_id = :main_id',
        ];

        if ($month !== null && $year !== null) {
            $params['month'] = $month;
            $params['year'] = $year;
            $where[] = 'MONTH(inv.ldate) = :month';
            $where[] = 'YEAR(inv.ldate) = :year';
        }

        if ($dateFrom !== '') {
            $where[] = 'inv.ldate >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $where[] = 'inv.ldate <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus === '' || $normalizedStatus === 'active') {
            $where[] = "(COALESCE(inv.lcancel_invoice, 0) = 0 AND LOWER(COALESCE(inv.lstatus, '')) <> 'cancelled')";
        } elseif ($normalizedStatus === 'cancelled' || $normalizedStatus === 'canceled') {
            $where[] = "(COALESCE(inv.lcancel_invoice, 0) = 1 OR LOWER(COALESCE(inv.lstatus, '')) = 'cancelled')";
        } elseif ($normalizedStatus === 'pending') {
            $where[] = "(LOWER(COALESCE(inv.lstatus, '')) = 'pending')";
        } elseif ($normalizedStatus === 'posted') {
            $where[] = "(LOWER(COALESCE(inv.lstatus, '')) = 'posted')";
        } elseif ($normalizedStatus !== 'all') {
            $where[] = "(COALESCE(inv.lcancel_invoice, 0) = 0 AND LOWER(COALESCE(inv.lstatus, '')) <> 'cancelled')";
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
    COALESCE(inv.linvoice_no, '') LIKE :search_no
    OR COALESCE(inv.lrefno, '') LIKE :search_ref
    OR COALESCE(inv.lcustomer_name, '') LIKE :search_cust
    OR COALESCE(inv.lcustomerid, '') LIKE :search_contact
    OR COALESCE(inv.lsales_no, '') LIKE :search_so
    OR EXISTS (
        SELECT 1
        FROM tblinvoice_itemrec ii_search
        WHERE ii_search.linvoice_refno = inv.lrefno
          AND CONCAT_WS(' ', COALESCE(ii_search.lpartno, ''), COALESCE(ii_search.litemcode, ''), COALESCE(ii_search.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) AS total FROM tblinvoice_list inv WHERE {$whereSql}");
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    inv.lid AS id,
    COALESCE(inv.lrefno, '') AS invoice_refno,
    COALESCE(inv.linvoice_no, '') AS invoice_no,
    COALESCE(inv.lsales_refno, '') AS order_id,
    COALESCE(inv.lsales_no, '') AS sales_no,
    COALESCE(inv.lcustomerid, '') AS contact_id,
    COALESCE(inv.ldate, '') AS sales_date,
    COALESCE(inv.lsales_person, '') AS sales_person,
    COALESCE(inv.lsales_person_id, '') AS sales_person_id,
    COALESCE(inv.lsales_address, '') AS delivery_address,
    COALESCE(inv.lmy_refno, '') AS reference_no,
    COALESCE(inv.lyour_refno, '') AS customer_reference,
    COALESCE(inv.lshipped, '') AS send_by,
    COALESCE(inv.lprice_group, '') AS price_group,
    COALESCE(inv.lcredit_limit, 0) AS credit_limit,
    COALESCE(inv.lterms, '') AS terms,
    COALESCE(inv.lpromisorry_note, '') AS promise_to_pay,
    COALESCE(inv.lpo_no, '') AS po_number,
    COALESCE(inv.lnote, '') AS remarks,
    COALESCE(
        NULLIF(inv.ldm_no, ''),
        (SELECT dm.ldm_no FROM tbldebit_memo dm WHERE dm.ltrans_refno = inv.lrefno ORDER BY dm.lid DESC LIMIT 1),
        ''
    ) AS debit_memo_no,
    COALESCE(
        NULLIF(inv.ldm_trackingno, ''),
        (SELECT dm.ltrackingno FROM tbldebit_memo dm WHERE dm.ltrans_refno = inv.lrefno ORDER BY dm.lid DESC LIMIT 1),
        ''
    ) AS tracking_no,
    COALESCE(inv.lstatus, 'Pending') AS status,
    COALESCE(inv.IsPrinted, 0) AS is_printed,
    COALESCE(inv.lcancel_invoice, 0) AS is_cancelled,
    COALESCE(inv.lcancel_reason, '') AS cancel_reason,
    COALESCE(inv.ldatetime, '') AS created_at,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tblinvoice_list inv
LEFT JOIN tblaccount acc
    ON acc.lid = inv.luser
WHERE {$whereSql}
ORDER BY inv.lid DESC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $refnos = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['invoice_refno'] ?? ''),
            $items
        )));
        $aggregateByRef = $this->fetchListAggregatesByRefnos($refnos);
        foreach ($items as &$row) {
            $ref = (string) ($row['invoice_refno'] ?? '');
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
             FROM tblinvoice_list
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
    ii.linvoice_refno AS invoice_refno,
    COUNT(*) AS item_count,
    SUM(COALESCE(ii.lqty, 0) * COALESCE(ii.lprice, 0)) AS grand_total
FROM tblinvoice_itemrec ii
WHERE ii.linvoice_refno IN (
    %s
)
GROUP BY ii.linvoice_refno
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(', ', $placeholders)));
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['invoice_refno'] ?? '');
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
    public function getInvoice(int $mainId, string $invoiceRefno): ?array
    {
        $sql = <<<SQL
SELECT
    inv.lid AS id,
    COALESCE(inv.lrefno, '') AS invoice_refno,
    COALESCE(inv.linvoice_no, '') AS invoice_no,
    COALESCE(inv.lsales_refno, '') AS order_id,
    COALESCE(inv.lsales_no, '') AS sales_no,
    COALESCE(inv.lcustomerid, '') AS contact_id,
    COALESCE(inv.ldate, '') AS sales_date,
    COALESCE(inv.lsales_person, '') AS sales_person,
    COALESCE(inv.lsales_person_id, '') AS sales_person_id,
    COALESCE(inv.lsales_address, '') AS delivery_address,
    COALESCE(inv.lmy_refno, '') AS reference_no,
    COALESCE(inv.lyour_refno, '') AS customer_reference,
    COALESCE(inv.lshipped, '') AS send_by,
    COALESCE(inv.lprice_group, '') AS price_group,
    COALESCE(inv.lcredit_limit, 0) AS credit_limit,
    COALESCE(inv.lterms, '') AS terms,
    COALESCE(inv.lpromisorry_note, '') AS promise_to_pay,
    COALESCE(inv.lpo_no, '') AS po_number,
    COALESCE(inv.lnote, '') AS remarks,
    COALESCE(
        NULLIF(inv.ldm_no, ''),
        (SELECT dm.ldm_no FROM tbldebit_memo dm WHERE dm.ltrans_refno = inv.lrefno ORDER BY dm.lid DESC LIMIT 1),
        ''
    ) AS debit_memo_no,
    COALESCE(
        NULLIF(inv.ldm_trackingno, ''),
        (SELECT dm.ltrackingno FROM tbldebit_memo dm WHERE dm.ltrans_refno = inv.lrefno ORDER BY dm.lid DESC LIMIT 1),
        ''
    ) AS tracking_no,
    COALESCE(inv.lstatus, 'Pending') AS status,
    COALESCE(inv.IsPrinted, 0) AS is_printed,
    COALESCE(inv.lcancel_invoice, 0) AS is_cancelled,
    COALESCE(inv.lcancel_reason, '') AS cancel_reason,
    COALESCE(inv.ldatetime, '') AS created_at,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tblinvoice_list inv
LEFT JOIN tblaccount acc
    ON acc.lid = inv.luser
WHERE inv.lmain_id = :main_id
  AND inv.lrefno = :invoice_refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'invoice_refno' => $invoiceRefno,
        ]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($invoice === false) {
            return null;
        }

        $items = $this->listItems($invoiceRefno);
        $summary = [
            'item_count' => count($items),
            'total_qty' => 0,
            'grand_total' => 0.0,
        ];
        foreach ($items as $item) {
            $summary['total_qty'] += (int) ($item['qty'] ?? 0);
            $summary['grand_total'] += (float) ($item['amount'] ?? 0);
        }

        return [
            'invoice' => $invoice,
            'items' => $items,
            'summary' => $summary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createInvoice(int $mainId, int $userId, array $payload): array
    {
        $contactId = trim((string) ($payload['contact_id'] ?? ''));
        if ($contactId === '') {
            throw new RuntimeException('contact_id is required');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $invoiceRefno = trim((string) ($payload['invoice_refno'] ?? ''));
            if ($invoiceRefno === '') {
                $invoiceRefno = date('YmdHis') . random_int(10000, 99999);
            }

            $invoiceNo = trim((string) ($payload['invoice_no'] ?? ''));
            if ($invoiceNo === '') {
                $nextNo = $this->nextNumber('Invoice');
                $invoiceNo = 'T-' . $nextNo;
                $this->insertNumberGenerator('Invoice', $nextNo);
            }

            $salesRefno = trim((string) ($payload['order_id'] ?? $payload['sales_refno'] ?? ''));
            $salesNo = trim((string) ($payload['sales_no'] ?? ''));
            $salesDate = trim((string) ($payload['sales_date'] ?? date('Y-m-d')));
            $status = $this->normalizeStatus((string) ($payload['status'] ?? 'Posted'));
            $customerName = $this->resolveCustomerCompany($mainId, $contactId);

            $stmt = $pdo->prepare(
                'INSERT INTO tblinvoice_list (
                    linvoice_no, lmain_id, luser, lrefno, lstatus,
                    lsales_refno, lsales_no, lcustomerid, lcustomer_name,
                    ldate, ldatetime, lsales_person, lsales_person_id,
                    lsales_address, lmy_refno, lyour_refno, lshipped,
                    lprice_group, lcredit_limit, lterms, lpromisorry_note,
                    lpo_no, lnote, IsPrinted, lcancel_invoice
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
                'linvoice_no' => $invoiceNo,
                'lmain_id' => (string) $mainId,
                'luser' => (string) $userId,
                'lrefno' => $invoiceRefno,
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

            $this->replaceItems($invoiceRefno, $userId, $salesRefno, $payload['items'] ?? []);
            (new AuditTrailWriter($pdo))->write($mainId, $userId, 'Invoice', 'Create', $invoiceRefno);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->getInvoice($mainId, $invoiceRefno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created invoice');
        }
        return $created;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateInvoice(int $mainId, string $invoiceRefno, array $payload): ?array
    {
        $existing = $this->getInvoice($mainId, $invoiceRefno);
        if ($existing === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $fields = [];
            $params = [
                'main_id' => (string) $mainId,
                'invoice_refno' => $invoiceRefno,
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
                $sql = 'UPDATE tblinvoice_list SET ' . implode(', ', $fields) . ' WHERE lmain_id = :main_id AND lrefno = :invoice_refno LIMIT 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            }

            if (array_key_exists('items', $payload) && is_array($payload['items'])) {
                $salesRefno = (string) ($payload['order_id'] ?? $existing['invoice']['order_id'] ?? '');
                $userId = (int) ($payload['user_id'] ?? 1);
                $this->replaceItems($invoiceRefno, $userId, $salesRefno, $payload['items']);
            }

            $auditUserId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
            (new AuditTrailWriter($pdo))->write($mainId, $auditUserId, 'Invoice', 'Update', $invoiceRefno);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->getInvoice($mainId, $invoiceRefno);
    }

    public function cancelInvoice(int $mainId, string $invoiceRefno, string $reason = ''): bool
    {
        $existing = $this->getInvoice($mainId, $invoiceRefno);
        if ($existing === null) {
            return false;
        }

        $invoice = is_array($existing['invoice'] ?? null) ? $existing['invoice'] : [];
        $items = is_array($existing['items'] ?? null) ? $existing['items'] : [];
        if ((int) ($invoice['is_cancelled'] ?? 0) === 1 || strtolower(trim((string) ($invoice['status'] ?? ''))) === 'cancelled') {
            return true;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE tblinvoice_list
                 SET lcancel_invoice = 1, lstatus = :status, lcancel_reason = :reason
                 WHERE lmain_id = :main_id AND lrefno = :invoice_refno
                 LIMIT 1'
            );
            $stmt->execute([
                'status' => 'Cancelled',
                'reason' => trim($reason),
                'main_id' => (string) $mainId,
                'invoice_refno' => $invoiceRefno,
            ]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }

            $this->syncLinkedSalesCancellation($pdo, (string) ($invoice['order_id'] ?? ''), trim($reason));
            $this->insertCancellationInventoryRestoreLogs($pdo, $invoice, $items, trim($reason));
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
    public function addItem(int $mainId, int $userId, string $invoiceRefno, array $payload): array
    {
        if ($this->getInvoice($mainId, $invoiceRefno) === null) {
            throw new RuntimeException('Invoice not found');
        }

        $data = $this->normalizeItemPayload($payload);
        $salesRefno = trim((string) ($payload['sales_refno'] ?? ''));

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblinvoice_itemrec (
                lrefno, linvoice_refno, litemid, litem_refno, linv_refno,
                litemcode, lpartno, ldesc, llocation, lqty, lprice, lremark, luser
             ) VALUES (
                :lrefno, :linvoice_refno, :litemid, :litem_refno, :linv_refno,
                :litemcode, :lpartno, :ldesc, :llocation, :lqty, :lprice, :lremark, :luser
             )'
        );
        $stmt->execute([
            'lrefno' => $salesRefno,
            'linvoice_refno' => $invoiceRefno,
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
            throw new RuntimeException('Failed to load created invoice item');
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
UPDATE tblinvoice_itemrec ii
INNER JOIN tblinvoice_list inv
    ON inv.lrefno = ii.linvoice_refno
SET %s
WHERE ii.lid = :item_id
  AND inv.lmain_id = :main_id
SQL;
            $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(', ', $set)));
            $stmt->execute($params);
        }

        return $this->findItem($mainId, $itemId);
    }

    public function deleteItem(int $mainId, int $itemId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE ii
             FROM tblinvoice_itemrec ii
             INNER JOIN tblinvoice_list inv
                 ON inv.lrefno = ii.linvoice_refno
             WHERE ii.lid = :item_id
               AND inv.lmain_id = :main_id'
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
    public function applyAction(int $mainId, string $invoiceRefno, string $action, array $payload): ?array
    {
        $normalized = strtolower(trim($action));
        if ($normalized === 'cancel' || $normalized === 'cancelled') {
            $this->cancelInvoice($mainId, $invoiceRefno, (string) ($payload['reason'] ?? ''));
            return $this->getInvoice($mainId, $invoiceRefno);
        }

        if ($normalized === 'post' || $normalized === 'send' || $normalized === 'record_payment' || $normalized === 'mark_overdue') {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblinvoice_list
                 SET lstatus = :status, lcancel_invoice = 0
                 WHERE lmain_id = :main_id AND lrefno = :invoice_refno
                 LIMIT 1'
            );
            $stmt->execute([
                'status' => 'Posted',
                'main_id' => (string) $mainId,
                'invoice_refno' => $invoiceRefno,
            ]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            return $this->getInvoice($mainId, $invoiceRefno);
        }

        if ($normalized === 'print' || $normalized === 'printed') {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblinvoice_list
                 SET IsPrinted = 1
                 WHERE lmain_id = :main_id AND lrefno = :invoice_refno
                 LIMIT 1'
            );
            $stmt->execute([
                'main_id' => (string) $mainId,
                'invoice_refno' => $invoiceRefno,
            ]);
            if ($stmt->rowCount() === 0) {
                return null;
            }
            return $this->getInvoice($mainId, $invoiceRefno);
        }

        if ($normalized === 'unpost') {
            $record = $this->getInvoice($mainId, $invoiceRefno);
            if ($record === null) {
                return null;
            }

            $salesRefno = trim((string) (($record['invoice']['order_id'] ?? '')));
            if ($salesRefno === '') {
                throw new RuntimeException('Unpost action is unavailable for invoices without a linked sales order');
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

        throw new RuntimeException('Unsupported action: ' . $action);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listItems(string $invoiceRefno): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                ii.lid AS id,
                COALESCE(ii.linvoice_refno, \'\') AS invoice_refno,
                COALESCE(ii.litemid, \'\') AS item_id,
                COALESCE(ii.litem_refno, \'\') AS item_refno,
                COALESCE(ii.linv_refno, \'\') AS inv_refno,
                COALESCE(ii.litemcode, \'\') AS item_code,
                COALESCE(ii.lpartno, \'\') AS part_no,
                COALESCE(ii.ldesc, \'\') AS description,
                COALESCE(ii.llocation, \'\') AS location,
                COALESCE(ii.lqty, 0) AS qty,
                COALESCE(ii.lprice, 0) AS unit_price,
                (COALESCE(ii.lqty, 0) * COALESCE(ii.lprice, 0)) AS amount,
                COALESCE(ii.lremark, \'\') AS remark
             FROM tblinvoice_itemrec ii
             WHERE ii.linvoice_refno = :invoice_refno
             ORDER BY ii.lid ASC'
        );
        $stmt->execute(['invoice_refno' => $invoiceRefno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $invoice
     * @param array<int, array<string, mixed>> $items
     */
    private function insertCancellationInventoryRestoreLogs(PDO $pdo, array $invoice, array $items, string $reason = ''): void
    {
        if ($items === []) {
            return;
        }

        $invoiceRefno = trim((string) ($invoice['invoice_refno'] ?? ''));
        if ($invoiceRefno === '') {
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
            'refno' => $invoiceRefno,
            'transaction_type' => 'Invoice',
        ]);
        if ((int) ($check->fetchColumn() ?: 0) > 0) {
            return;
        }

        $note = trim($reason) !== '' ? 'CANCELLED ' . trim($reason) : (string) ($invoice['customer_name'] ?? '');
        $createdAt = trim((string) ($invoice['created_at'] ?? ''));
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
            if (strcasecmp(trim((string) ($item['remark'] ?? '')), 'OnStock') !== 0) {
                continue;
            }

            $qty = max(0, (int) ($item['qty'] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $insert->execute([
                'linvent_id' => (string) ($item['inv_refno'] ?? $item['item_refno'] ?? ''),
                'lin' => $qty,
                'lout' => 0,
                'ltotal' => $qty,
                'ldateadded' => $createdAt,
                'lprocess_by' => 'INV ' . (string) ($invoice['invoice_no'] ?? ''),
                'lstatus_logs' => '+',
                'lnote' => $note,
                'linventory_id' => (string) ($item['item_id'] ?? ''),
                'lprice' => (float) ($item['unit_price'] ?? 0),
                'lrefno' => $invoiceRefno,
                'lcustomer_id' => (string) ($invoice['contact_id'] ?? ''),
                'llocation' => (string) ($item['location'] ?? ''),
                'lwarehouse' => 'WH1',
                'ltransaction_type' => 'Invoice',
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
                ii.lid AS id,
                COALESCE(ii.linvoice_refno, \'\') AS invoice_refno,
                COALESCE(ii.litemid, \'\') AS item_id,
                COALESCE(ii.litem_refno, \'\') AS item_refno,
                COALESCE(ii.linv_refno, \'\') AS inv_refno,
                COALESCE(ii.litemcode, \'\') AS item_code,
                COALESCE(ii.lpartno, \'\') AS part_no,
                COALESCE(ii.ldesc, \'\') AS description,
                COALESCE(ii.llocation, \'\') AS location,
                COALESCE(ii.lqty, 0) AS qty,
                COALESCE(ii.lprice, 0) AS unit_price,
                (COALESCE(ii.lqty, 0) * COALESCE(ii.lprice, 0)) AS amount,
                COALESCE(ii.lremark, \'\') AS remark
             FROM tblinvoice_itemrec ii
             INNER JOIN tblinvoice_list inv
                 ON inv.lrefno = ii.linvoice_refno
             WHERE ii.lid = :item_id
               AND inv.lmain_id = :main_id
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
    private function replaceItems(string $invoiceRefno, int $userId, string $salesRefno, array $items): void
    {
        $deleteStmt = $this->db->pdo()->prepare('DELETE FROM tblinvoice_itemrec WHERE linvoice_refno = :invoice_refno');
        $deleteStmt->execute(['invoice_refno' => $invoiceRefno]);

        if ($items === []) {
            return;
        }

        $insert = $this->db->pdo()->prepare(
            'INSERT INTO tblinvoice_itemrec (
                lrefno, linvoice_refno, litemid, litem_refno, linv_refno,
                litemcode, lpartno, ldesc, llocation, lqty, lprice, lremark, luser
             ) VALUES (
                :lrefno, :linvoice_refno, :litemid, :litem_refno, :linv_refno,
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
                'linvoice_refno' => $invoiceRefno,
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
     * @return array{item_id:string,item_refno:string,inv_refno:string,item_code:string,part_no:string,description:string,location:string,qty:int,unit_price:float,remark:string}
     */
    private function normalizeItemPayload(array $payload): array
    {
        $itemId = trim((string) ($payload['item_id'] ?? $payload['item_refno'] ?? $payload['inv_refno'] ?? ''));
        return [
            'item_id' => $itemId,
            'item_refno' => $itemId,
            'inv_refno' => $itemId,
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
        if ($normalized === 'posted' || $normalized === 'sent' || $normalized === 'paid' || $normalized === 'overdue') {
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
