<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class CollectionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function listCollections(
        int $mainId,
        string $search = '',
        string $status = '',
        string $dateFrom = '',
        string $dateTo = ''
    ): array {
        $sql = <<<SQL
SELECT
    col.*,
    COALESCE(ci.total_amt, 0) AS total_amt
FROM tblcollection col
LEFT JOIN (
    SELECT lrefno, SUM(lamt) AS total_amt
    FROM tblcollection_item
    GROUP BY lrefno
) ci ON ci.lrefno = col.lrefno
WHERE col.lmain_id = :main_id
SQL;
        $params = ['main_id' => $mainId];

        if ($search !== '') {
            $sql .= ' AND (col.lcolection_no LIKE :search OR col.lrefno LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $sql .= ' AND col.lstatus = :status';
            $params['status'] = $status;
        }
        if ($dateFrom !== '') {
            $sql .= ' AND DATE(col.ldatetime) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= ' AND DATE(col.ldatetime) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql .= ' ORDER BY col.lid DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createCollection(int $mainId, int $userId): array
    {
        $prefix = getenv('COLLECTION_PREFIX');
        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'DCR-0';
        }

        $next = $this->nextCollectionCounter();
        $refno = date('ymdHis') . (string) random_int(1000, 999999);
        $collectionNo = $prefix . $next;

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO tblcollection (lrefno, lmain_id, luserid, lstatus, lcolection_no, lamt, ldatetime)
             VALUES (:refno, :main_id, :user_id, :status, :collection_no, :amt, NOW())'
        );
        $stmt->execute([
            'refno' => $refno,
            'main_id' => $mainId,
            'user_id' => $userId,
            'status' => 'Pending',
            'collection_no' => $collectionNo,
            'amt' => 0.00,
        ]);

        $stmt2 = $pdo->prepare(
            'INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES (:type, :max_no)'
        );
        $stmt2->execute(['type' => 'Collection', 'max_no' => $next]);

        return [
            'lrefno' => $refno,
            'lcolection_no' => $collectionNo,
            'lstatus' => 'Pending',
        ];
    }

    public function getCollection(string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    col.*,
    COALESCE(ci.total_amt, 0) AS total_amt
FROM tblcollection col
LEFT JOIN (
    SELECT lrefno, SUM(lamt) AS total_amt
    FROM tblcollection_item
    GROUP BY lrefno
) ci ON ci.lrefno = col.lrefno
WHERE col.lrefno = :refno
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getCollectionItems(string $refno): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tblcollection_item WHERE lrefno = :refno ORDER BY lcollection_status ASC, lid DESC'
        );
        $stmt->execute(['refno' => $refno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUnpaidInvoicesAndOrderSlips(int $mainId, string $customerId): array
    {
        $invoiceSql = <<<SQL
SELECT
    inv.lrefno,
    inv.linvoice_no,
    inv.lvat_percent,
    COALESCE(iit.total_amount, 0) AS totalAmount,
    COALESCE(pay.total_paid, 0) AS totalPaid
FROM tblinvoice_list inv
LEFT JOIN (
    SELECT it.linvoice_refno, SUM(COALESCE(it.lprice, 0) * COALESCE(it.lqty, 0)) AS total_amount
    FROM tblinvoice_itemrec it
    GROUP BY it.linvoice_refno
) iit ON iit.linvoice_refno = inv.lrefno
LEFT JOIN (
    SELECT ct.ltransaction_refno, SUM(COALESCE(ct.lpaid_amt, 0)) AS total_paid
    FROM tblcollection_item_transactions ct
    WHERE ct.ltransaction_type = 'Invoice'
    GROUP BY ct.ltransaction_refno
) pay ON pay.ltransaction_refno = inv.lrefno
WHERE inv.lmain_id = :main_id
  AND inv.lcustomerid = :customer_id
  AND (inv.ldcr_refno = '' OR inv.ldcr_refno IS NULL)
ORDER BY inv.lid DESC
SQL;

        $orSql = <<<SQL
SELECT
    dr.lrefno,
    dr.linvoice_no,
    COALESCE(dit.total_amount, 0) AS totalAmount,
    COALESCE(pay.total_paid, 0) AS totalPaid
FROM tbldelivery_receipt dr
LEFT JOIN (
    SELECT it.lor_refno, SUM(COALESCE(it.lprice, 0) * COALESCE(it.lqty, 0)) AS total_amount
    FROM tbldelivery_receipt_items it
    GROUP BY it.lor_refno
) dit ON dit.lor_refno = dr.lrefno
LEFT JOIN (
    SELECT ct.ltransaction_refno, SUM(COALESCE(ct.lpaid_amt, 0)) AS total_paid
    FROM tblcollection_item_transactions ct
    WHERE ct.ltransaction_type = 'OrderSlip'
    GROUP BY ct.ltransaction_refno
) pay ON pay.ltransaction_refno = dr.lrefno
WHERE dr.lmain_id = :main_id
  AND dr.lcustomerid = :customer_id
  AND (dr.ldcr_refno = '' OR dr.ldcr_refno IS NULL)
ORDER BY dr.lid DESC
SQL;

        $params = ['main_id' => $mainId, 'customer_id' => $customerId];
        $stmtInv = $this->db->pdo()->prepare($invoiceSql);
        $stmtInv->execute($params);
        $invoiceRows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);

        $stmtOr = $this->db->pdo()->prepare($orSql);
        $stmtOr->execute($params);
        $orRows = $stmtOr->fetchAll(PDO::FETCH_ASSOC);

        $invoices = [];
        foreach ($invoiceRows as $row) {
            $totalAmount = (float) $row['totalAmount'];
            $vat = $row['lvat_percent'];
            if ($vat !== null && $vat !== '' && $vat !== 'Vat Inclusive' && $vat !== 'Inclusive') {
                $totalAmount += $totalAmount * (float) $vat;
            }
            $balance = $totalAmount - (float) $row['totalPaid'];
            if ($balance > 0) {
                $invoices[] = [
                    'lrefno' => $row['lrefno'],
                    'linvoice_no' => $row['linvoice_no'],
                    'totalAmount' => $balance,
                ];
            }
        }

        $orderslip = [];
        foreach ($orRows as $row) {
            $balance = (float) $row['totalAmount'] - (float) $row['totalPaid'];
            if ($balance > 0) {
                $orderslip[] = [
                    'lrefno' => $row['lrefno'],
                    'linvoice_no' => $row['linvoice_no'],
                    'totalAmount' => $balance,
                ];
            }
        }

        return [
            'INVLIST' => $invoices,
            'ORLIST' => $orderslip,
        ];
    }

    public function collectionSummary(
        int $mainId,
        string $dateFrom,
        string $dateTo,
        string $bank = '',
        string $checkStatus = '',
        string $customerId = '',
        string $collectionType = '',
        int $limit = 200
    ): array {
        $itemWhere = [
            'ci.lcollect_date >= :date_from',
            'ci.lcollect_date <= :date_to',
            'CAST(COALESCE(ci.lmainid, 0) AS SIGNED) = :main_id',
        ];
        $itemParams = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'main_id' => $mainId,
        ];

        $trimmedBank = trim($bank);
        if ($trimmedBank !== '') {
            $itemWhere[] = 'COALESCE(ci.lbank, \'\') = :bank';
            $itemParams['bank'] = $trimmedBank;
        }

        $trimmedCheckStatus = trim($checkStatus);
        if ($trimmedCheckStatus !== '') {
            $itemWhere[] = 'COALESCE(ci.lremarks, \'\') = :check_status';
            $itemParams['check_status'] = $trimmedCheckStatus;
        }

        $trimmedCustomerId = trim($customerId);
        if ($trimmedCustomerId !== '') {
            $itemWhere[] = 'COALESCE(ci.lcustomer, \'\') = :customer_id';
            $itemParams['customer_id'] = $trimmedCustomerId;
        }

        $trimmedCollectionType = trim($collectionType);
        if ($trimmedCollectionType !== '' && strcasecmp($trimmedCollectionType, 'All') !== 0) {
            if (strcasecmp($trimmedCollectionType, 'Cheque') === 0) {
                $itemWhere[] = "UPPER(TRIM(COALESCE(ci.ltype, ''))) IN ('CHECK','TT','T/T')";
            } else {
                $itemWhere[] = 'UPPER(TRIM(COALESCE(ci.ltype, \'\'))) = :collection_type';
                $itemParams['collection_type'] = strtoupper($trimmedCollectionType);
            }
        }

        $itemSql = sprintf(
            'SELECT
                ci.lid,
                COALESCE(ci.lrefno, \'\') AS lrefno,
                COALESCE(ci.lcustomer, \'\') AS lcustomer,
                COALESCE(ci.lcustomer_fname, \'\') AS lcustomer_fname,
                COALESCE(ci.lcustomer_lname, \'\') AS lcustomer_lname,
                COALESCE(ci.ltype, \'\') AS ltype,
                COALESCE(ci.lbank, \'\') AS lbank,
                COALESCE(ci.lchk_no, \'\') AS lchk_no,
                COALESCE(ci.lchk_date, \'\') AS lchk_date,
                COALESCE(ci.lamt, 0) AS lamt,
                COALESCE(ci.lnotes, \'\') AS lnotes,
                COALESCE(ci.lremarks, \'\') AS lremarks,
                COALESCE(ci.ldatetime, \'\') AS ldatetime,
                COALESCE(ci.lcollect_date, \'\') AS lcollect_date,
                COALESCE(c.lcolection_no, \'\') AS lcollection_no
             FROM tblcollection_item ci
             LEFT JOIN tblcollection c ON c.lrefno = ci.lrefno
             WHERE %s
             ORDER BY ci.lid DESC
             LIMIT :item_limit',
            implode(' AND ', $itemWhere)
        );
        $itemStmt = $this->db->pdo()->prepare($itemSql);
        foreach ($itemParams as $key => $value) {
            if ($key === 'main_id') {
                $itemStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $itemStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $itemStmt->bindValue('item_limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $itemStmt->execute();
        $collectionRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        $collectionRows = array_reverse($collectionRows);

        $summary = [
            'grand_cash' => 0.0,
            'grand_check' => 0.0,
            'grand_tt' => 0.0,
            'grand_less' => 0.0,
        ];

        $normalizedCollectionRows = [];
        foreach ($collectionRows as $row) {
            $amount = (float) ($row['lamt'] ?? 0);
            $cash = 0.0;
            $check = 0.0;
            $tt = 0.0;
            $less = 0.0;
            $remarksText = '';
            $type = strtoupper(trim((string) ($row['ltype'] ?? '')));
            $checkNo = (string) ($row['lchk_no'] ?? '');
            $checkDate = (string) ($row['lchk_date'] ?? '');
            $remarks = (string) ($row['lremarks'] ?? '');
            $bankName = (string) ($row['lbank'] ?? '');

            if ($type === 'CASH') {
                if ($checkNo === '') {
                    $cash = $amount;
                    $summary['grand_cash'] += $cash;
                }
                $remarksText = trim(($checkDate !== '' ? date('m/d/Y', strtotime($checkDate)) : '') . ' ' . $remarks);
            } elseif ($type === 'CHECK') {
                if ($checkNo === '') {
                    $tt = $amount;
                    $summary['grand_tt'] += $tt;
                    $remarksText = trim('TT/' . $bankName . ' ' . ($checkDate !== '' ? date('m/d/Y', strtotime($checkDate)) : '') . ' ' . $remarks);
                } else {
                    $check = $amount;
                    $summary['grand_check'] += $check;
                    $remarksText = trim($bankName . ' ' . $checkNo . ' ' . ($checkDate !== '' ? date('m/d/Y', strtotime($checkDate)) : '') . ' ' . $remarks);
                }
            } elseif ($type === 'TT' || $type === 'T/T') {
                $tt = $amount;
                $summary['grand_tt'] += $tt;
                $remarksText = trim('TT/' . $bankName . ' ' . ($checkDate !== '' ? date('m/d/Y', strtotime($checkDate)) : '') . ' ' . $remarks);
            }

            $normalizedCollectionRows[] = [
                'date' => $row['ldatetime'] !== '' ? date('Y-m-d', strtotime((string) $row['ldatetime'])) : (string) ($row['lcollect_date'] ?? ''),
                'customer' => (string) ($row['lcustomer_fname'] ?? ''),
                'dcr_no' => ltrim((string) ($row['lcollection_no'] ?? ''), 'DCR-'),
                'cash' => $cash,
                'check' => $check,
                'tt' => $tt,
                'less' => $less,
                'remarks' => $remarksText,
                'raw' => $row,
            ];
        }

        $debitWhere = [
            'dm.ldate >= :date_from',
            'dm.ldate <= :date_to',
            'CAST(COALESCE(dm.lmain_id, 0) AS SIGNED) = :main_id',
        ];
        $debitParams = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'main_id' => $mainId,
        ];
        if ($trimmedCustomerId !== '') {
            $debitWhere[] = 'COALESCE(dm.lcustomer, \'\') = :customer_id';
            $debitParams['customer_id'] = $trimmedCustomerId;
        }

        $debitSql = sprintf(
            <<<'SQL'
SELECT
    dm.lid,
    COALESCE(dm.lrefno, '') AS lrefno,
    COALESCE(dm.ldm_no, '') AS ldm_no,
    COALESCE(dm.lcustomer_fname, '') AS lcustomer_code,
    COALESCE(dm.lcustomer_lname, '') AS lcustomer_name,
    COALESCE(dm.ldatetime, '') AS ldatetime,
    GREATEST(
      COALESCE(CAST(dm.lamt AS DECIMAL(15,2)), 0),
      COALESCE(
        (SELECT SUM(COALESCE(CAST(dmi.lamount AS DECIMAL(15,2)), 0))
         FROM tbldebit_memo_items dmi
         WHERE dmi.lrefno = dm.lrefno),
        0
      )
    ) AS lamount
FROM tbldebit_memo dm
WHERE %s
ORDER BY dm.lid DESC
LIMIT :debit_limit
SQL,
            implode(' AND ', $debitWhere)
        );
        $debitStmt = $this->db->pdo()->prepare($debitSql);
        foreach ($debitParams as $key => $value) {
            if ($key === 'main_id') {
                $debitStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $debitStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $debitStmt->bindValue('debit_limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $debitStmt->execute();
        $debitRows = $debitStmt->fetchAll(PDO::FETCH_ASSOC);
        $debitRows = array_reverse($debitRows);

        $debitTotal = 0.0;
        foreach ($debitRows as &$debitRow) {
            $amount = (float) ($debitRow['lamount'] ?? 0);
            $debitRow['lamount'] = $amount;
            $debitTotal += $amount;
        }
        unset($debitRow);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'collection_items' => $normalizedCollectionRows,
            'collection_totals' => [
                'cash' => $summary['grand_cash'],
                'check' => $summary['grand_check'],
                'tt' => $summary['grand_tt'],
                'less' => $summary['grand_less'],
            ],
            'debit_items' => $debitRows,
            'debit_totals' => [
                'amount' => $debitTotal,
            ],
        ];
    }

    public function addCollectionPayment(string $collectionRefno, array $payload): int
    {
        $customer = $this->getCustomerBySession($payload['customer_id']);
        if ($customer === null) {
            throw new RuntimeException('Customer not found');
        }

        $checkDate = $this->toDate($payload['check_date']);
        $collectDate = $this->toDate($payload['collect_date']);

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tblcollection_item
                 (lrefno, lmainid, luserid, lcustomer, lcustomer_fname, lcustomer_lname, ltype, lbank, lchk_no, lchk_date, lamt, lstatus, lremarks, lcollect_date, lpost)
                 VALUES
                 (:lrefno, :lmainid, :luserid, :lcustomer, :lcustomer_fname, :lcustomer_lname, :ltype, :lbank, :lchk_no, :lchk_date, :lamt, :lstatus, :lremarks, :lcollect_date, 0)'
            );
            $stmt->execute([
                'lrefno' => $collectionRefno,
                'lmainid' => $payload['main_id'],
                'luserid' => $payload['user_id'],
                'lcustomer' => $payload['customer_id'],
                'lcustomer_fname' => $customer['lcompany'] ?? '',
                'lcustomer_lname' => $customer['lpatient_code'] ?? '',
                'ltype' => $payload['type'],
                'lbank' => $payload['bank'],
                'lchk_no' => $payload['check_no'],
                'lchk_date' => $checkDate,
                'lamt' => $payload['amount'],
                'lstatus' => $payload['status'],
                'lremarks' => $payload['remarks'],
                'lcollect_date' => $collectDate,
            ]);

            $itemId = (int) $pdo->lastInsertId();
            $remaining = (float) $payload['amount'];
            $csvNos = [];

            foreach ($payload['transactions'] as $tx) {
                $type = (string) ($tx['transaction_type'] ?? '');
                if ($type !== 'Invoice' && $type !== 'OrderSlip') {
                    continue;
                }

                $txRefno = (string) ($tx['transaction_refno'] ?? '');
                $txNo = (string) ($tx['transaction_no'] ?? '');
                $txAmt = (float) ($tx['transaction_amount'] ?? 0);
                if ($txRefno === '' || $txNo === '' || $txAmt <= 0) {
                    continue;
                }

                $csvNos[] = $txNo;
                if ($remaining >= $txAmt) {
                    $paidAmt = $txAmt;
                    $remaining -= $txAmt;
                } elseif ($remaining <= 0) {
                    $paidAmt = 0.0;
                } else {
                    $paidAmt = $remaining;
                    $remaining = 0.0;
                }

                $stmtTx = $pdo->prepare(
                    'INSERT INTO tblcollection_item_transactions
                     (ldatetime, lcollection_refno, lcollection_itemid, ltransaction_type, ltransaction_refno, ltransaction_no, ltransaction_amount, lpaid_amt)
                     VALUES (NOW(), :lcollection_refno, :lcollection_itemid, :ltransaction_type, :ltransaction_refno, :ltransaction_no, :ltransaction_amount, :lpaid_amt)'
                );
                $stmtTx->execute([
                    'lcollection_refno' => $collectionRefno,
                    'lcollection_itemid' => $itemId,
                    'ltransaction_type' => $type,
                    'ltransaction_refno' => $txRefno,
                    'ltransaction_no' => $txNo,
                    'ltransaction_amount' => $txAmt,
                    'lpaid_amt' => $paidAmt,
                ]);
            }

            $stmtUpdate = $pdo->prepare(
                'UPDATE tblcollection_item SET ltransaction_no = :ltransaction_no WHERE lid = :lid'
            );
            $stmtUpdate->execute([
                'ltransaction_no' => implode(',', $csvNos),
                'lid' => $itemId,
            ]);

            $pdo->commit();
            return $itemId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function setCollectionStatus(string $refno, string $status): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblcollection SET lstatus = :status WHERE lrefno = :refno'
        );
        $stmt->execute(['status' => $status, 'refno' => $refno]);

        return [
            'collection_refno' => $refno,
            'status' => $status,
            'updated' => $stmt->rowCount() > 0,
        ];
    }

    public function submitCollection(string $refno, int $mainId): array
    {
        $collection = $this->getCollection($refno);
        if ($collection === null) {
            throw new RuntimeException('Collection not found');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $approvers = $this->getApproverByOrder($mainId, 1);
            foreach ($approvers as $approver) {
                $staffId = (string) ($approver['lstaff_id'] ?? '');
                if ($staffId === '') {
                    continue;
                }
                if (!$this->checkApproverLogExists($staffId, $refno)) {
                    $this->insertApproverLog($mainId, $refno, $staffId);
                }
            }

            $stmt = $pdo->prepare('UPDATE tblcollection SET lstatus = :status WHERE lrefno = :refno');
            $stmt->execute(['status' => 'Submitted', 'refno' => $refno]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'collection_refno' => $refno,
            'status' => 'Submitted',
            'approver_logs_initialized' => true,
            'collection_no' => $collection['lcolection_no'] ?? null,
        ];
    }

    public function approveOrDisapproveCollection(
        string $refno,
        int $mainId,
        string $staffId,
        string $status,
        ?string $remarks = null
    ): array {
        if ($status !== 'Approve' && $status !== 'Disapprove') {
            throw new RuntimeException('Invalid approver action');
        }

        $collection = $this->getCollection($refno);
        if ($collection === null) {
            throw new RuntimeException('Collection not found');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->updateApproverLog($refno, $staffId, $status, $remarks);

            $currentOrder = $this->getApproverOrder($mainId, $staffId);
            $maxOrder = $this->getMaxApproverOrder($mainId);
            $finalStatus = null;
            $nextApprovers = [];

            if ($currentOrder !== null && $maxOrder !== null && $currentOrder >= $maxOrder) {
                $approved = $this->getCountApprove($mainId, $refno);
                $unapproved = $this->getCountUnapprove($mainId, $refno);

                if ($approved < $unapproved) {
                    $finalStatus = 'Rejected';
                } else {
                    $finalStatus = 'Approved';
                }

                $stmt = $pdo->prepare('UPDATE tblcollection SET lstatus = :status WHERE lrefno = :refno');
                $stmt->execute(['status' => $finalStatus, 'refno' => $refno]);
            } elseif ($currentOrder !== null) {
                $nextOrder = $currentOrder + 1;
                $approvers = $this->getApproverByOrder($mainId, $nextOrder);

                foreach ($approvers as $approver) {
                    $nextStaffId = (string) ($approver['lstaff_id'] ?? '');
                    if ($nextStaffId === '') {
                        continue;
                    }
                    if (!$this->checkApproverLogExists($nextStaffId, $refno)) {
                        $this->insertApproverLog($mainId, $refno, $nextStaffId);
                    }
                    $nextApprovers[] = $nextStaffId;
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'collection_refno' => $refno,
            'approver_action' => $status,
            'collection_status' => $finalStatus,
            'next_approvers' => $nextApprovers,
        ];
    }

    public function postCollection(string $refno): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblcollection
             SET lstatus = :status, ldate_approved = NOW()
             WHERE lrefno = :refno'
        );
        $stmt->execute(['status' => 'Posted', 'refno' => $refno]);

        return [
            'collection_refno' => $refno,
            'status' => 'Posted',
            'updated' => $stmt->rowCount() > 0,
        ];
    }

    public function rebuildCollectionLedger(string $refno): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM tblledger WHERE lrefno = :refno');
            $delete->execute(['refno' => $refno]);

            $sql = <<<SQL
SELECT
    it.*,
    (SELECT lcolection_no FROM tblcollection WHERE lrefno = it.lrefno LIMIT 1) AS dcrno,
    (SELECT SUM(ldebit - lcredit) FROM tblledger WHERE lcustomerid = it.lcustomer) AS lastbal
FROM tblcollection_item it
WHERE it.lrefno = :refno
SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['refno' => $refno]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insert = $pdo->prepare(
                'INSERT INTO tblledger
                 (lcustomerid, lrefno, lmesssage, lamt, ldatetime, lmainid, ltype, lcredit, ldebit, luserid, lcheckdate, lcheck_no, ldcr, lpdc, lbalance, lremarks, lref_name, lcollection_id)
                 VALUES
                 (:lcustomerid, :lrefno, :lmesssage, :lamt, :ldatetime, :lmainid, :ltype, :lcredit, :ldebit, :luserid, :lcheckdate, :lcheck_no, :ldcr, :lpdc, :lbalance, :lremarks, :lref_name, :lcollection_id)'
            );

            $today = date('Y-m-d');
            foreach ($items as $item) {
                $isPostDated = !empty($item['lchk_date']) && $item['lchk_date'] > $today;
                $amount = (float) $item['lamt'];

                $insert->execute([
                    'lcustomerid' => $item['lcustomer'],
                    'lrefno' => $item['lrefno'],
                    'lmesssage' => strtoupper((string) $item['ltype']),
                    'lamt' => $amount,
                    'ldatetime' => $item['lcollect_date'] ?: date('Y-m-d'),
                    'lmainid' => $item['lmainid'],
                    'ltype' => 'Credit',
                    'lcredit' => $isPostDated ? 0.0 : $amount,
                    'ldebit' => 0.0,
                    'luserid' => $item['luserid'],
                    'lcheckdate' => $item['lchk_date'],
                    'lcheck_no' => $item['lchk_no'],
                    'ldcr' => $item['dcrno'],
                    'lpdc' => $isPostDated ? $amount : 0.0,
                    'lbalance' => (float) ($item['lastbal'] ?? 0.0),
                    'lremarks' => $item['lremarks'],
                    'lref_name' => 'DCR',
                    'lcollection_id' => $item['lid'],
                ]);
            }

            $pdo->commit();
            return [
                'collection_refno' => $refno,
                'ledger_rows_inserted' => count($items),
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteCollection(string $refno): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Delete collection item transactions linked to items of this collection
            $stmtTx = $pdo->prepare(
                'DELETE cit FROM tblcollection_item_transactions cit
                 INNER JOIN tblcollection_item ci ON ci.lid = cit.lcollection_itemid
                 WHERE ci.lrefno = :refno'
            );
            $stmtTx->execute(['refno' => $refno]);

            // Delete ledger entries linked to this collection
            $stmtLedger = $pdo->prepare('DELETE FROM tblledger WHERE lrefno = :refno');
            $stmtLedger->execute(['refno' => $refno]);

            // Delete approver logs linked to this collection
            $stmtLogs = $pdo->prepare('DELETE FROM tblapprove_logs WHERE lsales_refno = :refno');
            $stmtLogs->execute(['refno' => $refno]);

            // Delete all collection items
            $stmtItems = $pdo->prepare('DELETE FROM tblcollection_item WHERE lrefno = :refno');
            $stmtItems->execute(['refno' => $refno]);

            // Delete the collection header
            $stmtHeader = $pdo->prepare('DELETE FROM tblcollection WHERE lrefno = :refno');
            $stmtHeader->execute(['refno' => $refno]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deleteCollectionItem(int $itemId): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmtTx = $pdo->prepare('DELETE FROM tblcollection_item_transactions WHERE lcollection_itemid = :item_id');
            $stmtTx->execute(['item_id' => $itemId]);

            $stmtItem = $pdo->prepare('DELETE FROM tblcollection_item WHERE lid = :item_id');
            $stmtItem->execute(['item_id' => $itemId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function updateCollectionPaymentLine(int $itemId, array $payload): array
    {
        $item = $this->getCollectionItem($itemId);
        if ($item === null) {
            throw new RuntimeException('Collection item not found');
        }

        $status = (string) ($payload['status'] ?? $item['lstatus']);
        $type = (string) ($payload['type'] ?? $item['ltype']);
        $bank = (string) ($payload['bank'] ?? $item['lbank']);
        $checkNo = (string) ($payload['check_no'] ?? $item['lchk_no']);
        $checkDate = $this->toDate((string) ($payload['check_date'] ?? $item['lchk_date']));
        $amount = isset($payload['amount']) ? (float) $payload['amount'] : (float) $item['lamt'];
        $remarks = (string) ($payload['remarks'] ?? $item['lremarks']);
        $userId = (string) ($payload['user_id'] ?? $item['luserid']);
        $mainId = (string) ($payload['main_id'] ?? $item['lmainid']);
        $transactions = is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmtUpdate = $pdo->prepare(
                'UPDATE tblcollection_item
                 SET lmainid = :lmainid, luserid = :luserid, ltype = :ltype, lbank = :lbank,
                     lchk_no = :lchk_no, lchk_date = :lchk_date, lamt = :lamt, lstatus = :lstatus, lremarks = :lremarks
                 WHERE lid = :lid'
            );
            $stmtUpdate->execute([
                'lmainid' => $mainId,
                'luserid' => $userId,
                'ltype' => $type,
                'lbank' => $bank,
                'lchk_no' => $checkNo,
                'lchk_date' => $checkDate,
                'lamt' => $amount,
                'lstatus' => $status,
                'lremarks' => $remarks,
                'lid' => $itemId,
            ]);

            $stmtLedger = $pdo->prepare(
                'UPDATE tblledger
                 SET lmesssage = :lmesssage, lcheck_no = :lcheck_no, lcheckdate = :lcheckdate, lremarks = :lremarks
                 WHERE lcollection_id = :lcollection_id'
            );
            $stmtLedger->execute([
                'lmesssage' => strtoupper($type),
                'lcheck_no' => strtoupper(trim($bank . ' ' . $checkNo)),
                'lcheckdate' => $checkDate,
                'lremarks' => $remarks,
                'lcollection_id' => $itemId,
            ]);

            $shouldDeleteTransactions = in_array($status, ['Cancelled', '1st Bounce', '2nd Bounce'], true);

            $stmtDeleteTx = $pdo->prepare('DELETE FROM tblcollection_item_transactions WHERE lcollection_itemid = :item_id');
            $stmtDeleteTx->execute(['item_id' => $itemId]);

            $colTrans = '';
            if (!$shouldDeleteTransactions && count($transactions) > 0) {
                $remaining = $amount;
                $txNos = [];

                foreach ($transactions as $tx) {
                    $txType = (string) ($tx['transaction_type'] ?? '');
                    if ($txType !== 'Invoice' && $txType !== 'OrderSlip') {
                        continue;
                    }

                    $txRefno = (string) ($tx['transaction_refno'] ?? '');
                    $txNo = (string) ($tx['transaction_no'] ?? '');
                    $txAmt = (float) ($tx['transaction_amount'] ?? 0);
                    if ($txRefno === '' || $txNo === '' || $txAmt <= 0) {
                        continue;
                    }

                    $txNos[] = $txNo;
                    if ($remaining >= $txAmt) {
                        $paidAmt = $txAmt;
                        $remaining -= $txAmt;
                    } elseif ($remaining <= 0) {
                        $paidAmt = 0.0;
                    } else {
                        $paidAmt = $remaining;
                        $remaining = 0.0;
                    }

                    $stmtInsTx = $pdo->prepare(
                        'INSERT INTO tblcollection_item_transactions
                         (ldatetime, lcollection_refno, lcollection_itemid, ltransaction_type, ltransaction_refno, ltransaction_no, ltransaction_amount, lpaid_amt)
                         VALUES (NOW(), :lcollection_refno, :lcollection_itemid, :ltransaction_type, :ltransaction_refno, :ltransaction_no, :ltransaction_amount, :lpaid_amt)'
                    );
                    $stmtInsTx->execute([
                        'lcollection_refno' => $item['lrefno'],
                        'lcollection_itemid' => $itemId,
                        'ltransaction_type' => $txType,
                        'ltransaction_refno' => $txRefno,
                        'ltransaction_no' => $txNo,
                        'ltransaction_amount' => $txAmt,
                        'lpaid_amt' => $paidAmt,
                    ]);
                }

                $colTrans = implode(',', $txNos);
            }

            $stmtUpdateCsv = $pdo->prepare('UPDATE tblcollection_item SET ltransaction_no = :ltransaction_no WHERE lid = :lid');
            $stmtUpdateCsv->execute(['ltransaction_no' => $colTrans, 'lid' => $itemId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'collection_item_id' => $itemId,
            'collection_refno' => $item['lrefno'],
            'status' => $status,
            'ltransaction_no' => $colTrans,
        ];
    }

    public function postCollectionItems(string $collectionRefno, array $itemIds, int $mainId, int $userId): array
    {
        $collection = $this->getCollection($collectionRefno);
        if ($collection === null) {
            throw new RuntimeException('Collection not found');
        }

        $count = 0;
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($itemIds as $itemIdRaw) {
                $itemId = (int) $itemIdRaw;
                if ($itemId <= 0) {
                    continue;
                }

                $item = $this->getCollectionItem($itemId);
                if ($item === null || (string) $item['lrefno'] !== $collectionRefno) {
                    continue;
                }

                $stmtPost = $pdo->prepare(
                    'UPDATE tblcollection_item SET lcollection_status = :posted, lpost = 1 WHERE lid = :lid'
                );
                $stmtPost->execute(['posted' => 'Posted', 'lid' => $itemId]);

                $amount = abs((float) $item['lamt']);
                $isDebit = ((float) $item['lamt']) < 0;
                $insert = $pdo->prepare(
                    'INSERT INTO tblledger
                     (lcustomerid, lrefno, lmesssage, lamt, lcredit, ldebit, lcheckdate, lcheck_no, ldcr, lremarks, lref_name, lmainid, ltype, luserid, lcollection_id, ldatetime)
                     VALUES
                     (:lcustomerid, :lrefno, :lmesssage, :lamt, :lcredit, :ldebit, :lcheckdate, :lcheck_no, :ldcr, :lremarks, :lref_name, :lmainid, :ltype, :luserid, :lcollection_id, :ldatetime)'
                );
                $insert->execute([
                    'lcustomerid' => $item['lcustomer'],
                    'lrefno' => $collectionRefno,
                    'lmesssage' => strtoupper((string) $item['ltype']),
                    'lamt' => $amount,
                    'lcredit' => $isDebit ? 0 : $amount,
                    'ldebit' => $isDebit ? $amount : 0,
                    'lcheckdate' => $item['lchk_date'],
                    'lcheck_no' => strtoupper(trim((string) $item['lbank'] . ' ' . (string) $item['lchk_no'])),
                    'ldcr' => $collection['lcolection_no'],
                    'lremarks' => strtoupper((string) $item['lremarks']),
                    'lref_name' => 'DCR',
                    'lmainid' => (string) $mainId,
                    'ltype' => $isDebit ? 'Debit' : 'Credit',
                    'luserid' => (string) $userId,
                    'lcollection_id' => $itemId,
                    'ldatetime' => date('Y-m-d H:i:s', strtotime((string) ($item['lcollect_date'] ?? date('Y-m-d')))),
                ]);

                $count++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'collection_refno' => $collectionRefno,
            'posted_items' => $count,
        ];
    }

    public function getApproverLogs(string $refno): array
    {
        $sql = <<<SQL
SELECT
    tal.*,
    acc.lfname AS staff_fName,
    acc.llname AS staff_lName
FROM tblapprove_logs tal
LEFT JOIN tblaccount acc ON CAST(acc.lid AS CHAR) = CAST(tal.lstaff_id AS CHAR)
WHERE tal.lsales_refno = :refno
ORDER BY tal.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['refno' => $refno]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function nextCollectionCounter(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lmax_no FROM tblnumber_generator WHERE ltransaction_type = :type ORDER BY lid DESC LIMIT 1'
        );
        $stmt->execute(['type' => 'Collection']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !isset($row['lmax_no'])) {
            return 1;
        }

        return ((int) $row['lmax_no']) + 1;
    }

    private function getCustomerBySession(string $sessionId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lsessionid, lcompany, lpatient_code FROM tblpatient WHERE lsessionid = :session_id LIMIT 1'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getCollectionItem(int $itemId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tblcollection_item WHERE lid = :lid LIMIT 1');
        $stmt->execute(['lid' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function checkApproverLogExists(string $staffId, string $refno): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid FROM tblapprove_logs WHERE lstaff_id = :staff_id AND lsales_refno = :refno LIMIT 1'
        );
        $stmt->execute(['staff_id' => $staffId, 'refno' => $refno]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function insertApproverLog(int $mainId, string $refno, string $staffId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblapprove_logs (lmain_id, lsales_refno, lstaff_id, IsApproved, IsUnapproved, ldatetime)
             VALUES (:main_id, :refno, :staff_id, 0, 0, NOW())'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'refno' => $refno,
            'staff_id' => $staffId,
        ]);
    }

    private function updateApproverLog(string $refno, string $staffId, string $status, ?string $remarks): void
    {
        if ($status === 'Approve') {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblapprove_logs
                 SET IsApproved = 1, ldatetime = NOW(), lremarks = :remarks
                 WHERE lsales_refno = :refno AND lstaff_id = :staff_id'
            );
        } else {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblapprove_logs
                 SET IsUnapproved = 1, ldatetime = NOW(), lremarks = :remarks
                 WHERE lsales_refno = :refno AND lstaff_id = :staff_id'
            );
        }

        $stmt->execute([
            'remarks' => $remarks,
            'refno' => $refno,
            'staff_id' => $staffId,
        ]);
    }

    private function getMaxApproverOrder(int $mainId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT MAX(lorder) AS max_order FROM tblapprover WHERE lmain_id = :main_id'
        );
        $stmt->execute(['main_id' => (string) $mainId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['max_order'] === null) {
            return null;
        }
        return (int) $row['max_order'];
    }

    private function getApproverOrder(int $mainId, string $staffId): ?int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lorder FROM tblapprover WHERE lmain_id = :main_id AND lstaff_id = :staff_id LIMIT 1'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'staff_id' => $staffId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['lorder'] === null) {
            return null;
        }
        return (int) $row['lorder'];
    }

    private function getCountApprove(int $mainId, string $refno): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(lstaff_id) AS total
             FROM tblapprove_logs
             WHERE lmain_id = :main_id AND lsales_refno = :refno AND IsApproved = 1'
        );
        $stmt->execute(['main_id' => (string) $mainId, 'refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['total'] ?? 0);
    }

    private function getCountUnapprove(int $mainId, string $refno): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(lstaff_id) AS total
             FROM tblapprove_logs
             WHERE lmain_id = :main_id AND lsales_refno = :refno AND IsUnapproved = 1'
        );
        $stmt->execute(['main_id' => (string) $mainId, 'refno' => $refno]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['total'] ?? 0);
    }

    private function getApproverByOrder(int $mainId, int $order): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tblapprover WHERE lmain_id = :main_id AND lorder = :lorder AND ltrans_type = :type'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'lorder' => $order,
            'type' => 'Collection',
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function toDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
