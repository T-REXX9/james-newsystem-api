<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class SalesInquiryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listInquiries(
        int $mainId,
        string $search = '',
        string $status = 'active',
        int $page = 1,
        int $perPage = 50
    ): array {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [
            'main_id' => (string) $mainId,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = ['iq.lmain_id = :main_id'];

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus === '' || $normalizedStatus === 'active') {
            $where[] = 'COALESCE(iq.IsCancel, 0) = 0';
        } elseif ($normalizedStatus === 'cancelled') {
            $where[] = "(COALESCE(iq.IsCancel, 0) = 1 OR COALESCE(iq.lsubmitstat, '') = 'Cancelled')";
        } elseif ($normalizedStatus === 'pending' || $normalizedStatus === 'draft') {
            $where[] = 'COALESCE(iq.IsCancel, 0) = 0';
            $where[] = "COALESCE(iq.lsubmitstat, 'Pending') = 'Pending'";
        } elseif ($normalizedStatus === 'submitted' || $normalizedStatus === 'approved') {
            $where[] = 'COALESCE(iq.IsCancel, 0) = 0';
            $where[] = "COALESCE(iq.lsubmitstat, '') = 'Submitted'";
        } elseif ($normalizedStatus !== 'all') {
            $where[] = 'COALESCE(iq.IsCancel, 0) = 0';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_no'] = '%' . $trimmedSearch . '%';
            $params['search_ref'] = '%' . $trimmedSearch . '%';
            $params['search_company'] = '%' . $trimmedSearch . '%';
            $params['search_sales'] = '%' . $trimmedSearch . '%';
            $params['search_customer'] = '%' . $trimmedSearch . '%';
            $where[] = <<<SQL
(
    COALESCE(iq.linqno, '') LIKE :search_no
    OR COALESCE(iq.lrefno, '') LIKE :search_ref
    OR COALESCE(iq.lcompany, '') LIKE :search_company
    OR COALESCE(iq.lsalesperson, '') LIKE :search_sales
    OR COALESCE(iq.lcustomerid, '') LIKE :search_customer
)
SQL;
        }

        $whereSql = implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) FROM tblinquiry iq WHERE {$whereSql}";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bindParams($countStmt, $params, false);
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    iq.lid AS id,
    COALESCE(iq.linqno, '') AS inquiry_no,
    COALESCE(iq.lrefno, '') AS inquiry_refno,
    COALESCE(iq.ldate, '') AS sales_date,
    COALESCE(iq.ltime, '') AS sales_time,
    COALESCE(iq.lcustomerid, '') AS contact_id,
    COALESCE(iq.lcompany, '') AS customer_company,
    COALESCE(iq.lsalesperson, '') AS sales_person,
    COALESCE(iq.lsales_person_id, '') AS sales_person_id,
    COALESCE(iq.lsales_address, '') AS delivery_address,
    COALESCE(iq.lmy_refno, '') AS reference_no,
    COALESCE(iq.lyour_refno, '') AS customer_reference,
    COALESCE(iq.lprice_group, '') AS price_group,
    COALESCE(iq.lcredit_limit, 0) AS credit_limit,
    COALESCE(iq.lterms, '') AS terms,
    COALESCE(iq.lpromissory_note, '') AS promise_to_pay,
    COALESCE(iq.lpo_no, '') AS po_number,
    COALESCE(iq.lnote, '') AS remarks,
    COALESCE(iq.lsource, '') AS inquiry_type,
    COALESCE(iq.lurgency, '') AS urgency,
    COALESCE(iq.lurgency_date, NULL) AS urgency_date,
    COALESCE(iq.lsubmitstat, 'Pending') AS status,
    COALESCE(iq.IsCancel, 0) AS is_cancelled
FROM tblinquiry iq
WHERE {$whereSql}
ORDER BY iq.lid DESC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $refnos = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['inquiry_refno'] ?? ''),
            $items
        )));

        $aggregateByRef = $this->fetchListAggregatesByRefnos($refnos);
        foreach ($items as &$row) {
            $ref = (string) ($row['inquiry_refno'] ?? '');
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
                    'search' => $trimmedSearch,
                    'status' => $normalizedStatus === '' ? 'active' : $normalizedStatus,
                ],
            ],
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
    i.linq_refno AS inquiry_refno,
    COUNT(*) AS item_count,
    SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS grand_total
