<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\CustomerLedgerCalculator;
use App\Support\PostedSalesDocumentSql;
use App\Support\PurchasedItemMatcher;
use App\Support\VipStanding;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class CustomerRepository
{
    public const PLATINUM_MIN_MONTHS = 3;
    private ?bool $hasCustomerDiscountCodeColumn = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Maps database pricing groups to vip1 / vip2 / vip3.
     */
    public function getNormalizedPriceGroup(string $dbValue): string
    {
        $canonical = preg_replace('/[\s_-]+/', '', strtolower(trim($dbValue))) ?? '';

        return match ($canonical) {
            'vip1', 'silver' => 'vip1',
            'vip2', 'gold' => 'vip2',
            'vip3', 'platinum', 'aaa', 'aa', 'regular' => 'vip3',
            default => 'vip3',
        };
    }

    public function normalizeDiscountCode(string $discountCode, string $fallbackPriceGroup = '', string $customerSince = ''): string
    {
        $canonical = preg_replace('/[\s_-]+/', ' ', strtolower(trim($discountCode))) ?? '';
        $canonical = preg_replace('/\s+/', ' ', $canonical) ?? '';

        if (in_array($canonical, ['regular', 'vip silver', 'vip gold', 'vip platinum'], true)) {
            return $canonical;
        }

        if ($this->resolvePlatinumEligibility($fallbackPriceGroup, $customerSince)) {
            return 'vip platinum';
        }

        return match ($this->getNormalizedPriceGroup($fallbackPriceGroup)) {
            'vip1' => 'vip silver',
            'vip2' => 'vip gold',
            default => 'regular',
        };
    }

    /**
     * Platinum applies only to vip2 customers retained for at least 3 months.
     */
    public function resolvePlatinumEligibility(string $priceGroup, string $customerSince): bool
    {
        $internal = $this->getNormalizedPriceGroup($priceGroup);
        $normalizedSince = CustomerLedgerCalculator::normalizeDate($customerSince);
        if ($internal !== 'vip2' || $normalizedSince === null) {
            return false;
        }

        try {
            $since = new DateTimeImmutable($normalizedSince);
            $now = new DateTimeImmutable();
        } catch (\Exception) {
            return false;
        }

        $diff = $since->diff($now);
        $months = ($diff->y * 12) + $diff->m;

        return !$diff->invert && $months >= self::PLATINUM_MIN_MONTHS;
    }

    public function findCustomerBySession(string $sessionId, ?int $mainId = null): ?array
    {
        $discountCodeSelect = $this->hasCustomerDiscountCodeColumn()
            ? 'p.ldiscount_code AS discount_code,'
            : "'' AS discount_code,";

        $mainFilter = $mainId === null ? '' : ' AND p.lmain_id = :main_id';
        $sql = <<<SQL
SELECT
    p.lsessionid,
    p.lmain_id,
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
    {$discountCodeSelect}
    p.lsince,
    p.ldealer_since,
    p.ldealer_quota,
    NULLIF(NULLIF(NULLIF(CAST(p.lsince AS CHAR), '1970-01-01'), '0000-00-00'), '') AS customer_since,
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
LEFT JOIN tblaccount agent ON agent.lid = p.lsales_person AND COALESCE(agent.lstatus, 0) = 1
WHERE p.lsessionid = :session_id
{$mainFilter}
  AND COALESCE(p.ldeleted, 0) = 0
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $params = ['session_id' => $sessionId];
        if ($mainId !== null) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            return null;
        }

        $customer['contact_persons'] = $this->getContactPersons($sessionId);
        return $customer;
    }

    /**
     * Resolve a Customer Ledger identity from posted sales documents when the
     * customer master row is missing. This is read-only and tenant-scoped.
     *
     * @return array<string,mixed>|null
     */
    private function findLedgerCustomer(string $sessionId, int $mainId): ?array
    {
        $customer = $this->findCustomerBySession($sessionId, $mainId);
        if ($customer !== null || $mainId <= 0) {
            return $customer;
        }

        $invoicePosted = PostedSalesDocumentSql::invoiceIsPosted('inv');
        $receiptPosted = PostedSalesDocumentSql::deliveryReceiptIsPosted('dr');
        $statement = $this->db->pdo()->prepare(<<<SQL
SELECT :session_id AS lsessionid,
       :main_id AS lmain_id,
       '' AS lpatient_code,
       COALESCE(MAX(NULLIF(TRIM(documents.customer_name), '')), :fallback_name) AS lcompany,
       '' AS lfname,
       '' AS llname,
       '' AS lphone,
       '' AS lmobile,
       '' AS lemail,
       '' AS laddress,
       '' AS ldelivery_address,
       '' AS lcity,
       '' AS lprovince,
       '' AS lterms,
       0 AS lcredit,
       '' AS lprice_group,
       '' AS price_group,
       '' AS discount_code,
       '' AS lsince,
       '' AS ldealer_since,
       0 AS ldealer_quota,
       '' AS customer_since,
       0 AS lsales_person,
       '' AS lvat_type,
       0 AS lvat_percent,
       1 AS lstatus,
       '' AS old_name,
       '' AS agent_name,
       '' AS latest_terms,
       COALESCE((SELECT SUM(COALESCE(ledger.ldebit, 0)) - SUM(COALESCE(ledger.lcredit, 0))
                 FROM tblledger ledger
                 WHERE ledger.lmainid = :ledger_main_id
                   AND ledger.lcustomerid = :ledger_customer_id), 0) AS latest_balance
FROM (
    SELECT inv.lcustomer_name AS customer_name
    FROM tblinvoice_list inv
    INNER JOIN tblinvoice_itemrec ii ON ii.linvoice_refno = inv.lrefno
    WHERE inv.lmain_id = :invoice_main_id
      AND inv.lcustomerid = :invoice_customer_id
      AND {$invoicePosted}

    UNION ALL

    SELECT dr.lcustomer_name AS customer_name
    FROM tbldelivery_receipt dr
    INNER JOIN tbldelivery_receipt_items dri ON dri.lor_refno = dr.lrefno
    WHERE dr.lmain_id = :receipt_main_id
      AND dr.lcustomerid = :receipt_customer_id
      AND {$receiptPosted}
) documents
SQL);
        $statement->execute([
            'session_id' => $sessionId,
            'main_id' => $mainId,
            'fallback_name' => $sessionId,
            'ledger_main_id' => $mainId,
            'ledger_customer_id' => $sessionId,
            'invoice_main_id' => $mainId,
            'invoice_customer_id' => $sessionId,
            'receipt_main_id' => $mainId,
            'receipt_customer_id' => $sessionId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['contact_persons'] = [];
        return $row;
    }

    public function getCurrentMonthPostedSales(string $sessionId, int $mainId): float
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return 0.0;
        }

        (new VipDocumentDiscountRepository($this->db))->ensureTable();
        $salesCtes = PostedSalesDocumentSql::currentMonthCustomerSalesCtes();
        $statement = $this->db->pdo()->prepare(<<<SQL
WITH {$salesCtes}
SELECT current_month_sales
FROM sales_report_current_month
WHERE customer_id = :customer_id
SQL);
        $statement->execute([
            'sales_report_invoice_main_id' => $mainId,
            'sales_report_dr_main_id' => $mainId,
            'customer_id' => $sessionId,
        ]);

        return (float) ($statement->fetchColumn() ?: 0);
    }

    public function getPurchaseHistory(
        string $sessionId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $limit = null,
        int $offset = 0
    ): array
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
            $filters .= ' AND src.ldate < :date_to_exclusive';
            $params['date_to_exclusive'] = $this->exclusiveDate($dateTo);
        }

        $paginationSql = '';
        if ($limit !== null) {
            $paginationSql = ' LIMIT :history_limit OFFSET :history_offset';
            $limit = max(1, min(51, $limit));
            $offset = max(0, $offset);
            $params['history_limit'] = $limit;
            $params['history_offset'] = $offset;
        }

        $invoicePostedCondition = PostedSalesDocumentSql::invoiceIsPosted('inv');
        $deliveryReceiptPostedCondition = PostedSalesDocumentSql::deliveryReceiptIsPosted('dr');

        $sql = <<<SQL
