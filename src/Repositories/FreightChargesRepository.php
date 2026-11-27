<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\AuditTrailWriter;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class FreightChargesRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function list(
        int $mainId,
        string $search = '',
        string $status = '',
        string $customerId = '',
        string $month = '',
        string $year = '',
        int $page = 1,
        int $perPage = 50,
        string $dmNo = ''
    ): array {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $trimmedDmNo = trim($dmNo);
        if ($trimmedDmNo !== '') {
            return $this->searchByDmNo($mainId, $trimmedDmNo, $perPage);
        }

        $params = ['main_id' => $mainId];
        $where = ['CAST(COALESCE(deb.lmain_id, 0) AS SIGNED) = :main_id'];

        $trimmedSearch = trim($search);
        $isDebitMemoSearch = preg_match('/^DM[\w-]+$/i', $trimmedSearch) === 1;
        $month = trim($month);
        $year = trim($year);
        if ($month === '') {
            $month = date('m');
        }
        if ($year === '') {
            $year = date('Y');
        }

        if (!$isDebitMemoSearch && preg_match('/^\d{2}$/', $month) === 1 && preg_match('/^\d{4}$/', $year) === 1) {
            $params['month_filter'] = sprintf('%s-%s', $year, $month);
            $where[] = 'DATE_FORMAT(COALESCE(deb.ldate, deb.ldatetime), "%Y-%m") = :month_filter';
        }

        if ($trimmedSearch !== '') {
            $searchLike = '%' . $trimmedSearch . '%';
            $isReferenceLike = preg_match('/[\d-]/', $trimmedSearch) === 1;

            $params['search_dm'] = $searchLike;
            $params['search_tracking'] = $searchLike;

            $searchParts = [
                'COALESCE(deb.lrefno, "") LIKE :search_refno',
                'COALESCE(deb.ldm_no, "") LIKE :search_dm',
                'COALESCE(deb.ltrackingno, "") LIKE :search_tracking',
            ];
            $params['search_refno'] = $searchLike;

            // Match customer names for text-based searches.
            if (!$isReferenceLike) {
                $params['search_customer'] = $searchLike;
                $searchParts[] = 'COALESCE(deb.lcustomer_lname, "") LIKE :search_customer';
            }

            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }

        $trimmedStatus = trim($status);
        if ($trimmedStatus !== '' && strtolower($trimmedStatus) !== 'all') {
            $params['status'] = $trimmedStatus;
            $where[] = 'COALESCE(deb.lstatus, "Pending") = :status';
        }

        $trimmedCustomer = trim($customerId);
        if ($trimmedCustomer !== '') {
            $params['customer_id'] = $trimmedCustomer;
            $where[] = 'COALESCE(deb.lcustomer, "") = :customer_id';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tbldebit_memo deb
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $countStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $countStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $transactionNumberSql = $this->linkedTransactionNumberSql();
        $sql = <<<SQL
SELECT
    deb.lid,
    COALESCE(deb.lrefno, '') AS lrefno,
    COALESCE(deb.ldm_no, '') AS ldm_no,
    COALESCE(deb.lcustomer, '') AS lcustomer,
    COALESCE(deb.lcustomer_lname, '') AS lcustomer_lname,
    COALESCE(deb.lmain_id, '') AS lmain_id,
    COALESCE(deb.luserid, '') AS luserid,
    COALESCE(deb.ldate, '') AS ldate,
    COALESCE(deb.lcurier_name, '') AS lcurier_name,
    COALESCE(deb.ltrackingno, '') AS ltrackingno,
    COALESCE(deb.lamt, 0) AS lamt,
    COALESCE(deb.lremarks, '') AS lremarks,
    COALESCE(deb.lstatus, 'Pending') AS lstatus,
    COALESCE(deb.ltransaction_type, 'No Reference') AS ltransaction_type,
    COALESCE(deb.IsFreightCollect, 0) AS IsFreightCollect,
    COALESCE(deb.ltrans_refno, '') AS ltrans_refno,
    {$transactionNumberSql} AS linvoice_no,
    COALESCE(deb.ldatetime, '') AS ldatetime,
    COALESCE(deb.created_at, '') AS created_at,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tbldebit_memo deb
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(deb.luserid AS CHAR)
WHERE {$whereSql}
ORDER BY deb.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
                'filters' => [
                    'search' => $trimmedSearch,
                    'status' => $trimmedStatus,
                    'customer_id' => $trimmedCustomer,
                    'month' => $month,
                    'year' => $year,
                ],
            ],
        ];
    }

    /** @return array{items: list<array<string, mixed>>, meta: array<string, mixed>} */
    private function searchByDmNo(int $mainId, string $query, int $limit): array
    {
        $limit = min(20, max(1, $limit));
        $sql = <<<'SQL'
SELECT
    lid,
    COALESCE(lrefno, '') AS lrefno,
    COALESCE(ldm_no, '') AS ldm_no,
    COALESCE(lcustomer_lname, '') AS lcustomer_lname,
    COALESCE(ltrackingno, '') AS ltrackingno,
    COALESCE(linvoice_no, '') AS linvoice_no
FROM tbldebit_memo
WHERE lmain_id = :main_id
  AND COALESCE(ldm_no, '') LIKE :dm_no
ORDER BY lid DESC
LIMIT :limit
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        $stmt->bindValue('dm_no', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $items,
            'meta' => [
                'page' => 1,
                'per_page' => $limit,
                'total' => count($items),
                'total_pages' => 1,
                'filters' => ['dm_no' => $query],
            ],
        ];
    }

    public function report(int $mainId, string $dateType, string $dateFrom = '', string $dateTo = ''): array
    {
        [$normalizedDateType, $normalizedDateFrom, $normalizedDateTo] = $this->resolveReportDateRange(
            $dateType,
            $dateFrom,
            $dateTo
        );

        $sql = <<<SQL
SELECT
    COALESCE(deb.lid, 0) AS lid,
    COALESCE(deb.lrefno, '') AS lrefno,
    COALESCE(deb.ldm_no, '') AS ldm_no,
    COALESCE(deb.lcustomer, '') AS lcustomer,
    COALESCE(NULLIF(deb.lcustomer_lname, ''), pat.lcompany, '') AS lcustomer_lname,
    COALESCE(deb.ldate, '') AS ldate,
    COALESCE(deb.lcurier_name, '') AS lcurier_name,
    COALESCE(deb.ltrackingno, '') AS ltrackingno,
    COALESCE(deb.lamt, 0) AS lamt,
    COALESCE(deb.lstatus, 'Pending') AS lstatus,
    COALESCE(deb.ltransaction_type, 'No Reference') AS ltransaction_type,
    COALESCE(deb.linvoice_no, '') AS linvoice_no,
    COALESCE(deb.created_at, '') AS created_at
FROM tbldebit_memo deb
LEFT JOIN tblpatient pat
  ON pat.lsessionid = deb.lcustomer
 AND CAST(COALESCE(pat.lmain_id, 0) AS SIGNED) = :patient_main_id
WHERE CAST(COALESCE(deb.lmain_id, 0) AS SIGNED) = :debit_main_id
  AND DATE(COALESCE(deb.ldate, deb.ldatetime)) >= :date_from
  AND DATE(COALESCE(deb.ldate, deb.ldatetime)) <= :date_to
ORDER BY COALESCE(deb.ldate, deb.ldatetime) ASC, deb.lid ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('patient_main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('debit_main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('date_from', $normalizedDateFrom, PDO::PARAM_STR);
        $stmt->bindValue('date_to', $normalizedDateTo, PDO::PARAM_STR);
        $stmt->execute();

        $rows = [];
        $totalAmount = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $amount = (float) ($row['lamt'] ?? 0);
            $rows[] = [
                'id' => (int) ($row['lid'] ?? 0),
                'refno' => (string) ($row['lrefno'] ?? ''),
                'dm_no' => (string) ($row['ldm_no'] ?? ''),
                'customer_id' => (string) ($row['lcustomer'] ?? ''),
                'customer' => (string) ($row['lcustomer_lname'] ?? ''),
                'transaction' => $this->resolveReportTransactionLabel(
                    (string) ($row['ltransaction_type'] ?? ''),
                    (string) ($row['linvoice_no'] ?? '')
                ),
                'tracking_no' => (string) ($row['ltrackingno'] ?? ''),
                'courier' => (string) ($row['lcurier_name'] ?? ''),
                'date' => $this->normalizeReportDate((string) ($row['ldate'] ?? '')),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'status' => (string) ($row['lstatus'] ?? 'Pending'),
                'amount' => $amount,
            ];
            $totalAmount += $amount;
        }

        return [
            'date_type' => $normalizedDateType,
            'date_from' => $normalizedDateFrom,
            'date_to' => $normalizedDateTo,
            'rows' => $rows,
            'total_amount' => $totalAmount,
        ];
    }

    public function getByRefno(string $refno, int $mainId): ?array
    {
        $transactionNumberSql = $this->linkedTransactionNumberSql();
        $sql = <<<SQL
SELECT
    deb.lid,
    COALESCE(deb.lrefno, '') AS lrefno,
    COALESCE(deb.ldm_no, '') AS ldm_no,
    COALESCE(deb.lcustomer, '') AS lcustomer,
    COALESCE(deb.lcustomer_lname, '') AS lcustomer_lname,
    COALESCE(deb.lmain_id, '') AS lmain_id,
    COALESCE(deb.luserid, '') AS luserid,
    COALESCE(deb.ldate, '') AS ldate,
    COALESCE(deb.lcurier_name, '') AS lcurier_name,
    COALESCE(deb.ltrackingno, '') AS ltrackingno,
    COALESCE(deb.lamt, 0) AS lamt,
    COALESCE(deb.lremarks, '') AS lremarks,
    COALESCE(deb.lstatus, 'Pending') AS lstatus,
    COALESCE(deb.ltransaction_type, 'No Reference') AS ltransaction_type,
    COALESCE(deb.IsFreightCollect, 0) AS IsFreightCollect,
    COALESCE(deb.ltrans_refno, '') AS ltrans_refno,
    {$transactionNumberSql} AS linvoice_no,
    COALESCE(deb.ldatetime, '') AS ldatetime,
    COALESCE(deb.created_at, '') AS created_at,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tbldebit_memo deb
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(deb.luserid AS CHAR)
WHERE CAST(COALESCE(deb.lmain_id, 0) AS SIGNED) = :main_id
  AND (deb.lrefno = :refno OR deb.ldm_no = :dm_no)
ORDER BY CASE WHEN deb.lrefno = :exact_refno THEN 0 ELSE 1 END
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->bindValue('dm_no', $refno, PDO::PARAM_STR);
        $stmt->bindValue('exact_refno', $refno, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    private function linkedTransactionNumberSql(): string
    {
        return <<<'SQL'
CASE
    WHEN deb.ltransaction_type = 'No Reference' THEN COALESCE(deb.linvoice_no, '')
    ELSE COALESCE(
        NULLIF(CONCAT(
            COALESCE((
                SELECT invoice.linvoice_no
                FROM tblinvoice_list invoice
                WHERE invoice.lrefno = deb.ltrans_refno
                  AND CAST(COALESCE(invoice.lmain_id, 0) AS SIGNED) = CAST(COALESCE(deb.lmain_id, 0) AS SIGNED)
                LIMIT 1
            ), ''),
            COALESCE((
                SELECT delivery.linvoice_no
                FROM tbldelivery_receipt delivery
                WHERE delivery.lrefno = deb.ltrans_refno
                  AND CAST(COALESCE(delivery.lmain_id, 0) AS SIGNED) = CAST(COALESCE(deb.lmain_id, 0) AS SIGNED)
                LIMIT 1
            ), '')
        ), ''),
        NULLIF(deb.linvoice_no, ''),
        ''
    )
END
SQL;
    }

    public function create(int $mainId, string $userId, array $payload): array
    {
        $customerId = trim((string) ($payload['customer_id'] ?? ''));
        if ($customerId === '') {
            throw new RuntimeException('customer_id is required');
        }

        $customer = $this->getCustomerBySession($customerId);
        if ($customer === null) {
            throw new RuntimeException('Customer not found');
        }

        $courierName = trim((string) ($payload['courier_name'] ?? ''));
        if ($courierName === '') {
            throw new RuntimeException('courier_name is required');
        }

        $trackingNo = trim((string) ($payload['tracking_no'] ?? ''));
        if ($trackingNo === '') {
            throw new RuntimeException('tracking_no is required');
        }

        $transactionType = trim((string) ($payload['transaction_type'] ?? 'No Reference'));
        if (!in_array($transactionType, ['No Reference', 'Invoice', 'Order Slip'], true)) {
            throw new RuntimeException('transaction_type must be one of: No Reference, Invoice, Order Slip');
        }

        $transRefno = trim((string) ($payload['transaction_refno'] ?? ''));
        $invoiceNo = trim((string) ($payload['invoice_no'] ?? ''));

        if ($this->trackingExists($courierName, $trackingNo, $transRefno)) {
            throw new RuntimeException('Tracking number already exists for this courier/transaction');
        }

        $isFreightCollect = (int) ((bool) ($payload['is_freight_collect'] ?? false));
        $amount = isset($payload['amount']) ? max(0.0, (float) $payload['amount']) : 0.0;
        if ($isFreightCollect === 1) {
            $amount = 0.0;
        }

        $remarks = trim((string) ($payload['remarks'] ?? ''));
        $date = $this->normalizeDateTime((string) ($payload['date'] ?? 'now'));
        $createdAt = date('Y-m-d H:i:s');

        $refno = date('YmdHis') . random_int(12345, 99999);
        $counter = $this->nextDebitMemoCounter();
        $dmNo = 'DM' . $counter;

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $insertCounter = $pdo->prepare(
                'INSERT INTO tblnumber_generator (lmax_no, ltransaction_type) VALUES (:max_no, :transaction_type)'
            );
            $insertCounter->execute([
                'max_no' => $counter,
                'transaction_type' => 'Debit Memo',
            ]);

            $insert = $pdo->prepare(
                'INSERT INTO tbldebit_memo
                (lrefno, ldm_no, lcustomer, lcustomer_lname, lmain_id, luserid, ldate, ltrans_refno, lcurier_name, ltrackingno, lamt, ltransaction_type, lremarks, IsFreightCollect, linvoice_refno, linvoice_no, lstatus, created_at)
                VALUES
                (:lrefno, :ldm_no, :lcustomer, :lcustomer_lname, :lmain_id, :luserid, :ldate, :ltrans_refno, :lcurier_name, :ltrackingno, :lamt, :ltransaction_type, :lremarks, :IsFreightCollect, :linvoice_refno, :linvoice_no, :lstatus, :created_at)'
            );
            $insert->execute([
                'lrefno' => $refno,
                'ldm_no' => $dmNo,
                'lcustomer' => $customerId,
                'lcustomer_lname' => (string) ($customer['lcompany'] ?? ''),
                'lmain_id' => $mainId,
                'luserid' => $userId,
                'ldate' => $date,
                'ltrans_refno' => $transRefno,
                'lcurier_name' => $courierName,
                'ltrackingno' => $trackingNo,
                'lamt' => $amount,
                'ltransaction_type' => $transactionType,
                'lremarks' => $remarks,
                'IsFreightCollect' => $isFreightCollect,
                'linvoice_refno' => $transRefno,
                'linvoice_no' => $invoiceNo,
                'lstatus' => 'Pending',
                'created_at' => $createdAt,
            ]);

            $this->syncLinkedDocumentHeader(
                $pdo,
                $transactionType,
                $transRefno,
                $refno,
                $dmNo,
                $trackingNo
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->getByRefno($refno, $mainId);
        if ($created === null) {
            throw new RuntimeException('Failed to load created freight charge record');
        }

        return $created;
    }

    public function update(int $mainId, string $refno, array $payload): ?array
    {
        $existing = $this->getByRefno($refno, $mainId);
        if ($existing === null) {
            return null;
        }

        $this->assertEditable($existing);

        $fields = [];
        $params = ['refno' => $refno];

        if (array_key_exists('customer_id', $payload)) {
            $customerId = trim((string) $payload['customer_id']);
            if ($customerId === '') {
                throw new RuntimeException('customer_id cannot be empty');
            }

            $customer = $this->getCustomerBySession($customerId);
            if ($customer === null) {
                throw new RuntimeException('Customer not found');
            }

            $fields[] = 'lcustomer = :lcustomer';
            $fields[] = 'lcustomer_lname = :lcustomer_lname';
            $params['lcustomer'] = $customerId;
            $params['lcustomer_lname'] = (string) ($customer['lcompany'] ?? '');
        }

        if (array_key_exists('date', $payload)) {
            $fields[] = 'ldate = :ldate';
            $params['ldate'] = $this->normalizeDateTime((string) $payload['date']);
        }

        if (array_key_exists('courier_name', $payload)) {
            $courier = trim((string) $payload['courier_name']);
            if ($courier === '') {
                throw new RuntimeException('courier_name cannot be empty');
            }
            $fields[] = 'lcurier_name = :lcurier_name';
            $params['lcurier_name'] = $courier;
        }

        if (array_key_exists('tracking_no', $payload)) {
            $tracking = trim((string) $payload['tracking_no']);
            if ($tracking === '') {
                throw new RuntimeException('tracking_no cannot be empty');
            }
            $fields[] = 'ltrackingno = :ltrackingno';
            $params['ltrackingno'] = $tracking;
        }

        $nextCollectFlag = array_key_exists('is_freight_collect', $payload)
            ? (int) ((bool) $payload['is_freight_collect'])
            : (int) ($existing['IsFreightCollect'] ?? 0);

        if (array_key_exists('is_freight_collect', $payload)) {
            $fields[] = 'IsFreightCollect = :IsFreightCollect';
            $params['IsFreightCollect'] = $nextCollectFlag;
        }

        if (array_key_exists('amount', $payload) || array_key_exists('is_freight_collect', $payload)) {
            $amount = array_key_exists('amount', $payload)
                ? max(0.0, (float) $payload['amount'])
                : (float) ($existing['lamt'] ?? 0);
            if ($nextCollectFlag === 1) {
                $amount = 0.0;
            }
            $fields[] = 'lamt = :lamt';
            $params['lamt'] = $amount;
        }

        if (array_key_exists('remarks', $payload)) {
            $fields[] = 'lremarks = :lremarks';
            $params['lremarks'] = trim((string) $payload['remarks']);
        }

        if (array_key_exists('transaction_type', $payload)) {
            $transactionType = trim((string) $payload['transaction_type']);
            if (!in_array($transactionType, ['No Reference', 'Invoice', 'Order Slip'], true)) {
                throw new RuntimeException('transaction_type must be one of: No Reference, Invoice, Order Slip');
            }
            $fields[] = 'ltransaction_type = :ltransaction_type';
            $params['ltransaction_type'] = $transactionType;
        }

        if (array_key_exists('transaction_refno', $payload)) {
            $fields[] = 'ltrans_refno = :ltrans_refno';
            $fields[] = 'linvoice_refno = :linvoice_refno';
            $params['ltrans_refno'] = trim((string) $payload['transaction_refno']);
            $params['linvoice_refno'] = trim((string) $payload['transaction_refno']);
        }

        if (array_key_exists('invoice_no', $payload)) {
            $fields[] = 'linvoice_no = :linvoice_no';
            $params['linvoice_no'] = trim((string) $payload['invoice_no']);
        }

        if ($fields !== []) {
            $nextCourier = (string) ($params['lcurier_name'] ?? $existing['lcurier_name'] ?? '');
            $nextTracking = (string) ($params['ltrackingno'] ?? $existing['ltrackingno'] ?? '');
            $nextTransRef = (string) ($params['ltrans_refno'] ?? $existing['ltrans_refno'] ?? '');
            if ($this->trackingExists($nextCourier, $nextTracking, $nextTransRef, $refno)) {
                throw new RuntimeException('Tracking number already exists for this courier/transaction');
            }

            $pdo = $this->db->pdo();
            $pdo->beginTransaction();
            try {
                $sql = 'UPDATE tbldebit_memo SET ' . implode(', ', $fields) . ' WHERE lrefno = :refno';
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    if ($key === 'lamt') {
                        $stmt->bindValue($key, (float) $value);
                        continue;
                    }
                    if ($key === 'IsFreightCollect') {
                        $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                        continue;
                    }
                    $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
                }
                $stmt->execute();

                $previousType = (string) ($existing['ltransaction_type'] ?? 'No Reference');
                $previousRefno = (string) ($existing['ltrans_refno'] ?? '');
                $nextType = (string) ($params['ltransaction_type'] ?? $previousType);
                $nextDocumentRef = (string) ($params['ltrans_refno'] ?? $previousRefno);
                $nextDmNo = (string) ($existing['ldm_no'] ?? '');
                $nextTrackingNo = (string) ($params['ltrackingno'] ?? $existing['ltrackingno'] ?? '');

                if (
                    $previousRefno !== '' &&
                    ($previousRefno !== $nextDocumentRef || strcasecmp($previousType, $nextType) !== 0)
                ) {
                    $this->syncLinkedDocumentHeader($pdo, $previousType, $previousRefno, '', '', '');
                }

                $this->syncLinkedDocumentHeader(
                    $pdo,
                    $nextType,
                    $nextDocumentRef,
                    $refno,
                    $nextDmNo,
                    $nextTrackingNo
                );

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        return $this->getByRefno($refno, $mainId);
    }

    public function delete(int $mainId, int $userId, string $refno): bool
    {
        if ($userId <= 0) {
            throw new RuntimeException('A valid user is required to delete freight charges');
        }

        $existing = $this->getByRefno($refno, $mainId);
        if ($existing === null) {
            return false;
        }

        $this->assertEditable($existing);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $auditDetails = sprintf(
                'DM No: %s; Customer: %s; Amount: %s; Date: %s; Tracking No: %s; Courier: %s; Remarks: %s',
                (string) ($existing['ldm_no'] ?? ''),
                (string) ($existing['lcustomer_lname'] ?? ''),
                number_format((float) ($existing['lamt'] ?? 0), 2, '.', ''),
                (string) ($existing['ldate'] ?? ''),
                (string) ($existing['ltrackingno'] ?? ''),
                (string) ($existing['lcurier_name'] ?? ''),
                (string) ($existing['lremarks'] ?? '')
            );
            $dmNo = trim((string) ($existing['ldm_no'] ?? ''));
            if ($dmNo !== '') {
                $relabelPriorEvents = $pdo->prepare(
                    'UPDATE tblaudit_trail
                     SET lrefno = :display_refno
                     WHERE lmain_id = :main_id
                       AND lpage = :page
                       AND CAST(lrefno AS BINARY) = CAST(:internal_refno AS BINARY)'
                );
                $relabelPriorEvents->execute([
                    'display_refno' => $dmNo,
                    'main_id' => $mainId,
                    'page' => 'Freight Charges',
                    'internal_refno' => $refno,
                ]);
            }
            $audit = $pdo->prepare(
                'INSERT INTO tblaudit_trail
                    (lmain_id, luser_id, lpage, laction, lrefno, lreason, lold_status, lnew_status, ldatetime)
                 VALUES (:main_id, :user_id, :page, :action, :refno, :reason, :old_status, :new_status, NOW())'
            );
            $audit->execute([
                'main_id' => $mainId,
                'user_id' => $userId,
                'page' => 'Freight Charges',
                'action' => 'Delete',
                'refno' => $dmNo !== '' ? $dmNo : 'DM No. unavailable',
                'reason' => substr($auditDetails, 0, 500),
                'old_status' => (string) ($existing['lstatus'] ?? 'Pending'),
                'new_status' => 'Deleted',
            ]);

            $this->syncLinkedDocumentHeader(
                $pdo,
                (string) ($existing['ltransaction_type'] ?? 'No Reference'),
                (string) ($existing['ltrans_refno'] ?? ''),
                '',
                '',
                ''
            );

            $stmtHeader = $pdo->prepare('DELETE FROM tbldebit_memo WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id');
            $stmtHeader->execute(['refno' => $refno, 'main_id' => $mainId]);

            $stmtItems = $pdo->prepare('DELETE FROM tbldebit_memo_items WHERE lrefno = :refno');
            $stmtItems->execute(['refno' => $refno]);

            $stmtLedger = $pdo->prepare("DELETE FROM tblledger WHERE lrefno = :refno AND lcustomerid = :customer_id AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id AND lref_name = 'Freight Charges' AND ltype = 'Debit'");
            $stmtLedger->execute(['refno' => $refno, 'customer_id' => (string) ($existing['lcustomer'] ?? ''), 'main_id' => $mainId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function previewCustomerLedgerDuplicateCleanup(int $mainId, string $customerId): array
    {
        $summary = $this->customerLedgerDuplicateCleanupSummary($mainId, $customerId, false);
        unset($summary['_groups']);
        return $summary;
    }

    public function cleanupCustomerLedgerDuplicates(int $mainId, string $customerId, int $userId): array
    {
        if ($mainId <= 0 || trim($customerId) === '' || $userId <= 0) {
            throw new RuntimeException('A valid company, customer, and authenticated user are required');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $summary = $this->customerLedgerDuplicateCleanupSummary($mainId, $customerId, true);
            if ($summary['rows_to_remove'] === 0) {
                $pdo->commit();
                unset($summary['_groups']);
                return $summary + ['cleaned' => true, 'rows_removed' => 0, 'archive_file' => null];
            }

            $archiveDirectory = dirname(__DIR__, 2) . '/storage/freight-ledger-duplicate-archives';
            if (!is_dir($archiveDirectory) && !mkdir($archiveDirectory, 0700, true) && !is_dir($archiveDirectory)) {
                throw new RuntimeException('Unable to create the protected Freight Charges archive directory');
            }
            @chmod($archiveDirectory, 0700);

            $archiveRows = [];
            foreach ($summary['_groups'] as $group) {
                foreach ($group['delete_rows'] as $row) {
                    $row['_repair_customer_id'] = $customerId;
                    $row['_repair_main_id'] = $mainId;
                    $row['_repair_refno'] = $group['refno'];
                    $row['_repair_memo_id'] = $group['memo']['lid'];
                    $row['_repair_memo_no'] = $group['memo']['ldm_no'];
                    $row['_repair_memo_amount'] = $group['memo']['lamt'];
                    $row['_repair_memo_date'] = $group['memo_date'];
                    $row['_repair_keep_lid'] = $group['keep_lid'];
                    $archiveRows[] = $row;
                }
            }

            $archiveName = sprintf(
                'customer-%s-%s-%s.json',
                preg_replace('/[^a-zA-Z0-9_-]/', '_', $customerId),
                date('Ymd-His'),
                bin2hex(random_bytes(4))
            );
            $archivePath = $archiveDirectory . '/' . $archiveName;
            $archive = [
                'main_id' => $mainId,
                'customer_id' => $customerId,
                'actor_id' => $userId,
                'archived_at' => date(DATE_ATOM),
                'deleted_row_count' => count($archiveRows),
                'rows' => $archiveRows,
            ];
            $json = json_encode($archive, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $handle = @fopen($archivePath, 'x');
            if ($handle === false) {
                throw new RuntimeException('Unable to create the protected Freight Charges archive');
            }
            try {
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new RuntimeException('Could not fully write the Freight Charges archive');
                }
                fflush($handle);
            } finally {
                fclose($handle);
            }
            @chmod($archivePath, 0600);

            $delete = $pdo->prepare(
                "DELETE FROM tblledger
                 WHERE lid = :lid
                   AND lcustomerid = :customer_id
                   AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id
                   AND lrefno = :refno
                   AND lref_name = 'Freight Charges'
                   AND ltype = 'Debit'"
            );
            $deleted = 0;
            foreach ($summary['_groups'] as $group) {
                foreach ($group['delete_rows'] as $row) {
                    $delete->execute([
                        'lid' => (int) $row['lid'],
                        'customer_id' => $customerId,
                        'main_id' => $mainId,
                        'refno' => $group['refno'],
                    ]);
                    if ($delete->rowCount() !== 1) {
                        throw new RuntimeException('A ledger row changed during cleanup; no rows were committed');
                    }
                    $deleted++;
                }
            }
            $pdo->commit();

            (new AuditTrailWriter($pdo))->write(
                $mainId,
                $userId,
                'Freight Charges',
                'Clean duplicate Freight Charges ledger rows',
                $customerId,
                sprintf('%d row(s) removed; %d valid row(s) retained. Archive: %s', $deleted, $summary['groups_repaired'], $archiveName),
                'Duplicates present',
                'Repaired'
            );

            unset($summary['_groups']);
            return $summary + [
                'cleaned' => true,
                'rows_removed' => $deleted,
                'archive_file' => $archiveName,
            ];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function customerLedgerDuplicateCleanupSummary(int $mainId, string $customerId, bool $lock): array
    {
        if ($mainId <= 0 || trim($customerId) === '') {
            throw new RuntimeException('A valid company and customer are required');
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT lmainid, lcustomerid, lrefno, COUNT(*) AS row_count
             FROM tblledger
             WHERE CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id
               AND lcustomerid = :customer_id
               AND lref_name = 'Freight Charges'
               AND ltype = 'Debit'
               AND COALESCE(lrefno, '') <> ''
             GROUP BY lmainid, lcustomerid, lrefno
             HAVING COUNT(*) > 1"
        );
        $stmt->execute(['main_id' => $mainId, 'customer_id' => $customerId]);
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $eligible = [];
        $skipped = 0;

        foreach ($keys as $key) {
            $assessment = $this->assessCustomerLedgerDuplicateGroup($pdo, $key, $lock);
            if (!$assessment['eligible']) {
                $skipped++;
                continue;
            }
            $eligible[] = [
                'refno' => (string) $key['lrefno'],
                'memo' => $assessment['memo'],
                'memo_date' => $assessment['memo_date'],
                'keep_lid' => $assessment['keep_lid'],
                'delete_rows' => $assessment['delete_rows'],
            ];
        }

        return [
            'groups_found' => count($keys),
            'groups_repaired' => count($eligible),
            'rows_to_remove' => array_sum(array_map(static fn (array $group): int => count($group['delete_rows']), $eligible)),
            'groups_skipped' => $skipped,
            '_groups' => $eligible,
        ];
    }

    private function assessCustomerLedgerDuplicateGroup(PDO $pdo, array $key, bool $lock): array
    {
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $memoStmt = $pdo->prepare(
            "SELECT lid, lrefno, lmain_id, lcustomer, ldm_no, lamt, ldate, ldatetime, lstatus
             FROM tbldebit_memo
             WHERE lrefno = :refno
               AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id
               AND lcustomer = :customer_id
               AND LOWER(COALESCE(lstatus, '')) = 'posted'" . $lockSql
        );
        $memoStmt->execute([
            'refno' => $key['lrefno'],
            'main_id' => (int) $key['lmainid'],
            'customer_id' => $key['lcustomerid'],
        ]);
        $memos = $memoStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($memos) !== 1) {
            return ['eligible' => false];
        }
        $memo = $memos[0];

        $ledgerStmt = $pdo->prepare(
            "SELECT * FROM tblledger
             WHERE lrefno = :refno
               AND lcustomerid = :customer_id
               AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id
               AND lref_name = 'Freight Charges'
               AND ltype = 'Debit'
             ORDER BY lid ASC" . $lockSql
        );
        $ledgerStmt->execute([
            'refno' => $key['lrefno'],
            'customer_id' => $key['lcustomerid'],
            'main_id' => (int) $key['lmainid'],
        ]);
        $ledgerRows = $ledgerStmt->fetchAll(PDO::FETCH_ASSOC);
        $amountCents = static function (mixed $value): ?int {
            return is_numeric($value) ? (int) round((float) $value * 100) : null;
        };
        $memoAmount = $amountCents($memo['lamt'] ?? null);
        if (count($ledgerRows) < 2 || $memoAmount === null) {
            return ['eligible' => false];
        }

        $memoDateValue = trim((string) ($memo['ldate'] ?? ''));
        if ($memoDateValue === '') {
            $memoDateValue = trim((string) ($memo['ldatetime'] ?? ''));
        }
        $memoDate = substr($memoDateValue, 0, 10);
        $dateMatches = [];
        foreach ($ledgerRows as $row) {
            if (
                $amountCents($row['lamt'] ?? null) !== $memoAmount
                || $amountCents($row['ldebit'] ?? null) !== $memoAmount
                || $amountCents($row['lcredit'] ?? null) !== 0
            ) {
                return ['eligible' => false];
            }
            if (substr((string) ($row['ldatetime'] ?? ''), 0, 10) === $memoDate) {
                $dateMatches[] = $row;
            }
        }
        if ($dateMatches === []) {
            return ['eligible' => false];
        }

        usort($dateMatches, static fn (array $a, array $b): int => (int) $a['lid'] <=> (int) $b['lid']);
        $keepId = (int) $dateMatches[0]['lid'];
        return [
            'eligible' => true,
            'memo' => $memo,
            'memo_date' => $memoDate,
            'keep_lid' => $keepId,
            'delete_rows' => array_values(array_filter(
                $ledgerRows,
                static fn (array $row): bool => (int) $row['lid'] !== $keepId
            )),
        ];
    }

    public function action(int $mainId, string $userId, string $refno, string $action): array
    {
        $existing = $this->getByRefno($refno, $mainId);
        if ($existing === null) {
            throw new RuntimeException('Freight charge record not found');
        }

        return match (strtolower(trim($action))) {
            'post', 'approverecord' => $this->postRecord($mainId, $userId, $existing),
            'unpost' => $this->unpostRecord($mainId, $userId, $refno, (string) ($existing['lcustomer'] ?? ''), (string) ($existing['lstatus'] ?? 'Posted')),
            default => throw new RuntimeException('Unsupported action'),
        };
    }

    private function postRecord(int $mainId, string $userId, array $record): array
    {
        $refno = (string) ($record['lrefno'] ?? '');
        $customerId = (string) ($record['lcustomer'] ?? '');
        if ($refno === '' || $customerId === '') {
            throw new RuntimeException('Invalid freight charge record');
        }

        if (strcasecmp((string) ($record['lstatus'] ?? 'Pending'), 'Pending') !== 0) {
            return [
                'lrefno' => $refno,
                'lstatus' => (string) ($record['lstatus'] ?? 'Posted'),
                'posted' => false,
                'message' => 'Record already posted',
            ];
        }

        $amount = abs((float) ($record['lamt'] ?? 0));
        $message = (string) ($record['ldm_no'] ?? '');
        $remarks = 'FREIGHT CHARGES';
        $remarkText = trim((string) ($record['lremarks'] ?? ''));
        if ($remarkText !== '') {
            $remarks .= '-' . $remarkText;
        }
        $date = $this->normalizeDateTime((string) ($record['ldate'] ?? 'now'));
        $createdAt = date('Y-m-d H:i:s');

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE tbldebit_memo SET lstatus = :status WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id');
            $update->execute(['status' => 'Posted', 'refno' => $refno, 'main_id' => $mainId]);

            $exists = $pdo->prepare("SELECT lid FROM tblledger WHERE lrefno = :refno AND lcustomerid = :customer_id AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id AND lref_name = 'Freight Charges' AND ltype = 'Debit' LIMIT 1");
            $exists->execute(['refno' => $refno, 'customer_id' => $customerId, 'main_id' => $mainId]);

            if ($exists->fetch(PDO::FETCH_ASSOC) === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO tblledger
                    (lcustomerid, lrefno, lamt, lmesssage, ldatetime, lmainid, ltype, lcredit, ldebit, luserid, lcheckdate, lcheck_no, ldcr, lpdc, lremarks, lref_name, ldebit_refno, created_at)
                    VALUES
                    (:lcustomerid, :lrefno, :lamt, :lmesssage, :ldatetime, :lmainid, :ltype, 0, :ldebit, :luserid, :lcheckdate, :lcheck_no, :ldcr, :lpdc, :lremarks, :lref_name, :ldebit_refno, :created_at)'
                );
                $insert->execute([
                    'lcustomerid' => $customerId,
                    'lrefno' => $refno,
                    'lamt' => $amount,
                    'lmesssage' => $message,
                    'ldatetime' => $date,
                    'lmainid' => (string) $mainId,
                    'ltype' => 'Debit',
                    'ldebit' => $amount,
                    'luserid' => $userId,
                    'lcheckdate' => '',
                    'lcheck_no' => '',
                    'ldcr' => '',
                    'lpdc' => 0,
                    'lremarks' => $remarks,
                    'lref_name' => 'Freight Charges',
                    'ldebit_refno' => date('Ymd') . random_int(1, 1000000) . random_int(1, 1000000),
                    'created_at' => $createdAt,
                ]);
            }

            $pdo->commit();
            (new AuditTrailWriter($pdo))->write($mainId, (int) $userId, 'Freight Charges', 'Post Freight Charges', $refno, (string) ($record['lremarks'] ?? ''), (string) ($record['lstatus'] ?? 'Pending'), 'Posted');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'lrefno' => $refno,
            'lstatus' => 'Posted',
            'posted' => true,
        ];
    }

    private function unpostRecord(int $mainId, string $userId, string $refno, string $customerId, string $oldStatus): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE tbldebit_memo SET lstatus = :status WHERE lrefno = :refno AND CAST(COALESCE(lmain_id, 0) AS SIGNED) = :main_id');
            $update->execute(['status' => 'Pending', 'refno' => $refno, 'main_id' => $mainId]);

            $delete = $pdo->prepare("DELETE FROM tblledger WHERE lrefno = :refno AND lcustomerid = :customer_id AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id AND lref_name = 'Freight Charges' AND ltype = 'Debit'");
            $delete->execute(['refno' => $refno, 'customer_id' => $customerId, 'main_id' => $mainId]);

            $pdo->commit();
            (new AuditTrailWriter($pdo))->write($mainId, (int) $userId, 'Freight Charges', 'Unpost Freight Charges', $refno, '', $oldStatus, 'Pending');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'lrefno' => $refno,
            'lstatus' => 'Pending',
            'unposted' => true,
        ];
    }

    private function getCustomerBySession(string $sessionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lsessionid, lcompany FROM tblpatient WHERE lsessionid = :session_id ORDER BY lid DESC LIMIT 1'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function nextDebitMemoCounter(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) AS max_no FROM tblnumber_generator WHERE ltransaction_type = :transaction_type'
        );
        $stmt->execute(['transaction_type' => 'Debit Memo']);
        $maxNo = (int) ($stmt->fetchColumn() ?: 0);
        return $maxNo + 1;
    }

    private function normalizeDateTime(string $value): string
    {
        $trimmed = trim($value);
        $ts = $trimmed === '' ? time() : strtotime($trimmed);
        if ($ts === false) {
            throw new RuntimeException('Invalid date value');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function assertEditable(array $row): void
    {
        $status = strtolower((string) ($row['lstatus'] ?? 'pending'));
        if ($status !== 'pending') {
            throw new RuntimeException('Only pending freight charges can be modified');
        }
    }

    private function trackingExists(string $courierName, string $trackingNo, string $transRefno = '', string $excludeRefno = ''): bool
    {
        $courierName = trim($courierName);
        $trackingNo = trim($trackingNo);
        if ($courierName === '' || $trackingNo === '') {
            return false;
        }

        $where = ['lcurier_name = :courier_name', 'ltrackingno = :tracking_no'];
        $params = [
            'courier_name' => $courierName,
            'tracking_no' => $trackingNo,
        ];

        $transRefno = trim($transRefno);
        if ($transRefno !== '') {
            $where[] = 'ltrans_refno = :trans_refno';
            $params['trans_refno'] = $transRefno;
        }

        if ($excludeRefno !== '') {
            $where[] = 'lrefno <> :exclude_refno';
            $params['exclude_refno'] = $excludeRefno;
        }

        $sql = 'SELECT lid FROM tbldebit_memo WHERE ' . implode(' AND ', $where) . ' LIMIT 1';
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function syncLinkedDocumentHeader(
        PDO $pdo,
        string $transactionType,
        string $documentRefno,
        string $dmRefno,
        string $dmNo,
        string $trackingNo
    ): void {
        $documentRefno = trim($documentRefno);
        if ($documentRefno === '') {
            return;
        }

        $normalizedType = strtolower(trim($transactionType));
        if ($normalizedType === 'invoice') {
            $stmt = $pdo->prepare(
                'UPDATE tblinvoice_list
                 SET ldm_refno = :ldm_refno,
                     ldm_no = :ldm_no,
                     ldm_trackingno = :ldm_trackingno
                 WHERE lrefno = :document_refno'
            );
        } elseif ($normalizedType === 'order slip') {
            $stmt = $pdo->prepare(
                'UPDATE tbldelivery_receipt
                 SET ldm_refno = :ldm_refno,
                     ldm_no = :ldm_no,
                     ldm_trackingno = :ldm_trackingno
                 WHERE lrefno = :document_refno'
            );
        } else {
            return;
        }

        $stmt->execute([
            'ldm_refno' => $dmRefno,
            'ldm_no' => $dmNo,
            'ldm_trackingno' => $trackingNo,
            'document_refno' => $documentRefno,
        ]);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolveReportDateRange(string $dateType, string $dateFrom, string $dateTo): array
    {
        $today = new DateTimeImmutable('today');
        $normalizedType = strtolower(trim($dateType));

        return match ($normalizedType) {
            'today' => ['Today', $today->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => ['Week', $today->modify('-1 week')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => ['Month', $today->modify('-1 month')->format('Y-m-d'), $today->format('Y-m-d')],
            'year' => ['Year', $today->modify('-1 year')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => ['Custom', $this->normalizeReportDate($dateFrom), $this->normalizeReportDate($dateTo)],
            default => ['Today', $today->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function normalizeReportDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return date('Y-m-d');
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return date('Y-m-d');
        }

        return date('Y-m-d', $timestamp);
    }

    private function resolveReportTransactionLabel(string $transactionType, string $invoiceNo): string
    {
        if (trim($transactionType) === 'No Reference') {
            return 'No Reference';
        }

        $value = trim($invoiceNo);
        return $value !== '' ? $value : trim($transactionType);
    }
}
