<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\PostedSalesDocumentSql;
use DateTimeImmutable;
use PDO;

final class SalesReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function listCustomers(int $mainId, string $search = '', int $limit = 300): array
    {
        $sql = <<<SQL
SELECT
    COALESCE(p.lsessionid, '') AS id,
    TRIM(COALESCE(p.lcompany, '')) AS company,
    COALESCE(p.lpatient_code, '') AS customer_code,
    p.lid AS legacy_id
FROM tblpatient p
WHERE p.lmain_id = :main_id
  AND COALESCE(p.lstatus, 0) = 1
SQL;

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $sql .= ' AND ('
                . 'TRIM(COALESCE(p.lcompany, \'\')) LIKE :search_company '
                . 'OR COALESCE(p.lpatient_code, \'\') LIKE :search_code '
                . 'OR COALESCE(p.lsessionid, \'\') LIKE :search_session'
                . ')';
        }

        $sql .= ' ORDER BY company ASC LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if ($trimmedSearch !== '') {
            $term = '%' . $trimmedSearch . '%';
            $stmt->bindValue('search_company', $term, PDO::PARAM_STR);
            $stmt->bindValue('search_code', $term, PDO::PARAM_STR);
            $stmt->bindValue('search_session', $term, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', max(1, min(2000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSalesReport(
        int $mainId,
        string $dateType,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $customerId,
        int $limit = 1200,
        ?string $agentId = null
    ): array {
        [$normalizedDateType, $fromDate, $toDate] = $this->resolveDateRange($dateType, $dateFrom, $dateTo);

        // The legacy Sales Report is a posted-sales report. It intentionally
        // reads only invoices and delivery receipts; sales orders are shown as
        // references on those records and must not become sales rows of their own.
        $invoiceRows = $this->fetchInvoiceRows($mainId, $fromDate, $toDate, $customerId, $limit, $agentId);
        $drRows = $this->fetchDrRows($mainId, $fromDate, $toDate, $customerId, $limit, $agentId);

        $transactions = array_merge($invoiceRows, $drRows);
        usort(
            $transactions,
            static fn(array $a, array $b): int => strcmp(
                ((string) ($a['date'] ?? '')) . '|' . str_pad((string) ($a['_sort_id'] ?? 0), 12, '0', STR_PAD_LEFT),
                ((string) ($b['date'] ?? '')) . '|' . str_pad((string) ($b['_sort_id'] ?? 0), 12, '0', STR_PAD_LEFT)
            )
        );
        $transactions = array_map(static function (array $row): array {
            unset($row['_sort_id']);
            return $row;
        }, $transactions);

        $customerTypes = $this->fetchCustomerTypes(
            $mainId,
            array_values(array_unique(array_filter(array_map(
                static fn(array $row): string => trim((string) ($row['customer_id'] ?? '')),
                $transactions
            ))))
        );
        foreach ($transactions as &$transaction) {
            $transaction['customer_type'] = $customerTypes[(string) ($transaction['customer_id'] ?? '')] ?? 'unclassified';
        }
        unset($transaction);

        $summary = $this->buildSummary($transactions, $mainId, $agentId);
        $summary['productTotals'] = $this->buildProductTotals($mainId, $transactions);

        return [
            'date_type' => $normalizedDateType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'filters' => [
                'customer_id' => trim((string) $customerId),
            ],
            'transactions' => $transactions,
            'summary' => $summary,
        ];
    }

    /**
     * Uses the sales-report classification requested in issue #47: customers
     * since 2026-01-01 are new; earlier customers are existing.
     *
     * @param array<int, string> $customerIds
     * @return array<string, string>
     */
    private function fetchCustomerTypes(int $mainId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        [$inClause, $bindings] = $this->buildInClause('customer', $customerIds);
        $effectiveSince = <<<SQL
COALESCE(
    CASE
        WHEN p.lsince IS NULL
          OR TRIM(COALESCE(p.lsince, '')) = ''
          OR p.lsince IN ('0000-00-00', '0000-00-00 00:00:00')
        THEN NULL
        ELSE DATE(p.lsince)
    END,
    CASE
        WHEN p.ldatereg IS NULL
          OR TRIM(COALESCE(p.ldatereg, '')) = ''
          OR p.ldatereg IN ('0000-00-00', '0000-00-00 00:00:00')
        THEN NULL
        ELSE DATE(p.ldatereg)
    END,
    (
        SELECT MIN(DATE(l.ldatetime))
        FROM tblledger l
        WHERE l.lcustomerid = p.lsessionid
    )
)
SQL;
        $sql = <<<SQL
SELECT p.lsessionid AS customer_id, {$effectiveSince} AS customer_since
FROM tblpatient p
WHERE p.lmain_id = :main_id
  AND p.lsessionid IN ({$inClause})
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $cutoffDate = '2026-01-01';
        $types = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $customerId = (string) ($row['customer_id'] ?? '');
            $customerSince = trim((string) ($row['customer_since'] ?? ''));
            if ($customerId === '' || $customerSince === '') {
                continue;
            }
            $types[$customerId] = $customerSince < $cutoffDate ? 'old' : 'new';
        }

        return $types;
    }

    public function getTransactionItems(int $mainId, string $transactionRefno, string $type): array
    {
        if ($type === 'invoice') {
            $sql = <<<SQL
SELECT
    CONCAT('inv-', i.lid) AS id,
    COALESCE(i.lqty, 0) AS qty,
    COALESCE(i.litemcode, '') AS item_code,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.lbrand, '') AS brand,
    COALESCE(i.ldesc, '') AS description,
    COALESCE(i.lprice, 0) AS unit_price,
    COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0) AS amount,
    COALESCE(NULLIF(NULLIF(TRIM(i.lcategory), ''), 'Uncategorized'), 'No category recorded') AS category
