<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class AdjustmentEntryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function list(
        int $mainId,
        string $search = '',
        string $customerId = '',
        string $month = '',
        string $year = '',
        string $status = '',
        string $type = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [];
        $where = ['1=1'];

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
            $where[] = 'DATE_FORMAT(adjust.ldate, "%Y-%m") = :month_filter';
        }

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search_no'] = '%' . $trimmedSearch . '%';
            $params['search_customer'] = '%' . $trimmedSearch . '%';
            $where[] = '(COALESCE(adjust.lno, "") LIKE :search_no OR COALESCE(adjust.lcustomername, "") LIKE :search_customer)';
        }

        $trimmedCustomer = trim($customerId);
        if ($trimmedCustomer !== '') {
            $params['customer_id'] = $trimmedCustomer;
            $where[] = 'adjust.lcustomerid = :customer_id';
        }

        $trimmedStatus = trim($status);
        if ($trimmedStatus !== '' && strtolower($trimmedStatus) !== 'all') {
            $params['status'] = $trimmedStatus;
            $where[] = 'COALESCE(adjust.lstatus, "Pending") = :status';
        }

        $trimmedType = trim($type);
        if ($trimmedType !== '' && strtolower($trimmedType) !== 'all') {
            $params['type'] = $trimmedType;
            $where[] = 'COALESCE(adjust.ltype, "") = :type';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tbladjustment adjust
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = <<<SQL
SELECT
    adjust.lid,
    COALESCE(adjust.lrefno, '') AS lrefno,
    COALESCE(adjust.lno, '') AS lno,
    COALESCE(adjust.lcustomerid, '') AS lcustomerid,
    COALESCE(adjust.lcustomername, '') AS lcustomername,
    adjust.ldate,
    COALESCE(adjust.ltype, '') AS ltype,
    COALESCE(adjust.lamount, 0) AS lamount,
    COALESCE(adjust.lremark, '') AS lremark,
    COALESCE(adjust.luserid, '') AS luserid,
    COALESCE(adjust.lstatus, 'Pending') AS lstatus,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tbladjustment adjust
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(adjust.luserid AS CHAR)
WHERE {$whereSql}
ORDER BY adjust.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
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
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'search' => $trimmedSearch,
                    'customer_id' => $trimmedCustomer,
                    'month' => $month,
                    'year' => $year,
                    'status' => $trimmedStatus,
                    'type' => $trimmedType,
                ],
            ],
        ];
    }

    public function getByRefno(string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    adjust.lid,
    COALESCE(adjust.lrefno, '') AS lrefno,
    COALESCE(adjust.lno, '') AS lno,
    COALESCE(adjust.lcustomerid, '') AS lcustomerid,
    COALESCE(adjust.lcustomername, '') AS lcustomername,
    adjust.ldate,
    COALESCE(adjust.ltype, '') AS ltype,
    COALESCE(adjust.lamount, 0) AS lamount,
    COALESCE(adjust.lremark, '') AS lremark,
    COALESCE(adjust.luserid, '') AS luserid,
    COALESCE(adjust.lstatus, 'Pending') AS lstatus,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tbladjustment adjust
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(adjust.luserid AS CHAR)
WHERE adjust.lrefno = :refno
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

        $type = trim((string) ($payload['type'] ?? 'Debit'));
        if (!in_array($type, ['Debit', 'Credit', 'Zero-Out'], true)) {
            throw new RuntimeException('type must be one of: Debit, Credit, Zero-Out');
        }

        $refno = date('YmdHis') . random_int(12345, 99999);

        $transactionType = $type === 'Debit' ? 'Debit Memo' : 'Credit Memo';
        $counter = $this->nextAdjustmentCounter($transactionType);
        $number = ($type === 'Debit' ? 'DM' : 'CM') . $counter;

        $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
        if ($type === 'Zero-Out') {
            $amount = $this->currentCustomerBalance($customerId);
        }

        $date = $this->normalizeDateTime((string) ($payload['date'] ?? 'now'));
        $remark = trim((string) ($payload['remark'] ?? ''));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $insertCounter = $pdo->prepare(
                'INSERT INTO tblnumber_generator (lmax_no, ltransaction_type) VALUES (:max_no, :transaction_type)'
            );
            $insertCounter->execute([
                'max_no' => $counter,
                'transaction_type' => $transactionType,
            ]);

            $insert = $pdo->prepare(
                'INSERT INTO tbladjustment
                (lrefno, lno, lcustomerid, lcustomername, luserid, ldate, ltype, lamount, lremark, lstatus)
                VALUES
                (:lrefno, :lno, :lcustomerid, :lcustomername, :luserid, :ldate, :ltype, :lamount, :lremark, :lstatus)'
            );
            $insert->execute([
                'lrefno' => $refno,
                'lno' => $number,
                'lcustomerid' => $customerId,
                'lcustomername' => (string) ($customer['lcompany'] ?? ''),
                'luserid' => $userId,
                'ldate' => $date,
                'ltype' => $type,
                'lamount' => $amount,
                'lremark' => $remark,
                'lstatus' => 'Pending',
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $created = $this->getByRefno($refno);
        if ($created === null) {
            throw new RuntimeException('Failed to load created adjustment entry');
        }
        return $created;
    }

    public function update(int $mainId, string $refno, array $payload): ?array
    {
        $existing = $this->getByRefno($refno);
        if ($existing === null) {
            return null;
        }
        if (strcasecmp((string) ($existing['lstatus'] ?? 'Pending'), 'Pending') !== 0) {
            throw new RuntimeException('Only pending adjustments can be edited');
        }

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

            $fields[] = 'lcustomerid = :lcustomerid';
            $fields[] = 'lcustomername = :lcustomername';
            $params['lcustomerid'] = $customerId;
            $params['lcustomername'] = (string) ($customer['lcompany'] ?? '');

            if ((string) ($existing['ltype'] ?? '') === 'Zero-Out') {
                $fields[] = 'lamount = :lamount';
                $params['lamount'] = $this->currentCustomerBalance($customerId);
            }
        }

        if (array_key_exists('date', $payload)) {
            $fields[] = 'ldate = :ldate';
            $params['ldate'] = $this->normalizeDateTime((string) $payload['date']);
        }

        if (array_key_exists('remark', $payload)) {
            $fields[] = 'lremark = :lremark';
            $params['lremark'] = trim((string) $payload['remark']);
        }

        if (array_key_exists('amount', $payload) && (string) ($existing['ltype'] ?? '') !== 'Zero-Out') {
            $fields[] = 'lamount = :lamount';
            $params['lamount'] = (float) $payload['amount'];
        }

        if ($fields !== []) {
            $sql = 'UPDATE tbladjustment SET ' . implode(', ', $fields) . ' WHERE lrefno = :refno';
            $stmt = $this->db->pdo()->prepare($sql);
            foreach ($params as $key => $value) {
                if ($key === 'lamount') {
                    $stmt->bindValue($key, (float) $value);
                    continue;
                }
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
            $stmt->execute();
        }

        return $this->getByRefno($refno);
    }

    public function delete(int $mainId, string $refno): bool
    {
        $existing = $this->getByRefno($refno);
        if ($existing === null) {
            return false;
        }
        if (strcasecmp((string) ($existing['lstatus'] ?? 'Pending'), 'Pending') !== 0) {
            throw new RuntimeException('Only pending adjustments can be deleted');
        }

        $stmt = $this->db->pdo()->prepare('DELETE FROM tbladjustment WHERE lrefno = :refno');
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function action(int $mainId, string $userId, string $refno, string $action): array
    {
        $existing = $this->getByRefno($refno);
        if ($existing === null) {
            throw new RuntimeException('Adjustment entry not found');
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
        $customerId = (string) ($record['lcustomerid'] ?? '');
        if ($refno === '' || $customerId === '') {
            throw new RuntimeException('Invalid adjustment record');
        }

        if (strcasecmp((string) ($record['lstatus'] ?? 'Pending'), 'Pending') !== 0) {
            return [
                'lrefno' => $refno,
                'lstatus' => (string) ($record['lstatus'] ?? 'Posted'),
                'posted' => false,
                'message' => 'Record already posted',
            ];
        }

        $amount = (float) ($record['lamount'] ?? 0);
        $type = (string) ($record['ltype'] ?? 'Debit');
        $message = (string) ($record['lno'] ?? '');
        $remarks = sprintf('%s: %s', $type, (string) ($record['lremark'] ?? ''));
        $date = $this->normalizeDateTime((string) ($record['ldate'] ?? 'now'));

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE tbladjustment SET lstatus = :status WHERE lrefno = :refno');
            $update->execute(['status' => 'Posted', 'refno' => $refno]);

            $exists = $pdo->prepare('SELECT lid FROM tblledger WHERE lrefno = :refno AND lcustomerid = :customer_id LIMIT 1');
            $exists->execute(['refno' => $refno, 'customer_id' => $customerId]);
            if ($exists->fetch(PDO::FETCH_ASSOC) === false) {
                if ($type === 'Debit') {
                    $insertLedger = $pdo->prepare(
                        'INSERT INTO tblledger
                        (lcustomerid, lrefno, lamt, lmesssage, ldatetime, lmainid, ltype, lcredit, ldebit, luserid, lcheckdate, lcheck_no, ldcr, lpdc, lremarks, lref_name, ldebit_refno)
                        VALUES
                        (:lcustomerid, :lrefno, :lamt, :lmesssage, :ldatetime, :lmainid, :ltype, 0, :ldebit, :luserid, :lcheckdate, :lcheck_no, :ldcr, :lpdc, :lremarks, :lref_name, :ldebit_refno)'
                    );
                    $insertLedger->execute([
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
                        'lref_name' => 'Adjustment',
                        'ldebit_refno' => date('Ymd') . random_int(1, 1000000) . random_int(1, 1000000),
                    ]);
                } else {
                    $insertLedger = $pdo->prepare(
                        'INSERT INTO tblledger
                        (lcustomerid, lrefno, lmesssage, lamt, lcredit, lcheckdate, lcheck_no, ldcr, lremarks, lref_name, lmainid, ltype, luserid, ldebit, ldatetime, llast_type)
                        VALUES
                        (:lcustomerid, :lrefno, :lmesssage, :lamt, :lcredit, :lcheckdate, :lcheck_no, :ldcr, :lremarks, :lref_name, :lmainid, :ltype, :luserid, 0, :ldatetime, :llast_type)'
                    );
                    $insertLedger->execute([
                        'lcustomerid' => $customerId,
                        'lrefno' => $refno,
                        'lmesssage' => $message,
                        'lamt' => $amount,
                        'lcredit' => $amount,
                        'lcheckdate' => '',
                        'lcheck_no' => '',
                        'ldcr' => '',
                        'lremarks' => $remarks,
                        'lref_name' => 'Adjustment',
                        'lmainid' => (string) $mainId,
                        'ltype' => 'Credit',
                        'luserid' => $userId,
                        'ldatetime' => $date,
                        'llast_type' => $type === 'Zero-Out' ? 'Zero-Out' : null,
                    ]);
                }
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
            $update = $pdo->prepare('UPDATE tbladjustment SET lstatus = :status WHERE lrefno = :refno');
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

    private function nextAdjustmentCounter(string $transactionType): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(MAX(lmax_no), 0) AS max_no FROM tblnumber_generator WHERE ltransaction_type = :transaction_type'
        );
        $stmt->execute(['transaction_type' => $transactionType]);
        $maxNo = (int) ($stmt->fetchColumn() ?: 0);
        return $maxNo + 1;
    }

    private function currentCustomerBalance(string $customerId): float
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(COALESCE(ldebit,0) - COALESCE(lcredit,0)), 0) FROM tblledger WHERE lcustomerid = :customer_id'
        );
        $stmt->execute(['customer_id' => $customerId]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    private function getCustomerBySession(string $sessionId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT lsessionid, lcompany FROM tblpatient WHERE lsessionid = :session_id LIMIT 1');
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function normalizeDateTime(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || strtolower($trimmed) === 'now') {
            return date('Y-m-d H:i:s');
        }

        $ts = strtotime($trimmed);
        if ($ts === false) {
            throw new RuntimeException('Invalid date value');
        }
        return date('Y-m-d H:i:s', $ts);
    }
}
