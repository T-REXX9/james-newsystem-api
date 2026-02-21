<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use DateTimeImmutable;
use PDO;

final class InquiryReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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

        $params = ['main_id' => (string) $mainId];
        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $sql .= ' AND ('
                . 'TRIM(COALESCE(p.lcompany, \'\')) LIKE :search_company '
                . 'OR COALESCE(p.lpatient_code, \'\') LIKE :search_code '
                . 'OR COALESCE(p.lsessionid, \'\') LIKE :search_session'
                . ')';
            $params['search_company'] = '%' . $trimmedSearch . '%';
            $params['search_code'] = '%' . $trimmedSearch . '%';
            $params['search_session'] = '%' . $trimmedSearch . '%';
        }

        $sql .= ' ORDER BY p.lcompany ASC, p.lid DESC LIMIT :limit';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', (string) $mainId, PDO::PARAM_STR);
        if (isset($params['search_company'])) {
            $stmt->bindValue('search_company', (string) $params['search_company'], PDO::PARAM_STR);
            $stmt->bindValue('search_code', (string) $params['search_code'], PDO::PARAM_STR);
            $stmt->bindValue('search_session', (string) $params['search_session'], PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', max(1, min(2000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInquiryReport(
        int $mainId,
        string $mode,
        string $dateType,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $customerId,
        int $limit = 500
    ): array {
        [$normalizedDateType, $fromDate, $toDate] = $this->resolveDateRange($dateType, $dateFrom, $dateTo);
        $normalizedMode = strtolower(trim($mode)) === 'detailed' ? 'detailed' : 'summary';

        $where = [
            'tr.lmain_id = :main_id',
            'COALESCE(tr.IsInquiry, 0) = 1',
            'tr.ldate >= :date_from',
            'tr.ldate <= :date_to',
        ];
        $params = [
            'main_id' => (string) $mainId,
            'date_from' => (string) $fromDate,
            'date_to' => (string) $toDate,
        ];

        $trimmedCustomerId = trim((string) $customerId);
        if ($trimmedCustomerId !== '' && strtolower($trimmedCustomerId) !== 'all') {
            $where[] = 'tr.lcustomerid = :customer_id';
            $params['customer_id'] = $trimmedCustomerId;
        }

        $sql = sprintf(
            'SELECT
                tr.lid,
                COALESCE(tr.lrefno, \'\') AS inquiry_refno,
                COALESCE(tr.lsaleno, \'\') AS inquiry_no,
                COALESCE(tr.lcustomerid, \'\') AS customer_id,
                TRIM(COALESCE(tr.lcompany, \'\')) AS customer_company,
                COALESCE(tr.ldate, \'\') AS sales_date,
                COALESCE(tr.ltime, \'\') AS sales_time,
                COALESCE(MAX(it.ldatetime), CONCAT(tr.ldate, \' 00:00:00\')) AS created_at,
                COALESCE(SUM(COALESCE(it.lqty, 0) * COALESCE(it.lprice, 0)), 0) AS grand_total,
                COUNT(it.lid) AS item_count
             FROM tbltransaction tr
             LEFT JOIN tbltransaction_item it
                ON it.lrefno = tr.lrefno
               AND COALESCE(it.lcancel, 0) = 0
             WHERE %s
             GROUP BY tr.lid, tr.lrefno, tr.lsaleno, tr.lcustomerid, tr.lcompany, tr.ldate, tr.ltime
             ORDER BY tr.ldate DESC, tr.lid DESC
             LIMIT :limit',
            implode(' AND ', $where)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', max(1, min(5000, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $inquiries = [];
        $refnos = [];
        $totalAmount = 0.0;

        foreach ($rows as $row) {
            $ref = (string) ($row['inquiry_refno'] ?? '');
            if ($ref !== '') {
                $refnos[] = $ref;
            }

            $grandTotal = (float) ($row['grand_total'] ?? 0);
            $totalAmount += $grandTotal;

            $inquiries[] = [
                'id' => (string) ($row['lid'] ?? ''),
                'inquiry_refno' => $ref,
                'inquiry_no' => (string) ($row['inquiry_no'] ?? ''),
                'customer_id' => (string) ($row['customer_id'] ?? ''),
                'customer_company' => (string) ($row['customer_company'] ?? ''),
                'sales_date' => (string) ($row['sales_date'] ?? ''),
                'sales_time' => (string) ($row['sales_time'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'grand_total' => $grandTotal,
                'item_count' => (int) ($row['item_count'] ?? 0),
                'items' => [],
            ];
        }

        if ($normalizedMode === 'detailed' && $refnos !== []) {
            $itemsByRef = $this->getInquiryItemsByRefnos($refnos);
            foreach ($inquiries as $index => $row) {
                $ref = (string) ($row['inquiry_refno'] ?? '');
                $inquiries[$index]['items'] = $itemsByRef[$ref] ?? [];
            }
        }

        return [
            'mode' => $normalizedMode,
            'date_type' => $normalizedDateType,
            'date_from' => $fromDate,
            'date_to' => $toDate,
            'filters' => [
                'customer_id' => $trimmedCustomerId,
            ],
            'summary' => [
                'total_inquiries' => count($inquiries),
                'total_amount' => $totalAmount,
                'average_amount' => count($inquiries) > 0 ? ($totalAmount / count($inquiries)) : 0.0,
            ],
            'items' => $inquiries,
        ];
    }

    /**
     * @param array<int, string> $refnos
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function getInquiryItemsByRefnos(array $refnos): array
    {
        $uniqueRefnos = array_values(array_unique(array_filter($refnos, static fn(string $v): bool => $v !== '')));
        if ($uniqueRefnos === []) {
            return [];
        }

        $bindings = [];
        $placeholders = [];
        foreach ($uniqueRefnos as $idx => $refno) {
            $key = 'ref' . $idx;
            $bindings[$key] = $refno;
            $placeholders[] = ':' . $key;
        }

        $sql = sprintf(
            'SELECT
                i.lrefno AS inquiry_refno,
                COALESCE(i.lqty, 0) AS qty,
                COALESCE(i.litemcode, \'\') AS item_code,
                COALESCE(i.lpartno, \'\') AS part_no,
                COALESCE(i.lbrand, \'\') AS brand,
                COALESCE(i.ldesc, \'\') AS description,
                COALESCE(i.lprice, 0) AS unit_price,
                COALESCE(i.lremark, \'\') AS remark
             FROM tbltransaction_item i
             WHERE i.lrefno IN (%s)
               AND COALESCE(i.lcancel, 0) = 0
             ORDER BY i.lid ASC',
            implode(', ', $placeholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mapped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['inquiry_refno'] ?? '');
            if ($ref === '') {
                continue;
            }
            if (!isset($mapped[$ref])) {
                $mapped[$ref] = [];
            }

            $mapped[$ref][] = [
                'qty' => (int) ($row['qty'] ?? 0),
                'item_code' => (string) ($row['item_code'] ?? ''),
                'part_no' => (string) ($row['part_no'] ?? ''),
                'brand' => (string) ($row['brand'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'remark' => (string) ($row['remark'] ?? ''),
            ];
        }

        return $mapped;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function resolveDateRange(string $dateType, ?string $dateFrom, ?string $dateTo): array
    {
        $type = strtolower(trim($dateType));
        if ($type === '') {
            $type = 'today';
        }

        $today = new DateTimeImmutable('today');

        return match ($type) {
            'today' => ['today', $today->format('Y-m-d'), $today->format('Y-m-d')],
            'week' => ['week', $today->modify('-1 week')->format('Y-m-d'), $today->format('Y-m-d')],
            'month' => ['month', $today->modify('-1 month')->format('Y-m-d'), $today->format('Y-m-d')],
            'year' => ['year', $today->modify('-1 year')->format('Y-m-d'), $today->format('Y-m-d')],
            'custom' => [
                'custom',
                $this->normalizeDate($dateFrom) ?? $today->format('Y-m-d'),
                $this->normalizeDate($dateTo) ?? $today->format('Y-m-d'),
            ],
            default => ['today', $today->format('Y-m-d'), $today->format('Y-m-d')],
        };
    }

    private function normalizeDate(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '' || $trimmed === '0000-00-00') {
            return null;
        }

        $ts = strtotime($trimmed);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