FROM tblinvoice_list d
INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.lrefno = :refno
  AND COALESCE(NULLIF(TRIM(d.lcancel), ''), '0') = '0'
ORDER BY i.lid ASC
SQL;
        } elseif ($type === 'dr') {
            $sql = <<<SQL
SELECT
    CONCAT('dr-', i.lid) AS id,
    COALESCE(i.lqty, 0) AS qty,
    COALESCE(i.litemcode, '') AS item_code,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.lbrand, '') AS brand,
    COALESCE(i.ldesc, '') AS description,
    COALESCE(i.lprice, 0) AS unit_price,
    COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0) AS amount,
    COALESCE(NULLIF(NULLIF(TRIM(i.lcategory), ''), 'Uncategorized'), 'No category recorded') AS category
FROM tbldelivery_receipt d
INNER JOIN tbldelivery_receipt_items i ON i.lor_refno = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.lrefno = :refno
  AND COALESCE(NULLIF(TRIM(d.lcancel), ''), '0') = '0'
ORDER BY i.lid ASC
SQL;
        } else {
            $sql = <<<SQL
SELECT
    CONCAT('so-', i.lid) AS id,
    COALESCE(i.lqty, 0) AS qty,
    COALESCE(i.litemcode, '') AS item_code,
    COALESCE(i.lpartno, '') AS part_no,
    COALESCE(i.lbrand, '') AS brand,
    COALESCE(i.ldesc, '') AS description,
    COALESCE(i.lprice, 0) AS unit_price,
    COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0) AS amount,
    COALESCE(i.ltype, 'Sales Order') AS category
FROM tbltransaction t
INNER JOIN tbltransaction_item i ON i.lrefno = t.lrefno
WHERE t.lmain_id = :main_id
  AND t.lrefno = :refno
  AND COALESCE(t.lcancel, 0) = 0
  AND COALESCE(i.lcancel, 0) = 0