FROM tblinquiry_item i
WHERE i.linq_refno IN (
    %s
)
GROUP BY i.linq_refno
SQL;
        $stmt = $this->db->pdo()->prepare(sprintf($sql, implode(', ', $placeholders)));
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['inquiry_refno'] ?? '');
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
    public function getInquiry(int $mainId, string $inquiryRefno): ?array
    {
        $sql = <<<SQL
SELECT
    iq.lid AS id,
    COALESCE(iq.linqno, '') AS inquiry_no,
    COALESCE(iq.lrefno, '') AS inquiry_refno,
    COALESCE(iq.ldate, '') AS sales_date,
    COALESCE(iq.ltime, '') AS sales_time,
    COALESCE(iq.lcustomerid, '') AS contact_id,
    COALESCE(iq.lcompany, '') AS customer_company,
    COALESCE(iq.lsalesperson, '') AS sales_person,
    COALESCE(iq.lsales_person_id, '') AS sales_person_id,
    COALESCE(iq.lsales_address, '') AS delivery_address,
    COALESCE(iq.lmy_refno, '') AS reference_no,
    COALESCE(iq.lyour_refno, '') AS customer_reference,
    COALESCE(iq.lprice_group, '') AS price_group,
    COALESCE(iq.lcredit_limit, 0) AS credit_limit,
    COALESCE(iq.lterms, '') AS terms,
    COALESCE(iq.lpromissory_note, '') AS promise_to_pay,
    COALESCE(iq.lpo_no, '') AS po_number,
    COALESCE(iq.lnote, '') AS remarks,
    COALESCE(iq.lsource, '') AS inquiry_type,
    COALESCE(iq.lurgency, '') AS urgency,
    COALESCE(iq.lurgency_date, NULL) AS urgency_date,
    COALESCE(iq.lsubmitstat, 'Pending') AS status,
    COALESCE(iq.IsCancel, 0) AS is_cancelled,
    COALESCE(iq.ltransaction_status, '') AS transaction_status,
    COALESCE(iq.lvat_type, '') AS vat_type,
    COALESCE(iq.lvat_percent, 0) AS vat_percent
FROM tblinquiry iq
WHERE iq.lmain_id = :main_id
  AND iq.lrefno = :inquiry_refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $stmt->bindValue('inquiry_refno', $inquiryRefno, PDO::PARAM_STR);
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($record === false) {
            return null;
        }

        $record['items'] = $this->listItems($inquiryRefno);
        $record['item_count'] = count($record['items']);
        $record['grand_total'] = array_reduce(
            $record['items'],
            static fn(float $sum, array $item): float => $sum + (float) ($item['amount'] ?? 0),
            0.0
        );

        return $record;
    }

    public function createInquiry(int $mainId, int $userId, array $payload): array
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
            $inquiryRefno = trim((string) ($payload['inquiry_refno'] ?? ''));
            if ($inquiryRefno === '') {
                $inquiryRefno = date('YmdHis') . random_int(10000, 99999);
            }

            $inquiryNo = trim((string) ($payload['inquiry_no'] ?? ''));
            if ($inquiryNo === '') {
                $inquiryNo = $this->generateInquiryNumber($pdo);
            }

            $salesDate = $this->normalizeDate((string) ($payload['sales_date'] ?? date('Y-m-d')));
            $salesTime = $this->normalizeTime((string) ($payload['sales_time'] ?? date('H:i:s')));

            $insert = $pdo->prepare(
                'INSERT INTO tblinquiry
                (linqno, ldate, ltime, lcustomerid, lmain_id, luser, lrefno, lcompany, lsalesperson, lsales_person_id, lsales_address, lterms, lterms_condition, lmy_refno, lyour_refno, lprice_group, lcredit_limit, lpromissory_note, lpo_no, lnote, lsource, lsubmitstat, IsCancel, ltransaction_status, lurgency, lurgency_date, lvat_type, lvat_percent, lcity)
                VALUES
                (:linqno, :ldate, :ltime, :lcustomerid, :lmain_id, :luser, :lrefno, :lcompany, :lsalesperson, :lsales_person_id, :lsales_address, :lterms, :lterms_condition, :lmy_refno, :lyour_refno, :lprice_group, :lcredit_limit, :lpromissory_note, :lpo_no, :lnote, :lsource, :lsubmitstat, 0, "Unposted", :lurgency, :lurgency_date, :lvat_type, :lvat_percent, :lcity)'
            );
            $insert->execute([
                'linqno' => $inquiryNo,
                'ldate' => $salesDate,
                'ltime' => $salesTime,
                'lcustomerid' => $contactId,
                'lmain_id' => (string) $mainId,
                'luser' => (string) $userId,
                'lrefno' => $inquiryRefno,
                'lcompany' => $this->stringOrFallback($payload['customer_company'] ?? null, $customer['lcompany'] ?? ''),
                'lsalesperson' => $this->stringOrFallback($payload['sales_person'] ?? null, $customer['sales_person_name'] ?? ''),
                'lsales_person_id' => $this->stringOrFallback($payload['sales_person_id'] ?? null, (string) ($customer['lsales_person'] ?? '')),
                'lsales_address' => $this->stringOrFallback($payload['delivery_address'] ?? null, $customer['ldelivery_address'] ?? ''),
                'lterms' => (string) ($payload['terms'] ?? ($customer['lterms'] ?? '')),
                'lterms_condition' => (string) ($payload['terms'] ?? ($customer['lterms'] ?? '')),
                'lmy_refno' => (string) ($payload['reference_no'] ?? $inquiryNo),
                'lyour_refno' => (string) ($payload['customer_reference'] ?? ''),
                'lprice_group' => (string) ($payload['price_group'] ?? ($customer['lprice_group'] ?? '')),
                'lcredit_limit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : (float) ($customer['lcredit'] ?? 0),
                'lpromissory_note' => (string) ($payload['promise_to_pay'] ?? ''),
                'lpo_no' => (string) ($payload['po_number'] ?? ''),
                'lnote' => (string) ($payload['remarks'] ?? ''),
                'lsource' => (string) ($payload['inquiry_type'] ?? ''),
                'lsubmitstat' => $this->normalizeStatus((string) ($payload['status'] ?? 'Pending')),
                'lurgency' => (string) ($payload['urgency'] ?? ''),
                'lurgency_date' => $this->normalizeNullableDate((string) ($payload['urgency_date'] ?? '')),
                'lvat_type' => (string) ($payload['vat_type'] ?? ($customer['lvat_type'] ?? '')),
                'lvat_percent' => isset($payload['vat_percent']) ? (float) $payload['vat_percent'] : (float) ($customer['lvat_percent'] ?? 0),
                'lcity' => (string) ($customer['lcity'] ?? ''),
            ]);

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->insertItem($pdo, $inquiryRefno, $inquiryNo, $salesDate, $item);
            }

            $pdo->commit();
            return $this->getInquiry($mainId, $inquiryRefno) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateInquiry(int $mainId, string $inquiryRefno, array $payload): ?array
    {
        $existing = $this->getInquiry($mainId, $inquiryRefno);
        if ($existing === null) {
            return null;
        }

        $salesDate = $this->normalizeDate((string) ($payload['sales_date'] ?? (string) ($existing['sales_date'] ?? date('Y-m-d'))));
        $status = $this->normalizeStatus((string) ($payload['status'] ?? (string) ($existing['status'] ?? 'Pending')));

        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblinquiry
             SET
                ldate = :ldate,
                ltime = :ltime,
                lcustomerid = :lcustomerid,
                lcompany = :lcompany,
                lsalesperson = :lsalesperson,
                lsales_person_id = :lsales_person_id,
                lsales_address = :lsales_address,
                lterms = :lterms,
                lterms_condition = :lterms_condition,
                lmy_refno = :lmy_refno,
                lyour_refno = :lyour_refno,
                lprice_group = :lprice_group,
                lcredit_limit = :lcredit_limit,
                lpromissory_note = :lpromissory_note,
                lpo_no = :lpo_no,
                lnote = :lnote,
                lsource = :lsource,
                lsubmitstat = :lsubmitstat,
                lurgency = :lurgency,
                lurgency_date = :lurgency_date
             WHERE lmain_id = :lmain_id
               AND lrefno = :lrefno'
        );
        $stmt->execute([
            'ldate' => $salesDate,
            'ltime' => $this->normalizeTime((string) ($payload['sales_time'] ?? (string) ($existing['sales_time'] ?? date('H:i:s')))),
            'lcustomerid' => (string) ($payload['contact_id'] ?? $existing['contact_id'] ?? ''),
            'lcompany' => (string) ($payload['customer_company'] ?? $existing['customer_company'] ?? ''),
            'lsalesperson' => (string) ($payload['sales_person'] ?? $existing['sales_person'] ?? ''),
            'lsales_person_id' => (string) ($payload['sales_person_id'] ?? $existing['sales_person_id'] ?? ''),
            'lsales_address' => (string) ($payload['delivery_address'] ?? $existing['delivery_address'] ?? ''),
            'lterms' => (string) ($payload['terms'] ?? $existing['terms'] ?? ''),
            'lterms_condition' => (string) ($payload['terms'] ?? $existing['terms'] ?? ''),
            'lmy_refno' => (string) ($payload['reference_no'] ?? $existing['reference_no'] ?? ''),
            'lyour_refno' => (string) ($payload['customer_reference'] ?? $existing['customer_reference'] ?? ''),
            'lprice_group' => (string) ($payload['price_group'] ?? $existing['price_group'] ?? ''),
            'lcredit_limit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : (float) ($existing['credit_limit'] ?? 0),
            'lpromissory_note' => (string) ($payload['promise_to_pay'] ?? $existing['promise_to_pay'] ?? ''),
            'lpo_no' => (string) ($payload['po_number'] ?? $existing['po_number'] ?? ''),
            'lnote' => (string) ($payload['remarks'] ?? $existing['remarks'] ?? ''),
            'lsource' => (string) ($payload['inquiry_type'] ?? $existing['inquiry_type'] ?? ''),
            'lsubmitstat' => $status,
            'lurgency' => (string) ($payload['urgency'] ?? $existing['urgency'] ?? ''),
            'lurgency_date' => $this->normalizeNullableDate((string) ($payload['urgency_date'] ?? $existing['urgency_date'] ?? '')),
            'lmain_id' => (string) $mainId,
            'lrefno' => $inquiryRefno,
        ]);

        if (is_array($payload['items'] ?? null)) {
            $deleteStmt = $this->db->pdo()->prepare('DELETE FROM tblinquiry_item WHERE linq_refno = :linq_refno');
            $deleteStmt->execute(['linq_refno' => $inquiryRefno]);
            foreach ($payload['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->insertItem($this->db->pdo(), $inquiryRefno, (string) ($existing['inquiry_no'] ?? ''), $salesDate, $item);
            }
        }

        return $this->getInquiry($mainId, $inquiryRefno);
    }

    public function cancelInquiry(int $mainId, string $inquiryRefno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblinquiry
             SET IsCancel = 1, lsubmitstat = "Cancelled"
             WHERE lmain_id = :lmain_id
               AND lrefno = :lrefno'
        );
        $stmt->execute([
            'lmain_id' => (string) $mainId,
            'lrefno' => $inquiryRefno,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function addItem(int $mainId, string $inquiryRefno, array $payload): array
    {
        $inquiry = $this->getInquiry($mainId, $inquiryRefno);
        if ($inquiry === null) {
            throw new RuntimeException('Sales inquiry not found');
        }

        $this->insertItem(
            $this->db->pdo(),
            $inquiryRefno,
            (string) ($inquiry['inquiry_no'] ?? ''),
            (string) ($inquiry['sales_date'] ?? date('Y-m-d')),
            $payload
        );
        $itemId = (int) $this->db->pdo()->lastInsertId();

        $item = $this->getItemById($itemId);
        if ($item === null) {
            throw new RuntimeException('Unable to load created sales inquiry item');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateItem(int $mainId, int $itemId, array $payload): ?array
    {
        if (!$this->itemBelongsToMain($mainId, $itemId)) {
            return null;
        }

        $fields = [];
        $params = ['item_id' => $itemId];

        if (array_key_exists('item_id', $payload)) {
            $fields[] = 'litem_id = :litem_id';
            $params['litem_id'] = (string) $payload['item_id'];
        }
        if (array_key_exists('item_refno', $payload)) {
            $fields[] = 'litem_refno = :litem_refno';
            $params['litem_refno'] = (string) $payload['item_refno'];
        }
        if (array_key_exists('part_no', $payload)) {
            $fields[] = 'lpartno = :lpartno';
            $params['lpartno'] = (string) $payload['part_no'];
        }
        if (array_key_exists('item_code', $payload)) {
            $fields[] = 'litem_code = :litem_code';
            $params['litem_code'] = (string) $payload['item_code'];
        }
        if (array_key_exists('description', $payload)) {
            $fields[] = 'ldesc = :ldesc';
            $params['ldesc'] = (string) $payload['description'];
        }
        if (array_key_exists('location', $payload)) {
            $fields[] = 'llocation = :llocation';
            $params['llocation'] = (string) $payload['location'];
        }
        if (array_key_exists('remark', $payload)) {
            $fields[] = 'lremark = :lremark';
            $params['lremark'] = (string) $payload['remark'];
        }
        if (array_key_exists('approved', $payload)) {
            $fields[] = 'lapproved = :lapproved';
            $params['lapproved'] = ((int) $payload['approved']) > 0 ? 1 : 0;
        }

        $hasQty = array_key_exists('qty', $payload);
        $hasPrice = array_key_exists('unit_price', $payload);
        if ($hasQty) {
            $fields[] = 'lqty = :lqty';
            $params['lqty'] = (int) $payload['qty'];
        }
        if ($hasPrice) {
            $fields[] = 'lprice = :lprice';
            $params['lprice'] = (float) $payload['unit_price'];
        }

        if ($fields === []) {
            return $this->getItemById($itemId);
        }

        $sql = 'UPDATE tblinquiry_item SET ' . implode(', ', $fields) . ' WHERE lid = :item_id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->getItemById($itemId);
    }

    public function deleteItem(int $mainId, int $itemId): bool
    {
        if (!$this->itemBelongsToMain($mainId, $itemId)) {
            return false;
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tblinquiry_item WHERE lid = :item_id');
        $stmt->execute(['item_id' => $itemId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Convert an approved inquiry into a sales order using old-system flow semantics:
     * - reuses an existing linked sales order if found
     * - otherwise creates a new sales order with status Submitted
     * - copies inquiry items to sales order items
     * - stores linkage back to inquiry (lso_refno/lso_no)
     *
     * @return array<string, mixed>
     */
    public function convertToSalesOrder(int $mainId, int $userId, string $inquiryRefno): array
    {
        $inquiry = $this->getInquiry($mainId, $inquiryRefno);
        if ($inquiry === null) {
            throw new RuntimeException('Sales inquiry not found');
        }

        $status = strtolower(trim((string) ($inquiry['status'] ?? '')));
        if ($status !== 'submitted') {
            throw new RuntimeException('Inquiry must be finalized/submitted before conversion');
        }

        $existingLinkedRef = $this->findLinkedSalesRefno($mainId, $inquiryRefno);
        if ($existingLinkedRef !== '') {
            $salesRepo = new SalesOrderRepository($this->db);
            $existing = $salesRepo->getSalesOrder($mainId, $existingLinkedRef);
            if ($existing !== null) {
                return $existing;
            }
        }

        $items = is_array($inquiry['items'] ?? null) ? $inquiry['items'] : [];
        $approvedItems = array_values(array_filter(
            $items,
            static function (mixed $item): bool {
                if (!is_array($item)) {
                    return false;
                }

                return (int) ($item['approved'] ?? 0) > 0
                    && strcasecmp(trim((string) ($item['remark'] ?? '')), 'NotListed') !== 0;
            }
        ));
        if ($approvedItems === []) {
            throw new RuntimeException('Inquiry has no approved items to convert');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $salesRefno = date('YmdHis') . random_int(10000, 99999);
            $salesNo = $this->generateSalesNumber($pdo);
            $salesDate = $this->normalizeDate((string) ($inquiry['sales_date'] ?? date('Y-m-d')));
            $salesTime = $this->normalizeTime((string) ($inquiry['sales_time'] ?? date('H:i:s')));

            $insert = $pdo->prepare(
                'INSERT INTO tbltransaction
                (lsaleno, ldate, ltime, lcustomerid, lmain_id, luser, lrefno, lcompany, lsales_address, lmy_refno, lyour_refno, lprice_group, lcredit_limit, lterms, lterm_condition, lpromissory_note, lpo_no, lnote, lsales_person, lsales_person_id, lsubmitstat, ltransaction_status, lcancel, linquiry_refno, linquiry_no, IsInquiry, lurgency, lurgency_date, lcity)
                VALUES
                (:lsaleno, :ldate, :ltime, :lcustomerid, :lmain_id, :luser, :lrefno, :lcompany, :lsales_address, :lmy_refno, :lyour_refno, :lprice_group, :lcredit_limit, :lterms, :lterm_condition, :lpromissory_note, :lpo_no, :lnote, :lsales_person, :lsales_person_id, :lsubmitstat, :ltransaction_status, 0, :linquiry_refno, :linquiry_no, 0, :lurgency, :lurgency_date, :lcity)'
            );
            $insert->execute([
                'lsaleno' => $salesNo,
                'ldate' => $salesDate,
                'ltime' => $salesTime,
                'lcustomerid' => (string) ($inquiry['contact_id'] ?? ''),
                'lmain_id' => (string) $mainId,
                'luser' => (string) $userId,
                'lrefno' => $salesRefno,
                'lcompany' => (string) ($inquiry['customer_company'] ?? ''),
                'lsales_address' => (string) ($inquiry['delivery_address'] ?? ''),
                'lmy_refno' => (string) ($inquiry['reference_no'] ?? ''),
                'lyour_refno' => (string) ($inquiry['customer_reference'] ?? ''),
                'lprice_group' => (string) ($inquiry['price_group'] ?? ''),
                'lcredit_limit' => (float) ($inquiry['credit_limit'] ?? 0),
                'lterms' => (string) ($inquiry['terms'] ?? ''),
                'lterm_condition' => (string) ($inquiry['terms'] ?? ''),
                'lpromissory_note' => (string) ($inquiry['promise_to_pay'] ?? ''),
                'lpo_no' => (string) ($inquiry['po_number'] ?? ''),
                'lnote' => (string) ($inquiry['remarks'] ?? ''),
                'lsales_person' => (string) ($inquiry['sales_person'] ?? ''),
                'lsales_person_id' => (string) ($inquiry['sales_person_id'] ?? ''),
                'lsubmitstat' => 'Submitted',
                'ltransaction_status' => 'Unposted',
                'linquiry_refno' => $inquiryRefno,
                'linquiry_no' => (string) ($inquiry['inquiry_no'] ?? ''),
                'lurgency' => (string) ($inquiry['urgency'] ?? ''),
                'lurgency_date' => $this->normalizeNullableDate((string) ($inquiry['urgency_date'] ?? '')),
                'lcity' => (string) ($inquiry['city'] ?? ''),
            ]);

            $insertItem = $pdo->prepare(
                'INSERT INTO tbltransaction_item
                (lrefno, ltype, litemid, lname, ldesc, lprice, lqty, luser, linv_refno, litem_refno, litemcode, lpartno, lbrand, llocation, lremark, ltransaction_date, lcancel)
                VALUES
                (:lrefno, :ltype, :litemid, :lname, :ldesc, :lprice, :lqty, :luser, :linv_refno, :litem_refno, :litemcode, :lpartno, :lbrand, :llocation, :lremark, :ltransaction_date, 0)'
            );
            foreach ($approvedItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemRef = (string) ($item['item_refno'] ?? $item['item_id'] ?? '');
                $insertItem->execute([
                    'lrefno' => $salesRefno,
                    'ltype' => 'Sales Order',
                    'litemid' => (string) ($item['item_id'] ?? $itemRef),
                    'lname' => (string) ($item['description'] ?? ''),
                    'ldesc' => (string) ($item['description'] ?? ''),
                    'lprice' => (float) ($item['unit_price'] ?? 0),
                    'lqty' => max(0, (int) ($item['qty'] ?? 0)),
                    'luser' => (string) $userId,
                    'linv_refno' => $itemRef,
                    'litem_refno' => $itemRef,
                    'litemcode' => (string) ($item['item_code'] ?? ''),
                    'lpartno' => (string) ($item['part_no'] ?? ''),
                    'lbrand' => (string) ($item['brand'] ?? ''),
                    'llocation' => (string) ($item['location'] ?? ''),
                    'lremark' => (string) ($item['remark'] ?? 'OnStock'),
                    'ltransaction_date' => $salesDate,
                ]);
            }

            $updateInquiry = $pdo->prepare(
                'UPDATE tblinquiry
                 SET lso_refno = :lso_refno,
                     lso_no = :lso_no
                 WHERE lmain_id = :main_id
                   AND lrefno = :inquiry_refno'
            );
            $updateInquiry->execute([
                'lso_refno' => $salesRefno,
                'lso_no' => $salesNo,
                'main_id' => (string) $mainId,
                'inquiry_refno' => $inquiryRefno,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $salesRepo = new SalesOrderRepository($this->db);
        $converted = $salesRepo->getSalesOrder($mainId, $salesRefno);
        if ($converted === null) {
            throw new RuntimeException('Failed to load converted sales order');
        }
        return $converted;
    }

    private function findLinkedSalesRefno(int $mainId, string $inquiryRefno): string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lrefno
             FROM tbltransaction
             WHERE lmain_id = :main_id
               AND linquiry_refno = :inquiry_refno
               AND COALESCE(lcancel, 0) = 0
             ORDER BY lid DESC
             LIMIT 1'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'inquiry_refno' => $inquiryRefno,
        ]);
        return (string) ($stmt->fetchColumn() ?: '');
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

        $prefixStmt = $pdo->query(
            "SELECT lsaleno
             FROM tbltransaction
             WHERE COALESCE(lsaleno, '') <> ''
             ORDER BY lid DESC
             LIMIT 1"
        );
        $latest = (string) ($prefixStmt->fetchColumn() ?: '');
        $prefix = 'NSO-';
        if ($latest !== '' && preg_match('/^([A-Za-z\\-]+)\\d+$/', $latest, $matches) === 1) {
            $prefix = (string) ($matches[1] ?? 'NSO-');
        }

        return $prefix . $next;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCustomerSnapshot(int $mainId, string $contactId): ?array
    {
        $sql = <<<SQL
SELECT
    p.lsessionid,
    p.lcompany,
    p.ldelivery_address,
    p.lprice_group,
    p.lterms,
    p.lcredit,
    p.lsales_person,
    p.lvat_type,
    p.lvat_percent,
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
     * @return array<int, array<string, mixed>>
     */
    private function listItems(string $inquiryRefno): array
    {
        $sql = <<<SQL
SELECT
    i.lid AS id,
    COALESCE(i.linq_refno, '') AS inquiry_refno,
    COALESCE(i.linq_no, '') AS inquiry_no,
    COALESCE(i.litem_id, '') AS item_id,
    COALESCE(i.litem_refno, '') AS item_refno,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    COALESCE(i.llocation, '') AS location,
    COALESCE(i.lqty, 0) AS qty,
    COALESCE(i.lprice, 0) AS unit_price,
    COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0) AS amount,
    COALESCE(i.lremark, '') AS remark,
    COALESCE(i.lapproved, 1) AS approved,
    COALESCE(i.linquiry_date, NULL) AS inquiry_date
FROM tblinquiry_item i
WHERE i.linq_refno = :inquiry_refno
ORDER BY i.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('inquiry_refno', $inquiryRefno, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getItemById(int $itemId): ?array
    {
        $sql = <<<SQL
SELECT
    i.lid AS id,
    COALESCE(i.linq_refno, '') AS inquiry_refno,
    COALESCE(i.linq_no, '') AS inquiry_no,
    COALESCE(i.litem_id, '') AS item_id,
    COALESCE(i.litem_refno, '') AS item_refno,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.litem_code, '') AS item_code,
    COALESCE(i.ldesc, '') AS description,
    COALESCE(i.llocation, '') AS location,
    COALESCE(i.lqty, 0) AS qty,
    COALESCE(i.lprice, 0) AS unit_price,
    COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0) AS amount,
    COALESCE(i.lremark, '') AS remark,
    COALESCE(i.lapproved, 1) AS approved,
    COALESCE(i.linquiry_date, NULL) AS inquiry_date
FROM tblinquiry_item i
WHERE i.lid = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function itemBelongsToMain(int $mainId, int $itemId): bool
    {
        $sql = <<<SQL
SELECT 1
FROM tblinquiry_item i
INNER JOIN tblinquiry iq ON iq.lrefno = i.linq_refno
WHERE iq.lmain_id = :main_id
  AND i.lid = :item_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => (string) $mainId,
            'item_id' => $itemId,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    private function insertItem(PDO $pdo, string $inquiryRefno, string $inquiryNo, string $inquiryDate, array $item): void
    {
        $qty = max(0, (int) ($item['qty'] ?? 0));
        $unitPrice = (float) ($item['unit_price'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO tblinquiry_item
            (linq_no, linq_refno, litem_id, litem_refno, lqty, lprice, litem_code, lpartno, ldesc, llocation, lremark, linquiry_date, lapproved)
            VALUES
            (:linq_no, :linq_refno, :litem_id, :litem_refno, :lqty, :lprice, :litem_code, :lpartno, :ldesc, :llocation, :lremark, :linquiry_date, :lapproved)'
        );
        $stmt->execute([
            'linq_no' => $inquiryNo,
            'linq_refno' => $inquiryRefno,
            'litem_id' => (string) ($item['item_id'] ?? ''),
            'litem_refno' => (string) ($item['item_refno'] ?? ''),
            'lqty' => $qty,
            'lprice' => $unitPrice,
            'litem_code' => (string) ($item['item_code'] ?? ''),
            'lpartno' => (string) ($item['part_no'] ?? ''),
            'ldesc' => (string) ($item['description'] ?? ''),
            'llocation' => (string) ($item['location'] ?? ''),
            'lremark' => (string) ($item['remark'] ?? ''),
            'linquiry_date' => $this->normalizeDate((string) ($item['inquiry_date'] ?? $inquiryDate)),
            'lapproved' => isset($item['approved']) ? ((((int) $item['approved']) > 0) ? 1 : 0) : 1,
        ]);
    }

    private function generateInquiryNumber(PDO $pdo): string
    {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(lmax_no), 0) AS max_no FROM tblnumber_generator WHERE ltransaction_type = 'Inquiry'"
        );
        $stmt->execute();
        $next = (int) ($stmt->fetchColumn() ?: 0) + 1;

        $insert = $pdo->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES ("Inquiry", :lmax_no)'
        );
        $insert->execute(['lmax_no' => $next]);

        return 'INQ' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'submitted', 'approved' => 'Submitted',
            'cancelled', 'canceled' => 'Cancelled',
            default => 'Pending',
        };
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

    private function stringOrFallback(mixed $value, string $fallback): string
    {
        $candidate = trim((string) ($value ?? ''));
        return $candidate === '' ? $fallback : $candidate;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $stmt, array $params, bool $withLimit): void
    {
        foreach ($params as $key => $value) {
            if ($key === 'limit' || $key === 'offset') {
                continue;
            }
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        if ($withLimit) {
            $stmt->bindValue('limit', (int) $params['limit'], PDO::PARAM_INT);
            $stmt->bindValue('offset', (int) $params['offset'], PDO::PARAM_INT);
        }
    }
}
