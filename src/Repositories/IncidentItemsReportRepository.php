<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class IncidentItemsReportRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Create or refresh the item projection for a customer incident.
     *
     * The existing table has no unique key for incident_report_id, so the
     * lookup/update prevents duplicate rows when a client retries a sync.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(int $mainId, ?int $userId, array $payload): array
    {
        $pdo = $this->db->pdo();
        $incidentReportId = trim((string) ($payload['incident_report_id'] ?? ''));
        $existingStmt = $pdo->prepare(
            'SELECT id FROM incident_report_items
             WHERE main_id = :main_id AND incident_report_id = :incident_report_id
             LIMIT 1'
        );
        $existingStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $existingStmt->bindValue('incident_report_id', $incidentReportId, PDO::PARAM_STR);
        $existingStmt->execute();
        $existingId = $existingStmt->fetchColumn();

        $nullableString = static function (mixed $value): ?string {
            $value = trim((string) ($value ?? ''));
            return $value === '' ? null : $value;
        };
        $productId = $nullableString($payload['product_id'] ?? null);
        $itemCode = $nullableString($payload['item_code'] ?? null);
        $partNo = $nullableString($payload['part_no'] ?? null);
        $issueType = strtolower(trim((string) ($payload['issue_type'] ?? 'other')));
        $requiresPurchasedItem = in_array($issueType, ['product_quality', 'delivery'], true)
            || $productId !== null
            || $itemCode !== null
            || $partNo !== null;
        if ($requiresPurchasedItem) {
            $customers = new CustomerRepository($this->db);
            $customers->assertCustomerPurchasedItem(
                (string) ($payload['contact_id'] ?? ''),
                (string) ($itemCode ?? ''),
                (string) ($partNo ?? '')
            );
        }
        $supplierId = $nullableString($payload['supplier_id'] ?? null);
        $supplierName = $nullableString($payload['supplier_name'] ?? null);
        $contactId = $nullableString($payload['contact_id'] ?? null);
        $quantity = $payload['quantity'] ?? null;
        $quantityValue = $quantity === null || $quantity === '' ? null : (float) $quantity;
        $confidence = min(1, max(0, (float) ($payload['confidence_score'] ?? 1)));
        // #region agent log
        $this->debugLog('B', 'IncidentItemsReportRepository.php:create', 'storing incident item confidence', [
            'payload_has_confidence_score' => array_key_exists('confidence_score', $payload),
            'payload_confidence_score' => $payload['confidence_score'] ?? null,
            'stored_confidence' => $confidence,
            'match_source' => 'manual',
            'has_product_id' => $productId !== null,
            'has_item_code' => $itemCode !== null,
            'has_part_no' => $partNo !== null,
            'issue_type' => $issueType,
        ]);
        // #endregion
        $metadata = json_encode([
            'source' => 'customer_incident_report',
            'issue_type' => trim((string) ($payload['issue_type'] ?? 'other')),
            'report_date' => trim((string) ($payload['report_date'] ?? '')),
            'sync_version' => 1,
        ], JSON_THROW_ON_ERROR);

        $values = [
            'main_id' => $mainId,
            'incident_report_id' => $incidentReportId,
            'contact_id' => $contactId,
            'product_id' => $productId,
            'item_code' => $itemCode,
            'part_no' => $partNo,
            'description' => trim((string) ($payload['description'] ?? '')),
            'supplier_id' => $supplierId,
            'supplier_name' => $supplierName,
            'quantity' => $quantityValue,
            'issue_summary' => trim((string) ($payload['issue_summary'] ?? $payload['description'] ?? '')),
            'match_source' => 'manual',
            'confidence_score' => $confidence,
            'metadata' => $metadata,
            'created_by_user_id' => $userId,
        ];

        if ($existingId !== false) {
            $sql = 'UPDATE incident_report_items SET
                contact_id = :contact_id,
                product_id = :product_id,
                item_code = :item_code,
                part_no = :part_no,
                description = :description,
                supplier_id = :supplier_id,
                supplier_name = :supplier_name,
                quantity = :quantity,
                issue_summary = :issue_summary,
                match_source = :match_source,
                confidence_score = :confidence_score,
                metadata = :metadata,
                updated_at = CURRENT_TIMESTAMP(3)
                WHERE id = :id AND main_id = :main_id';
            $stmt = $pdo->prepare($sql);
            $this->bindIncidentItemValues($stmt, $values);
            $stmt->bindValue('id', (int) $existingId, PDO::PARAM_INT);
            $stmt->execute();
            $id = (int) $existingId;
            $created = false;
        } else {
            $sql = 'INSERT INTO incident_report_items (
                main_id, incident_report_id, contact_id, product_id, item_code,
                part_no, description, supplier_id, supplier_name, quantity,
                issue_summary, match_source, confidence_score, metadata, created_by_user_id
            ) VALUES (
                :main_id, :incident_report_id, :contact_id, :product_id, :item_code,
                :part_no, :description, :supplier_id, :supplier_name, :quantity,
                :issue_summary, :match_source, :confidence_score, :metadata, :created_by_user_id
            )';
            $stmt = $pdo->prepare($sql);
            $this->bindIncidentItemValues($stmt, $values);
            $stmt->execute();
            $id = (int) $pdo->lastInsertId();
            $created = true;
        }

        return [
            'id' => (string) $id,
            'incident_report_id' => $incidentReportId,
            'created' => $created,
        ];
    }

    /**
     * @param array<string, string|int> $filters
     */
    public function report(int $mainId, array $filters, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        [$whereSql, $params] = $this->buildWhere($mainId, $filters);
        $minCount = max(1, (int) ($filters['min_count'] ?? 1));

        $groupSql = $this->groupedSql($whereSql);
        $countSql = "SELECT COUNT(*) AS total FROM ({$groupSql} HAVING incident_count >= :min_count) grouped_count";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $this->bind($countStmt, $params);
        $countStmt->bindValue('min_count', $minCount, PDO::PARAM_INT);
        $countStmt->execute();
        $total = (int) (($countStmt->fetch(PDO::FETCH_ASSOC) ?: [])['total'] ?? 0);

        $rowsSql = <<<SQL
SELECT *
FROM ({$groupSql} HAVING incident_count >= :min_count) grouped_rows
ORDER BY incident_count DESC, latest_incident_date DESC, supplier_name ASC, part_no ASC
LIMIT :limit OFFSET :offset
SQL;
        $rowsStmt = $this->db->pdo()->prepare($rowsSql);
        $this->bind($rowsStmt, $params);
        $rowsStmt->bindValue('min_count', $minCount, PDO::PARAM_INT);
        $rowsStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $rowsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $rowsStmt->execute();
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
        // #region agent log
        $this->debugLog('A', 'IncidentItemsReportRepository.php:report', 'grouped confidence aggregation', [
            'row_count' => count($rows),
            'min_count' => $minCount,
            'groups' => array_map(static function (array $row): array {
                return [
                    'item_code' => (string) ($row['item_code'] ?? ''),
                    'part_no' => (string) ($row['part_no'] ?? ''),
                    'incident_count' => (int) ($row['incident_count'] ?? 0),
                    'affected_customer_count' => (int) ($row['affected_customer_count'] ?? 0),
                    'average_confidence' => (float) ($row['average_confidence'] ?? 0),
                    'evidence_count' => (int) ($row['debug_evidence_count'] ?? 0),
                    'legacy_match_average' => isset($row['debug_match_average_confidence']) ? (float) $row['debug_match_average_confidence'] : null,
                    'avg_skip_null' => isset($row['debug_avg_skip_null']) ? (float) $row['debug_avg_skip_null'] : null,
                    'null_scores' => (int) ($row['debug_null_confidence_count'] ?? 0),
                    'min_score' => $row['debug_min_confidence'] ?? null,
                    'max_score' => $row['debug_max_confidence'] ?? null,
                    'scores' => (string) ($row['debug_confidence_values'] ?? ''),
                    'match_sources' => (string) ($row['match_sources'] ?? ''),
                    'demo_rows' => (int) ($row['debug_demo_rows'] ?? 0),
                    'customer_rows' => (int) ($row['debug_customer_rows'] ?? 0),
                ];
            }, array_slice($rows, 0, 15)),
        ]);
        // #endregion

        return [
            'items' => array_map(fn(array $row): array => $this->mapRow($row), $rows),
            'summary' => $this->summary($whereSql, $params, $rows),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
                'search' => (string) ($filters['search'] ?? ''),
                'supplier' => (string) ($filters['supplier'] ?? ''),
                'match_source' => (string) ($filters['match_source'] ?? 'all'),
                'min_count' => $minCount,
            ],
        ];
    }

    /**
     * List every Incident Report for one Incident Item group (no 5-row cap).
     *
     * @param array<string, string|int> $filters
     * @return array{incidents: array<int, array<string, string>>}
     */
    public function listItemIncidents(int $mainId, array $filters): array
    {
        [$whereSql, $params] = $this->buildWhere($mainId, $filters);
        $groupKeys = $this->buildItemGroupWhere($filters);
        $whereSql = $whereSql === '' ? $groupKeys['sql'] : ($whereSql . ' AND ' . $groupKeys['sql']);
        $params = array_merge($params, $groupKeys['params']);

        $sql = <<<SQL
SELECT
    incident_report_items.incident_report_id,
    DATE_FORMAT(incident_report_items.created_at, '%Y-%m-%d') AS date,
    COALESCE(NULLIF(incident_report_items.contact_id, ''), '') AS contact_id,
    COALESCE(NULLIF(customer.lcompany, ''), incident_report_items.contact_id, 'Unknown customer') AS customer_name,
    LEFT(COALESCE(incident_report_items.issue_summary, ''), 160) AS summary
FROM incident_report_items
LEFT JOIN tblpatient customer ON customer.lsessionid = incident_report_items.contact_id
WHERE {$whereSql}
ORDER BY incident_report_items.created_at DESC, incident_report_items.id DESC
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'incidents' => array_map(static function (array $row): array {
                return [
                    'incident_report_id' => (string) ($row['incident_report_id'] ?? ''),
                    'date' => (string) ($row['date'] ?? ''),
                    'contact_id' => (string) ($row['contact_id'] ?? ''),
                    'customer_name' => (string) ($row['customer_name'] ?? 'Unknown customer'),
                    'summary' => (string) ($row['summary'] ?? ''),
                ];
            }, $rows),
        ];
    }

    /**
     * Full Incident Report for warehouse detail (Master User may approve via daily-call decision API).
     *
     * @return array<string, mixed>|null
     */
    public function getIncidentReport(int $mainId, string $reportId): ?array
    {
        $stmt = $this->db->pdo()->prepare(<<<'SQL'
SELECT
    ir.id,
    'incident_report' AS record_source,
    ir.contact_id,
    COALESCE(NULLIF(customer.lcompany, ''), ir.contact_id, 'Unknown customer') AS customer_name,
    ir.report_date,
    ir.report_time,
    ir.incident_date,
    ir.incident_time,
    ir.issue_type,
    ir.description,
    ir.reported_by,
    ir.done_by,
    ir.attachments,
    ir.related_transactions,
    ir.approval_status,
    ir.approved_by,
    ir.approval_date,
    ir.decision_note,
    ir.notes,
    iri.product_id,
    iri.item_code,
    iri.part_no,
    iri.description AS item_description,
    iri.quantity AS affected_quantity,
    iri.supplier_id,
    iri.supplier_name,
    ira.id AS return_action_id,
    ira.disposition AS return_disposition,
    ira.status AS return_action_status,
    ira.authorized_by_name,
    ira.authorized_at,
    (
      SELECT COUNT(*) FROM incident_reports customer_incidents
      WHERE customer_incidents.main_id = ir.main_id
        AND customer_incidents.contact_id = ir.contact_id
    ) AS customer_incident_count,
    (
      SELECT COUNT(DISTINCT matching_items.incident_report_id)
      FROM incident_report_items matching_items
      WHERE matching_items.main_id = ir.main_id
        AND (
          (NULLIF(iri.product_id, '') IS NOT NULL AND matching_items.product_id = iri.product_id)
          OR (NULLIF(iri.part_no, '') IS NOT NULL AND matching_items.part_no = iri.part_no)
          OR (NULLIF(iri.item_code, '') IS NOT NULL AND matching_items.item_code = iri.item_code)
        )
    ) AS item_incident_count
FROM incident_reports ir
LEFT JOIN tblpatient customer ON customer.lsessionid = ir.contact_id
LEFT JOIN incident_report_items iri
  ON iri.main_id = ir.main_id AND iri.incident_report_id = ir.id
LEFT JOIN incident_return_actions ira
  ON ira.main_id = ir.main_id AND ira.incident_report_id = ir.id
WHERE ir.main_id = :main_id AND ir.id = :id
ORDER BY iri.id ASC
LIMIT 1
SQL);
        $stmt->execute(['main_id' => $mainId, 'id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['attachments'] = $this->decodeJsonArray($row['attachments'] ?? null);
        $row['related_transactions'] = $this->decodeJsonArray($row['related_transactions'] ?? null);
        $row['customer_incident_count'] = (int) ($row['customer_incident_count'] ?? 0);
        $row['item_incident_count'] = (int) ($row['item_incident_count'] ?? 0);
        $row['return_action'] = !empty($row['return_action_id']) ? [
            'id' => (string) $row['return_action_id'],
            'disposition' => (string) ($row['return_disposition'] ?? ''),
            'status' => (string) ($row['return_action_status'] ?? ''),
            'authorized_by_name' => (string) ($row['authorized_by_name'] ?? ''),
            'authorized_at' => (string) ($row['authorized_at'] ?? ''),
        ] : null;
        unset(
            $row['return_action_id'],
            $row['return_disposition'],
            $row['return_action_status'],
            $row['authorized_by_name'],
            $row['authorized_at']
        );

        return $row;
    }

    /**
     * Match one Incident Item group using the same COALESCE identity as the grouped report.
     *
     * @param array<string, string|int> $filters
     * @return array{sql: string, params: array<string, string>}
     */
    private function buildItemGroupWhere(array $filters): array
    {
        $params = [
            'group_supplier_id' => (string) ($filters['supplier_id'] ?? 'unassigned'),
            'group_supplier_name' => (string) ($filters['supplier_name'] ?? 'Unassigned Supplier'),
            'group_product_id' => (string) ($filters['product_id'] ?? ''),
            'group_item_code' => (string) ($filters['item_code'] ?? ''),
            'group_part_no' => (string) ($filters['part_no'] ?? ''),
            'group_description' => (string) ($filters['description'] ?? ''),
        ];

        $sql = '('
            . 'COALESCE(NULLIF(supplier_id, \'\'), \'unassigned\') = :group_supplier_id '
            . 'AND COALESCE(NULLIF(supplier_name, \'\'), \'Unassigned Supplier\') = :group_supplier_name '
            . 'AND COALESCE(product_id, \'\') = :group_product_id '
            . 'AND COALESCE(item_code, \'\') = :group_item_code '
            . 'AND COALESCE(part_no, \'\') = :group_part_no '
            . 'AND COALESCE(description, \'\') = :group_description'
            . ')';

        return ['sql' => $sql, 'params' => $params];
    }

    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param array<string, string|int> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildWhere(int $mainId, array $filters): array
    {
        $where = ['main_id = :main_id'];
        $params = ['main_id' => $mainId];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Native MySQL PDO prepares do not allow one named placeholder to
            // appear more than once in a statement. Keep each LIKE operand
            // separately bound so filtering does not fail with SQLSTATE[HY093].
            $searchLike = '%' . $search . '%';
            $params['search_supplier_name'] = $searchLike;
            $params['search_item_code'] = $searchLike;
            $params['search_part_no'] = $searchLike;
            $params['search_description'] = $searchLike;
            $params['search_issue_summary'] = $searchLike;
            $where[] = '('
                . 'COALESCE(supplier_name, "") LIKE :search_supplier_name '
                . 'OR COALESCE(item_code, "") LIKE :search_item_code '
                . 'OR COALESCE(part_no, "") LIKE :search_part_no '
                . 'OR COALESCE(description, "") LIKE :search_description '
                . 'OR COALESCE(issue_summary, "") LIKE :search_issue_summary'
                . ')';
        }

        $supplier = trim((string) ($filters['supplier'] ?? ''));
        if ($supplier !== '') {
            $supplierLike = '%' . $supplier . '%';
            $params['supplier_id'] = $supplierLike;
            $params['supplier_name'] = $supplierLike;
            $where[] = '(COALESCE(supplier_id, "") LIKE :supplier_id OR COALESCE(supplier_name, "") LIKE :supplier_name)';
        }

        $matchSource = trim((string) ($filters['match_source'] ?? 'all'));
        if ($matchSource !== '' && $matchSource !== 'all') {
            $params['match_source'] = $matchSource;
            $where[] = 'match_source = :match_source';
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $params['date_from'] = $dateFrom;
            $where[] = 'DATE(created_at) >= :date_from';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $params['date_to'] = $dateTo;
            $where[] = 'DATE(created_at) <= :date_to';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Problem confidence is independent corroboration, not item-match quality.
     * Each distinct customer (and each unattributed report) halves remaining uncertainty:
     * 1 report 50%, 2 reports 75%, 3 reports 87.5%, approaching 100%.
     */
    private function groupedSql(string $whereSql): string
    {
        return <<<SQL
SELECT
    COALESCE(NULLIF(supplier_id, ''), 'unassigned') AS supplier_id,
    COALESCE(NULLIF(supplier_name, ''), 'Unassigned Supplier') AS supplier_name,
    COALESCE(product_id, '') AS product_id,
    COALESCE(item_code, '') AS item_code,
    COALESCE(part_no, '') AS part_no,
    COALESCE(description, '') AS description,
    COUNT(*) AS incident_count,
    COUNT(DISTINCT NULLIF(contact_id, '')) AS affected_customer_count,
    MAX(created_at) AS latest_incident_date,
    ROUND(
        1 - POWER(
            0.5,
            (
                COUNT(DISTINCT NULLIF(contact_id, ''))
                + SUM(CASE WHEN NULLIF(contact_id, '') IS NULL THEN 1 ELSE 0 END)
            )
        ),
        4
    ) AS average_confidence,
    (
        COUNT(DISTINCT NULLIF(contact_id, ''))
        + SUM(CASE WHEN NULLIF(contact_id, '') IS NULL THEN 1 ELSE 0 END)
    ) AS debug_evidence_count,
    ROUND(AVG(COALESCE(confidence_score, 0)), 4) AS debug_match_average_confidence,
    ROUND(AVG(confidence_score), 4) AS debug_avg_skip_null,
    SUM(CASE WHEN confidence_score IS NULL THEN 1 ELSE 0 END) AS debug_null_confidence_count,
    MIN(confidence_score) AS debug_min_confidence,
    MAX(confidence_score) AS debug_max_confidence,
    GROUP_CONCAT(COALESCE(CAST(confidence_score AS CHAR), 'NULL') ORDER BY created_at SEPARATOR ',') AS debug_confidence_values,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.seed')) = 'incident-items-demo' THEN 1 ELSE 0 END) AS debug_demo_rows,
    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.source')) = 'customer_incident_report' THEN 1 ELSE 0 END) AS debug_customer_rows,
    GROUP_CONCAT(DISTINCT match_source ORDER BY match_source SEPARATOR ', ') AS match_sources,
    GROUP_CONCAT(
        CONCAT(
            incident_report_id,
            '|',
            DATE_FORMAT(created_at, '%Y-%m-%d'),
            '|',
            COALESCE(NULLIF(contact_id, ''), 'Unknown customer'),
            '|',
            REPLACE(REPLACE(COALESCE(customer_name, contact_id, 'Unknown customer'), '|', '/'), '\n', ' '),
            '|',
            REPLACE(REPLACE(LEFT(COALESCE(issue_summary, ''), 160), '\n', ' '), '|', '/')
        )
        ORDER BY created_at DESC
        SEPARATOR ';;'
    ) AS recent_incidents
FROM (
    SELECT iri.*, COALESCE(NULLIF(customer.lcompany, ''), iri.contact_id, 'Unknown customer') AS customer_name
    FROM incident_report_items iri
    LEFT JOIN tblpatient customer ON customer.lsessionid = iri.contact_id
) incident_report_items
WHERE {$whereSql}
GROUP BY
    COALESCE(NULLIF(supplier_id, ''), 'unassigned'),
    COALESCE(NULLIF(supplier_name, ''), 'Unassigned Supplier'),
    COALESCE(product_id, ''),
    COALESCE(item_code, ''),
    COALESCE(part_no, ''),
    COALESCE(description, '')
SQL;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<int, array<string, mixed>> $pagedRows
     */
    private function summary(string $whereSql, array $params, array $pagedRows): array
    {
        $sql = <<<SQL
SELECT
    COUNT(*) AS total_incident_items,
    COUNT(DISTINCT COALESCE(NULLIF(supplier_id, ''), 'unassigned')) AS affected_suppliers,
    COUNT(DISTINCT CONCAT(COALESCE(product_id, ''), '|', COALESCE(item_code, ''), '|', COALESCE(part_no, ''))) AS affected_items
FROM incident_report_items
WHERE {$whereSql}
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bind($stmt, $params);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $top = $pagedRows[0] ?? [];

        return [
            'total_incident_items' => (int) ($row['total_incident_items'] ?? 0),
            'affected_suppliers' => (int) ($row['affected_suppliers'] ?? 0),
            'affected_items' => (int) ($row['affected_items'] ?? 0),
            'top_supplier_name' => (string) ($top['supplier_name'] ?? ''),
            'top_item_description' => (string) ($top['description'] ?? ''),
            'top_incident_count' => (int) ($top['incident_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): array
    {
        $recent = [];
        foreach (explode(';;', (string) ($row['recent_incidents'] ?? '')) as $entry) {
            if ($entry === '') {
                continue;
            }
            [$id, $date, $contactId, $customerName, $summary] = array_pad(explode('|', $entry, 5), 5, '');
            $recent[] = [
                'incident_report_id' => $id,
                'date' => $date,
                'contact_id' => $contactId,
                'customer_name' => $customerName,
                'summary' => $summary,
            ];
        }

        return [
            'supplier_id' => (string) ($row['supplier_id'] ?? ''),
            'supplier_name' => (string) ($row['supplier_name'] ?? ''),
            'product_id' => (string) ($row['product_id'] ?? ''),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'part_no' => (string) ($row['part_no'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'incident_count' => (int) ($row['incident_count'] ?? 0),
            'affected_customer_count' => (int) ($row['affected_customer_count'] ?? 0),
            'latest_incident_date' => (string) ($row['latest_incident_date'] ?? ''),
            'average_confidence' => (float) ($row['average_confidence'] ?? 0),
            'match_sources' => (string) ($row['match_sources'] ?? ''),
            'recent_incidents' => array_slice($recent, 0, 5),
        ];
    }

        /**
     * @param array<string, mixed> $values
     */
    private function bindIncidentItemValues(\PDOStatement $stmt, array $values): void
    {
        foreach ($values as $key => $value) {
            $type = PDO::PARAM_STR;
            if ($key === 'main_id' || $key === 'created_by_user_id') {
                $type = $value === null ? PDO::PARAM_NULL : PDO::PARAM_INT;
            } elseif ($value === null) {
                $type = PDO::PARAM_NULL;
            }
            $stmt->bindValue($key, $value, $type);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function debugLog(string $hypothesisId, string $location, string $message, array $data): void
    {
        // #region agent log
        $payload = json_encode([
            'sessionId' => '924957',
            'runId' => 'post-fix',
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'message' => $message,
            'data' => $data,
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        @file_put_contents('/Users/melsonleanbacuen/james-system/.cursor/debug-924957.log', $payload . "\n", FILE_APPEND | LOCK_EX);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nX-Debug-Session-Id: 924957\r\n",
                'content' => $payload,
                'timeout' => 0.4,
                'ignore_errors' => true,
            ],
        ]);
        @file_get_contents('http://127.0.0.1:7586/ingest/8c501c88-a103-4e50-912c-b3b44d4a265a', false, $context);
        // #endregion
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bind(\PDOStatement $stmt, array $params): void {
        $sql = $stmt->queryString ?: '';
        foreach ($params as $key => $value) {
            $pattern = '/:' . preg_quote($key, '/') . '(?![A-Za-z0-9_])/';
            if (preg_match($pattern, $sql) !== 1) {
                continue;
            }
            $type = $key === 'main_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $type === PDO::PARAM_INT ? (int) $value : (string) $value, $type);
        }
    }
}