ORDER BY i.lid ASC
SQL;
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('refno', $transactionRefno, PDO::PARAM_STR);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'qty' => (int) ($row['qty'] ?? 0),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'part_no' => (string) ($row['part_no'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'amount' => (float) ($row['amount'] ?? 0),
                'category' => (string) ($row['category'] ?? 'No category recorded'),
            ],
            $rows
        );
    }

    private function fetchInvoiceRows(
        int $mainId,
        ?string $fromDate,
        ?string $toDate,
        ?string $customerId,
        int $limit,
        ?string $agentId
    ): array {
        $where = [
            'l.lmain_id = :main_id',
            PostedSalesDocumentSql::invoiceIsPosted('l'),
        ];

        $params = ['main_id' => $mainId];

        if ($fromDate !== null && $toDate !== null) {
            // ldatetime is the row/import timestamp.  The corporate source
            // preserves the actual invoice sales date in ldate, so reports
            // must filter on that field after a corporate sync.
            $where[] = 'l.ldate >= :date_from';
            $where[] = 'l.ldate <= :date_to';
            $params['date_from'] = $fromDate;
            $params['date_to'] = $toDate;
        }

        $trimmedCustomerId = trim((string) $customerId);
        if ($trimmedCustomerId !== '' && strtolower($trimmedCustomerId) !== 'all') {
            $where[] = 'l.lcustomerid = :customer_id';
            $params['customer_id'] = $trimmedCustomerId;
        }
        $currentAgentJoin = 'LEFT JOIN tblpatient p ON p.lmain_id = l.lmain_id AND p.lsessionid = l.lcustomerid LEFT JOIN tblaccount current_agent ON current_agent.lid = p.lsales_person';
        if ($agentId !== null && $agentId !== '') {
            $where[] = 'p.lsales_person = :agent_id';
            $params['agent_id'] = $agentId;
        }

        $sql = sprintf(
            <<<SQL
SELECT
    COALESCE(l.lrefno, '') AS id,
    l.ldate AS `date`,
    TRIM(COALESCE(l.lcustomer_name, '')) AS customer,
    COALESCE(l.lcustomerid, '') AS customer_id,
    COALESCE((SELECT s.lis_starred FROM tblpatient_stars s WHERE s.lmain_id = l.lmain_id AND s.lsessionid = l.lcustomerid), 0) AS is_starred,
    COALESCE(l.lterms, '') AS terms,
    COALESCE(l.linvoice_no, '') AS ref_no,
    COALESCE(l.lsales_refno, '') AS sales_refno,
    COALESCE(l.ltax_type, '') AS ltax_type,
    COALESCE(l.lsales_person, '') AS salesperson,
    COALESCE(p.lsales_person, '') AS current_agent_id,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(current_agent.lfname, ''), ' ', COALESCE(current_agent.llname, ''))), ''), 'Unassigned') AS current_agent,
    l.lid AS sort_id
