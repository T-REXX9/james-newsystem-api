<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
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
        int $perPage = 50
    ): array {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = ['main_id' => $mainId];
        $where = ['CAST(COALESCE(deb.lmain_id, 0) AS SIGNED) = :main_id'];

        $month = trim($month);
        $year = trim($year);
        if ($month === '') {
            $month = date('m');
        }
        if ($year === '') {
            $year = date('Y');
        }

        if (preg_match('/^\d{2}$/', $month) === 1 && preg_match('/^\d{4}$/', $year) === 1) {
            $params['month_filter'] = sprintf('%s-%s', $year, $month);
            $where[] = 'DATE_FORMAT(COALESCE(deb.ldate, deb.ldatetime), "%Y-%m") = :month_filter';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $searchLike = '%' . $trimmedSearch . '%';
            $isReferenceLike = preg_match('/[\d-]/', $trimmedSearch) === 1;

            $params['search_dm'] = $searchLike;
            $params['search_tracking'] = $searchLike;

            $searchParts = [
                'COALESCE(deb.ldm_no, "") LIKE :search_dm',
                'COALESCE(deb.ltrackingno, "") LIKE :search_tracking',
            ];

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
    COALESCE(deb.linvoice_no, '') AS linvoice_no,
    COALESCE(deb.ldatetime, '') AS ldatetime,
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

    public function getByRefno(string $refno): ?array
    {
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
    COALESCE(deb.linvoice_no, '') AS linvoice_no,
    COALESCE(deb.ldatetime, '') AS ldatetime,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tbldebit_memo deb
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(deb.luserid AS CHAR)
WHERE deb.lrefno = :refno
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
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
                (lrefno, ldm_no, lcustomer, lcustomer_lname, lmain_id, luserid, ldate, ltrans_refno, lcurier_name, ltrackingno, lamt, ltransaction_type, lremarks, IsFreightCollect, linvoice_refno, linvoice_no, lstatus)
                VALUES
                (:lrefno, :ldm_no, :lcustomer, :lcustomer_lname, :lmain_id, :luserid, :ldate, :ltrans_refno, :lcurier_name, :ltrackingno, :lamt, :ltransaction_type, :lremarks, :IsFreightCollect, :linvoice_refno, :linvoice_no, :lstatus)'
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

        $created = $this->getByRefno($refno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created freight charge record');
        }

        return $created;
    }

    public function update(int $mainId, string $refno, array $payload): ?array
    {
        $existing = $this->getByRefno($refno);
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

        return $this->getByRefno($refno);
    }

    public function delete(int $mainId, string $refno): bool
    {
        $existing = $this->getByRefno($refno);
        if ($existing === null) {
            return false;
        }

        $this->assertEditable($existing);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->syncLinkedDocumentHeader(
                $pdo,
                (string) ($existing['ltransaction_type'] ?? 'No Reference'),
                (string) ($existing['ltrans_refno'] ?? ''),
                '',
                '',
                ''
            );

            $stmtHeader = $pdo->prepare('DELETE FROM tbldebit_memo WHERE lrefno = :refno');
            $stmtHeader->execute(['refno' => $refno]);

            $stmtItems = $pdo->prepare('DELETE FROM tbldebit_memo_items WHERE lrefno = :refno');
            $stmtItems->execute(['refno' => $refno]);

            $stmtLedger = $pdo->prepare('DELETE FROM tblledger WHERE lrefno = :refno');
            $stmtLedger->execute(['refno' => $refno]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function action(int $mainId, string $userId, string $refno, string $action): array
    {
        $existing = $this->getByRefno($refno);
        if ($existing === null) {
            throw new RuntimeException('Freight charge record not found');
        }

        return match (strtolower(trim($action))) {
            'post', 'approverecord' => $this->postRecord($mainId, $userId, $existing),
            'unpost' => $this->unpostRecord($refno),
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

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE tbldebit_memo SET lstatus = :status WHERE lrefno = :refno');
            $update->execute(['status' => 'Posted', 'refno' => $refno]);

            $exists = $pdo->prepare('SELECT lid FROM tblledger WHERE lrefno = :refno AND lcustomerid = :customer_id LIMIT 1');
            $exists->execute(['refno' => $refno, 'customer_id' => $customerId]);

            if ($exists->fetch(PDO::FETCH_ASSOC) === false) {
                $insert = $pdo->prepare(
                    'INSERT INTO tblledger
                    (lcustomerid, lrefno, lamt, lmesssage, ldatetime, lmainid, ltype, lcredit, ldebit, luserid, lcheckdate, lcheck_no, ldcr, lpdc, lremarks, lref_name, ldebit_refno)
                    VALUES
                    (:lcustomerid, :lrefno, :lamt, :lmesssage, :ldatetime, :lmainid, :ltype, 0, :ldebit, :luserid, :lcheckdate, :lcheck_no, :ldcr, :lpdc, :lremarks, :lref_name, :ldebit_refno)'
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
                ]);
            }

            $pdo->commit();
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

    private function unpostRecord(string $refno): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE tbldebit_memo SET lstatus = :status WHERE lrefno = :refno');
            $update->execute(['status' => 'Pending', 'refno' => $refno]);

            $delete = $pdo->prepare('DELETE FROM tblledger WHERE lrefno = :refno');
            $delete->execute(['refno' => $refno]);

            $pdo->commit();
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
}
