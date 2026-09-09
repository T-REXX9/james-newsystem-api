<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\CustomerLedgerCalculator;
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

    public function findCustomerBySession(string $sessionId): ?array
    {
        $discountCodeSelect = $this->hasCustomerDiscountCodeColumn()
            ? 'p.ldiscount_code AS discount_code,'
            : "'' AS discount_code,";

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
LEFT JOIN tblaccount agent ON agent.lid = p.lsales_person
WHERE p.lsessionid = :session_id
  AND COALESCE(p.ldeleted, 0) = 0
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
            $filters .= ' AND src.ldate <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $paginationSql = '';
        if ($limit !== null) {
            $paginationSql = ' LIMIT :history_limit OFFSET :history_offset';
            $limit = max(1, min(51, $limit));
            $offset = max(0, $offset);
            $params['history_limit'] = $limit;
            $params['history_offset'] = $offset;
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
        $lastMonthSales = (float) ($salesTotals['last_month_sales'] ?? 0);
        $dealershipSales = (float) ($salesTotals['dealership_sales'] ?? 0);
        $ishinomotoSales = (float) ($salesTotals['ishinomoto_sales'] ?? 0);

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

        // VIP status is spend-based (last month); price code remains the stored price group.
        $rawPriceGroup = (string) ($customer['lprice_group'] ?? '');
        $customerSince = CustomerLedgerCalculator::normalizeDate((string) ($customer['lsince'] ?? ''));
        $mainId = (int) ($customer['lmain_id'] ?? 0);
        $vipStatus = $this->resolveVipStandingLevel($mainId, $lastMonthSales);
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
            'last_month_sales' => $lastMonthSales,
            'customer_since' => $customerSince,
            'credit_limit' => (float) ($customer['lcredit'] ?? 0),
            'terms' => $terms,
            'balance' => $balance,
            'old_name' => $oldName !== '' ? $oldName : null,
            'price_code' => $rawPriceGroup !== '' ? $rawPriceGroup : null,
            'discount_code' => $discountCode,
            'vip_status' => $vipStatus,
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
     * @return array{dealership_sales:float,ishinomoto_sales:float,monthly_sales:float,last_month_sales:float}
     */
    public function getCustomerSalesTotals(string $sessionId): array
    {
        return $this->loadCustomerSalesTotals($sessionId);
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
     * @return array{dealership_sales:float,ishinomoto_sales:float,monthly_sales:float,last_month_sales:float}
     */
    private function loadCustomerSalesTotals(string $sessionId): array
    {
        $sql = <<<'SQL'
SELECT
    COALESCE(SUM(COALESCE(l.ldebit, 0)), 0) AS dealership_sales,
    COALESCE(SUM(COALESCE(l.ldebit, 0)), 0) AS ishinomoto_sales,
    COALESCE(SUM(
        CASE
            WHEN DATE_FORMAT(l.ldatetime, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            THEN COALESCE(l.ldebit, 0)
            ELSE 0
        END
    ), 0) AS monthly_sales,
    COALESCE(SUM(
        CASE
            WHEN DATE_FORMAT(l.ldatetime, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m')
            THEN COALESCE(l.ldebit, 0)
            ELSE 0
        END
    ), 0) AS last_month_sales
FROM tblledger l
WHERE l.lcustomerid = :customer_id
  AND LOWER(TRIM(COALESCE(l.ltype, ''))) = 'debit'
  AND LOWER(TRIM(COALESCE(l.lref_name, ''))) IN ('invoice', 'order slip', 'order_slip')
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['customer_id' => $sessionId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'dealership_sales' => (float) ($row['dealership_sales'] ?? 0),
            'ishinomoto_sales' => (float) ($row['ishinomoto_sales'] ?? 0),
            'monthly_sales' => (float) ($row['monthly_sales'] ?? 0),
            'last_month_sales' => (float) ($row['last_month_sales'] ?? 0),
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