FROM tblinvoice_list l
{$currentAgentJoin}
WHERE %s
ORDER BY l.ldate DESC, l.lid DESC
SQL,
            implode(' AND ', $where)
        );
        $sql .= ' LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->bindValue('limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($docs === []) {
            return [];
        }

        $refnos = array_values(array_unique(array_filter(array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $docs))));
        $salesRefnos = array_values(array_unique(array_filter(array_map(static fn(array $r): string => (string) ($r['sales_refno'] ?? ''), $docs))));

        $invoiceAgg = $this->fetchItemAgg('tblinvoice_itemrec', 'linvoice_refno', $refnos);
        $soAgg = $this->fetchSoAgg($salesRefnos);

        $rows = [];
        foreach ($docs as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $salesRef = (string) ($doc['sales_refno'] ?? '');
            $item = $invoiceAgg[$id] ?? ['amount' => 0.0, 'category' => 'No category recorded'];
            $so = $soAgg[$salesRef] ?? ['so_no' => '', 'so_amount' => 0.0];

            $invoiceAmount = (float) ($item['amount'] ?? 0);
            if (strtolower((string) ($doc['ltax_type'] ?? '')) === 'exclusive') {
                $invoiceAmount *= 1.12;
            }

            $vatType = null;
            $tax = strtolower((string) ($doc['ltax_type'] ?? ''));
            if ($tax === 'exclusive' || $tax === 'inclusive') {
                $vatType = $tax;
            }

            $rows[] = [
                'id' => $id,
                'date' => (string) ($doc['date'] ?? ''),
                'customer' => (string) ($doc['customer'] ?? ''),
                'customer_id' => (string) ($doc['customer_id'] ?? ''),
                'terms' => (string) ($doc['terms'] ?? ''),
                'ref_no' => (string) ($doc['ref_no'] ?? ''),
                'so_no' => (string) ($so['so_no'] ?? ''),
                'so_amount' => (float) ($so['so_amount'] ?? 0),
                'dr_amount' => 0.0,
                'invoice_amount' => $invoiceAmount,
                'salesperson' => (string) ($doc['current_agent'] ?? 'Unassigned'),
                'current_agent_id' => (string) ($doc['current_agent_id'] ?? ''),
                'category' => (string) ($item['category'] ?? 'No category recorded'),
                'vat_type' => $vatType,
                'type' => 'invoice',
                '_sort_id' => (int) ($doc['sort_id'] ?? 0),
            ];
        }

        return $rows;
    }

    private function fetchDrRows(
        int $mainId,
        ?string $fromDate,
        ?string $toDate,
        ?string $customerId,
        int $limit,
        ?string $agentId
    ): array {
        $where = [
            'l.lmain_id = :main_id',
            PostedSalesDocumentSql::deliveryReceiptIsPosted('l'),
        ];

        $params = ['main_id' => $mainId];

        if ($fromDate !== null && $toDate !== null) {
            $where[] = 'l.ldate >= :date_from';
            $where[] = 'l.ldate <= :date_to';
            $params['date_from'] = $fromDate;
            $params['date_to'] = $toDate;
        }

        $trimmedCustomerId = trim((string) $customerId);
        if ($trimmedCustomerId !== '' && strtolower($trimmedCustomerId) !== 'all') {
            $where[] = 'l.lcustomerid = :customer_id';
            $params['customer_id'] = $trimmedCustomerId;
        }
        $currentAgentJoin = 'LEFT JOIN tblpatient p ON p.lmain_id = l.lmain_id AND p.lsessionid = l.lcustomerid LEFT JOIN tblaccount current_agent ON current_agent.lid = p.lsales_person';
        if ($agentId !== null && $agentId !== '') {
            $where[] = 'p.lsales_person = :agent_id';
            $params['agent_id'] = $agentId;
        }

        $sql = sprintf(
            <<<SQL
SELECT
    COALESCE(l.lrefno, '') AS id,
    l.ldate AS `date`,
    TRIM(COALESCE(l.lcustomer_name, '')) AS customer,
    COALESCE(l.lcustomerid, '') AS customer_id,
    COALESCE((SELECT s.lis_starred FROM tblpatient_stars s WHERE s.lmain_id = l.lmain_id AND s.lsessionid = l.lcustomerid), 0) AS is_starred,
    COALESCE(l.lterms, '') AS terms,
    COALESCE(l.linvoice_no, '') AS ref_no,
    COALESCE(l.lsales_refno, '') AS sales_refno,
    COALESCE(l.ltax_type, '') AS ltax_type,
    COALESCE(l.lsales_person, '') AS salesperson,
    COALESCE(p.lsales_person, '') AS current_agent_id,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(current_agent.lfname, ''), ' ', COALESCE(current_agent.llname, ''))), ''), 'Unassigned') AS current_agent,
    l.lid AS sort_id