SELECT
    src.source_type,
    src.source_status,
    src.source_refno,
    src.source_no,
    src.ldate,
    src.created_at,
    src.litemcode,
    src.lpartno,
    src.ldesc,
    src.lbrand,
    src.lqty,
    src.lprice,
    COALESCE((
        SELECT SUM(COALESCE(cri.lqty, 0))
        FROM tblcredit_return_item cri
        WHERE cri.ltransaction_item_id = src.source_refno
          AND cri.litemcode = src.litemcode
    ), 0) AS return_qty
FROM (
    SELECT
        'INVOICE' AS source_type,
        COALESCE(inv.lstatus, '') AS source_status,
        inv.lrefno AS source_refno,
        inv.linvoice_no AS source_no,
        inv.ldate,
        COALESCE(inv.created_at, '') AS created_at,
        item.litemcode,
        item.lpartno,
        item.ldesc,
        item.lbrand,
        item.lqty,
        item.lprice
    FROM tblinvoice_list inv
    INNER JOIN tblinvoice_itemrec item ON item.linvoice_refno = inv.lrefno
    WHERE inv.lcustomerid = :customer_id_invoice
      AND {$invoicePostedCondition}

    UNION ALL

    SELECT
        'ORDER_SLIP' AS source_type,
        COALESCE(dr.lstatus, '') AS source_status,
        dr.lrefno AS source_refno,
        dr.linvoice_no AS source_no,
        dr.ldate,
        COALESCE(dr.created_at, '') AS created_at,
        dri.litemcode,
        dri.lpartno,
        dri.ldesc,
        dri.lbrand,
        dri.lqty,
        dri.lprice
    FROM tbldelivery_receipt dr
    INNER JOIN tbldelivery_receipt_items dri ON dri.lor_refno = dr.lrefno
    WHERE dr.lcustomerid = :customer_id_or
      AND {$deliveryReceiptPostedCondition}
) src
WHERE 1=1
{$filters}
ORDER BY src.ldate DESC, src.source_type ASC
{$paginationSql}
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Distinct items this customer has actually bought (invoices + order slips).
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchPurchasedItems(string $sessionId, string $search = '', int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $params = [
            'customer_id_invoice' => $sessionId,
            'customer_id_or' => $sessionId,
        ];
        $searchSql = '';
        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $searchSql = <<<SQL
 AND (
    COALESCE(src.litemcode, '') LIKE :search_item_code
    OR COALESCE(src.lpartno, '') LIKE :search_part_no
    OR COALESCE(src.ldesc, '') LIKE :search_description
 )
SQL;
            $like = '%' . $trimmedSearch . '%';
            $params['search_item_code'] = $like;
            $params['search_part_no'] = $like;
            $params['search_description'] = $like;
        }

        $sql = <<<SQL
