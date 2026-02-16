<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CustomerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findCustomerBySession(string $sessionId): ?array
    {
        $sql = <<<SQL
SELECT
    p.lsessionid,
    p.lpatient_code,
    p.lcompany,
    p.lfname,
    p.llname,
    p.lphone,
    p.lmobile,
    p.lemail,
    p.laddress,
    p.ldelivery_address,
    p.lcity,
    p.lprovince,
    p.lterms,
    p.lprice_group,
    p.lsales_person,
    p.lvat_type,
    p.lvat_percent,
    p.lstatus,
    (
        SELECT pt.lname
        FROM tblpatient_terms pt
        WHERE pt.lpatient = p.lsessionid
        ORDER BY pt.lid DESC
        LIMIT 1
    ) AS latest_terms,
    (
        SELECT l.lbalance
        FROM tblledger l
        WHERE l.lcustomerid = p.lsessionid
        ORDER BY l.lid DESC
        LIMIT 1
    ) AS latest_balance
FROM tblpatient p
WHERE p.lsessionid = :session_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['session_id' => $sessionId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            return null;
        }

        $customer['contact_persons'] = $this->getContactPersons($sessionId);
        return $customer;
    }

    public function getPurchaseHistory(string $sessionId, ?string $dateFrom, ?string $dateTo): array
    {
        $filters = '';
        $params = ['customer_id' => $sessionId];

        if ($dateFrom !== null && $dateFrom !== '') {
            $filters .= ' AND src.ldate >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $filters .= ' AND src.ldate <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = <<<SQL
SELECT
    src.source_type,
    src.source_refno,
    src.source_no,
    src.ldate,
    src.litemcode,
    src.lpartno,
    src.ldesc,
    src.lbrand,
    src.lqty,
    src.lprice,
    COALESCE(ret.return_qty, 0) AS return_qty
FROM (
    SELECT
        'INVOICE' AS source_type,
        inv.lrefno AS source_refno,
        inv.linvoice_no AS source_no,
        inv.ldate,
        item.litemcode,
        item.lpartno,
        item.ldesc,
        item.lbrand,
        item.lqty,
        item.lprice
    FROM tblinvoice_list inv
    INNER JOIN tblinvoice_itemrec item ON item.linvoice_refno = inv.lrefno
    WHERE inv.lcustomerid = :customer_id
      AND COALESCE(inv.lcancel_invoice, 0) = 0

    UNION ALL

    SELECT
        'ORDER_SLIP' AS source_type,
        dr.lrefno AS source_refno,
        dr.linvoice_no AS source_no,
        dr.ldate,
        dri.litemcode,
        dri.lpartno,
        dri.ldesc,
        dri.lbrand,
        dri.lqty,
        dri.lprice
    FROM tbldelivery_receipt dr
    INNER JOIN tbldelivery_receipt_items dri ON dri.lor_refno = dr.lrefno
    WHERE dr.lcustomerid = :customer_id
      AND COALESCE(dr.lcancel, 0) = 0
) src
LEFT JOIN (
    SELECT
        litemcode,
        lpartno,
        SUM(lqty) AS return_qty
    FROM tblcredit_return_item
    GROUP BY litemcode, lpartno
) ret ON ret.litemcode = src.litemcode AND ret.lpartno = src.lpartno
WHERE 1=1
{$filters}
ORDER BY src.ldate DESC, src.source_type ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getContactPersons(string $sessionId): array
    {
        $sql = <<<SQL
SELECT
    lfname,
    lmname,
    llname,
    lc_phone,
    lc_mobile,
    lemail,
    lposition
FROM tblcontact_person
WHERE lrefno = :session_id
ORDER BY lid DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['session_id' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

