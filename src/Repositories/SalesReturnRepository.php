<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;
use RuntimeException;

final class SalesReturnRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function generateRefno(): string
    {
        return date('YmdHis') . random_int(12345, 99999);
    }

    private function getNextCreditMemoNumber(\PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT COALESCE(MAX(lmax_no), 0) AS max_no FROM tblnumber_generator WHERE ltransaction_type = 'Credit Memo'");
        $maxNo = (int) ($stmt->fetchColumn() ?: 0);
        $nextNo = $maxNo + 1;
        $creditNo = 'CM' . date('y') . '-' . $nextNo;

        $insert = $pdo->prepare("INSERT INTO tblnumber_generator (ltransaction_type, lmax_no) VALUES ('Credit Memo', :max_no)");
        $insert->execute(['max_no' => $nextNo]);

        return ['counter' => $nextNo, 'credit_no' => $creditNo];
    }

    private function getCustomerName(\PDO $pdo, string $customerId): string
    {
        $stmt = $pdo->prepare('SELECT COALESCE(lcompany, "") FROM tblpatient WHERE lsessionid = :sid LIMIT 1');
        $stmt->execute(['sid' => $customerId]);
        return (string) ($stmt->fetchColumn() ?: 'Unknown Customer');
    }

    private function ensurePendingStatus(int $mainId, string $refno): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM tblcredit_memo WHERE lrefno = :refno AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id LIMIT 1'
        );
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('Credit memo not found');
        }
        if (strtolower(trim((string) ($row['lstatus'] ?? ''))) === 'posted') {
            throw new RuntimeException('Cannot modify a posted credit memo');
        }
        return $row;
    }

    // -----------------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------------

    public function create(int $mainId, int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $refno = $this->generateRefno();
            $numbering = $this->getNextCreditMemoNumber($pdo);
            $creditNo = $numbering['credit_no'];

            $customerId = trim((string) ($payload['customer_id'] ?? ''));
            $customerName = $customerId !== '' ? $this->getCustomerName($pdo, $customerId) : '';
            $invoiceRefno = trim((string) ($payload['invoice_refno'] ?? ''));
            $invoiceNo = trim((string) ($payload['invoice_no'] ?? ''));
            $type = trim((string) ($payload['type'] ?? ''));
            $transactionRefno = trim((string) ($payload['transaction_refno'] ?? ''));
            $discountAmt = (float) ($payload['discount_amt'] ?? 0);
            $taxType = trim((string) ($payload['tax_type'] ?? 'Exclusive'));

            // If a source document refno is provided, resolve its display number and linked sales refno.
            if ($invoiceRefno !== '') {
                if (strcasecmp($type, 'OR') === 0) {
                    $srcStmt = $pdo->prepare(
                        'SELECT
                            COALESCE(linvoice_no, "") AS doc_no,
                            COALESCE(lsales_refno, "") AS sales_refno
                         FROM tbldelivery_receipt
                         WHERE lrefno = :ref
                         LIMIT 1'
                    );
                } else {
                    $srcStmt = $pdo->prepare(
                        'SELECT
                            COALESCE(linvoice_no, "") AS doc_no,
                            COALESCE(lsales_refno, "") AS sales_refno
                         FROM tblinvoice_list
                         WHERE lrefno = :ref
                         LIMIT 1'
                    );
                }

                $srcStmt->execute(['ref' => $invoiceRefno]);
                $srcRow = $srcStmt->fetch(PDO::FETCH_ASSOC);
                if ($srcRow) {
                    if ($invoiceNo === '') {
                        $invoiceNo = (string) ($srcRow['doc_no'] ?? '');
                    }
                    if ($transactionRefno === '') {
                        $transactionRefno = (string) ($srcRow['sales_refno'] ?? '');
                    }
                }
            }

            $insert = $pdo->prepare(<<<'SQL'
INSERT INTO tblcredit_memo (
    lrefno, lmainid, luserid, lcredit_no, lcustomer, clname, lstatus, lprestatus,
    linvoice_refno, linvoice_no, ltype, ldate, ldaterec, ldatetime,
    lsalesman, lshipvia, ltrackno, lremark, lnote, lcomplaintnote,
    ltransaction_refno, ldiscount_amt, ltax_type
) VALUES (
    :lrefno, :lmainid, :luserid, :lcredit_no, :lcustomer, :clname, 'Pending', '',
    :linvoice_refno, :linvoice_no, :ltype, :ldate, :ldaterec, :ldatetime,
    :lsalesman, :lshipvia, :ltrackno, :lremark, :lnote, :lcomplaintnote,
    :ltransaction_refno, :ldiscount_amt, :ltax_type
)
SQL);
            $now = date('Y-m-d H:i:s');
            $insert->execute([
                'lrefno' => $refno,
                'lmainid' => (string) $mainId,
                'luserid' => (string) $userId,
                'lcredit_no' => $creditNo,
                'lcustomer' => $customerId,
                'clname' => $customerName,
                'linvoice_refno' => $invoiceRefno,
                'linvoice_no' => $invoiceNo,
                'ltype' => $type,
                'ldate' => trim((string) ($payload['date'] ?? date('Y-m-d'))),
                'ldaterec' => $now,
                'ldatetime' => $now,
                'lsalesman' => trim((string) ($payload['salesman'] ?? '')),
                'lshipvia' => trim((string) ($payload['ship_via'] ?? '')),
                'ltrackno' => trim((string) ($payload['tracking_no'] ?? '')),
                'lremark' => trim((string) ($payload['remark'] ?? '')),
                'lnote' => trim((string) ($payload['note'] ?? '')),
                'lcomplaintnote' => trim((string) ($payload['complaint_note'] ?? '')),
                'ltransaction_refno' => $transactionRefno,
                'ldiscount_amt' => $discountAmt,
                'ltax_type' => $taxType,
            ]);

            $pdo->commit();
            return $this->show($mainId, $refno) ?? ['lrefno' => $refno, 'lcredit_no' => $creditNo];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Update
    // -----------------------------------------------------------------------

    public function update(int $mainId, string $refno, array $payload): array
    {
        $this->ensurePendingStatus($mainId, $refno);

        $sets = [];
        $params = ['refno' => $refno, 'main_id' => $mainId];
        $fieldMap = [
            'date' => 'ldate',
            'salesman' => 'lsalesman',
            'ship_via' => 'lshipvia',
            'tracking_no' => 'ltrackno',
            'remark' => 'lremark',
            'note' => 'lnote',
            'complaint_note' => 'lcomplaintnote',
            'customer_id' => 'lcustomer',
        ];

        foreach ($fieldMap as $payloadKey => $dbCol) {
            if (array_key_exists($payloadKey, $payload)) {
                $paramKey = 'set_' . $payloadKey;
                $sets[] = "{$dbCol} = :{$paramKey}";
                $params[$paramKey] = trim((string) $payload[$payloadKey]);
            }
        }

        // If customer_id changed, also update clname
        if (array_key_exists('customer_id', $payload)) {
            $pdo = $this->db->pdo();
            $newName = $this->getCustomerName($pdo, trim((string) $payload['customer_id']));
            $sets[] = 'clname = :set_clname';
            $params['set_clname'] = $newName;
        }

        if ($sets === []) {
            return $this->show($mainId, $refno) ?? [];
        }

        $setSql = implode(', ', $sets);
        $sql = "UPDATE tblcredit_memo SET {$setSql} WHERE lrefno = :refno AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id";
        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        return $this->show($mainId, $refno) ?? [];
    }

    // -----------------------------------------------------------------------
    // Source Items (available items from the linked Invoice/OR)
    // -----------------------------------------------------------------------

    public function sourceItems(int $mainId, string $refno): array
    {
        $pdo = $this->db->pdo();
        $cmStmt = $pdo->prepare(
            'SELECT ltype, linvoice_refno, ltransaction_refno FROM tblcredit_memo WHERE lrefno = :refno AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id LIMIT 1'
        );
        $cmStmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $cmStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $cmStmt->execute();
        $cm = $cmStmt->fetch(PDO::FETCH_ASSOC);
        if (!$cm) {
            throw new RuntimeException('Credit memo not found');
        }

        $type = strtoupper(trim((string) ($cm['ltype'] ?? '')));
        $invoiceRefno = trim((string) ($cm['linvoice_refno'] ?? ''));
        $transactionRefno = trim((string) ($cm['ltransaction_refno'] ?? ''));
        $sourceRef = $invoiceRefno !== '' ? $invoiceRefno : $transactionRefno;

        if ($sourceRef === '') {
            return ['items' => []];
        }

        // Get source items depending on type
        if ($type === 'INVOICE' || $type === '') {
            $sql = <<<'SQL'
SELECT
    ii.lid AS source_item_id,
    COALESCE(ii.linv_refno, ii.litemid, '') AS linv_refno,
    COALESCE(ii.lname, '') AS item_code,
    COALESCE(inv_item.lpartno, '') AS part_no,
    COALESCE(inv_item.lbrand, '') AS brand,
    COALESCE(ii.ldesc, '') AS description,
    COALESCE(ii.lprice, 0) AS unit_price,
    COALESCE(ii.lqty, 0) AS original_qty,
    COALESCE(ii.lunit, '') AS unit,
    COALESCE(ii.ldiscount, 0) AS discount,
    COALESCE(
        (SELECT SUM(COALESCE(cri.lqty, 0)) FROM tblcredit_return_item cri
         WHERE cri.linv_refno = COALESCE(ii.linv_refno, ii.litemid, '')
           AND cri.lrefno IN (SELECT cm2.lrefno FROM tblcredit_memo cm2 WHERE cm2.linvoice_refno = :src_ref)),
        0
    ) AS already_returned_qty
FROM tblinvoice_itemrec ii
LEFT JOIN tblinventory_item inv_item ON inv_item.lsession = COALESCE(ii.linv_refno, ii.litemid, '')
WHERE ii.linvoice_refno = :src_ref2
ORDER BY ii.lid ASC
SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['src_ref' => $sourceRef, 'src_ref2' => $sourceRef]);
        } else {
            // OR (delivery receipt)
            $sql = <<<'SQL'
SELECT
    dri.lid AS source_item_id,
    COALESCE(dri.linv_refno, dri.litemid, '') AS linv_refno,
    COALESCE(dri.lname, '') AS item_code,
    COALESCE(inv_item.lpartno, '') AS part_no,
    COALESCE(inv_item.lbrand, '') AS brand,
    COALESCE(dri.ldesc, '') AS description,
    COALESCE(dri.lprice, 0) AS unit_price,
    COALESCE(dri.lqty, 0) AS original_qty,
    COALESCE(dri.lunit, '') AS unit,
    COALESCE(dri.ldiscount, 0) AS discount,
    COALESCE(
        (SELECT SUM(COALESCE(cri.lqty, 0)) FROM tblcredit_return_item cri
         WHERE cri.linv_refno = COALESCE(dri.linv_refno, dri.litemid, '')
           AND cri.lrefno IN (SELECT cm2.lrefno FROM tblcredit_memo cm2 WHERE cm2.linvoice_refno = :src_ref)),
        0
    ) AS already_returned_qty
FROM tbldelivery_receipt_items dri
LEFT JOIN tblinventory_item inv_item ON inv_item.lsession = COALESCE(dri.linv_refno, dri.litemid, '')
WHERE dri.lor_refno = :src_ref2
ORDER BY dri.lid ASC
SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['src_ref' => $sourceRef, 'src_ref2' => $sourceRef]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $originalQty = (int) ($row['original_qty'] ?? 0);
            $alreadyReturned = (int) ($row['already_returned_qty'] ?? 0);
            $remainingQty = $originalQty - $alreadyReturned;
            if ($remainingQty <= 0) {
                continue;
            }
            $items[] = [
                'source_item_id' => (int) ($row['source_item_id'] ?? 0),
                'linv_refno' => (string) ($row['linv_refno'] ?? ''),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'part_no' => (string) ($row['part_no'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'original_qty' => $originalQty,
                'remaining_qty' => $remainingQty,
                'unit' => (string) ($row['unit'] ?? ''),
                'discount' => (float) ($row['discount'] ?? 0),
            ];
        }

        return ['items' => $items];
    }

    // -----------------------------------------------------------------------
    // Add Item
    // -----------------------------------------------------------------------

    public function addItem(int $mainId, string $refno, array $payload): array
    {
        $cm = $this->ensurePendingStatus($mainId, $refno);
        $pdo = $this->db->pdo();

        $itemCode = trim((string) ($payload['item_code'] ?? ''));
        $partNo = trim((string) ($payload['part_no'] ?? ''));
        $brand = trim((string) ($payload['brand'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $price = (float) ($payload['unit_price'] ?? $payload['price'] ?? 0);
        $qty = (float) ($payload['qty'] ?? 0);
        $invRefno = trim((string) ($payload['linv_refno'] ?? $payload['item_session_id'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));
        $remark = trim((string) ($payload['remark'] ?? ''));
        $unit = trim((string) ($payload['unit'] ?? ''));
        $originalQty = (float) ($payload['original_qty'] ?? 0);
        $discount = (float) ($payload['discount'] ?? 0);
        $transactionItemId = trim((string) ($payload['transaction_item_id'] ?? ''));

        if ($qty <= 0) {
            throw new RuntimeException('Quantity must be greater than 0');
        }

        $insert = $pdo->prepare(<<<'SQL'
INSERT INTO tblcredit_return_item (
    lrefno, litemcode, lpartno, lbrand, ldesc, lprice, lqty,
    luser, linv_refno, lunit, llocation, loriginal_qty,
    ltransaction_item_id, ldiscount, lremark
) VALUES (
    :lrefno, :litemcode, :lpartno, :lbrand, :ldesc, :lprice, :lqty,
    :luser, :linv_refno, :lunit, :llocation, :loriginal_qty,
    :ltransaction_item_id, :ldiscount, :lremark
)
SQL);
        $insert->execute([
            'lrefno' => $refno,
            'litemcode' => $itemCode,
            'lpartno' => $partNo,
            'lbrand' => $brand,
            'ldesc' => $description,
            'lprice' => $price,
            'lqty' => $qty,
            'luser' => (string) ($payload['user_id'] ?? ''),
            'linv_refno' => $invRefno,
            'lunit' => $unit,
            'llocation' => $location,
            'loriginal_qty' => $originalQty,
            'ltransaction_item_id' => $transactionItemId,
            'ldiscount' => $discount,
            'lremark' => $remark,
        ]);

        $newId = (int) $pdo->lastInsertId();
        return [
            'id' => $newId,
            'item_code' => $itemCode,
            'part_no' => $partNo,
            'brand' => $brand,
            'description' => $description,
            'qty' => $qty,
            'unit_price' => $price,
            'amount' => $qty * $price,
            'remark' => $remark,
        ];
    }

    // -----------------------------------------------------------------------
    // Delete Item
    // -----------------------------------------------------------------------

    public function deleteItem(int $mainId, int $itemId): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(<<<'SQL'
SELECT cri.lid, cm.lstatus, cm.lrefno
FROM tblcredit_return_item cri
INNER JOIN tblcredit_memo cm ON cm.lrefno = cri.lrefno
WHERE cri.lid = :item_id AND CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id
LIMIT 1
SQL);
        $stmt->bindValue('item_id', $itemId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException('Item not found');
        }
        if (strtolower(trim((string) ($row['lstatus'] ?? ''))) === 'posted') {
            throw new RuntimeException('Cannot delete item from a posted credit memo');
        }

        $del = $pdo->prepare('DELETE FROM tblcredit_return_item WHERE lid = :item_id');
        $del->execute(['item_id' => $itemId]);
        return true;
    }

    // -----------------------------------------------------------------------
    // Post — sets Posted, creates ledger credit entry, restores inventory
    // -----------------------------------------------------------------------

    public function post(int $mainId, int $userId, string $refno): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // 1. Load CM header
            $cmStmt = $pdo->prepare(
                'SELECT * FROM tblcredit_memo WHERE lrefno = :refno AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id LIMIT 1'
            );
            $cmStmt->bindValue('refno', $refno, PDO::PARAM_STR);
            $cmStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
            $cmStmt->execute();
            $cm = $cmStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cm) {
                throw new RuntimeException('Credit memo not found');
            }
            if (strtolower(trim((string) ($cm['lstatus'] ?? ''))) === 'posted') {
                throw new RuntimeException('Credit memo is already posted');
            }

            // 2. Load items
            $itemStmt = $pdo->prepare('SELECT * FROM tblcredit_return_item WHERE lrefno = :refno');
            $itemStmt->execute(['refno' => $refno]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($items) === 0) {
                throw new RuntimeException('Cannot post a credit memo with no items');
            }

            // 3. Calculate grand total
            $grandTotal = 0.0;
            $totalReturnQty = 0.0;
            foreach ($items as $item) {
                $qty = (float) ($item['lqty'] ?? 0);
                $price = (float) ($item['lprice'] ?? 0);
                $grandTotal += $qty * $price;
                $totalReturnQty += $qty;
            }

            // 4. Apply discount proration (matching old system)
            $discountAmt = (float) ($cm['ldiscount_amt'] ?? 0);
            $grandPrice = $grandTotal - $discountAmt;
            if ($grandPrice < 0) {
                $grandPrice = 0;
            }

            // 5. Apply tax (matching old system: Exclusive means add 12% VAT)
            $taxType = strtolower(trim((string) ($cm['ltax_type'] ?? '')));
            if ($taxType === 'exclusive') {
                $grandPrice = $grandPrice * 1.12;
            }

            // 6. Update status to Posted
            $updateStmt = $pdo->prepare("UPDATE tblcredit_memo SET lstatus = 'Posted' WHERE lrefno = :refno");
            $updateStmt->execute(['refno' => $refno]);

            // 7. Insert ledger CREDIT entry (customer owes less)
            $creditNo = (string) ($cm['lcredit_no'] ?? '');
            $customerId = (string) ($cm['lcustomer'] ?? '');
            $cmDate = (string) ($cm['ldate'] ?? date('Y-m-d'));
            $remarkMsg = 'Sales Return:' . (string) ($cm['lremark'] ?? '');

            $ledgerInsert = $pdo->prepare(<<<'SQL'
INSERT INTO tblledger (
    lcustomerid, lrefno, lamt, lmesssage, ldatetime, lmainid, ltype,
    lcredit, ldebit, luserid, lcheckdate, lcheck_no, ldcr, lpdc,
    lremarks, lref_name
) VALUES (
    :lcustomerid, :lrefno, :lamt, :lmesssage, :ldatetime, :lmainid, 'Credit',
    :lcredit, 0, :luserid, '', '', '', 0,
    :lremarks, 'Credit Memo'
)
SQL);
            $ledgerInsert->execute([
                'lcustomerid' => $customerId,
                'lrefno' => $refno,
                'lamt' => $grandPrice,
                'lmesssage' => $creditNo,
                'ldatetime' => $cmDate,
                'lmainid' => (string) $mainId,
                'lcredit' => $grandPrice,
                'luserid' => (string) $userId,
                'lremarks' => $remarkMsg,
            ]);

            // 8. Insert inventory logs for each item (stock back in)
            $customerName = (string) ($cm['clname'] ?? '');
            $note = 'SALES RETURN - ' . $customerName;
            $source = 'RS ' . $creditNo;

            $invLogInsert = $pdo->prepare(<<<'SQL'
INSERT INTO tblinventory_logs (
    linvent_id, lin, lout, ltotal, ldateadded, lprocess_by,
    lstatus_logs, lnote, lprice, lrefno, llocation, ltransaction_type
) VALUES (
    :linvent_id, :lin, 0, :ltotal, :ldateadded, :lprocess_by,
    '+', :lnote, :lprice, :lrefno, :llocation, 'Credit Memo'
)
SQL);
            // Check existence to avoid duplicate logs
            $existCheck = $pdo->prepare(
                'SELECT lid FROM tblinventory_logs WHERE lrefno = :refno AND linvent_id = :inv_id LIMIT 1'
            );

            foreach ($items as $item) {
                $invRefno = trim((string) ($item['linv_refno'] ?? ''));
                if ($invRefno === '') {
                    continue;
                }

                $existCheck->execute(['refno' => $refno, 'inv_id' => $invRefno]);
                if ($existCheck->fetch() !== false) {
                    continue; // already logged
                }

                $invLogInsert->execute([
                    'linvent_id' => $invRefno,
                    'lin' => (int) ($item['lqty'] ?? 0),
                    'ltotal' => (int) ($item['lqty'] ?? 0),
                    'ldateadded' => $cmDate,
                    'lprocess_by' => $source,
                    'lnote' => $note,
                    'lprice' => (float) ($item['lprice'] ?? 0),
                    'lrefno' => $refno,
                    'llocation' => trim((string) ($item['llocation'] ?? '')),
                ]);
            }

            $pdo->commit();
            return $this->show($mainId, $refno) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // Unpost — revert to Pending, delete ledger + inventory log entries
    // -----------------------------------------------------------------------

    public function unpost(int $mainId, int $userId, string $refno): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $cmStmt = $pdo->prepare(
                'SELECT lstatus FROM tblcredit_memo WHERE lrefno = :refno AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id LIMIT 1'
            );
            $cmStmt->bindValue('refno', $refno, PDO::PARAM_STR);
            $cmStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
            $cmStmt->execute();
            $cm = $cmStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cm) {
                throw new RuntimeException('Credit memo not found');
            }
            if (strtolower(trim((string) ($cm['lstatus'] ?? ''))) !== 'posted') {
                throw new RuntimeException('Credit memo is not posted');
            }

            // Revert status
            $pdo->prepare("UPDATE tblcredit_memo SET lstatus = 'Pending' WHERE lrefno = :refno")
                ->execute(['refno' => $refno]);

            // Delete ledger entries
            $pdo->prepare('DELETE FROM tblledger WHERE lrefno = :refno')
                ->execute(['refno' => $refno]);

            // Delete inventory logs
            $pdo->prepare('DELETE FROM tblinventory_logs WHERE lrefno = :refno')
                ->execute(['refno' => $refno]);

            $pdo->commit();
            return $this->show($mainId, $refno) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function list(
        int $mainId,
        string $search = '',
        string $status = '',
        string $month = '',
        string $year = '',
        int $page = 1,
        int $perPage = 50
    ): array {
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id'];
        $params = ['main_id' => $mainId];

        $month = trim($month) !== '' ? trim($month) : date('m');
        $year = trim($year) !== '' ? trim($year) : date('Y');
        if (preg_match('/^\d{2}$/', $month) === 1 && preg_match('/^\d{4}$/', $year) === 1) {
            $params['month_filter'] = sprintf('%s-%s', $year, $month);
            $where[] = 'DATE_FORMAT(COALESCE(cm.ldate, cm.ldaterec, cm.ldatetime), "%Y-%m") = :month_filter';
        }

        $search = trim($search);
        if ($search !== '') {
            $searchLike = '%' . $search . '%';
            $params['search_credit'] = $searchLike;
            $params['search_invoice'] = $searchLike;
            $params['search_customer'] = $searchLike;
            $params['search_salesman'] = $searchLike;
            $params['search_remark'] = $searchLike;
            $where[] = '(
                COALESCE(cm.lcredit_no, "") LIKE :search_credit OR
                COALESCE(cm.linvoice_no, "") LIKE :search_invoice OR
                COALESCE(cm.clname, "") LIKE :search_customer OR
                COALESCE(cm.lsalesman, "") LIKE :search_salesman OR
                COALESCE(cm.lremark, "") LIKE :search_remark
            )';
        }

        $status = trim($status);
        if ($status !== '' && strtolower($status) !== 'all') {
            $params['status'] = $status;
            $where[] = 'COALESCE(cm.lstatus, "Pending") = :status';
        }

        $whereSql = implode(' AND ', $where);

        $countSql = <<<SQL
SELECT COUNT(*)
FROM tblcredit_memo cm
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
    MAX(cm.lid) AS sort_id,
    COALESCE(cm.lrefno, '') AS lrefno,
    COALESCE(cm.lcredit_no, '') AS lcredit_no,
    COALESCE(cm.linvoice_no, '') AS linvoice_no,
    COALESCE(cm.linvoice_refno, '') AS linvoice_refno,
    COALESCE(cm.ldate, '') AS ldate,
    COALESCE(cm.lstatus, 'Pending') AS lstatus,
    COALESCE(cm.ltype, '') AS ltype,
    COALESCE(cm.lcustomer, '') AS lcustomer,
    TRIM(
        COALESCE(NULLIF(cm.clname, ''), NULLIF(pt.lcompany, ''), 'Unknown Customer')
    ) AS customer_name,
    COALESCE(cm.lsalesman, '') AS sales_person,
    COALESCE(cm.ltrackno, '') AS tracking_no,
    COALESCE(cm.lshipvia, '') AS ship_via,
    COALESCE(cm.lremark, '') AS lremark,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0)), 0) AS DECIMAL(15,2)) AS total_qty,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0)), 0) AS DECIMAL(15,2)) AS total_amount