SELECT
    COALESCE(src.litemcode, '') AS item_code,
    COALESCE(src.lpartno, '') AS part_no,
    MAX(COALESCE(src.ldesc, '')) AS description,
    MAX(COALESCE(src.lbrand, '')) AS brand,
    MAX(COALESCE(src.lprice, 0)) AS unit_price,
    SUM(COALESCE(src.lqty, 0)) AS purchased_qty,
    SUM(COALESCE(ret.return_qty, 0)) AS returned_qty
FROM (
    SELECT
        inv.lrefno AS source_refno,
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
        dr.lrefno AS source_refno,
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
WHERE COALESCE(src.litemcode, '') <> '' OR COALESCE(src.lpartno, '') <> ''
{$searchSql}
GROUP BY COALESCE(src.litemcode, ''), COALESCE(src.lpartno, '')
ORDER BY MAX(src.source_refno) DESC
LIMIT {$limit}
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $purchasedQty = (float) ($row['purchased_qty'] ?? 0);
            $returnedQty = (float) ($row['returned_qty'] ?? 0);
            $items[] = [
                'item_code' => (string) ($row['item_code'] ?? ''),
                'part_no' => (string) ($row['part_no'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'purchased_qty' => $purchasedQty,
                'returned_qty' => $returnedQty,
                'remaining_qty' => max(0, $purchasedQty - $returnedQty),
            ];
        }

        return $items;
    }

    public function customerPurchasedItem(string $sessionId, string $itemCode, string $partNo): bool
    {
        $itemCode = trim($itemCode);
        $partNo = trim($partNo);
        if ($itemCode === '' && $partNo === '') {
            return false;
        }

        $searches = array_values(array_unique(array_filter([$itemCode, $partNo], static fn (string $value): bool => $value !== '')));
        $seen = [];
        foreach ($searches as $search) {
            foreach ($this->searchPurchasedItems($sessionId, $search, 80) as $row) {
                $key = ($row['item_code'] ?? '') . '|' . ($row['part_no'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if (PurchasedItemMatcher::identifiersMatch(
                    $itemCode,
                    $partNo,
                    (string) ($row['item_code'] ?? ''),
                    (string) ($row['part_no'] ?? '')
                )) {
                    return true;
                }
            }
        }

        return false;
    }

    public function assertCustomerPurchasedItem(string $sessionId, string $itemCode, string $partNo): void
    {
        $sessionId = trim($sessionId);
        $itemCode = trim($itemCode);
        $partNo = trim($partNo);
        if ($sessionId === '') {
            throw new RuntimeException('A customer is required before an item can be selected.');
        }
        if ($itemCode === '' && $partNo === '') {
            throw new RuntimeException('Select an item from this customer\'s purchase history.');
        }
        if (!$this->customerPurchasedItem($sessionId, $itemCode, $partNo)) {
            $label = $partNo !== '' ? $partNo : $itemCode;
            throw new RuntimeException(PurchasedItemMatcher::notPurchasedMessage($label));
        }
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
        ?string $dateTo,
        int $mainId = 0
    ): array {
        $customer = $mainId > 0
            ? $this->findLedgerCustomer($sessionId, $mainId)
            : $this->findCustomerBySession($sessionId);
        if ($customer === null) {
            throw new RuntimeException('Customer not found');
        }

        [$normalizedType, $fromDate, $toDate] = CustomerLedgerCalculator::resolveDateRange(
            $dateType,
            $dateFrom,
            $dateTo
        );
        $reportTypeKey = strtolower(trim($reportType));
        $normalizedReportType = match ($reportTypeKey) {
            'summary' => 'summary',
            'yearly' => 'yearly',
            default => 'detailed',
        };

        $params = ['customer_id' => $sessionId];
        $tenantSql = '';
        if ($mainId > 0) {
            $tenantSql = ' AND CAST(COALESCE(l.lmainid, 0) AS SIGNED) = :main_id';
            $params['main_id'] = $mainId;
        }
        $dateSql = '';
        if ($fromDate !== null && $toDate !== null) {
            // Keep the datetime column bare so MySQL can use the existing
            // (lcustomerid, ..., ldatetime) index for date-filtered reports.
            $dateSql = ' AND l.ldatetime >= :date_from AND l.ldatetime < :date_to_exclusive';
            $params['date_from'] = $fromDate . ' 00:00:00';
            $params['date_to_exclusive'] = $this->exclusiveDate($toDate) . ' 00:00:00';
        }

        $baseSql = <<<SQL
SELECT
    l.lid,
    l.lcustomerid,
    l.lrefno,
    l.lmesssage,
    l.lamt,
    l.ldatetime,
    l.created_at,
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
{$tenantSql}
{$dateSql}
SQL;

        $rows = [];
        $summaryRows = [];
        $rawLedgerRows = null;
        $detailedReport = null;
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
            $openingBalance = $this->computeBalanceBefore($sessionId, $fromDate, $mainId);
        }

        if ($normalizedReportType === 'yearly') {
            $yearlySql = <<<SQL
SELECT
    YEAR(l.ldatetime) AS year,
    MONTH(l.ldatetime) AS month,
    SUM(COALESCE(l.ldebit, 0)) AS debit
FROM tblledger l
WHERE l.lcustomerid = :customer_id
{$tenantSql}
  AND LOWER(TRIM(COALESCE(l.ltype, ''))) = 'debit'
  AND LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')
  AND COALESCE(l.ldebit, 0) > 0
  AND l.ldatetime < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
{$dateSql}
GROUP BY YEAR(l.ldatetime), MONTH(l.ldatetime)
ORDER BY YEAR(l.ldatetime) ASC, MONTH(l.ldatetime) ASC
SQL;
            $stmt = $this->db->pdo()->prepare($yearlySql);
            $stmt->execute($params);
            $rawYearly = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalDebit = 0.0;
            foreach ($rawYearly as $rawRow) {
                $year = (int) ($rawRow['year'] ?? 0);
                $month = (int) ($rawRow['month'] ?? 0);
                $debit = (float) ($rawRow['debit'] ?? 0);
                if ($year <= 0 || $month < 1 || $month > 12) {
                    continue;
                }
                $totalDebit += $debit;
                $summaryRows[] = [
                    'year' => $year,
                    'month' => $month,
                    'month_name' => '',
                    'debit' => $debit,
                    'credit' => 0.0,
                    'balance' => 0.0,
                ];
            }
            $totals = [
                'debit' => $totalDebit,
                'credit' => 0.0,
                'pdc' => 0.0,
                'balance' => 0.0,
                'row_count' => count($summaryRows),
            ];
        } elseif ($normalizedReportType === 'summary') {
            $summarySql = <<<SQL
SELECT
    YEAR(l.ldatetime) AS year,
    MONTH(l.ldatetime) AS month,
    SUM(COALESCE(l.ldebit, 0)) AS debit,
    SUM(COALESCE(l.lcredit, 0)) AS credit
FROM tblledger l
WHERE l.lcustomerid = :customer_id
{$tenantSql}
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
            $rawLedgerRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($mainId > 0 && $rawLedgerRows !== []) {
                $rawLedgerRows = $this->attachUnpostedSalesOrderLinks($rawLedgerRows, $mainId);
            }

            $detailedReport = CustomerLedgerCalculator::buildDetailedReport(
                $rawLedgerRows,
                date('Y-m-d'),
                $openingBalance
            );
            $rows = $detailedReport['rows'];
            $totals = $detailedReport['totals'];
        }

        // Metrics always describe the customer's full history. A filtered
        // detailed response can reuse its rows only when it is unfiltered.
        $metrics = $this->buildLedgerMetrics(
            $sessionId,
            $customer,
            $fromDate === null ? $rawLedgerRows : null,
            $fromDate === null ? $detailedReport : null
        );

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
     * Map invoice-referenced ledger rows to their originating Sales Order when
     * the order has no posted invoice or delivery receipt yet. This explains
     * ledger debits that remain in the account balance but are not posted sales.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function attachUnpostedSalesOrderLinks(array $rows, int $mainId): array
    {
        $references = [];
        foreach ($rows as $row) {
            if (strtolower(trim((string) ($row['ltype'] ?? ''))) !== 'debit'
                || strtolower(trim((string) ($row['lref_name'] ?? ''))) !== 'invoice') {
                continue;
            }
            $reference = trim((string) ($row['lrefno'] ?? ''));
            if ($reference !== '') {
                $references[$reference] = true;
            }
        }
        if ($references === []) {
            return $rows;
        }

        $bindings = ['main_id' => $mainId];
        $placeholders = [];
        foreach (array_keys($references) as $index => $reference) {
            $key = 'invoice_ref_' . $index;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $reference;
        }
        $invoicePosted = PostedSalesDocumentSql::invoiceIsPosted('posted_invoice');
        $receiptPosted = PostedSalesDocumentSql::deliveryReceiptIsPosted('posted_receipt');
        $referencePlaceholders = implode(', ', $placeholders);
        $statement = $this->db->pdo()->prepare(<<<SQL
SELECT transaction.invoice_refno,
       transaction.lrefno AS sales_order_id,
       transaction.lsaleno AS sales_order_no
FROM tbltransaction transaction
WHERE transaction.lmain_id = :main_id
  AND COALESCE(transaction.lcancel, 0) = 0
  AND LOWER(COALESCE(transaction.lsubmitstat, '')) IN ('submitted', 'approved', 'posted')
  AND COALESCE(TRIM(transaction.invoice_refno), '') <> ''
  AND transaction.invoice_refno IN ({$referencePlaceholders})
  AND NOT EXISTS (
      SELECT 1 FROM tblinvoice_list posted_invoice
      WHERE posted_invoice.lmain_id = transaction.lmain_id
        AND posted_invoice.lsales_refno = transaction.lrefno
        AND {$invoicePosted}
  )
  AND NOT EXISTS (
      SELECT 1 FROM tbldelivery_receipt posted_receipt
      WHERE posted_receipt.lmain_id = transaction.lmain_id
        AND posted_receipt.lsales_refno = transaction.lrefno
        AND {$receiptPosted}
  )
ORDER BY transaction.lid DESC
SQL);
        $statement->execute($bindings);
        $orderByInvoiceReference = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $reference = trim((string) ($order['invoice_refno'] ?? ''));
            if ($reference !== '' && !isset($orderByInvoiceReference[$reference])) {
                $orderByInvoiceReference[$reference] = $order;
            }
        }

        foreach ($rows as &$row) {
            $order = $orderByInvoiceReference[trim((string) ($row['lrefno'] ?? ''))] ?? null;
            $row['unposted_sales_order_id'] = (string) ($order['sales_order_id'] ?? '');
            $row['unposted_sales_order_no'] = (string) ($order['sales_order_no'] ?? '');
        }
        unset($row);
        return $rows;
    }

    /**
     * Compute the net ledger balance for a customer before a given date,
     * using the same PDC / future-check logic as mapDetailedLedgerRow.
     */
    private function computeBalanceBefore(string $sessionId, string $beforeDate, int $mainId = 0): float
    {
        $tenantSql = $mainId > 0
            ? ' AND CAST(COALESCE(l.lmainid, 0) AS SIGNED) = :main_id'
            : '';
        $sql = <<<SQL
SELECT
    l.ltype,
    l.lcredit,
    l.ldebit,
    l.lpdc,
    l.lcheckdate
FROM tblledger l
WHERE l.lcustomerid = :customer_id
{$tenantSql}
  AND l.ldatetime < :before_date
ORDER BY l.ldatetime ASC, l.ltype DESC, l.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $params = [
            'customer_id' => $sessionId,
            'before_date' => $beforeDate . ' 00:00:00',
        ];
        if ($mainId > 0) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);
        $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $report = CustomerLedgerCalculator::buildDetailedReport($rawRows, date('Y-m-d'));
        return (float) $report['totals']['balance'];
    }

    private function buildLedgerMetrics(
        string $sessionId,
        array $customer,
        ?array $rawLedgerRows = null,
        ?array $detailedReport = null
    ): array
    {
        $mainId = (int) ($customer['lmain_id'] ?? 0);
        $salesTotals = $this->loadCustomerSalesTotals($sessionId, $mainId);
        $monthlySales = (float) ($salesTotals['monthly_sales'] ?? 0);
        $ledgerMonthlySales = (float) ($salesTotals['ledger_monthly_sales'] ?? 0);
        $lastMonthSales = (float) ($salesTotals['last_month_sales'] ?? 0);
        $dealershipSales = (float) ($salesTotals['dealership_sales'] ?? 0);
        $ishinomotoSales = (float) ($salesTotals['ishinomoto_sales'] ?? 0);

        $ledgerRows = $rawLedgerRows ?? $this->loadLedgerRows($sessionId, $mainId);
        $ledgerReport = $detailedReport ?? CustomerLedgerCalculator::buildDetailedReport($ledgerRows, date('Y-m-d'));
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

        // VIP status is spend-based (last month); price code remains the stored price group.
        $rawPriceGroup = (string) ($customer['lprice_group'] ?? '');
        $customerSince = CustomerLedgerCalculator::normalizeDate((string) ($customer['lsince'] ?? ''));
        $mainId = (int) ($customer['lmain_id'] ?? 0);
        $vipConfig = $this->vipTierConfig($mainId);
        $vipStatus = VipStanding::resolveLevel(
            $lastMonthSales,
            (float) $vipConfig['one_time_discount_threshold'],
            (float) $vipConfig['unlimited_discount_threshold']
        );
        $discountCode = $this->recordQualifiedDiscountCode(
            $mainId,
            $sessionId,
            (string) ($customer['discount_code'] ?? ''),
            $rawPriceGroup,
            (string) ($customer['lsince'] ?? ''),
            $lastMonthSales
        );

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
            'ishinomoto_sales' => $ishinomotoSales,
            'dealership_quota' => (float) ($customer['ldealer_quota'] ?? 0),
            'monthly_sales' => $monthlySales,
            'ledger_monthly_sales' => $ledgerMonthlySales,
            'last_month_sales' => $lastMonthSales,
            'customer_since' => $customerSince,
            'credit_limit' => (float) ($customer['lcredit'] ?? 0),
            'terms' => $terms,
            'balance' => $balance,
            'old_name' => $oldName !== '' ? $oldName : null,
            'price_code' => $rawPriceGroup !== '' ? $rawPriceGroup : null,
            'discount_code' => $discountCode,
            'vip_status' => $vipStatus,
            'vip_one_time_discount_threshold' => (float) $vipConfig['one_time_discount_threshold'],
            'vip_unlimited_discount_threshold' => (float) $vipConfig['unlimited_discount_threshold'],
            'aging' => $aging,
        ];
    }

    /**
     * @return 'regular'|'silver'|'gold'
     */
    public function resolveVipStandingLevel(int $mainId, float $lastMonthSpend): string
    {
        $config = $this->vipTierConfig($mainId);

        return VipStanding::resolveLevel(
            $lastMonthSpend,
            (float) $config['one_time_discount_threshold'],
            (float) $config['unlimited_discount_threshold']
        );
    }

    /**
     * @return array{dealership_sales:float,ishinomoto_sales:float,monthly_sales:float,ledger_monthly_sales:float,last_month_sales:float}
     */
    public function getCustomerSalesTotals(string $sessionId, int $mainId): array
    {
        return $this->loadCustomerSalesTotals($sessionId, $mainId);
    }

    /**
     * @return array{
     *   one_time_discount_threshold: int|float,
     *   unlimited_discount_threshold: int|float,
     *   discount_percentage?: int|float
     * }
     */
    private function vipTierConfig(int $mainId): array
    {
        if ($mainId > 0) {
            return (new VipTierSettingsRepository($this->db))->getConfig($mainId);
        }

        return [
            'one_time_discount_threshold' => 10000,
            'unlimited_discount_threshold' => 30000,
            'discount_percentage' => 10,
        ];
    }

    private function recordQualifiedDiscountCode(
        int $mainId,
        string $sessionId,
        string $currentDiscountCode,
        string $priceGroup,
        string $customerSince,
        float $benefitMonthBasisSales
    ): string {
        if ($this->resolvePlatinumEligibility($priceGroup, $customerSince)) {
            return $this->persistDiscountCode($mainId, $sessionId, $currentDiscountCode, 'vip platinum');
        }

        $level = $this->resolveVipStandingLevel($mainId, $benefitMonthBasisSales);
        $qualified = match ($level) {
            'gold' => 'vip gold',
            'silver' => 'vip silver',
            default => null,
        };

        if ($qualified !== null) {
            return $this->persistDiscountCode($mainId, $sessionId, $currentDiscountCode, $qualified);
        }

        return $this->normalizeDiscountCode($currentDiscountCode, $priceGroup, $customerSince);
    }

    private function persistDiscountCode(int $mainId, string $sessionId, string $currentDiscountCode, string $nextDiscountCode): string
    {
        $normalizedCurrent = $this->normalizeDiscountCode($currentDiscountCode);
        if ($mainId <= 0 || $sessionId === '' || $normalizedCurrent === $nextDiscountCode || !$this->hasCustomerDiscountCodeColumn()) {
            return $nextDiscountCode;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblpatient
             SET ldiscount_code = :discount_code
             WHERE lmain_id = :main_id
               AND lsessionid = :session_id
               AND COALESCE(ldeleted, 0) = 0'
        );
        $stmt->execute([
            'discount_code' => $nextDiscountCode,
            'main_id' => $mainId,
            'session_id' => $sessionId,
        ]);

        return $nextDiscountCode;
    }

    private function hasCustomerDiscountCodeColumn(): bool
    {
        if ($this->hasCustomerDiscountCodeColumn !== null) {
            return $this->hasCustomerDiscountCodeColumn;
        }

        try {
            $stmt = $this->db->pdo()->query('SHOW COLUMNS FROM tblpatient LIKE "ldiscount_code"');
            $this->hasCustomerDiscountCodeColumn = $stmt !== false && $stmt->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (\Throwable) {
            $this->hasCustomerDiscountCodeColumn = false;
        }

        return $this->hasCustomerDiscountCodeColumn;
    }

    /** @return list<array<string,mixed>> */
    private function loadLedgerRows(string $sessionId, int $mainId = 0): array
    {
        $tenantSql = $mainId > 0
            ? ' AND CAST(COALESCE(lmainid, 0) AS SIGNED) = :main_id'
            : '';
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lrefno, lmesssage, ldatetime, ltype, lcredit, ldebit,
                    lcheckdate, lcheck_no, ldcr, lpdc, lremarks, lref_name, promisetopay
             FROM tblledger
             WHERE lcustomerid = :customer_id
             ' . $tenantSql . '
             ORDER BY ldatetime ASC, ltype DESC, lid ASC'
        );
        $params = ['customer_id' => $sessionId];
        if ($mainId > 0) {
            $params['main_id'] = $mainId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array{dealership_sales:float,ishinomoto_sales:float,monthly_sales:float,ledger_monthly_sales:float,last_month_sales:float}
     */
    private function loadCustomerSalesTotals(string $sessionId, int $mainId): array
    {
        $invoicePosted = PostedSalesDocumentSql::invoiceIsPosted('posted_invoice');
        $receiptPosted = PostedSalesDocumentSql::deliveryReceiptIsPosted('posted_receipt');
        $sql = <<<SQL
SELECT
    COALESCE(SUM(COALESCE(l.ldebit, 0)), 0) AS dealership_sales,
    COALESCE(SUM(COALESCE(l.ldebit, 0)), 0) AS ishinomoto_sales,
    COALESCE(SUM(
        CASE
            WHEN DATE_FORMAT(l.ldatetime, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
             AND (
                (LOWER(TRIM(COALESCE(l.lref_name, ''))) = 'invoice' AND EXISTS (
                    SELECT 1 FROM tblinvoice_list posted_invoice
                    WHERE posted_invoice.lmain_id = l.lmainid
                      AND posted_invoice.lrefno = l.lrefno
                      AND {$invoicePosted}
                ))
                OR (LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('order slip', 'order_slip') AND EXISTS (
                    SELECT 1 FROM tbldelivery_receipt posted_receipt
                    WHERE posted_receipt.lmain_id = l.lmainid
                      AND posted_receipt.lrefno = l.lrefno
                      AND {$receiptPosted}
                ))
             )
            THEN COALESCE(l.ldebit, 0)
            ELSE 0
        END
    ), 0) AS ledger_monthly_sales,
    COALESCE(SUM(
        CASE
            WHEN DATE_FORMAT(l.ldatetime, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')
             AND (
                (LOWER(TRIM(COALESCE(l.lref_name, ''))) = 'invoice' AND EXISTS (
                    SELECT 1 FROM tblinvoice_list posted_invoice
                    WHERE posted_invoice.lmain_id = l.lmainid
                      AND posted_invoice.lrefno = l.lrefno
                      AND {$invoicePosted}
                ))
                OR (LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('order slip', 'order_slip') AND EXISTS (
                    SELECT 1 FROM tbldelivery_receipt posted_receipt
                    WHERE posted_receipt.lmain_id = l.lmainid
                      AND posted_receipt.lrefno = l.lrefno
                      AND {$receiptPosted}
                ))
             )
            THEN COALESCE(l.ldebit, 0)
            ELSE 0
        END
    ), 0) AS last_month_sales
FROM tblledger l
WHERE l.lcustomerid = :customer_id
  AND l.lmainid = :main_id
  AND LOWER(TRIM(COALESCE(l.ltype, ''))) = 'debit'
  AND LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['customer_id' => $sessionId, 'main_id' => $mainId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'dealership_sales' => (float) ($row['dealership_sales'] ?? 0),
            'ishinomoto_sales' => (float) ($row['ishinomoto_sales'] ?? 0),
            'monthly_sales' => $this->getCurrentMonthPostedSales($sessionId, $mainId),
            'ledger_monthly_sales' => (float) ($row['ledger_monthly_sales'] ?? 0),
            'last_month_sales' => (float) ($row['last_month_sales'] ?? 0),
        ];
    }

    private function exclusiveDate(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
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
