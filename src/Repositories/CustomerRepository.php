<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\CustomerLedgerCalculator;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class CustomerRepository
{
    public const PLATINUM_MIN_MONTHS = 3;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Maps legacy database pricing groups to API-facing tiers.
     */
    public function getNormalizedPriceGroup(string $dbValue): string
    {
        return match (strtolower(trim($dbValue))) {
            'aaa' => 'regular',
            'vip1' => 'silver',
            'vip2' => 'gold',
            default => 'unknown',
        };
    }

    /**
     * Platinum applies only to gold customers retained for at least 3 months.
     */
    public function resolvePlatinumEligibility(string $priceGroup, string $customerSince): bool
    {
        $internal = $this->getNormalizedPriceGroup($priceGroup);
        if ($internal !== 'gold' || trim($customerSince) === '') {
            return false;
        }

        try {
            $since = new DateTimeImmutable($customerSince);
            $now = new DateTimeImmutable();
        } catch (\Exception) {
            return false;
        }

        $diff = $since->diff($now);
        $months = ($diff->y * 12) + $diff->m;

        return !$diff->invert && $months >= self::PLATINUM_MIN_MONTHS;
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
    p.lcredit,
    p.lprice_group,
    p.lprice_group AS price_group,
    p.lsince,
    p.ldealer_since,
    p.ldealer_quota,
    COALESCE(p.ldealer_since, p.ldatereg, '') AS customer_since,
    p.lsales_person,
    p.lvat_type,
    p.lvat_percent,
    p.lstatus,
    COALESCE((
        SELECT old_customer.loldname
        FROM tlbCustomer_Details old_customer
        WHERE old_customer.lsessionid = p.lsessionid
          AND COALESCE(TRIM(old_customer.loldname), '') <> ''
        ORDER BY old_customer.ldate DESC, old_customer.lid DESC
        LIMIT 1
    ), '') AS old_name,
    TRIM(CONCAT(COALESCE(agent.lfname, ''), ' ', COALESCE(agent.llname, ''))) AS agent_name,
    (
        SELECT pt.lname
        FROM tblpatient_terms pt
        WHERE pt.lpatient = p.lsessionid
        ORDER BY pt.lid DESC
        LIMIT 1
    ) AS latest_terms,
    COALESCE(
        (SELECT SUM(COALESCE(l.ldebit, 0)) - SUM(COALESCE(l.lcredit, 0))
         FROM tblledger l
         WHERE l.lcustomerid = p.lsessionid),
        0
    ) AS latest_balance
FROM tblpatient p
LEFT JOIN tblaccount agent ON agent.lid = p.lsales_person
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
        $params = [
            'customer_id_invoice' => $sessionId,
            'customer_id_or' => $sessionId,
        ];

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
    src.source_status,
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
        COALESCE(inv.lstatus, '') AS source_status,
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
    WHERE inv.lcustomerid = :customer_id_invoice
      AND COALESCE(inv.lcancel_invoice, 0) = 0

    UNION ALL

    SELECT
        'ORDER_SLIP' AS source_type,
        COALESCE(dr.lstatus, '') AS source_status,
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
    WHERE dr.lcustomerid = :customer_id_or
      AND COALESCE(dr.lcancel, 0) = 0
) src
LEFT JOIN (
    SELECT
        cm.linvoice_refno AS source_refno,
        cri.litemcode,
        cri.lpartno,
        SUM(COALESCE(cri.lqty, 0)) AS return_qty
    FROM tblcredit_return_item cri
    INNER JOIN tblcredit_memo cm ON cm.lrefno = cri.lrefno
    WHERE COALESCE(cm.lstatus, '') IN ('Posted', 'Approved')
    GROUP BY cm.linvoice_refno, cri.litemcode, cri.lpartno
) ret ON ret.source_refno = src.source_refno
     AND ret.litemcode = src.litemcode
     AND ret.lpartno = src.lpartno
WHERE 1=1
{$filters}
ORDER BY src.ldate DESC, src.source_type ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getInquiryHistory(string $sessionId, ?string $dateFrom, ?string $dateTo): array
    {
        $filters = '';
        $params = ['customer_id' => $sessionId];

        if ($dateFrom !== null && $dateFrom !== '') {
            $filters .= ' AND iq.ldate >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $filters .= ' AND iq.ldate <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $sql = <<<SQL
SELECT
    iq.lrefno,
    iq.linqno,
    iq.ldate,
    iq.lsubmitstat,
    iq.ltransaction_status,
    iq.IsCancel
FROM tblinquiry iq
WHERE iq.lcustomerid = :customer_id
{$filters}
ORDER BY iq.ldate DESC, iq.lid DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCustomerLedger(
        string $sessionId,
        string $reportType,
        string $dateType,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        $customer = $this->findCustomerBySession($sessionId);
        if ($customer === null) {
            throw new RuntimeException('Customer not found');
        }

        [$normalizedType, $fromDate, $toDate] = CustomerLedgerCalculator::resolveDateRange(
            $dateType,
            $dateFrom,
            $dateTo
        );
        $normalizedReportType = strtolower(trim($reportType)) === 'summary' ? 'summary' : 'detailed';

        $params = ['customer_id' => $sessionId];
        $dateSql = '';
        if ($fromDate !== null && $toDate !== null) {
            $dateSql = ' AND DATE(l.ldatetime) >= :date_from AND DATE(l.ldatetime) <= :date_to';
            $params['date_from'] = $fromDate;
            $params['date_to'] = $toDate;
        }

        $baseSql = <<<SQL
SELECT
    l.lid,
    l.lcustomerid,
    l.lrefno,
    l.lmesssage,
    l.lamt,
    l.ldatetime,
    l.lmainid,
    l.ltype,
    l.lcredit,
    l.ldebit,
    l.luserid,
    l.lcheckdate,
    l.lcheck_no,
    l.ldcr,
    l.lpdc,
    l.lbalance,
    l.lremarks,
    l.lref_name,
    l.promisetopay
FROM tblledger l
WHERE l.lcustomerid = :customer_id
{$dateSql}
SQL;

        $rows = [];
        $summaryRows = [];
        $totals = [
            'debit' => 0.0,
            'credit' => 0.0,
            'pdc' => 0.0,
            'balance' => 0.0,
            'row_count' => 0,
        ];

        // Compute opening balance when a date filter is active.
        $openingBalance = 0.0;
        if ($fromDate !== null) {
            $openingBalance = $this->computeBalanceBefore($sessionId, $fromDate);
        }

        if ($normalizedReportType === 'summary') {
            $summarySql = <<<SQL
SELECT
    YEAR(l.ldatetime) AS year,
    MONTH(l.ldatetime) AS month,
    SUM(COALESCE(l.ldebit, 0)) AS debit,
    SUM(COALESCE(l.lcredit, 0)) AS credit
FROM tblledger l
WHERE l.lcustomerid = :customer_id
{$dateSql}
GROUP BY YEAR(l.ldatetime), MONTH(l.ldatetime)
ORDER BY YEAR(l.ldatetime) ASC, MONTH(l.ldatetime) ASC
SQL;
            $stmt = $this->db->pdo()->prepare($summarySql);
            $stmt->execute($params);
            $rawSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $summaryReport = CustomerLedgerCalculator::buildSummaryReport($rawSummary, $openingBalance);
            $summaryRows = $summaryReport['rows'];
            $totals = $summaryReport['totals'];
        } else {
            $stmt = $this->db->pdo()->prepare($baseSql . ' ORDER BY l.ldatetime ASC, l.ltype DESC, l.lid ASC');
            $stmt->execute($params);
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $detailedReport = CustomerLedgerCalculator::buildDetailedReport(
                $rawRows,
                date('Y-m-d'),
                $openingBalance
            );
            $rows = $detailedReport['rows'];
            $totals = $detailedReport['totals'];
        }

        $metrics = $this->buildLedgerMetrics($sessionId, $customer);

        return [
            'customer' => [
                'session_id' => (string) ($customer['lsessionid'] ?? ''),
                'company' => (string) ($customer['lcompany'] ?? ''),
                'customer_code' => (string) ($customer['lpatient_code'] ?? ''),
            ],
            'report_type' => $normalizedReportType,
            'date_type' => $normalizedType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'metrics' => $metrics,
            'rows' => $rows,
            'summary_rows' => $summaryRows,
            'totals' => $totals,
        ];
    }

    /**
     * Compute the net ledger balance for a customer before a given date,
     * using the same PDC / future-check logic as mapDetailedLedgerRow.
     */
    private function computeBalanceBefore(string $sessionId, string $beforeDate): float
    {
        $sql = <<<'SQL'
SELECT
    l.ltype,
    l.lcredit,
    l.ldebit,
    l.lpdc,
    l.lcheckdate
FROM tblledger l
WHERE l.lcustomerid = :customer_id
  AND DATE(l.ldatetime) < :before_date
ORDER BY l.ldatetime ASC, l.ltype DESC, l.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'customer_id' => $sessionId,
            'before_date' => $beforeDate,
        ]);
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $report = CustomerLedgerCalculator::buildDetailedReport($rawRows, date('Y-m-d'));
        return (float) $report['totals']['balance'];
    }

    private function buildLedgerMetrics(string $sessionId, array $customer): array
    {
        $salesTotals = $this->loadCustomerSalesTotals($sessionId);
        $monthlySales = (float) ($salesTotals['monthly_sales'] ?? 0);
        $dealershipSales = (float) ($salesTotals['dealership_sales'] ?? 0);

        $ledgerRows = $this->loadLedgerRows($sessionId);
        $ledgerReport = CustomerLedgerCalculator::buildDetailedReport($ledgerRows, date('Y-m-d'));
        $balance = (float) $ledgerReport['totals']['balance'];

        $termsStmt = $this->db->pdo()->prepare(
            'SELECT lname
             FROM tblpatient_terms
             WHERE lpatient = :customer_id
             ORDER BY lid DESC
             LIMIT 1'
        );
        $termsStmt->execute(['customer_id' => $sessionId]);
        $terms = (string) ($termsStmt->fetchColumn() ?: ($customer['lterms'] ?? ''));

        // VIP status & price code
        $rawPriceGroup = (string) ($customer['lprice_group'] ?? '');
        $normalizedPriceGroup = $this->getNormalizedPriceGroup($rawPriceGroup);
        $customerSince = CustomerLedgerCalculator::normalizeDate((string) ($customer['lsince'] ?? ''));
        $platinum = $this->resolvePlatinumEligibility($rawPriceGroup, (string) ($customer['lsince'] ?? ''));
        $vipStatus = $platinum ? 'platinum' : $normalizedPriceGroup;

        $oldNameStmt = $this->db->pdo()->prepare(
            'SELECT loldname
             FROM tlbCustomer_Details
             WHERE lsessionid = :customer_id
               AND COALESCE(TRIM(loldname), \'\') <> \'\'
             ORDER BY ldate DESC, lid DESC
             LIMIT 1'
        );
        $oldNameStmt->execute(['customer_id' => $sessionId]);
        $oldName = trim((string) ($oldNameStmt->fetchColumn() ?: ''));

        $aging = CustomerLedgerCalculator::buildAgingBuckets($ledgerRows, date('Y-m-d'));

        return [
            'dealership_since' => CustomerLedgerCalculator::normalizeDate((string) ($customer['ldealer_since'] ?? '')),
            'dealership_sales' => $dealershipSales,
            'dealership_quota' => (float) ($customer['ldealer_quota'] ?? 0),
            'monthly_sales' => $monthlySales,
            'customer_since' => $customerSince,
            'credit_limit' => (float) ($customer['lcredit'] ?? 0),
            'terms' => $terms,
            'balance' => $balance,
            'old_name' => $oldName !== '' ? $oldName : null,
            'price_code' => $rawPriceGroup !== '' ? $rawPriceGroup : null,
            'vip_status' => $vipStatus !== 'unknown' ? $vipStatus : null,
            'aging' => $aging,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function loadLedgerRows(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lrefno, lmesssage, ldatetime, ltype, lcredit, ldebit,
                    lcheckdate, lcheck_no, ldcr, lpdc, lremarks, lref_name, promisetopay
             FROM tblledger
             WHERE lcustomerid = :customer_id
             ORDER BY ldatetime ASC, ltype DESC, lid ASC'
        );
        $stmt->execute(['customer_id' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{dealership_sales:float,monthly_sales:float}
     */
    private function loadCustomerSalesTotals(string $sessionId): array
    {
        $sql = <<<'SQL'
SELECT
    COALESCE(SUM(doc.amount), 0) AS dealership_sales,
    COALESCE(SUM(
        CASE
            WHEN DATE_FORMAT(doc.doc_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            THEN doc.amount
            ELSE 0
        END
    ), 0) AS monthly_sales
FROM (
    SELECT
        inv.lrefno AS document_refno,
        COALESCE(NULLIF(inv.lsales_refno, ''), CONCAT('INV:', inv.lrefno)) AS sales_refno,
        COALESCE(inv.ldate, DATE(inv.ldatetime), CURDATE()) AS doc_date,
        SUM(COALESCE(ii.lqty, 0) * COALESCE(ii.lprice, 0)) AS amount,
        2 AS priority
    FROM tblinvoice_list inv
    INNER JOIN tblinvoice_itemrec ii ON ii.linvoice_refno = inv.lrefno
    WHERE inv.lcustomerid = :customer_id_invoice
      AND COALESCE(inv.lcancel_invoice, 0) = 0
      AND LOWER(COALESCE(inv.lstatus, '')) <> 'cancelled'
    GROUP BY inv.lrefno, sales_refno, doc_date

    UNION ALL

    SELECT
        dr.lrefno AS document_refno,
        COALESCE(NULLIF(dr.lsales_refno, ''), CONCAT('DR:', dr.lrefno)) AS sales_refno,
        COALESCE(dr.ldate, DATE(dr.ldatetime), CURDATE()) AS doc_date,
        SUM(COALESCE(dri.lqty, 0) * COALESCE(dri.lprice, 0)) AS amount,
        1 AS priority
    FROM tbldelivery_receipt dr
    INNER JOIN tbldelivery_receipt_items dri ON dri.lor_refno = dr.lrefno
    WHERE dr.lcustomerid = :customer_id_order_slip
      AND COALESCE(dr.lcancel, 0) = 0
      AND LOWER(COALESCE(dr.lstatus, '')) <> 'cancelled'
    GROUP BY dr.lrefno, sales_refno, doc_date
) doc
WHERE NOT EXISTS (
    SELECT 1
    FROM (
        SELECT
            inv2.lrefno AS document_refno,
            COALESCE(NULLIF(inv2.lsales_refno, ''), CONCAT('INV:', inv2.lrefno)) AS sales_refno,
            2 AS priority
        FROM tblinvoice_list inv2
        WHERE inv2.lcustomerid = :customer_id_invoice_shadow
          AND COALESCE(inv2.lcancel_invoice, 0) = 0
          AND LOWER(COALESCE(inv2.lstatus, '')) <> 'cancelled'

        UNION ALL

        SELECT
            dr2.lrefno AS document_refno,
            COALESCE(NULLIF(dr2.lsales_refno, ''), CONCAT('DR:', dr2.lrefno)) AS sales_refno,
            1 AS priority
        FROM tbldelivery_receipt dr2
        WHERE dr2.lcustomerid = :customer_id_order_slip_shadow
          AND COALESCE(dr2.lcancel, 0) = 0
          AND LOWER(COALESCE(dr2.lstatus, '')) <> 'cancelled'
    ) ranked
    WHERE ranked.sales_refno = doc.sales_refno
      AND (
          ranked.priority > doc.priority
          OR (ranked.priority = doc.priority AND ranked.document_refno > doc.document_refno)
      )
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'customer_id_invoice' => $sessionId,
            'customer_id_order_slip' => $sessionId,
            'customer_id_invoice_shadow' => $sessionId,
            'customer_id_order_slip_shadow' => $sessionId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'dealership_sales' => (float) ($row['dealership_sales'] ?? 0),
            'monthly_sales' => (float) ($row['monthly_sales'] ?? 0),
        ];
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