FROM tblcredit_memo cm
LEFT JOIN tblpatient pt ON CAST(pt.lsessionid AS CHAR) = CAST(cm.lcustomer AS CHAR)
LEFT JOIN tblcredit_return_item itm ON itm.lrefno = cm.lrefno
WHERE {$whereSql}
GROUP BY
    cm.lrefno, cm.lcredit_no, cm.linvoice_no, cm.linvoice_refno, cm.ldate, cm.lstatus,
    cm.ltype, cm.lcustomer, cm.clname, pt.lcompany, cm.lsalesman, cm.ltrackno, cm.lshipvia, cm.lremark
ORDER BY sort_id DESC
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

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / max(1, $perPage))),
                'filters' => [
                    'search' => $search,
                    'status' => $status,
                    'month' => $month,
                    'year' => $year,
                ],
            ],
        ];
    }

    public function show(int $mainId, string $refno): ?array
    {
        $sql = <<<SQL
SELECT
    COALESCE(cm.lrefno, '') AS lrefno,
    COALESCE(cm.lcredit_no, '') AS lcredit_no,
    COALESCE(cm.linvoice_no, '') AS linvoice_no,
    COALESCE(cm.linvoice_refno, '') AS linvoice_refno,
    COALESCE(cm.ldate, '') AS ldate,
    COALESCE(cm.lstatus, 'Pending') AS lstatus,
    COALESCE(cm.ltype, '') AS ltype,
    COALESCE(cm.lcustomer, '') AS lcustomer,
    TRIM(
        COALESCE(NULLIF(cm.clname, ''), NULLIF(pt.lcompany, ''), 'Unknown Customer')
    ) AS customer_name,
    COALESCE(cm.lsalesman, '') AS sales_person,
    COALESCE(cm.ltrackno, '') AS tracking_no,
    COALESCE(cm.lshipvia, '') AS ship_via,
    COALESCE(cm.lremark, '') AS lremark,
    COALESCE(cm.lmy_remarks, '') AS internal_remarks,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0)), 0) AS DECIMAL(15,2)) AS total_qty,
    CAST(COALESCE(SUM(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0)), 0) AS DECIMAL(15,2)) AS total_amount