FROM tbldelivery_receipt l
{$currentAgentJoin}
WHERE %s
ORDER BY l.ldate DESC, l.lid DESC
SQL,
            implode(' AND ', $where)
        );
        $sql .= ' LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->bindValue('limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($docs === []) {
            return [];
        }

        $refnos = array_values(array_unique(array_filter(array_map(static fn(array $r): string => (string) ($r['id'] ?? ''), $docs))));
        $salesRefnos = array_values(array_unique(array_filter(array_map(static fn(array $r): string => (string) ($r['sales_refno'] ?? ''), $docs))));

        $drAgg = $this->fetchItemAgg('tbldelivery_receipt_items', 'lor_refno', $refnos);
        $soAgg = $this->fetchSoAgg($salesRefnos);

        $rows = [];
        foreach ($docs as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $salesRef = (string) ($doc['sales_refno'] ?? '');
            $item = $drAgg[$id] ?? ['amount' => 0.0, 'category' => 'No category recorded'];
            $so = $soAgg[$salesRef] ?? ['so_no' => '', 'so_amount' => 0.0];

            $vatType = null;
            $tax = strtolower((string) ($doc['ltax_type'] ?? ''));
            if ($tax === 'exclusive' || $tax === 'inclusive') {
                $vatType = $tax;
            }

            $rows[] = [
                'id' => $id,
                'date' => (string) ($doc['date'] ?? ''),
                'customer' => (string) ($doc['customer'] ?? ''),
                'customer_id' => (string) ($doc['customer_id'] ?? ''),
                'terms' => (string) ($doc['terms'] ?? ''),
                'ref_no' => (string) ($doc['ref_no'] ?? ''),
                'so_no' => (string) ($so['so_no'] ?? ''),
                'so_amount' => (float) ($so['so_amount'] ?? 0),
                'dr_amount' => (float) ($item['amount'] ?? 0),
                'invoice_amount' => 0.0,
                'salesperson' => (string) ($doc['current_agent'] ?? 'Unassigned'),
                'current_agent_id' => (string) ($doc['current_agent_id'] ?? ''),
                'category' => (string) ($item['category'] ?? 'No category recorded'),
                'vat_type' => $vatType,
                'type' => 'dr',
                '_sort_id' => (int) ($doc['sort_id'] ?? 0),
            ];
        }

        return $rows;
    }

    private function fetchItemAgg(string $table, string $refColumn, array $refnos): array
    {
        if ($refnos === []) {
            return [];
        }

        [$inClause, $binds] = $this->buildInClause('ref', $refnos);

        $sql = sprintf(
            <<<SQL
SELECT
    x.%s AS refno,
    SUM(COALESCE(x.lqty, 0) * COALESCE(x.lprice, 0)) AS amount,
    GROUP_CONCAT(DISTINCT NULLIF(TRIM(x.lcategory), '')) AS categories
FROM %s x
WHERE x.%s IN (%s)
GROUP BY x.%s
SQL,
            $refColumn,
            $table,
            $refColumn,
            $inClause,
            $refColumn
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($binds as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['refno'] ?? '');
            if ($ref === '') {
                continue;
            }

            $categories = (string) ($row['categories'] ?? '');
            $category = 'No category recorded';
            if ($categories !== '') {
                $category = str_contains($categories, '|') || str_contains($categories, ',') ? 'Mixed' : $categories;
            }

            $mapped[$ref] = [
                'amount' => (float) ($row['amount'] ?? 0),
                'category' => $category,
            ];
        }

        return $mapped;
    }

    private function fetchSoAgg(array $salesRefnos): array
    {
        if ($salesRefnos === []) {
            return [];
        }

        [$inClause, $binds] = $this->buildInClause('sref', $salesRefnos);

        $sql = <<<SQL
SELECT
    t.lrefno,
    MAX(COALESCE(t.lsaleno, '')) AS so_no,
    SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS so_amount,
    GROUP_CONCAT(DISTINCT NULLIF(TRIM(COALESCE(i.ltype, '')), '')) AS categories
FROM tbltransaction t
LEFT JOIN tbltransaction_item i
  ON i.lrefno = t.lrefno
WHERE t.lrefno IN ({{IN}})
GROUP BY t.lrefno
SQL;
        $sql = str_replace('{{IN}}', $inClause, $sql);

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($binds as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['lrefno'] ?? '');
            if ($ref === '') {
                continue;
            }
            $categories = (string) ($row['categories'] ?? '');
            $category = 'No category recorded';
            if ($categories !== '') {
                $category = str_contains($categories, '|') || str_contains($categories, ',') ? 'Mixed' : $categories;
            }
            $mapped[$ref] = [
                'so_no' => (string) ($row['so_no'] ?? ''),
                'so_amount' => (float) ($row['so_amount'] ?? 0),
                'category' => $category,
            ];
        }

        return $mapped;
    }

    private function fetchLegacySalespersonTotals(int $mainId, ?string $fromDate, ?string $toDate): array
    {
        if ($fromDate === null || $toDate === null) {
            return [];
        }

        $sql = <<<SQL
SELECT
    a.lid AS salesperson_id,
    TRIM(COALESCE(a.lfname, '')) AS salesperson,
    c.lname AS category,
    SUM(
        COALESCE(i.lprice, 0) * COALESCE(i.lqty, 0)
        * CASE WHEN t.ltax_type = 'Exclusive' THEN 1.12 ELSE 1 END
    ) AS amount
FROM tblaccount a
INNER JOIN tbltransaction t
    ON t.lsales_person_id = a.lid
   AND t.lmain_id = :transaction_main_id
INNER JOIN tbltransaction_item i
    ON i.lrefno = t.lrefno
   AND i.lremark = 'OnStock'
   AND i.ltransaction_date >= :date_from
   AND i.ltransaction_date <= :date_to
INNER JOIN tblcategory c
    ON c.lname = i.lcategory
   AND c.lmain_id = :category_main_id
WHERE a.larchieve = 0
  AND a.ltype = '2'
  AND a.lmother_id = :account_main_id
GROUP BY a.lid, a.lfname, c.lid, c.lname
HAVING amount <> 0
ORDER BY a.lid ASC, c.lname ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('transaction_main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('category_main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('account_main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('date_from', $fromDate, PDO::PARAM_STR);
        $stmt->bindValue('date_to', $toDate, PDO::PARAM_STR);
        $stmt->execute();

        $totals = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $salespersonId = (string) ($row['salesperson_id'] ?? '');
            if ($salespersonId === '') {
                continue;
            }

            if (!isset($totals[$salespersonId])) {
                $totals[$salespersonId] = [
                    'salesperson' => (string) ($row['salesperson'] ?? ''),
                    'categories' => [],
                    'total' => 0.0,
                ];
            }

            $amount = (float) ($row['amount'] ?? 0);
            $totals[$salespersonId]['categories'][] = [
                'category' => (string) ($row['category'] ?? ''),
                'soAmount' => $amount,
                'drAmount' => 0.0,
                'invoiceAmount' => 0.0,
            ];
            $totals[$salespersonId]['total'] += $amount;
        }

        return array_values($totals);
    }

    /**
     * @param array<int, string> $values
     * @return array{0:string,1:array<string,string>}
     */
    private function buildInClause(string $prefix, array $values): array
    {
        $placeholders = [];
        $bindings = [];
        foreach (array_values($values) as $i => $value) {
            $key = $prefix . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $value;
        }

        return [implode(', ', $placeholders), $bindings];
    }

    private function buildSummary(array $transactions, int $mainId, ?string $agentId): array
    {
        $categoryTotals = [];
        $salespersonBuckets = [];

        $grandSo = 0.0;
        $grandDr = 0.0;
        $grandInvoice = 0.0;

        foreach ($transactions as $tx) {
            $category = (string) ($tx['category'] ?? 'No category recorded');
            $salesperson = trim((string) ($tx['salesperson'] ?? ''));
            if ($salesperson === '') {
                $salesperson = 'Unassigned';
            }
            $salespersonId = trim((string) ($tx['current_agent_id'] ?? ''));
            $salespersonKey = $salespersonId !== '' ? 'id:' . $salespersonId : 'name:' . $salesperson;

            $so = (float) ($tx['so_amount'] ?? 0);
            $dr = (float) ($tx['dr_amount'] ?? 0);
            $invoice = (float) ($tx['invoice_amount'] ?? 0);

            $grandSo += $so;
            $grandDr += $dr;
            $grandInvoice += $invoice;

            if (!isset($categoryTotals[$category])) {
                $categoryTotals[$category] = [
                    'category' => $category,
                    'soAmount' => 0.0,
                    'drAmount' => 0.0,
                    'invoiceAmount' => 0.0,
                ];
            }
            $categoryTotals[$category]['soAmount'] += $so;
            $categoryTotals[$category]['drAmount'] += $dr;
            $categoryTotals[$category]['invoiceAmount'] += $invoice;

            if (!isset($salespersonBuckets[$salespersonKey])) {
                $salespersonBuckets[$salespersonKey] = [
                    'id' => $salespersonId,
                    'salesperson' => $salesperson,
                    'categories' => [],
                ];
            }
            if (!isset($salespersonBuckets[$salespersonKey]['categories'][$category])) {
                $salespersonBuckets[$salespersonKey]['categories'][$category] = [
                    'category' => $category,
                    'soAmount' => 0.0,
                    'drAmount' => 0.0,
                    'invoiceAmount' => 0.0,
                ];
            }
            $salespersonBuckets[$salespersonKey]['categories'][$category]['soAmount'] += $so;
            $salespersonBuckets[$salespersonKey]['categories'][$category]['drAmount'] += $dr;
            $salespersonBuckets[$salespersonKey]['categories'][$category]['invoiceAmount'] += $invoice;
        }

        foreach ($this->fetchActiveSalespeople($mainId, $agentId) as $salesperson) {
            $salespersonId = (string) ($salesperson['id'] ?? '');
            $salespersonName = (string) ($salesperson['salesperson'] ?? 'Unassigned');
            $salespersonKey = $salespersonId !== '' ? 'id:' . $salespersonId : 'name:' . $salespersonName;
            if (!isset($salespersonBuckets[$salespersonKey])) {
                $salespersonBuckets[$salespersonKey] = [
                    'id' => $salespersonId,
                    'salesperson' => $salespersonName,
                    'categories' => [],
                ];
            }
        }

        ksort($categoryTotals);

        $salespersonTotals = [];
        foreach ($salespersonBuckets as $bucket) {
            $categories = $bucket['categories'];
            $categoriesList = array_values($categories);
            usort(
                $categoriesList,
                static fn(array $a, array $b): int => strcmp((string) $a['category'], (string) $b['category'])
            );

            $total = 0.0;
            foreach ($categoriesList as $entry) {
                // The legacy report's total-sales figure is the posted amount:
                // delivery receipts plus invoices. SO is contextual only.
                $total += (float) $entry['drAmount'] + (float) $entry['invoiceAmount'];
            }

            $salespersonTotals[] = [
                'id' => $bucket['id'],
                'salesperson' => $bucket['salesperson'],
                'categories' => $categoriesList,
                'total' => $total,
            ];
        }

        usort(
            $salespersonTotals,
            static fn(array $a, array $b): int => ((float) $b['total'] <=> (float) $a['total'])
        );

        return [
            'categoryTotals' => array_values($categoryTotals),
            'salespersonTotals' => $salespersonTotals,
            'grandTotal' => [
                'soAmount' => $grandSo,
                'drAmount' => $grandDr,
                'invoiceAmount' => $grandInvoice,
                'total' => $grandDr + $grandInvoice,
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $transactions
     *  @return array<int, array{itemCode:string,partNo:string,brand:string,product:string,total:float}>
     */
    private function buildProductTotals(int $mainId, array $transactions): array
    {
        $invoiceRefs = [];
        $exclusiveInvoiceRefs = [];
        $drRefs = [];
        foreach ($transactions as $transaction) {
            $refno = trim((string) ($transaction['id'] ?? ''));
            if ($refno === '') continue;
            if (($transaction['type'] ?? '') === 'invoice') {
                if (($transaction['vat_type'] ?? null) === 'exclusive') {
                    $exclusiveInvoiceRefs[] = $refno;
                } else {
                    $invoiceRefs[] = $refno;
                }
            } elseif (($transaction['type'] ?? '') === 'dr') {
                $drRefs[] = $refno;
            }
        }

        $totals = [];
        $this->addProductItemTotals($mainId, 'tblinvoice_list', 'tblinvoice_itemrec', 'linvoice_refno', $invoiceRefs, 'invoice', 1.0, $totals);
        $this->addProductItemTotals($mainId, 'tblinvoice_list', 'tblinvoice_itemrec', 'linvoice_refno', $exclusiveInvoiceRefs, 'invoice', 1.12, $totals);
        $this->addProductItemTotals($mainId, 'tbldelivery_receipt', 'tbldelivery_receipt_items', 'lor_refno', $drRefs, 'dr', 1.0, $totals);

        $rows = array_values($totals);
        usort($rows, static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
        return $rows;
    }

    /** @param array<int, string> $refnos
     *  @param array<string, array{itemCode:string,partNo:string,brand:string,product:string,total:float}> $totals
     */
    private function addProductItemTotals(
        int $mainId,
        string $headerTable,
        string $itemTable,
        string $itemRefColumn,
        array $refnos,
        string $documentType,
        float $amountFactor,
        array &$totals
    ): void {
        if ($refnos === []) return;
        $isInvoice = $documentType === 'invoice';
        $postedCondition = $isInvoice
            ? PostedSalesDocumentSql::invoiceIsPosted('d')
            : PostedSalesDocumentSql::deliveryReceiptIsPosted('d');

        foreach (array_chunk(array_values(array_unique($refnos)), 500) as $index => $batch) {
            [$inClause, $bindings] = $this->buildInClause('product_ref_' . $index . '_', $batch);
            $sql = sprintf(
                <<<SQL
SELECT
    COALESCE(TRIM(x.litemcode), '') AS item_code,
    COALESCE(TRIM(x.lpartno), '') AS part_no,
    COALESCE(TRIM(x.lbrand), '') AS brand,
    COALESCE(TRIM(x.ldesc), '') AS description,
    SUM(COALESCE(x.lqty, 0) * COALESCE(x.lprice, 0)) AS amount
FROM %s d
INNER JOIN %s x ON x.%s = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.lrefno IN (%s)
  AND %s
GROUP BY item_code, part_no, brand, description
SQL,
                $headerTable,
                $itemTable,
                $itemRefColumn,
                $inClause,
                $postedCondition
            );
            $statement = $this->db->pdo()->prepare($sql);
            $statement->bindValue('main_id', $mainId, PDO::PARAM_INT);
            foreach ($bindings as $key => $value) {
                $statement->bindValue($key, $value, PDO::PARAM_STR);
            }
            $statement->execute();

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $itemCode = trim((string) ($row['item_code'] ?? ''));
                $partNo = trim((string) ($row['part_no'] ?? ''));
                $brand = trim((string) ($row['brand'] ?? ''));
                $description = trim((string) ($row['description'] ?? ''));
                $product = $description !== '' ? $description : ($partNo !== '' ? $partNo : ($itemCode !== '' ? $itemCode : 'Unnamed product'));
                $key = implode('|', [strtolower($itemCode), strtolower($partNo), strtolower($description), strtolower($brand)]);
                if (!isset($totals[$key])) {
                    $totals[$key] = [
                        'itemCode' => $itemCode,
                        'partNo' => $partNo,
                        'brand' => $brand,
                        'product' => $product,
                        'total' => 0.0,
                    ];
                }
                $totals[$key]['total'] += (float) ($row['amount'] ?? 0) * $amountFactor;
            }
        }
    }

    /** @return array<int, array{id:string,salesperson:string}> */
    private function fetchActiveSalespeople(int $mainId, ?string $agentId): array
    {
        $sql = <<<SQL
SELECT
    CAST(a.lid AS CHAR) AS id,
    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(a.lfname, ''), ' ', COALESCE(a.llname, ''))), ''), 'Unassigned') AS salesperson
FROM tblaccount a
WHERE a.lmother_id = :main_id
  AND a.ltype = '2'
  AND a.lstatus = 1
SQL;
        if ($agentId !== null && trim($agentId) !== '') {
            $sql .= ' AND CAST(a.lid AS CHAR) = :agent_id';
        }
        $sql .= ' ORDER BY salesperson ASC, a.lid ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if ($agentId !== null && trim($agentId) !== '') {
            $stmt->bindValue('agent_id', trim($agentId), PDO::PARAM_STR);
        }
        $stmt->execute();

        return array_values(array_map(
            static fn(array $row): array => [
                'id' => trim((string) ($row['id'] ?? '')),
                'salesperson' => trim((string) ($row['salesperson'] ?? '')),
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        ));
    }

    /**
     * @return array{0:string,1:?string,2:?string}
     */
    private function resolveDateRange(string $dateType, ?string $dateFrom, ?string $dateTo): array
    {
        $type = strtolower(trim($dateType));
        if ($type === '') {
            $type = 'all';
        }

        $today = new DateTimeImmutable('today');

        return match ($type) {
            'today' => ['today', $today->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => ['week', $today->modify('-1 week')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => ['month', $today->format('Y-m-01'), $today->format('Y-m-t')],
            'year' => ['year', $today->modify('-1 year')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => ['custom', $this->normalizeDate((string) $dateFrom), $this->normalizeDate((string) $dateTo)],
            default => ['all', '2013-06-01', $today->format('Y-m-d')],
        };
    }

    private function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }
}
