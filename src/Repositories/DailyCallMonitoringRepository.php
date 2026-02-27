<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class DailyCallMonitoringRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function getExcelRows(int $mainId, string $status = 'all', string $search = ''): array
    {
        $customers = $this->getCustomerBaseRows($mainId, $status, $search);
        if (count($customers) === 0) {
            return [];
        }

        $customerIds = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $customers
        )));

        $monthFrom = date('Y-m-01');
        $purchases = $this->getPurchaseRows($mainId, $customerIds, $monthFrom);
        $callLogs = $this->getCallLogRows($mainId, $customerIds);
        $metrics = $this->getCustomerMetrics($mainId, $customerIds);

        $purchasesByCustomer = [];
        foreach ($purchases as $row) {
            $cid = (string) ($row['contact_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            if (!isset($purchasesByCustomer[$cid])) {
                $purchasesByCustomer[$cid] = [];
            }
            $purchasesByCustomer[$cid][] = $row;
        }

        $logsByCustomer = [];
        foreach ($callLogs as $row) {
            $cid = (string) ($row['contact_id'] ?? '');
            if ($cid === '') {
                continue;
            }
            if (!isset($logsByCustomer[$cid])) {
                $logsByCustomer[$cid] = [];
            }
            $logsByCustomer[$cid][] = $row;
        }

        $rows = [];
        foreach ($customers as $customer) {
            $cid = (string) ($customer['id'] ?? '');
            $customerPurchases = $purchasesByCustomer[$cid] ?? [];
            $monthlyOrder = $this->computeCurrentMonthOrder($customerPurchases);
            $weeklyTotals = $this->computeWeeklyRangeTotals($customerPurchases);
            $dailyActivity = $this->buildDailyActivity($logsByCustomer[$cid] ?? []);

            $metricsRow = $metrics[$cid] ?? null;

            $rows[] = [
                'id' => $cid,
                'source' => $customer['source'] ?: 'Manual',
                'assignedTo' => $customer['assigned_to'] ?: 'Unassigned',
                'assignedDate' => $this->formatDateText($customer['assigned_date']),
                'clientSince' => $this->formatDateText($customer['client_since']),
                'province' => $customer['province'] ?: '—',
                'city' => $customer['city'] ?: '—',
                'shopName' => $customer['shop_name'] ?: 'Unnamed Shop',
                'contactNumber' => $customer['contact_number'] ?: '—',
                'codeDate' => $this->formatCodeDate($customer['code_text'], $customer['code_date']),
                'dealerPriceGroup' => $customer['dealer_price_group'] ?: '',
                'dealerPriceDate' => $this->formatDateText($customer['dealer_price_date']),
                'ishinomotoDealerSince' => $this->formatDateText($customer['dealer_since']),
                'ishinomotoSignageSince' => $this->formatDateText($customer['signage_since']),
                'quota' => (float) ($customer['quota'] ?? 0),
                'terms' => $customer['mode_of_payment'] ?: '—',
                'modeOfPayment' => $customer['mode_of_payment'] ?: '—',
                'courier' => $customer['courier'] ?: '—',
                'status' => $customer['status_label'] ?: 'active',
                'statusDate' => $this->formatDateText($customer['status_date']),
                'outstandingBalance' => (float) ($metricsRow['outstanding_balance'] ?? 0),
                'averageMonthlyOrder' => (float) ($metricsRow['average_monthly_purchase'] ?? 0),
                'monthlyOrder' => $monthlyOrder,
                'weeklyRangeTotals' => $weeklyTotals,
                'dailyActivity' => $dailyActivity,
            ];
        }

        usort($rows, static fn(array $a, array $b) => strcmp((string) $a['shopName'], (string) $b['shopName']));
        return $rows;
    }

    public function getOwnerSnapshot(int $mainId): array
    {
        $contacts = $this->getOwnerContacts($mainId);
        $contactIds = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $contacts
        )));

        return [
            'contacts' => $contacts,
            'callLogs' => $this->getCallLogRows($mainId, $contactIds),
            'purchases' => $this->getPurchaseRows($mainId, $contactIds),
            'inquiries' => $this->getInquiryRows($mainId, $contactIds),
            'returns' => [],
            'deals' => [],
            'profiles' => $this->getProfileRows($mainId),
        ];
    }

    public function getCustomerPurchaseHistory(int $mainId, string $contactId): array
    {
        $sql = <<<SQL
SELECT
    tr.lrefno AS id,
    tr.lcustomerid AS contact_id,
    tr.ldate AS purchase_date,
    tr.invoice_no AS invoice_number,
    tr.lpayment_status AS payment_status,
    tr.lsubmitstat AS submit_status,
    tr.lnote AS notes,
    COALESCE(NULLIF(tr.lamount, 0), (
        SELECT SUM(COALESCE(it.lprice, 0) * COALESCE(it.lqty, 0))
        FROM tbltransaction_item it
        WHERE it.lrefno = tr.lrefno
          AND COALESCE(it.lcancel, 0) = 0
    ), 0) AS total_amount
FROM tbltransaction tr
WHERE tr.lmain_id = :main_id
  AND tr.lcustomerid = :contact_id
  AND COALESCE(tr.lcancel, 0) = 0
ORDER BY tr.ldate DESC, tr.lid DESC
LIMIT 150
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            return [];
        }

        $refnos = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $rows
        )));
        $itemsByRef = $this->getTransactionItemsByRefnos($refnos);

        foreach ($rows as &$row) {
            $ref = (string) ($row['id'] ?? '');
            $row['payment_status'] = $this->normalizePaymentStatus((string) ($row['payment_status'] ?? ''));
            $row['products'] = $itemsByRef[$ref] ?? [];
        }

        return $rows;
    }

    public function getCustomerSalesReports(int $mainId, string $contactId): array
    {
        $sql = <<<SQL
SELECT
    inq.lrefno AS id,
    inq.lcustomerid AS contact_id,
    inq.ldate AS date,
    inq.ltime AS time,
    inq.lsubmitstat AS submit_status,
    CAST(COALESCE(inq.lsales_person_id, inq.lsalesperson) AS CHAR) AS sales_agent,
    inq.lnote AS notes
FROM tblinquiry inq
WHERE inq.lmain_id = :main_id
  AND inq.lcustomerid = :contact_id
  AND COALESCE(inq.IsCancel, 0) = 0
ORDER BY inq.ldate DESC, inq.lid DESC
LIMIT 150
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            return [];
        }

        $refnos = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['id'] ?? ''),
            $rows
        )));
        $itemsByRef = $this->getInquiryItemsByRefnos($refnos);

        foreach ($rows as &$row) {
            $ref = (string) ($row['id'] ?? '');
            $items = $itemsByRef[$ref] ?? [];
            $total = 0.0;
            foreach ($items as $item) {
                $total += ((float) ($item['price'] ?? 0)) * ((float) ($item['quantity'] ?? 0));
            }

            $row['products'] = array_map(
                static fn(array $item): array => [
                    'name' => (string) ($item['name'] ?? ''),
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                ],
                $items
            );
            $row['total_amount'] = round($total, 2);
            $row['approval_status'] = $this->mapApprovalStatus((string) ($row['submit_status'] ?? ''));
        }

        return $rows;
    }

    public function getCustomerIncidentReports(int $mainId, string $contactId): array
    {
        $sql = <<<SQL
SELECT
    CAST(cl.lid AS CHAR) AS id,
    cl.lcustomer_id AS contact_id,
    cl.ldatetime AS report_date,
    cl.ldatetime AS incident_date,
    cl.ltype AS issue_type_raw,
    cl.lstatus AS status_raw,
    cl.lnotes AS description,
    CAST(cl.luser AS CHAR) AS reported_by,
    cl.lfile AS attachment,
    cl.comments AS notes
FROM tblcustomer_logs cl
WHERE cl.lmain_id = :main_id
  AND cl.lcustomer_id = :contact_id
ORDER BY cl.ldatetime DESC, cl.lid DESC
LIMIT 150
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'contact_id' => $contactId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $attachment = trim((string) ($row['attachment'] ?? ''));
            $row['issue_type'] = $this->mapIssueType((string) ($row['issue_type_raw'] ?? ''), (string) ($row['status_raw'] ?? ''));
            $row['approval_status'] = $this->mapIncidentStatus((string) ($row['status_raw'] ?? ''));
            $row['attachments'] = $attachment === '' ? [] : [$attachment];
            $row['related_transactions'] = [];
            if (trim((string) ($row['description'] ?? '')) === '') {
                $row['description'] = 'Customer activity log entry';
            }
        }

        return $rows;
    }

    private function getOwnerContacts(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    p.lsessionid AS id,
    p.lcompany AS company,
    p.lprovince AS province,
    p.lcity AS city,
    CAST(p.lsales_person AS CHAR) AS assignedAgent,
    CAST(p.lsales_person AS CHAR) AS salesman,
    CASE
        WHEN LOWER(COALESCE(p.lprofile_type, '')) LIKE '%prospective%' THEN 'prospective'
        WHEN LOWER(COALESCE(p.lactive, '')) LIKE '%inactive%' OR COALESCE(p.lstatus, 1) = 0 THEN 'inactive'
        ELSE 'active'
    END AS status,
    p.llast_transaction AS lastContactDate,
    p.ldatetime AS created_at,
    COALESCE(p.lcredit, 0) AS creditLimit,
    COALESCE((
        SELECT SUM(COALESCE(lg.ldebit, 0) - COALESCE(lg.lcredit, 0))
        FROM tblledger lg
        WHERE lg.lcustomerid = p.lsessionid
    ), 0) AS balance,
    0 AS is_deleted
FROM tblpatient p
WHERE p.lmain_id = :main_id
ORDER BY p.lid DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['main_id' => $mainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getCustomerBaseRows(int $mainId, string $status, string $search): array
    {
        $sql = <<<SQL
SELECT
    p.lsessionid AS id,
    p.lcompany AS shop_name,
    p.lprovince AS province,
    p.lcity AS city,
    p.lmobile AS mobile,
    p.lphone AS phone,
    p.lterms AS mode_of_payment,
    p.ldelivery_address AS courier,
    p.ldealer_quota AS quota,
    p.ldealer_since AS dealer_since,
    p.ldealer_since AS dealer_price_date,
    NULL AS signage_since,
    p.lprice_group AS code_text,
    p.lprice_group AS dealer_price_group,
    p.ldealer_since AS code_date,
    p.lrefer_by AS source,
    COALESCE(a.lfname, '') AS assigned_to_fname,
    COALESCE(a.llname, '') AS assigned_to_lname,
    p.ldate_assigned AS assigned_date,
    p.lsince AS client_since,
    p.ldatetime AS status_date,
    CASE
        WHEN LOWER(COALESCE(p.lprofile_type, '')) LIKE '%prospective%' THEN 'prospective'
        WHEN LOWER(COALESCE(p.lactive, '')) LIKE '%inactive%' OR COALESCE(p.lstatus, 1) = 0 THEN 'inactive'
        ELSE 'active'
    END AS status_label
FROM tblpatient p
LEFT JOIN tblaccount a ON CAST(a.lid AS CHAR) = CAST(p.lsales_person AS CHAR)
WHERE p.lmain_id = :main_id
SQL;
        $params = ['main_id' => $mainId];

        $statusLower = strtolower(trim($status));
        if ($statusLower !== '' && $statusLower !== 'all') {
            if ($statusLower === 'active') {
                $sql .= " AND NOT (LOWER(COALESCE(p.lprofile_type, '')) LIKE '%prospective%')
                          AND NOT (LOWER(COALESCE(p.lactive, '')) LIKE '%inactive%' OR COALESCE(p.lstatus, 1) = 0)";
            } elseif ($statusLower === 'inactive') {
                $sql .= " AND (LOWER(COALESCE(p.lactive, '')) LIKE '%inactive%' OR COALESCE(p.lstatus, 1) = 0)";
            } elseif ($statusLower === 'prospective') {
                $sql .= " AND LOWER(COALESCE(p.lprofile_type, '')) LIKE '%prospective%'";
            }
        }

        if (trim($search) !== '') {
            $sql .= " AND (
                p.lcompany LIKE :search_company
                OR p.lcity LIKE :search_city
                OR p.lprovince LIKE :search_province
                OR p.lmobile LIKE :search_mobile
                OR p.lphone LIKE :search_phone
                OR p.lpatient_code LIKE :search_code
            )";
            $searchValue = '%' . trim($search) . '%';
            $params['search_company'] = $searchValue;
            $params['search_city'] = $searchValue;
            $params['search_province'] = $searchValue;
            $params['search_mobile'] = $searchValue;
            $params['search_phone'] = $searchValue;
            $params['search_code'] = $searchValue;
        }

        $sql .= ' ORDER BY p.lcompany ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $assigned = trim(($row['assigned_to_fname'] ?? '') . ' ' . ($row['assigned_to_lname'] ?? ''));
            $row['assigned_to'] = $assigned;
            $row['contact_number'] = (string) ($row['mobile'] ?: $row['phone'] ?: '');
        }

        return $rows;
    }

    private function getPurchaseRows(int $mainId, array $contactIds, ?string $dateFrom = null): array
    {
        if (count($contactIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $sql = <<<SQL
SELECT
    tr.lrefno AS id,
    tr.lcustomerid AS contact_id,
    COALESCE(NULLIF(tr.lamount, 0), COALESCE(its.total_amount, 0), 0) AS total_amount,
    tr.ldate AS purchase_date
FROM tbltransaction tr
LEFT JOIN (
    SELECT it.lrefno, SUM(COALESCE(it.lprice, 0) * COALESCE(it.lqty, 0)) AS total_amount
    FROM tbltransaction_item it
    WHERE COALESCE(it.lcancel, 0) = 0
    GROUP BY it.lrefno
) its ON its.lrefno = tr.lrefno
WHERE tr.lmain_id = ?
  AND tr.lcustomerid IN ({$placeholders})
  AND COALESCE(tr.lcancel, 0) = 0
  AND COALESCE(tr.lsubmitstat, '') IN ('Approved', 'Posted', 'Submitted')
SQL;
        $params = array_merge([$mainId], $contactIds);
        if ($dateFrom !== null && trim($dateFrom) !== '') {
            $sql .= ' AND tr.ldate >= ?';
            $params[] = $dateFrom;
        }
        $sql .= ' ';
        $sql .= <<<SQL
ORDER BY tr.ldate DESC, tr.lid DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getInquiryRows(int $mainId, array $contactIds): array
    {
        if (count($contactIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $sql = <<<SQL
SELECT
    inq.lrefno AS id,
    inq.lcustomerid AS contact_id,
    CAST(COALESCE(inq.lsales_person_id, inq.lsalesperson) AS CHAR) AS sales_person,
    inq.lsubmitstat AS status,
    inq.ldate AS sales_date,
    COALESCE(inq.IsCancel, 0) AS is_deleted
FROM tblinquiry inq
WHERE inq.lmain_id = ?
  AND inq.lcustomerid IN ({$placeholders})
ORDER BY inq.ldate DESC, inq.lid DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $params = array_merge([$mainId], $contactIds);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getProfileRows(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(lid AS CHAR) AS id, TRIM(CONCAT(COALESCE(lfname, \'\'), \' \', COALESCE(llname, \'\'))) AS full_name, COALESCE(lsales_quota, 0) AS monthly_quota
             FROM tblaccount
             WHERE CAST(COALESCE(lmother_id, 0) AS SIGNED) = :main_id_1 OR CAST(lid AS SIGNED) = :main_id_2'
        );
        $stmt->execute(['main_id_1' => $mainId, 'main_id_2' => $mainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getCallLogRows(int $mainId, array $contactIds): array
    {
        if (count($contactIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $sql = <<<SQL
SELECT
    CAST(cl.lid AS CHAR) AS id,
    cl.lcustomer_id AS contact_id,
    CAST(cl.luser AS CHAR) AS agent_name,
    CASE
        WHEN LOWER(COALESCE(cl.ltype, '')) LIKE '%text%' OR LOWER(COALESCE(cl.ltype, '')) LIKE '%sms%' THEN 'text'
        ELSE 'call'
    END AS channel,
    cl.lstatus AS outcome,
    cl.ldatetime AS occurred_at
FROM tblcustomer_logs cl
WHERE cl.lmain_id = ?
  AND cl.lcustomer_id IN ({$placeholders})
ORDER BY cl.ldatetime DESC, cl.lid DESC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $params = array_merge([$mainId], $contactIds);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getCustomerMetrics(int $mainId, array $contactIds): array
    {
        $result = [];
        if (count($contactIds) === 0) {
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        $ledgerSql = <<<SQL
SELECT
    lg.lcustomerid AS contact_id,
    SUM(COALESCE(lg.ldebit, 0) - COALESCE(lg.lcredit, 0)) AS outstanding_balance
FROM tblledger lg
WHERE lg.lcustomerid IN ({$placeholders})
GROUP BY lg.lcustomerid
SQL;
        $stmt = $this->db->pdo()->prepare($ledgerSql);
        $stmt->execute($contactIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $result[(string) $row['contact_id']] = [
                'outstanding_balance' => (float) ($row['outstanding_balance'] ?? 0),
                'average_monthly_purchase' => 0.0,
            ];
        }

        $avgSql = <<<SQL
SELECT
    monthly.customer_id AS contact_id,
    AVG(monthly.month_total) AS average_monthly_purchase
FROM (
    SELECT
        tr.lcustomerid AS customer_id,
        DATE_FORMAT(tr.ldate, '%Y-%m') AS ym,
        SUM(COALESCE(NULLIF(tr.lamount, 0), COALESCE(its.total_amount, 0), 0)) AS month_total
    FROM tbltransaction tr
    LEFT JOIN (
        SELECT it.lrefno, SUM(COALESCE(it.lprice, 0) * COALESCE(it.lqty, 0)) AS total_amount
        FROM tbltransaction_item it
        WHERE COALESCE(it.lcancel, 0) = 0
        GROUP BY it.lrefno
    ) its ON its.lrefno = tr.lrefno
    WHERE tr.lmain_id = ?
      AND tr.lcustomerid IN ({$placeholders})
      AND COALESCE(tr.lcancel, 0) = 0
      AND COALESCE(tr.lsubmitstat, '') IN ('Approved', 'Posted', 'Submitted')
    GROUP BY tr.lcustomerid, DATE_FORMAT(tr.ldate, '%Y-%m')
) monthly
GROUP BY monthly.customer_id
SQL;
        $avgStmt = $this->db->pdo()->prepare($avgSql);
        $avgStmt->execute(array_merge([$mainId], $contactIds));
        $avgRows = $avgStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($avgRows as $row) {
            $contactId = (string) ($row['contact_id'] ?? '');
            if ($contactId === '') {
                continue;
            }
            if (!isset($result[$contactId])) {
                $result[$contactId] = [
                    'outstanding_balance' => 0.0,
                    'average_monthly_purchase' => 0.0,
                ];
            }
            $result[$contactId]['average_monthly_purchase'] = (float) ($row['average_monthly_purchase'] ?? 0);
        }

        return $result;
    }

    private function computeCurrentMonthOrder(array $purchaseRows): float
    {
        $month = date('Y-m');
        $sum = 0.0;
        foreach ($purchaseRows as $row) {
            $date = (string) ($row['purchase_date'] ?? '');
            if ($date === '' || strpos($date, $month) !== 0) {
                continue;
            }
            $sum += (float) ($row['total_amount'] ?? 0);
        }
        return $sum;
    }

    private function computeWeeklyRangeTotals(array $purchaseRows): array
    {
        $buckets = $this->getWeeklyRangeBuckets();
        $totals = array_fill(0, count($buckets), 0.0);

        foreach ($purchaseRows as $row) {
            $dateText = (string) ($row['purchase_date'] ?? '');
            if ($dateText === '') {
                continue;
            }
            $ts = strtotime($dateText);
            if ($ts === false) {
                continue;
            }
            $year = (int) date('Y', $ts);
            $month = (int) date('n', $ts) - 1;
            $day = (int) date('j', $ts);
            $amt = (float) ($row['total_amount'] ?? 0);

            foreach ($buckets as $index => $bucket) {
                if ($year !== $bucket['year'] || $month !== $bucket['month']) {
                    continue;
                }
                if ($day >= $bucket['startDay'] && $day <= $bucket['endDay']) {
                    $totals[$index] += $amt;
                }
            }
        }

        return array_map(static fn(float $v): float => round($v, 2), $totals);
    }

    private function buildDailyActivity(array $logs): array
    {
        $map = [];
        foreach ($logs as $log) {
            $occurred = (string) ($log['occurred_at'] ?? '');
            if ($occurred === '') {
                continue;
            }
            $ts = strtotime($occurred);
            if ($ts === false) {
                continue;
            }
            $key = date('Y-m-d', $ts);
            $channel = strtolower((string) ($log['channel'] ?? 'call'));
            $activityType = $channel === 'text' ? 'text' : 'call';

            if (!isset($map[$key])) {
                $map[$key] = [
                    'id' => (string) ($log['id'] ?? '') . '-' . $key,
                    'contact_id' => (string) ($log['contact_id'] ?? ''),
                    'activity_date' => $key,
                    'activity_type' => $activityType,
                    'activity_count' => 1,
                    'notes' => null,
                ];
                continue;
            }

            $map[$key]['activity_count'] = ((int) $map[$key]['activity_count']) + 1;
            $map[$key]['activity_type'] = ($map[$key]['activity_type'] === 'call' || $activityType === 'call') ? 'call' : 'text';
        }

        $rows = array_values($map);
        usort($rows, static fn(array $a, array $b) => strcmp((string) $b['activity_date'], (string) $a['activity_date']));
        return $rows;
    }

    private function getWeeklyRangeBuckets(): array
    {
        $year = (int) date('Y');
        $month = (int) date('n') - 1;
        $daysInMonth = (int) date('t');

        $buckets = [];
        $rangeStart = null;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $ts = strtotime(sprintf('%d-%02d-%02d', $year, $month + 1, $day));
            $dow = (int) date('w', $ts);
            $isSunday = $dow === 0;
            $isSaturday = $dow === 6;

            if ($isSunday) {
                if ($rangeStart !== null) {
                    $buckets[] = [
                        'startDay' => $rangeStart,
                        'endDay' => $day - 1,
                        'month' => $month,
                        'year' => $year,
                    ];
                    $rangeStart = null;
                }
                continue;
            }

            if ($rangeStart === null) {
                $rangeStart = $day;
            }

            if ($isSaturday || $day === $daysInMonth) {
                $buckets[] = [
                    'startDay' => $rangeStart,
                    'endDay' => $day,
                    'month' => $month,
                    'year' => $year,
                ];
                $rangeStart = null;
            }
        }

        return $buckets;
    }

    private function formatDateText(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }
        return date('M j, Y', $ts);
    }

    private function formatCodeDate(?string $codeText, ?string $codeDate): string
    {
        $trimmedText = trim((string) $codeText);
        $formattedDate = $this->formatDateText($codeDate);

        if ($trimmedText !== '' && $formattedDate !== '—') {
            return $trimmedText . ' (' . $formattedDate . ')';
        }
        if ($trimmedText !== '') {
            return $trimmedText;
        }
        if ($formattedDate !== '—') {
            return $formattedDate;
        }
        return '—';
    }

    private function getTransactionItemsByRefnos(array $refnos): array
    {
        if (count($refnos) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($refnos), '?'));
        $sql = <<<SQL
SELECT
    it.lrefno AS refno,
    COALESCE(NULLIF(it.ldesc, ''), NULLIF(it.lname, ''), NULLIF(it.lpartno, ''), NULLIF(it.litemcode, ''), 'Item') AS item_name,
    COALESCE(it.lqty, 0) AS qty,
    COALESCE(it.lprice, 0) AS price
FROM tbltransaction_item it
WHERE it.lrefno IN ({$placeholders})
  AND COALESCE(it.lcancel, 0) = 0
ORDER BY it.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($refnos);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['refno'] ?? '');
            if ($ref === '') {
                continue;
            }
            if (!isset($grouped[$ref])) {
                $grouped[$ref] = [];
            }
            $grouped[$ref][] = [
                'name' => (string) ($row['item_name'] ?? 'Item'),
                'quantity' => (float) ($row['qty'] ?? 0),
                'price' => (float) ($row['price'] ?? 0),
            ];
        }

        return $grouped;
    }

    private function getInquiryItemsByRefnos(array $refnos): array
    {
        if (count($refnos) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($refnos), '?'));
        $sql = <<<SQL
SELECT
    ii.linq_refno AS refno,
    COALESCE(NULLIF(ii.ldesc, ''), NULLIF(ii.lpartno, ''), NULLIF(ii.litem_code, ''), 'Item') AS item_name,
    COALESCE(ii.lqty, 0) AS qty,
    COALESCE(ii.lprice, 0) AS price
FROM tblinquiry_item ii
WHERE ii.linq_refno IN ({$placeholders})
ORDER BY ii.lid ASC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($refnos);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $ref = (string) ($row['refno'] ?? '');
            if ($ref === '') {
                continue;
            }
            if (!isset($grouped[$ref])) {
                $grouped[$ref] = [];
            }
            $grouped[$ref][] = [
                'name' => (string) ($row['item_name'] ?? 'Item'),
                'quantity' => (float) ($row['qty'] ?? 0),
                'price' => (float) ($row['price'] ?? 0),
            ];
        }

        return $grouped;
    }

    private function normalizePaymentStatus(string $value): string
    {
        $v = strtolower(trim($value));
        if ($v === 'paid' || $v === 'payment' || $v === 'fully paid') {
            return 'paid';
        }
        if ($v === 'overdue' || $v === 'past due') {
            return 'overdue';
        }
        return 'pending';
    }

    private function mapApprovalStatus(string $value): string
    {
        $v = strtolower(trim($value));
        if (str_contains($v, 'approved') || str_contains($v, 'posted') || str_contains($v, 'submit')) {
            return 'approved';
        }
        if (str_contains($v, 'cancel') || str_contains($v, 'reject')) {
            return 'rejected';
        }
        return 'pending';
    }

    private function mapIssueType(string $type, string $status): string
    {
        $v = strtolower(trim($type . ' ' . $status));
        if (str_contains($v, 'product') || str_contains($v, 'item') || str_contains($v, 'quality')) {
            return 'product_quality';
        }
        if (str_contains($v, 'deliver') || str_contains($v, 'ship') || str_contains($v, 'courier')) {
            return 'delivery';
        }
        if (str_contains($v, 'service') || str_contains($v, 'call') || str_contains($v, 'follow')) {
            return 'service_quality';
        }
        return 'other';
    }

    private function mapIncidentStatus(string $value): string
    {
        $v = strtolower(trim($value));
        if (str_contains($v, 'resolved') || str_contains($v, 'approved') || str_contains($v, 'done')) {
            return 'approved';
        }
        if (str_contains($v, 'reject') || str_contains($v, 'cancel')) {
            return 'rejected';
        }
        return 'pending';
    }
}