FROM tblcredit_memo cm
LEFT JOIN tblpatient pt ON CAST(pt.lsessionid AS CHAR) = CAST(cm.lcustomer AS CHAR)
LEFT JOIN tblcredit_return_item itm ON itm.lrefno = cm.lrefno
WHERE CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id
  AND cm.lrefno = :refno
GROUP BY
    cm.lrefno, cm.lcredit_no, cm.linvoice_no, cm.linvoice_refno, cm.ldate, cm.lstatus, cm.ltype,
    cm.lcustomer, cm.clname, pt.lcompany, cm.lsalesman, cm.ltrackno, cm.lshipvia, cm.lremark, cm.lmy_remarks
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    public function items(int $mainId, string $refno): array
    {
        $sql = <<<SQL
SELECT
    COALESCE(itm.lid, 0) AS id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    COALESCE(itm.lbrand, '') AS brand,
    COALESCE(itm.llocation, '') AS location,
    COALESCE(itm.ldesc, '') AS description,
    CAST(COALESCE(itm.lqty, 0) AS DECIMAL(15,2)) AS qty,
    CAST(COALESCE(itm.lprice, 0) AS DECIMAL(15,2)) AS unit_price,
    CAST(COALESCE(itm.lqty, 0) * COALESCE(itm.lprice, 0) AS DECIMAL(15,2)) AS amount,
    COALESCE(itm.lremark, '') AS remark
FROM tblcredit_return_item itm
INNER JOIN tblcredit_memo cm ON cm.lrefno = itm.lrefno
WHERE CAST(COALESCE(cm.lmainid, 0) AS SIGNED) = :main_id
  AND itm.lrefno = :refno
ORDER BY itm.lid DESC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('refno', $refno, PDO::PARAM_STR);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
