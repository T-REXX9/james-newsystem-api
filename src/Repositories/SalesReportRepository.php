<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
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
        int $limit = 1200
    ): array {
        [$normalizedDateType, $fromDate, $toDate] = $this->resolveDateRange($dateType, $dateFrom, $dateTo);

        $invoiceRows = $this->fetchInvoiceRows($mainId, $fromDate, $toDate, $customerId, $limit);
        $drRows = $this->fetchDrRows($mainId, $fromDate, $toDate, $customerId, $limit);

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

        $summary = $this->buildSummary($transactions);

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
    COALESCE(i.lcategory, 'Uncategorized') AS category
FROM tblinvoice_list d
INNER JOIN tblinvoice_itemrec i ON i.linvoice_refno = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.lrefno = :refno
  AND COALESCE(d.lcancel, '') = ''
ORDER BY i.lid ASC
SQL;
        } else {
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
    COALESCE(i.lcategory, 'Uncategorized') AS category
FROM tbldelivery_receipt d
INNER JOIN tbldelivery_receipt_items i ON i.lor_refno = d.lrefno
WHERE d.lmain_id = :main_id
  AND d.lrefno = :refno
  AND COALESCE(d.lcancel, '') = ''
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
                'category' => (string) ($row['category'] ?? 'Uncategorized'),
            ],
            $rows
        );
    }

    private function fetchInvoiceRows(
        int $mainId,
        ?string $fromDate,
        ?string $toDate,
        ?string $customerId,
        int $limit
    ): array {
        $where = [
            'l.lmain_id = :main_id',
            'COALESCE(l.lcancel, \'\') = \'\'',
        ];

        $params = ['main_id' => $mainId];

        if ($fromDate !== null && $toDate !== null) {
            $where[] = 'DATE(l.ldatetime) >= :date_from';
            $where[] = 'DATE(l.ldatetime) <= :date_to';
            $params['date_from'] = $fromDate;
            $params['date_to'] = $toDate;
        }

        $trimmedCustomerId = trim((string) $customerId);
        if ($trimmedCustomerId !== '' && strtolower($trimmedCustomerId) !== 'all') {
            $where[] = 'l.lcustomerid = :customer_id';
            $params['customer_id'] = $trimmedCustomerId;
        }

        $sql = sprintf(
            <<<SQL
SELECT
    COALESCE(l.lrefno, '') AS id,
    DATE(l.ldatetime) AS `date`,
    TRIM(COALESCE(l.lcustomer_name, '')) AS customer,
    COALESCE(l.lcustomerid, '') AS customer_id,
    COALESCE(l.lterms, '') AS terms,
    COALESCE(l.linvoice_no, '') AS ref_no,
    COALESCE(l.lsales_refno, '') AS sales_refno,
    COALESCE(l.ltax_type, '') AS ltax_type,
    COALESCE(l.lsales_person, '') AS salesperson,
    l.lid AS sort_id
FROM tblinvoice_list l
WHERE %s
ORDER BY DATE(l.ldatetime) DESC, l.lid DESC
LIMIT :limit
SQL,
            implode(' AND ', $where)
        );

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
            $item = $invoiceAgg[$id] ?? ['amount' => 0.0, 'category' => 'Uncategorized'];
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
                'salesperson' => (string) ($doc['salesperson'] ?? ''),
                'category' => (string) ($item['category'] ?? 'Uncategorized'),
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
        int $limit
    ): array {
        $where = [
            'l.lmain_id = :main_id',
            'COALESCE(l.lcancel, \'\') = \'\'',
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

        $sql = sprintf(
            <<<SQL
SELECT
    COALESCE(l.lrefno, '') AS id,
    l.ldate AS `date`,
    TRIM(COALESCE(l.lcustomer_name, '')) AS customer,
    COALESCE(l.lcustomerid, '') AS customer_id,
    COALESCE(l.lterms, '') AS terms,
    COALESCE(l.linvoice_no, '') AS ref_no,
    COALESCE(l.lsales_refno, '') AS sales_refno,
    COALESCE(l.ltax_type, '') AS ltax_type,
    COALESCE(l.lsales_person, '') AS salesperson,
    l.lid AS sort_id
FROM tbldelivery_receipt l
WHERE %s
ORDER BY l.ldate DESC, l.lid DESC
LIMIT :limit
SQL,
            implode(' AND ', $where)
        );

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
            $item = $drAgg[$id] ?? ['amount' => 0.0, 'category' => 'Uncategorized'];
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
                'salesperson' => (string) ($doc['salesperson'] ?? ''),
                'category' => (string) ($item['category'] ?? 'Uncategorized'),
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
    GROUP_CONCAT(DISTINCT NULLIF(TRIM(x.lcategory), '') SEPARATOR '|') AS categories
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
            $category = 'Uncategorized';
            if ($categories !== '') {
                $category = str_contains($categories, '|') ? 'Mixed' : $categories;
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
    SUM(COALESCE(i.lqty, 0) * COALESCE(i.lprice, 0)) AS so_amount
FROM tbltransaction t
LEFT JOIN tbltransaction_item i
  ON i.lrefno = t.lrefno
 AND COALESCE(i.lcancel, 0) = 0
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
            $mapped[$ref] = [
                'so_no' => (string) ($row['so_no'] ?? ''),
                'so_amount' => (float) ($row['so_amount'] ?? 0),
            ];
        }

        return $mapped;
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

    private function buildSummary(array $transactions): array
    {
        $categoryTotals = [];
        $salespersonBuckets = [];

        $grandSo = 0.0;
        $grandDr = 0.0;
        $grandInvoice = 0.0;

        foreach ($transactions as $tx) {
            $category = (string) ($tx['category'] ?? 'Uncategorized');
            $salesperson = trim((string) ($tx['salesperson'] ?? ''));
            if ($salesperson === '') {
                $salesperson = 'Unassigned';
            }

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

            if (!isset($salespersonBuckets[$salesperson])) {
                $salespersonBuckets[$salesperson] = [];
            }
            if (!isset($salespersonBuckets[$salesperson][$category])) {
                $salespersonBuckets[$salesperson][$category] = [
                    'category' => $category,
                    'soAmount' => 0.0,
                    'drAmount' => 0.0,
                    'invoiceAmount' => 0.0,
                ];
            }
            $salespersonBuckets[$salesperson][$category]['soAmount'] += $so;
            $salespersonBuckets[$salesperson][$category]['drAmount'] += $dr;
            $salespersonBuckets[$salesperson][$category]['invoiceAmount'] += $invoice;
        }

        ksort($categoryTotals);

        $salespersonTotals = [];
        foreach ($salespersonBuckets as $salesperson => $categories) {
            $categoriesList = array_values($categories);
            usort(
                $categoriesList,
                static fn(array $a, array $b): int => strcmp((string) $a['category'], (string) $b['category'])
            );

            $total = 0.0;
            foreach ($categoriesList as $entry) {
                $total += (float) $entry['soAmount'] + (float) $entry['drAmount'] + (float) $entry['invoiceAmount'];
            }

            $salespersonTotals[] = [
                'salesperson' => $salesperson,
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
                'total' => $grandSo + $grandDr + $grandInvoice,
            ],
        ];
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
            default => ['all', null, null],
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
