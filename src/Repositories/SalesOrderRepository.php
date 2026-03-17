<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class SalesOrderRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   meta: array<string, mixed>
     * }
     */
    public function listSalesOrders(
        int $mainId,
        ?int $month = null,
        ?int $year = null,
        string $status = 'all',
        string $search = '',
        int $page = 1,
        int $perPage = 100,
        int $viewerUserId = 0
    ): array {
        if ($month === null || $year === null) {
            $period = $this->resolveLatestPeriod($mainId);
            $month = $period['month'];
            $year = $period['year'];
        }

        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [
            'main_id' => (string) $mainId,
            'month' => $month,
            'year' => $year,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = [
            'so.lmain_id = :main_id',
            'MONTH(so.ldate) = :month',
            'YEAR(so.ldate) = :year',
        ];

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus === '' || $normalizedStatus === 'active') {
            $where[] = 'COALESCE(so.lcancel, 0) = 0';
        } elseif ($normalizedStatus === 'cancelled' || $normalizedStatus === 'canceled') {
            $where[] = "(COALESCE(so.lcancel, 0) = 1 OR LOWER(COALESCE(so.lsubmitstat, '')) = 'cancelled' OR LOWER(COALESCE(so.ltransaction_status, '')) = 'cancelled')";
        } elseif ($normalizedStatus === 'pending' || $normalizedStatus === 'draft' || $normalizedStatus === 'unposted') {
            $where[] = 'COALESCE(so.lcancel, 0) = 0';
            $where[] = "LOWER(COALESCE(so.lsubmitstat, 'pending')) = 'pending'";
        } elseif ($normalizedStatus === 'submitted') {
            $where[] = 'COALESCE(so.lcancel, 0) = 0';
            $where[] = "LOWER(COALESCE(so.lsubmitstat, '')) = 'submitted'";
        } elseif ($normalizedStatus === 'approved') {
            $where[] = 'COALESCE(so.lcancel, 0) = 0';
            $where[] = "LOWER(COALESCE(so.lsubmitstat, '')) = 'approved'";
        } elseif ($normalizedStatus === 'posted') {
            $where[] = 'COALESCE(so.lcancel, 0) = 0';
            $where[] = "(LOWER(COALESCE(so.lsubmitstat, '')) = 'posted' OR LOWER(COALESCE(so.ltransaction_status, '')) = 'posted')";
        } elseif ($normalizedStatus !== 'all') {
            $where[] = 'COALESCE(so.lcancel, 0) = 0';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_so'] = '%' . $trimmedSearch . '%';
            $params['search_ref'] = '%' . $trimmedSearch . '%';
            $params['search_customer'] = '%' . $trimmedSearch . '%';
            $params['search_contact'] = '%' . $trimmedSearch . '%';
            $params['search_inquiry'] = '%' . $trimmedSearch . '%';
            $params['search_item'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    COALESCE(so.lsaleno, '') LIKE :search_so
    OR COALESCE(so.lrefno, '') LIKE :search_ref
    OR COALESCE(so.lcompany, '') LIKE :search_customer
    OR COALESCE(so.lcustomerid, '') LIKE :search_contact
    OR COALESCE(so.linquiry_no, '') LIKE :search_inquiry
    OR EXISTS (
        SELECT 1
        FROM tbltransaction_item soi_search
        WHERE soi_search.lrefno = so.lrefno
          AND COALESCE(soi_search.lcancel, 0) = 0
          AND CONCAT_WS(' ', COALESCE(soi_search.lpartno, ''), COALESCE(soi_search.litemcode, ''), COALESCE(soi_search.ldesc, '')) LIKE :search_item
    )
)
SQL;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) AS total FROM tbltransaction so WHERE {$whereSql}");
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    so.lid AS id,
    COALESCE(so.luser, '') AS user_id,
    COALESCE(so.luser, '') AS created_by_id,
    COALESCE(so.lrefno, '') AS sales_refno,
    COALESCE(so.lsaleno, '') AS sales_no,
    COALESCE(so.ldate, '') AS sales_date,
    COALESCE(so.ltime, '') AS sales_time,
    COALESCE(so.lcustomerid, '') AS contact_id,
    COALESCE(so.lcompany, '') AS customer_company,
    COALESCE(so.lsales_person, '') AS sales_person,
    COALESCE(so.lsales_person_id, '') AS sales_person_id,
    COALESCE(so.lsales_address, '') AS delivery_address,
    COALESCE(so.lmy_refno, '') AS reference_no,
    COALESCE(so.lyour_refno, '') AS customer_reference,
    COALESCE(so.lprice_group, '') AS price_group,
    COALESCE(so.lcredit_limit, 0) AS credit_limit,
    COALESCE(so.lterms, '') AS terms,
    COALESCE(so.lpromissory_note, '') AS promise_to_pay,
    COALESCE(so.lpo_no, '') AS po_number,
    COALESCE(so.lnote, '') AS remarks,
    COALESCE(so.lsubmitstat, 'Pending') AS status,
    COALESCE(so.ltransaction_status, 'Pending') AS transaction_status,
    COALESCE(so.lcancel, 0) AS is_cancelled,
    COALESCE(so.linquiry_refno, '') AS inquiry_refno,
    COALESCE(so.linquiry_no, '') AS inquiry_no,
    COALESCE(so.ldr_refno, '') AS order_slip_refno,
    COALESCE(so.ldr_no, '') AS order_slip_no,
    COALESCE(so.invoice_refno, '') AS invoice_refno,
    COALESCE(so.invoice_no, '') AS invoice_no,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tbltransaction so
LEFT JOIN tblaccount acc
    ON acc.lid = so.luser
WHERE {$whereSql}
ORDER BY so.lid DESC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $refnos = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['sales_refno'] ?? ''),
            $items
        )));

        $aggregateByRef = $this->fetchListAggregatesByRefnos($refnos);
        $viewerIsApprover = $viewerUserId > 0 ? $this->isApprover($mainId, $viewerUserId, 'SO') : false;
        foreach ($items as &$row) {
            $ref = (string) ($row['sales_refno'] ?? '');
            $agg = $aggregateByRef[$ref] ?? ['item_count' => 0, 'grand_total' => 0.0];
            $row['item_count'] = (int) ($agg['item_count'] ?? 0);
            $row['grand_total'] = (float) ($agg['grand_total'] ?? 0);
            $row['viewer_is_approver'] = $viewerIsApprover;
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
             FROM tbltransaction
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
    soi.lrefno AS sales_refno,
    COUNT(*) AS item_count,
    SUM(COALESCE(soi.lqty, 0) * COALESCE(soi.lprice, 0)) AS grand_total
FROM tbltransaction_item soi
WHERE COALESCE(soi.lcancel, 0) = 0
  AND soi.lrefno IN (
    %s
  )
GROUP BY soi.lrefno
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(', ', $placeholders)));
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['sales_refno'] ?? '');
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
    public function getSalesOrder(int $mainId, string $salesRefno, int $viewerUserId = 0): ?array
    {
        $orderSql = <<<SQL
SELECT
    so.lid AS id,
    COALESCE(so.luser, '') AS created_by_id,
    COALESCE(so.lrefno, '') AS sales_refno,
    COALESCE(so.lsaleno, '') AS sales_no,
    COALESCE(so.ldate, '') AS sales_date,
    COALESCE(so.ltime, '') AS sales_time,
    COALESCE(so.lcustomerid, '') AS contact_id,
    COALESCE(so.lcompany, '') AS customer_company,
    COALESCE(so.lsales_person, '') AS sales_person,
    COALESCE(so.lsales_person_id, '') AS sales_person_id,
    COALESCE(so.lsales_address, '') AS delivery_address,
    COALESCE(so.lmy_refno, '') AS reference_no,
    COALESCE(so.lyour_refno, '') AS customer_reference,
    COALESCE(so.lprice_group, '') AS price_group,
    COALESCE(so.lcredit_limit, 0) AS credit_limit,
    COALESCE(so.lterms, '') AS terms,
    COALESCE(so.lterm_condition, '') AS terms_condition,
    COALESCE(so.lpromissory_note, '') AS promise_to_pay,
    COALESCE(so.lpo_no, '') AS po_number,
    COALESCE(so.lnote, '') AS remarks,
    COALESCE(so.lsubmitstat, 'Pending') AS status,
    COALESCE(so.ltransaction_status, 'Pending') AS transaction_status,
    COALESCE(so.lcancel, 0) AS is_cancelled,
    COALESCE(so.lcancel_reason, '') AS cancel_reason,
    COALESCE(so.linquiry_refno, '') AS inquiry_refno,
    COALESCE(so.linquiry_no, '') AS inquiry_no,
    COALESCE(so.ldr_refno, '') AS order_slip_refno,
    COALESCE(so.ldr_no, '') AS order_slip_no,
    COALESCE(so.invoice_refno, '') AS invoice_refno,
    COALESCE(so.invoice_no, '') AS invoice_no,
    COALESCE(so.lurgency, '') AS urgency,
    COALESCE(so.lurgency_date, NULL) AS urgency_date,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS created_by
FROM tbltransaction so
LEFT JOIN tblaccount acc
    ON acc.lid = so.luser
WHERE so.lmain_id = :main_id
  AND so.lrefno = :sales_refno
LIMIT 1
SQL;
        $orderStmt = $this->db->pdo()->prepare($orderSql);
        $orderStmt->execute([
            'main_id' => (string) $mainId,
            'sales_refno' => $salesRefno,
        ]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            return null;
        }

        $items = $this->listItems($salesRefno);
        $order['viewer_is_approver'] = $viewerUserId > 0 ? $this->isApprover($mainId, $viewerUserId, 'SO') : false;
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
            'order' => $order,
            'items' => $items,
            'summary' => $summary,
        ];
    }

    public function createSalesOrder(int $mainId, int $userId, array $payload): array
    {
        $contactId = trim((string) ($payload['contact_id'] ?? ''));
        if ($contactId === '') {
            throw new RuntimeException('contact_id is required');
        }

        $customer = $this->getCustomerSnapshot($mainId, $contactId);
        if ($customer === null) {
            throw new RuntimeException('Customer not found for contact_id');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $salesRefno = trim((string) ($payload['sales_refno'] ?? ''));
            if ($salesRefno === '') {
                $salesRefno = date('YmdHis') . random_int(10000, 99999);
            }

            $salesNo = trim((string) ($payload['sales_no'] ?? ''));
            if ($salesNo === '') {
                $salesNo = $this->generateSalesNumber($pdo);
            }

            $salesDate = $this->normalizeDate((string) ($payload['sales_date'] ?? date('Y-m-d')));
            $salesTime = $this->normalizeTime((string) ($payload['sales_time'] ?? date('H:i:s')));
            $status = $this->normalizeSubmitStatus((string) ($payload['status'] ?? 'Pending'));
            $transactionStatus = $this->normalizeTransactionStatus((string) ($payload['transaction_status'] ?? $status));

            $terms = trim((string) ($payload['terms'] ?? ''));
            $termCondition = trim((string) ($payload['terms_condition'] ?? ''));
            if ($terms === '' || $termCondition === '') {
                $latestTerms = $this->getLatestTerms($contactId);
                if ($terms === '') {
                    $terms = (string) ($latestTerms['lclass_code'] ?? ($customer['lterms'] ?? ''));
                }
                if ($termCondition === '') {
                    $termCondition = (string) ($latestTerms['lname'] ?? ($customer['lterms'] ?? ''));
                }
            }

            $insert = $pdo->prepare(
                'INSERT INTO tbltransaction
                (lsaleno, ldate, ltime, lcustomerid, lmain_id, luser, lrefno, lbranch, lt_lfname, lt_llname, lcompany, lsales_address, lmy_refno, lyour_refno, lprice_group, lcredit_limit, lpromissory_note, lpo_no, lnote, lterms, lterm_condition, lsales_person, lsales_person_id, lsubmitstat, ltransaction_status, lcancel, linquiry_refno, linquiry_no, IsInquiry, lurgency, lurgency_date)
                VALUES
                (:lsaleno, :ldate, :ltime, :lcustomerid, :lmain_id, :luser, :lrefno, :lbranch, :lt_lfname, :lt_llname, :lcompany, :lsales_address, :lmy_refno, :lyour_refno, :lprice_group, :lcredit_limit, :lpromissory_note, :lpo_no, :lnote, :lterms, :lterm_condition, :lsales_person, :lsales_person_id, :lsubmitstat, :ltransaction_status, :lcancel, :linquiry_refno, :linquiry_no, 0, :lurgency, :lurgency_date)'
            );
            $insert->execute([
                'lsaleno' => $salesNo,
                'ldate' => $salesDate,
                'ltime' => $salesTime,
                'lcustomerid' => $contactId,
                'lmain_id' => (string) $mainId,
                'luser' => (string) $userId,
                'lrefno' => $salesRefno,
                'lbranch' => (string) ($payload['branch'] ?? 'mainbranch'),
                'lt_lfname' => (string) ($customer['lfname'] ?? ''),
                'lt_llname' => (string) ($customer['llname'] ?? ''),
                'lcompany' => $this->stringOrFallback($payload['customer_company'] ?? null, (string) ($customer['lcompany'] ?? '')),
                'lsales_address' => $this->stringOrFallback($payload['delivery_address'] ?? null, (string) ($customer['ldelivery_address'] ?? '')),
                'lmy_refno' => (string) ($payload['reference_no'] ?? $salesNo),
                'lyour_refno' => (string) ($payload['customer_reference'] ?? ''),
                'lprice_group' => (string) ($payload['price_group'] ?? ($customer['lprice_group'] ?? '')),
                'lcredit_limit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : (float) ($customer['lcredit'] ?? 0),
                'lpromissory_note' => (string) ($payload['promise_to_pay'] ?? ''),
                'lpo_no' => (string) ($payload['po_number'] ?? ''),
                'lnote' => (string) ($payload['remarks'] ?? ''),
                'lterms' => $terms,
                'lterm_condition' => $termCondition,
                'lsales_person' => $this->stringOrFallback($payload['sales_person'] ?? null, (string) ($customer['sales_person_name'] ?? '')),
                'lsales_person_id' => $this->stringOrFallback($payload['sales_person_id'] ?? null, (string) ($customer['lsales_person'] ?? '')),
                'lsubmitstat' => $status,
                'ltransaction_status' => $transactionStatus,
                'lcancel' => $status === 'Cancelled' ? 1 : 0,
                'linquiry_refno' => (string) ($payload['inquiry_refno'] ?? ''),
                'linquiry_no' => (string) ($payload['inquiry_no'] ?? ''),
                'lurgency' => (string) ($payload['urgency'] ?? ''),
                'lurgency_date' => $this->normalizeNullableDate((string) ($payload['urgency_date'] ?? '')),
            ]);

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->insertItem($pdo, $mainId, $userId, $salesRefno, $salesDate, $item);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $record = $this->getSalesOrder($mainId, $salesRefno);
        if ($record === null) {
            throw new RuntimeException('Failed to create sales order');
        }

        return $record;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateSalesOrder(int $mainId, string $salesRefno, array $payload): ?array
    {
        $existing = $this->getSalesOrder($mainId, $salesRefno);
        if ($existing === null) {
            return null;
        }

        $order = $existing['order'];
        $salesDate = $this->normalizeDate((string) ($payload['sales_date'] ?? (string) ($order['sales_date'] ?? date('Y-m-d'))));
        $status = $this->normalizeSubmitStatus((string) ($payload['status'] ?? (string) ($order['status'] ?? 'Pending')));
        $transactionStatus = $this->normalizeTransactionStatus((string) ($payload['transaction_status'] ?? (string) ($order['transaction_status'] ?? $status)));

        $sql = <<<SQL
UPDATE tbltransaction
SET
    ldate = :ldate,
    ltime = :ltime,
    lcustomerid = :lcustomerid,
    lcompany = :lcompany,
    lsales_person = :lsales_person,
    lsales_person_id = :lsales_person_id,
    lsales_address = :lsales_address,
    lmy_refno = :lmy_refno,
    lyour_refno = :lyour_refno,
    lprice_group = :lprice_group,
    lcredit_limit = :lcredit_limit,
    lpromissory_note = :lpromissory_note,
    lpo_no = :lpo_no,
    lnote = :lnote,
    lterms = :lterms,
    lterm_condition = :lterm_condition,
    lsubmitstat = :lsubmitstat,
    ltransaction_status = :ltransaction_status,
    lcancel = :lcancel,
    linquiry_refno = :linquiry_refno,
    linquiry_no = :linquiry_no,
    lurgency = :lurgency,
    lurgency_date = :lurgency_date
WHERE lmain_id = :lmain_id
  AND lrefno = :lrefno
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'ldate' => $salesDate,
            'ltime' => $this->normalizeTime((string) ($payload['sales_time'] ?? (string) ($order['sales_time'] ?? date('H:i:s')))),
            'lcustomerid' => (string) ($payload['contact_id'] ?? $order['contact_id'] ?? ''),
            'lcompany' => (string) ($payload['customer_company'] ?? $order['customer_company'] ?? ''),
            'lsales_person' => (string) ($payload['sales_person'] ?? $order['sales_person'] ?? ''),
            'lsales_person_id' => (string) ($payload['sales_person_id'] ?? $order['sales_person_id'] ?? ''),
            'lsales_address' => (string) ($payload['delivery_address'] ?? $order['delivery_address'] ?? ''),
            'lmy_refno' => (string) ($payload['reference_no'] ?? $order['reference_no'] ?? ''),
            'lyour_refno' => (string) ($payload['customer_reference'] ?? $order['customer_reference'] ?? ''),
            'lprice_group' => (string) ($payload['price_group'] ?? $order['price_group'] ?? ''),
            'lcredit_limit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : (float) ($order['credit_limit'] ?? 0),
            'lpromissory_note' => (string) ($payload['promise_to_pay'] ?? $order['promise_to_pay'] ?? ''),
            'lpo_no' => (string) ($payload['po_number'] ?? $order['po_number'] ?? ''),
            'lnote' => (string) ($payload['remarks'] ?? $order['remarks'] ?? ''),
            'lterms' => (string) ($payload['terms'] ?? $order['terms'] ?? ''),
            'lterm_condition' => (string) ($payload['terms_condition'] ?? $order['terms_condition'] ?? ''),
            'lsubmitstat' => $status,
            'ltransaction_status' => $transactionStatus,
            'lcancel' => $status === 'Cancelled' ? 1 : 0,
            'linquiry_refno' => (string) ($payload['inquiry_refno'] ?? $order['inquiry_refno'] ?? ''),
            'linquiry_no' => (string) ($payload['inquiry_no'] ?? $order['inquiry_no'] ?? ''),
            'lurgency' => (string) ($payload['urgency'] ?? $order['urgency'] ?? ''),
            'lurgency_date' => $this->normalizeNullableDate((string) ($payload['urgency_date'] ?? $order['urgency_date'] ?? '')),
            'lmain_id' => (string) $mainId,
            'lrefno' => $salesRefno,
        ]);

        if (is_array($payload['items'] ?? null)) {
            $deleteStmt = $this->db->pdo()->prepare('DELETE FROM tbltransaction_item WHERE lrefno = :lrefno');
            $deleteStmt->execute(['lrefno' => $salesRefno]);

            $items = $payload['items'];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemUserId = isset($payload['user_id'])
                    ? (int) $payload['user_id']
                    : (int) ($order['user_id'] ?? 0);
                if ($itemUserId <= 0) {
                    $itemUserId = 1;
                }
                $this->insertItem($this->db->pdo(), $mainId, $itemUserId, $salesRefno, $salesDate, $item);
            }
        }

        return $this->getSalesOrder($mainId, $salesRefno);
    }

    public function cancelSalesOrder(int $mainId, string $salesRefno, string $reason = ''): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tbltransaction
             SET lcancel = 1, lsubmitstat = "Cancelled", ltransaction_status = "Cancelled", lcancel_reason = :reason
             WHERE lmain_id = :lmain_id
               AND lrefno = :lrefno'
        );
        $stmt->execute([
            'reason' => trim($reason),
            'lmain_id' => (string) $mainId,
            'lrefno' => $salesRefno,
        ]);

        if ($stmt->rowCount() <= 0) {
            return false;
        }

        $this->syncInquiryOnSalesCancel($salesRefno);
        return true;
    }

    public function addItem(int $mainId, int $userId, string $salesRefno, array $payload): array
    {
        $so = $this->getSalesOrder($mainId, $salesRefno);
        if ($so === null) {
            throw new RuntimeException('Sales order not found');
        }

        $salesDate = (string) ($so['order']['sales_date'] ?? date('Y-m-d'));
        $this->insertItem($this->db->pdo(), $mainId, $userId, $salesRefno, $salesDate, $payload);
        $itemId = (int) $this->db->pdo()->lastInsertId();

        $item = $this->getItemById($mainId, $itemId);
        if ($item === null) {
            throw new RuntimeException('Unable to load created sales order item');
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

        if (array_key_exists('qty', $payload)) {
            $fields[] = 'lqty = :lqty';
            $params['lqty'] = max(0, (int) $payload['qty']);
        }
        if (array_key_exists('unit_price', $payload)) {
            $fields[] = 'lprice = :lprice';
            $params['lprice'] = (float) $payload['unit_price'];
        }
        if (array_key_exists('description', $payload)) {
            $fields[] = 'ldesc = :ldesc';
            $params['ldesc'] = (string) $payload['description'];
        }
        if (array_key_exists('remark', $payload)) {
            $fields[] = 'lremark = :lremark';
            $params['lremark'] = (string) $payload['remark'];
        }
        if (array_key_exists('location', $payload)) {
            $fields[] = 'llocation = :llocation';
            $params['llocation'] = (string) $payload['location'];
        }
        if (array_key_exists('item_code', $payload)) {
            $fields[] = 'litemcode = :litemcode';
            $params['litemcode'] = (string) $payload['item_code'];
        }
        if (array_key_exists('part_no', $payload)) {
            $fields[] = 'lpartno = :lpartno';
            $params['lpartno'] = (string) $payload['part_no'];
        }

        if ($fields === []) {
            return $existing;
        }

        $sql = 'UPDATE tbltransaction_item SET ' . implode(', ', $fields) . ' WHERE lid = :item_id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->getItemById($mainId, $itemId);
    }

    public function deleteItem(int $mainId, int $itemId): bool
    {
        $existing = $this->getItemById($mainId, $itemId);
        if ($existing === null) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tbltransaction_item WHERE lid = :item_id');
        $stmt->execute(['item_id' => $itemId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function applyAction(int $mainId, string $salesRefno, string $action, array $payload = []): ?array
    {
        $viewerUserId = max(0, (int) ($payload['user_id'] ?? 0));
        $existing = $this->getSalesOrder($mainId, $salesRefno, $viewerUserId);
        if ($existing === null) {
            return null;
        }

        $normalizedAction = strtolower(trim($action));
        $set = [];
        $params = [
            'main_id' => (string) $mainId,
            'refno' => $salesRefno,
        ];

        if ($normalizedAction === 'submit' || $normalizedAction === 'submitsales') {
            $set[] = "lsubmitstat = 'Submitted'";
            $set[] = 'lcancel = 0';
        } elseif ($normalizedAction === 'approve' || $normalizedAction === 'approvesales') {
            if (!$this->isApprover($mainId, $viewerUserId, 'SO')) {
                throw new RuntimeException('Only approver accounts can approve sales orders');
            }
            $set[] = "lsubmitstat = 'Approved'";
            $set[] = 'lcancel = 0';
        } elseif ($normalizedAction === 'unpost') {
            $set[] = "lsubmitstat = 'Pending'";
            $set[] = "ltransaction_status = 'Pending'";
            $set[] = 'lcancel = 0';
            $set[] = 'invoice_refno = NULL';
            $set[] = 'invoice_no = NULL';
            $set[] = 'ldr_refno = NULL';
            $set[] = 'ldr_no = NULL';
            $this->syncInquiryOnSalesUnpost($salesRefno);
        } elseif ($normalizedAction === 'cancel' || $normalizedAction === 'cancel_so' || $normalizedAction === 'cancelled' || $normalizedAction === 'canceled') {
            $set[] = "lsubmitstat = 'Cancelled'";
            $set[] = "ltransaction_status = 'Cancelled'";
            $set[] = 'lcancel = 1';
            $set[] = 'lcancel_reason = :cancel_reason';
            $params['cancel_reason'] = trim((string) ($payload['reason'] ?? ''));
            $this->syncInquiryOnSalesCancel($salesRefno);
        } else {
            throw new RuntimeException('Unsupported action: ' . $action);
        }

        if ($set !== []) {
            $sql = 'UPDATE tbltransaction SET ' . implode(', ', $set) . ' WHERE lmain_id = :main_id AND lrefno = :refno';
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($params);
        }

        return $this->getSalesOrder($mainId, $salesRefno, $viewerUserId);
    }

    /**
     * Convert a sales order to either an order slip or invoice.
     * Mirrors old-system import flow by:
     * - reusing existing linked documents when present
     * - creating target document from SO header/items when absent
     * - updating SO linkage fields and posted status
     *
     * @return array<string, mixed>
     */
    public function convertToDocument(
        int $mainId,
        int $userId,
        string $salesRefno,
        string $documentType,
        array $payload = []
    ): array {
        $sales = $this->getSalesOrder($mainId, $salesRefno);
        if ($sales === null) {
            throw new RuntimeException('Sales order not found');
        }

        $order = $sales['order'] ?? [];
        $items = is_array($sales['items'] ?? null) ? $sales['items'] : [];
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        if (!in_array($status, ['approved', 'posted'], true)) {
            throw new RuntimeException('Sales order must be approved before conversion');
        }
        if ((int) ($order['is_cancelled'] ?? 0) > 0) {
            throw new RuntimeException('Cancelled sales orders cannot be converted');
        }

        $normalizedType = strtolower(trim($documentType));
        if (in_array($normalizedType, ['orderslip', 'order-slip', 'order_slip', 'converttoor'], true)) {
            return $this->convertToOrderSlip($mainId, $userId, $salesRefno, $order, $items);
        }
        if (in_array($normalizedType, ['invoice', 'converttoinclusive', 'converttoexclusive'], true)) {
            return $this->convertToInvoice($mainId, $userId, $salesRefno, $order, $items, $payload);
        }

        throw new RuntimeException('Unsupported documentType: ' . $documentType);
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function convertToOrderSlip(
        int $mainId,
        int $userId,
        string $salesRefno,
        array $order,
        array $items
    ): array {
        $orderSlipRepo = new OrderSlipRepository($this->db);
        $existingRef = trim((string) ($order['order_slip_refno'] ?? ''));
        if ($existingRef !== '') {
            $existing = $orderSlipRepo->getOrderSlip($mainId, $existingRef);
            if ($existing !== null) {
                return [
                    'type' => 'orderslip',
                    'created' => false,
                    'document' => $existing,
                ];
            }
        }

        $createPayload = [
            'main_id' => $mainId,
            'user_id' => $userId,
            'order_id' => $salesRefno,
            'sales_no' => (string) ($order['sales_no'] ?? ''),
            'contact_id' => (string) ($order['contact_id'] ?? ''),
            'sales_date' => (string) ($order['sales_date'] ?? date('Y-m-d')),
            'sales_person' => (string) ($order['sales_person'] ?? ''),
            'sales_person_id' => (string) ($order['sales_person_id'] ?? ''),
            'delivery_address' => (string) ($order['delivery_address'] ?? ''),
            'reference_no' => (string) ($order['reference_no'] ?? ''),
            'customer_reference' => (string) ($order['customer_reference'] ?? ''),
            'price_group' => (string) ($order['price_group'] ?? ''),
            'credit_limit' => (float) ($order['credit_limit'] ?? 0),
            'terms' => (string) ($order['terms'] ?? ''),
            'promise_to_pay' => (string) ($order['promise_to_pay'] ?? ''),
            'po_number' => (string) ($order['po_number'] ?? ''),
            'remarks' => (string) ($order['remarks'] ?? ''),
            'status' => 'Posted',
            'items' => array_map(
                static fn(array $item): array => [
                    'item_id' => (string) ($item['item_refno'] ?? $item['item_id'] ?? ''),
                    'part_no' => (string) ($item['part_no'] ?? ''),
                    'item_code' => (string) ($item['item_code'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                    'location' => (string) ($item['location'] ?? ''),
                    'qty' => (int) ($item['qty'] ?? 0),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'remark' => (string) ($item['remark'] ?? 'OnStock'),
                ],
                $items
            ),
        ];

        $created = $orderSlipRepo->createOrderSlip($mainId, $userId, $createPayload);
        $doc = $created['order_slip'] ?? [];
        $docRef = (string) ($doc['order_slip_refno'] ?? '');
        $docNo = (string) ($doc['slip_no'] ?? '');

        $update = $this->db->pdo()->prepare(
            'UPDATE tbltransaction
             SET ldr_refno = :doc_ref,
                 ldr_no = :doc_no,
                 lsubmitstat = "Posted",
                 ltransaction_status = "Posted",
                 limported = 1
             WHERE lmain_id = :main_id
               AND lrefno = :sales_refno'
        );
        $update->execute([
            'doc_ref' => $docRef,
            'doc_no' => $docNo,
            'main_id' => (string) $mainId,
            'sales_refno' => $salesRefno,
        ]);

        return [
            'type' => 'orderslip',
            'created' => true,
            'document' => $created,
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function convertToInvoice(
        int $mainId,
        int $userId,
        string $salesRefno,
        array $order,
        array $items,
        array $payload = []
    ): array {
        $invoiceRepo = new InvoiceRepository($this->db);
        $existingRef = trim((string) ($order['invoice_refno'] ?? ''));
        if ($existingRef !== '') {
            $existing = $invoiceRepo->getInvoice($mainId, $existingRef);
            if ($existing !== null) {
                return [
                    'type' => 'invoice',
                    'created' => false,
                    'document' => $existing,
                ];
            }
        }

        $createPayload = [
            'main_id' => $mainId,
            'user_id' => $userId,
            'order_id' => $salesRefno,
            'sales_no' => (string) ($order['sales_no'] ?? ''),
            'contact_id' => (string) ($order['contact_id'] ?? ''),
            'sales_date' => (string) ($order['sales_date'] ?? date('Y-m-d')),
            'sales_person' => (string) ($order['sales_person'] ?? ''),
            'sales_person_id' => (string) ($order['sales_person_id'] ?? ''),
            'delivery_address' => (string) ($order['delivery_address'] ?? ''),
            'reference_no' => (string) ($order['reference_no'] ?? ''),
            'customer_reference' => (string) ($order['customer_reference'] ?? ''),
            'price_group' => (string) ($order['price_group'] ?? ''),
            'credit_limit' => (float) ($order['credit_limit'] ?? 0),
            'terms' => (string) ($order['terms'] ?? ''),
            'promise_to_pay' => (string) ($order['promise_to_pay'] ?? ''),
            'po_number' => (string) ($order['po_number'] ?? ''),
            'remarks' => (string) ($order['remarks'] ?? ''),
            'status' => 'Posted',
            'tax_type' => (string) ($payload['tax_type'] ?? ''),
            'items' => array_map(
                static fn(array $item): array => [
                    'item_id' => (string) ($item['item_refno'] ?? $item['item_id'] ?? ''),
                    'part_no' => (string) ($item['part_no'] ?? ''),
                    'item_code' => (string) ($item['item_code'] ?? ''),
                    'description' => (string) ($item['description'] ?? ''),
                    'location' => (string) ($item['location'] ?? ''),
                    'qty' => (int) ($item['qty'] ?? 0),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'remark' => (string) ($item['remark'] ?? 'OnStock'),
                ],
                $items
            ),
        ];

        $created = $invoiceRepo->createInvoice($mainId, $userId, $createPayload);
        $doc = $created['invoice'] ?? [];
        $docRef = (string) ($doc['invoice_refno'] ?? '');
        $docNo = (string) ($doc['invoice_no'] ?? '');

        $update = $this->db->pdo()->prepare(
            'UPDATE tbltransaction
             SET invoice_refno = :doc_ref,
                 invoice_no = :doc_no,
                 lsubmitstat = "Posted",
                 ltransaction_status = "Posted",
                 limported = 1
             WHERE lmain_id = :main_id
               AND lrefno = :sales_refno'
        );
        $update->execute([
            'doc_ref' => $docRef,
            'doc_no' => $docNo,
            'main_id' => (string) $mainId,
            'sales_refno' => $salesRefno,
        ]);

        return [
            'type' => 'invoice',
            'created' => true,
            'document' => $created,
        ];
    }

    private function syncInquiryOnSalesCancel(string $salesRefno): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT linquiry_refno FROM tbltransaction WHERE lrefno = :refno LIMIT 1');
        $stmt->execute(['refno' => $salesRefno]);
        $inquiryRefno = (string) ($stmt->fetchColumn() ?: '');
        if ($inquiryRefno === '') {
            return;
        }

        $update = $this->db->pdo()->prepare(
            'UPDATE tblinquiry
             SET IsCancel = 1, lsubmitstat = "Cancelled"
             WHERE lrefno = :refno'
        );
        $update->execute(['refno' => $inquiryRefno]);
    }

    private function syncInquiryOnSalesUnpost(string $salesRefno): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT linquiry_refno FROM tbltransaction WHERE lrefno = :refno LIMIT 1');
        $stmt->execute(['refno' => $salesRefno]);
        $inquiryRefno = (string) ($stmt->fetchColumn() ?: '');
        if ($inquiryRefno === '') {
            return;
        }

        $update = $this->db->pdo()->prepare(
            'UPDATE tblinquiry
             SET IsCancel = 0, lsubmitstat = "Submitted"
             WHERE lrefno = :refno'
        );
        $update->execute(['refno' => $inquiryRefno]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCustomerSnapshot(int $mainId, string $contactId): ?array
    {
        $sql = <<<SQL
SELECT
    p.lsessionid,
    p.lfname,
    p.llname,
    p.lcompany,
    p.ldelivery_address,
    p.lprice_group,
    p.lcredit,
    p.lterms,
    p.lsales_person,
    p.lcity,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person_name
FROM tblpatient p
LEFT JOIN tblaccount acc ON acc.lid = p.lsales_person
WHERE p.lmain_id = :main_id
  AND p.lsessionid = :contact_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'contact_id' => $contactId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function getLatestTerms(string $contactId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lclass_code, lname
             FROM tblpatient_terms
             WHERE lpatient = :contact_id
             ORDER BY lsince DESC, lid DESC
             LIMIT 1'
        );
        $stmt->execute(['contact_id' => $contactId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? [] : $row;
    }

    private function generateSalesNumber(PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) AS max_no
             FROM tblnumber_generator
             WHERE ltransaction_type = :type'
        );
        $stmt->execute(['type' => 'Sales Order']);
        $next = (int) ($stmt->fetchColumn() ?: 0) + 1;

        $insert = $pdo->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no)
             VALUES (:type, :max_no)'
        );
        $insert->execute([
            'type' => 'Sales Order',
            'max_no' => $next,
        ]);

        $prefix = $this->detectSalesNumberPrefix($pdo);
        return $prefix . $next;
    }

    private function detectSalesNumberPrefix(PDO $pdo): string
    {
        $stmt = $pdo->query(
            "SELECT lsaleno
             FROM tbltransaction
             WHERE COALESCE(lsaleno, '') <> ''
             ORDER BY lid DESC
             LIMIT 1"
        );
        $latest = (string) ($stmt->fetchColumn() ?: '');
        if ($latest !== '' && preg_match('/^([A-Za-z\\-]+)\\d+$/', $latest, $matches) === 1) {
            return (string) ($matches[1] ?? 'NSO-');
        }
        return 'NSO-';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listItems(string $salesRefno): array
    {
        $sql = <<<SQL
SELECT
    soi.lid AS id,
    COALESCE(soi.lrefno, '') AS sales_refno,
    COALESCE(soi.litemid, '') AS item_id,
    COALESCE(soi.linv_refno, '') AS item_refno,
    COALESCE(soi.lpartno, '') AS part_no,
    COALESCE(soi.litemcode, '') AS item_code,
    COALESCE(soi.ldesc, '') AS description,
    COALESCE(soi.llocation, '') AS location,
    COALESCE(soi.lqty, 0) AS qty,
    COALESCE(soi.lprice, 0) AS unit_price,
    COALESCE(soi.lqty, 0) * COALESCE(soi.lprice, 0) AS amount,
    COALESCE(soi.lremark, '') AS remark,
    COALESCE(soi.lbrand, '') AS brand,
    COALESCE(soi.lcancel, 0) AS is_cancelled
FROM tbltransaction_item soi
WHERE soi.lrefno = :sales_refno
  AND COALESCE(soi.lcancel, 0) = 0
ORDER BY soi.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('sales_refno', $salesRefno, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getItemById(int $mainId, int $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    soi.lid AS id,
    COALESCE(soi.lrefno, '') AS sales_refno,
    COALESCE(soi.litemid, '') AS item_id,
    COALESCE(soi.linv_refno, '') AS item_refno,
    COALESCE(soi.lpartno, '') AS part_no,
    COALESCE(soi.litemcode, '') AS item_code,
    COALESCE(soi.ldesc, '') AS description,
    COALESCE(soi.llocation, '') AS location,
    COALESCE(soi.lqty, 0) AS qty,
    COALESCE(soi.lprice, 0) AS unit_price,
    COALESCE(soi.lqty, 0) * COALESCE(soi.lprice, 0) AS amount,
    COALESCE(soi.lremark, '') AS remark,
    COALESCE(soi.lbrand, '') AS brand,
    COALESCE(soi.lcancel, 0) AS is_cancelled
FROM tbltransaction_item soi
INNER JOIN tbltransaction so
    ON so.lrefno = soi.lrefno
WHERE so.lmain_id = :main_id
  AND soi.lid = :item_id
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

    private function insertItem(PDO $pdo, int $mainId, int $userId, string $salesRefno, string $salesDate, array $item): void
    {
        $resolved = $this->resolveInventoryItem($mainId, $item);
        $qty = max(0, (int) ($item['qty'] ?? 0));
        if ($qty <= 0) {
            throw new RuntimeException('qty must be greater than 0');
        }
        $unitPrice = isset($item['unit_price'])
            ? (float) $item['unit_price']
            : (float) ($resolved['price'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO tbltransaction_item
            (lrefno, ltype, litemid, lname, ldesc, lprice, lqty, luser, linv_refno, litem_refno, litemcode, lpartno, lbrand, llocation, lremark, ltransaction_date, lcancel)
            VALUES
            (:lrefno, :ltype, :litemid, :lname, :ldesc, :lprice, :lqty, :luser, :linv_refno, :litem_refno, :litemcode, :lpartno, :lbrand, :llocation, :lremark, :ltransaction_date, 0)'
        );
        $stmt->execute([
            'lrefno' => $salesRefno,
            'ltype' => (string) ($item['type'] ?? 'Sales Order'),
            'litemid' => (string) ($resolved['id'] ?? ''),
            'lname' => (string) ($resolved['description'] ?? ''),
            'ldesc' => (string) ($item['description'] ?? $resolved['description'] ?? ''),
            'lprice' => $unitPrice,
            'lqty' => $qty,
            'luser' => (string) $userId,
            'linv_refno' => (string) ($resolved['session'] ?? ''),
            'litem_refno' => (string) ($resolved['session'] ?? ''),
            'litemcode' => (string) ($item['item_code'] ?? $resolved['item_code'] ?? ''),
            'lpartno' => (string) ($item['part_no'] ?? $resolved['part_no'] ?? ''),
            'lbrand' => (string) ($resolved['brand'] ?? ''),
            'llocation' => (string) ($item['location'] ?? $resolved['location'] ?? ''),
            'lremark' => (string) ($item['remark'] ?? 'OnStock'),
            'ltransaction_date' => $salesDate,
        ]);
    }

    /**
     * @return array{id:string, session:string, item_code:string, part_no:string, description:string, brand:string, location:string, price:float}
     */
    private function resolveInventoryItem(int $mainId, array $payload): array
    {
        $itemRefno = trim((string) ($payload['item_refno'] ?? $payload['product_session'] ?? ''));
        $itemId = trim((string) ($payload['item_id'] ?? $payload['product_id'] ?? ''));

        if ($itemRefno === '' && $itemId === '') {
            throw new RuntimeException('item_refno or item_id is required');
        }

        if ($itemRefno !== '') {
            $stmt = $this->db->pdo()->prepare(
                'SELECT lid, lsession, litemcode, lpartno, ldescription, lbrand, llocation, COALESCE(lunit_price, lcog, 0) AS unit_price
                 FROM tblinventory_item
                 WHERE lmain_id = :main_id
                   AND lsession = :session
                 LIMIT 1'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'session' => $itemRefno,
            ]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'SELECT lid, lsession, litemcode, lpartno, ldescription, lbrand, llocation, COALESCE(lunit_price, lcog, 0) AS unit_price
                 FROM tblinventory_item
                 WHERE lmain_id = :main_id
                   AND lid = :id
                 LIMIT 1'
            );
            $stmt->execute([
                'main_id' => $mainId,
                'id' => $itemId,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Inventory item not found');
        }

        return [
            'id' => (string) ($row['lid'] ?? ''),
            'session' => (string) ($row['lsession'] ?? ''),
            'item_code' => (string) ($row['litemcode'] ?? ''),
            'part_no' => (string) ($row['lpartno'] ?? ''),
            'description' => (string) ($row['ldescription'] ?? ''),
            'brand' => (string) ($row['lbrand'] ?? ''),
            'location' => (string) ($row['llocation'] ?? ''),
            'price' => (float) ($row['unit_price'] ?? 0),
        ];
    }

    private function normalizeDate(string $date): string
    {
        $trimmed = trim($date);
        if ($trimmed === '') {
            return date('Y-m-d');
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeNullableDate(string $date): ?string
    {
        $trimmed = trim($date);
        if ($trimmed === '') {
            return null;
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeTime(string $time): string
    {
        $trimmed = trim($time);
        if ($trimmed === '') {
            return date('H:i:s');
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return date('H:i:s');
        }

        return date('H:i:s', $timestamp);
    }

    private function normalizeSubmitStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'submitted', 'approve', 'approved' => $normalized === 'approved' ? 'Approved' : 'Submitted',
            'posted' => 'Posted',
            'cancelled', 'canceled', 'cancel' => 'Cancelled',
            default => 'Pending',
        };
    }

    private function normalizeTransactionStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'submitted' => 'Submitted',
            'approved' => 'Approved',
            'posted' => 'Posted',
            'cancelled', 'canceled', 'cancel' => 'Cancelled',
            default => 'Pending',
        };
    }

    private function stringOrFallback(mixed $value, string $fallback): string
    {
        $candidate = trim((string) ($value ?? ''));
        return $candidate === '' ? $fallback : $candidate;
    }

    private function isApprover(int $mainId, int $userId, string $module): bool
    {
        if ($mainId <= 0 || $userId <= 0) {
            return false;
        }

        $ownerStmt = $this->db->pdo()->prepare(
            'SELECT 1
             FROM tblaccount
             WHERE lid = :user_id
               AND COALESCE(lstatus, 0) = 1
               AND CAST(COALESCE(ltype, 0) AS SIGNED) = 1
             LIMIT 1'
        );
        $ownerStmt->execute(['user_id' => $userId]);
        if ($ownerStmt->fetchColumn() !== false) {
            return true;
        }

        $aliases = $this->approverModuleAliases($module);
        $placeholders = [];
        $params = [
            'main_id' => (string) $mainId,
            'user_id' => (string) $userId,
        ];
        foreach ($aliases as $index => $alias) {
            $key = 'module_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $alias;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT 1
             FROM tblapprover
             WHERE lmain_id = :main_id
               AND lstaff_id = :user_id
               AND COALESCE(ltrans_type, \'\') IN (' . implode(', ', $placeholders) . ')
             LIMIT 1'
        );
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return array<int, string>
     */
    private function approverModuleAliases(string $module): array
    {
        $normalized = strtolower(trim($module));

        return match ($normalized) {
            'so', 'sales order', 'sales-order' => ['SO', 'Sales Order'],
            'po', 'purchase order', 'purchase-order' => ['PO', 'Purchase Order'],
            'pr', 'purchase request', 'purchase-request' => ['PR', 'Purchase Request'],
            default => [strtoupper(trim($module)), trim($module)],
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
    }
}
