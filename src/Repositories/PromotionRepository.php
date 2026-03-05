<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class PromotionRepository
{
    private ?array $promotionColumns = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function pdo(): PDO
    {
        return $this->db->pdo();
    }

    /**
     * Get all promotions with optional filtering
     */
    public function list(
        int $page = 1,
        int $perPage = 50,
        string $status = '',
        string $search = '',
        bool $includeDeleted = false
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = 'SELECT COUNT(*) as total FROM promotions WHERE 1=1';
        $params = [];

        if (!$includeDeleted) {
            $countSql .= ' AND is_deleted = FALSE';
        }

        if ($status !== '') {
            $countSql .= ' AND status = :status';
            $params['status'] = $status;
        }

        if ($search !== '') {
            $countSql .= ' AND campaign_title LIKE :search';
            $params['search'] = "%{$search}%";
        }

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $selectColumns = $this->promotionSelectColumns();

        // Get paginated data
        $sql = "SELECT\n    {$selectColumns}\nFROM promotions\nWHERE 1=1";

        if (!$includeDeleted) {
            $sql .= ' AND is_deleted = FALSE';
        }

        if ($status !== '') {
            $sql .= ' AND status = :status';
        }

        if ($search !== '') {
            $sql .= ' AND campaign_title LIKE :search';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($status !== '') {
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        }
        if ($search !== '') {
            $stmt->bindValue(':search', "%{$search}%", PDO::PARAM_STR);
        }
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hydrate with creator info
        $items = $this->hydrateWithCreators($items);

        return [
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Get single promotion
     */
    public function show(string $id): ?array
    {
        $selectColumns = $this->promotionSelectColumns();
        $sql = "SELECT\n    {$selectColumns}\nFROM promotions\nWHERE id = :id AND is_deleted = FALSE";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return null;
        }

        $records = $this->hydrateWithCreators([$record]);
        return $records[0] ?? null;
    }

    /**
     * Create new promotion
     */
    public function create(array $promotion): ?array
    {
        $columns = [
            'campaign_title',
            'description',
            'start_date',
            'end_date',
            'status',
            'created_by',
            'assigned_to',
            'target_platforms',
        ];
        $placeholders = [
            ':campaign_title',
            ':description',
            ':start_date',
            ':end_date',
            ':status',
            ':created_by',
            ':assigned_to',
            ':target_platforms',
        ];
        $assignedTo = $this->encodeArray($promotion['assigned_to'] ?? []);
        $targetPlatforms = $this->encodeArray($promotion['target_platforms'] ?? []);
        $params = [
            ':campaign_title' => $promotion['campaign_title'] ?? null,
            ':description' => $promotion['description'] ?? null,
            ':start_date' => $promotion['start_date'] ?? null,
            ':end_date' => $promotion['end_date'] ?? null,
            ':status' => $promotion['status'] ?? 'Draft',
            ':created_by' => $promotion['created_by'] ?? null,
            ':assigned_to' => $assignedTo,
            ':target_platforms' => $targetPlatforms,
        ];

        if ($this->hasPromotionColumn('target_all_clients')) {
            $columns[] = 'target_all_clients';
            $placeholders[] = ':target_all_clients';
            $params[':target_all_clients'] = array_key_exists('target_all_clients', $promotion)
                ? (int) ((bool) $promotion['target_all_clients'])
                : 1;
        }

        if ($this->hasPromotionColumn('target_client_ids')) {
            $columns[] = 'target_client_ids';
            $placeholders[] = ':target_client_ids';
            $params[':target_client_ids'] = $this->encodeArray($promotion['target_client_ids'] ?? []);
        }

        if ($this->hasPromotionColumn('target_cities')) {
            $columns[] = 'target_cities';
            $placeholders[] = ':target_cities';
            $params[':target_cities'] = $this->encodeArray($promotion['target_cities'] ?? []);
        }

        $sql = sprintf(
            "INSERT INTO promotions (\n    %s,\n    created_at, updated_at\n) VALUES (\n    %s,\n    NOW(), NOW()\n)",
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute($params);

        if (!$result) {
            return null;
        }

        $id = $this->db->pdo()->lastInsertId();
        return $this->show($id);
    }

    /**
     * Update promotion
     */
    public function update(string $id, array $updates): ?array
    {
        $sql = 'UPDATE promotions SET ';
        $fields = [];
        $params = ['id' => $id];

        if (isset($updates['campaign_title'])) {
            $fields[] = 'campaign_title = :campaign_title';
            $params['campaign_title'] = $updates['campaign_title'];
        }

        if (isset($updates['description'])) {
            $fields[] = 'description = :description';
            $params['description'] = $updates['description'];
        }

        if (isset($updates['start_date'])) {
            $fields[] = 'start_date = :start_date';
            $params['start_date'] = $updates['start_date'];
        }

        if (isset($updates['end_date'])) {
            $fields[] = 'end_date = :end_date';
            $params['end_date'] = $updates['end_date'];
        }

        if (isset($updates['status'])) {
            $fields[] = 'status = :status';
            $params['status'] = $updates['status'];
        }

        if ($this->hasPromotionColumn('target_all_clients') && array_key_exists('target_all_clients', $updates)) {
            $fields[] = 'target_all_clients = :target_all_clients';
            $params['target_all_clients'] = (int) ((bool) $updates['target_all_clients']);
        }

        if ($this->hasPromotionColumn('target_client_ids') && isset($updates['target_client_ids'])) {
            $fields[] = 'target_client_ids = :target_client_ids';
            $params['target_client_ids'] = $this->encodeArray($updates['target_client_ids']);
        }

        if ($this->hasPromotionColumn('target_cities') && isset($updates['target_cities'])) {
            $fields[] = 'target_cities = :target_cities';
            $params['target_cities'] = $this->encodeArray($updates['target_cities']);
        }

        if (isset($updates['assigned_to'])) {
            $fields[] = 'assigned_to = :assigned_to';
            $params['assigned_to'] = $this->encodeArray($updates['assigned_to']);
        }

        if (isset($updates['target_platforms'])) {
            $fields[] = 'target_platforms = :target_platforms';
            $params['target_platforms'] = $this->encodeArray($updates['target_platforms']);
        }

        if (empty($fields)) {
            return $this->show($id);
        }

        $sql .= implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->show($id);
    }

    /**
     * Delete promotion (soft delete)
     */
    public function delete(string $id): bool
    {
        $sql = <<<SQL
UPDATE promotions SET
    is_deleted = TRUE,
    deleted_at = NOW(),
    updated_at = NOW()
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get promotions by status
     */
    public function getByStatus(string $status, int $limit = 100): array
    {
        $selectColumns = $this->promotionSelectColumns();
        $sql = "SELECT\n    {$selectColumns}\nFROM promotions\nWHERE status = :status AND is_deleted = FALSE\nORDER BY created_at DESC\nLIMIT :limit";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateWithCreators($items);
    }

    /**
     * Get active promotions (for public/customer views)
     */
    public function getActive(int $limit = 50): array
    {
        $selectColumns = $this->promotionSelectColumns(false);
        $sql = "SELECT\n    {$selectColumns}\nFROM promotions\nWHERE is_deleted = FALSE AND status = 'Active'\n  AND (start_date IS NULL OR start_date <= NOW())\n  AND end_date >= NOW()\nORDER BY start_date DESC, created_at DESC\nLIMIT :limit";

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Hydrate promotions with creator info
     */
    private function hydrateWithCreators(array $records): array
    {
        if (empty($records)) {
            return $records;
        }

        $creatorIds = array_unique(array_filter(array_column($records, 'created_by')));
        $creatorsMap = [];

        if (!empty($creatorIds)) {
            $placeholders = implode(',', array_fill(0, count($creatorIds), '?'));
            $creatorSql = <<<SQL
SELECT
    lid AS id,
    CONCAT(COALESCE(lfname, ''), ' ', COALESCE(llname, '')) AS full_name,
    lusername AS email,
    NULL AS avatar_url,
    lgroup AS role
FROM tbluser
WHERE lid IN ($placeholders)
SQL;

            $stmt = $this->db->pdo()->prepare($creatorSql);
            $stmt->execute(array_values($creatorIds));
            $creators = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($creators as $creator) {
                $creatorsMap[$creator['id']] = $creator;
            }
        }

        foreach ($records as &$record) {
            $createdBy = $record['created_by'] ?? null;
            $record['creator'] = ($createdBy !== null) ? ($creatorsMap[$createdBy] ?? null) : null;
            $this->hydrateArrayFields($record);
        }

        return $records;
    }

    /**
     * Encode array for database storage (JSON format for MySQL)
     */
    private function encodeArray(array $data): string
    {
        return json_encode(array_values(array_filter($data))) ?: '[]';
    }

    /**
     * Decode array from database
     */
    public function decodeArray(string $data): array
    {
        $decoded = json_decode($data, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get promotion statistics
     */
    public function getStats(): array
    {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $sevenDaysFromNow = (new \DateTime('+7 days'))->format('Y-m-d H:i:s');

        // Active promotions count
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) as cnt FROM promotions WHERE status = 'Active' AND is_deleted = 0"
        );
        $stmt->execute();
        $activeCount = (int)($stmt->fetchColumn() ?: 0);

        // Pending review postings count
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) as cnt FROM promotion_postings WHERE status = 'Pending Review'"
        );
        $stmt->execute();
        $pendingCount = (int)($stmt->fetchColumn() ?: 0);

        // Expiring soon count (within 7 days)
        $stmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) as cnt FROM promotions WHERE status = 'Active' AND is_deleted = 0 AND end_date <= :seven_days AND end_date >= :now"
        );
        $stmt->execute([':seven_days' => $sevenDaysFromNow, ':now' => $now]);
        $expiringCount = (int)($stmt->fetchColumn() ?: 0);

        return [
            'total_active' => $activeCount,
            'pending_reviews' => $pendingCount,
            'expiring_soon' => $expiringCount,
        ];
    }

    /**
     * Get promotions assigned to a user
     */
    public function getAssigned(string $userId, int $limit = 100): array
    {
        $sql = <<<SQL
SELECT
    p.*,
    u.lid as creator_id,
    CONCAT(COALESCE(u.lfname, ''), ' ', COALESCE(u.llname, '')) as creator_name,
    u.lusername as creator_email
FROM promotions p
LEFT JOIN tbluser u ON p.created_by = u.lid
WHERE p.is_deleted = 0
  AND p.status IN ('Active', 'Draft')
  AND (
    JSON_CONTAINS(p.assigned_to, JSON_QUOTE(:user_id))
    OR JSON_LENGTH(p.assigned_to) = 0
    OR p.assigned_to IS NULL
  )
ORDER BY p.end_date ASC
LIMIT :lmt
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_STR);
        $stmt->bindValue(':lmt', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Hydrate with products and postings for each
        foreach ($rows as &$row) {
            $this->hydrateArrayFields($row);
            if (isset($row['creator_id'])) {
                $row['creator'] = [
                    'id' => $row['creator_id'],
                    'full_name' => $row['creator_name'],
                    'email' => $row['creator_email'],
                ];
            }
            unset($row['creator_id'], $row['creator_name'], $row['creator_email']);

            // Get products summary
            $pStmt = $this->db->pdo()->prepare(
                "SELECT pp.id, pp.product_id, itm.ldescription AS description, itm.litemcode AS item_code FROM promotion_products pp LEFT JOIN tblinventory_item itm ON pp.product_id = CAST(itm.lsession AS CHAR) WHERE pp.promotion_id = :pid"
            );
            $pStmt->execute([':pid' => $row['id']]);
            $row['products'] = $pStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get postings summary
            $postStmt = $this->db->pdo()->prepare(
                "SELECT id, platform_name, status, screenshot_url, rejection_reason FROM promotion_postings WHERE promotion_id = :pid"
            );
            $postStmt->execute([':pid' => $row['id']]);
            $row['postings'] = $postStmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;
    }

    /**
     * Extend a promotion end date
     */
    public function extend(string $id, string $newEndDate): ?array
    {
        $sql = "UPDATE promotions SET end_date = :end_date, status = 'Active', updated_at = NOW() WHERE id = :id AND is_deleted = 0";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([':end_date' => $newEndDate, ':id' => $id]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->show($id);
    }

    /**
     * Hydrate JSON array fields in a row
     */
    private function hydrateArrayFields(array &$row): void
    {
        if (array_key_exists('target_all_clients', $row)) {
            $row['target_all_clients'] = (bool) $row['target_all_clients'];
        } else {
            $row['target_all_clients'] = true;
        }

        foreach (['assigned_to', 'target_platforms', 'target_client_ids', 'target_cities'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $decoded = json_decode($row[$field], true);
                $row[$field] = is_array($decoded) ? $decoded : [];
            } elseif (!array_key_exists($field, $row)) {
                $row[$field] = [];
            }
        }
    }

    private function promotionSelectColumns(bool $includeDeletedFields = true): string
    {
        $columns = [
            'id',
            'campaign_title',
            'description',
            'start_date',
            'end_date',
            'status',
            $this->hasPromotionColumn('target_all_clients') ? 'target_all_clients' : 'TRUE AS target_all_clients',
            $this->hasPromotionColumn('target_client_ids') ? 'target_client_ids' : "'[]' AS target_client_ids",
            $this->hasPromotionColumn('target_cities') ? 'target_cities' : "'[]' AS target_cities",
            'created_by',
            'assigned_to',
            'target_platforms',
            'created_at',
            'updated_at',
        ];

        if ($includeDeletedFields) {
            $columns[] = 'deleted_at';
            $columns[] = 'is_deleted';
        }

        return implode(",\n    ", $columns);
    }

    private function hasPromotionColumn(string $column): bool
    {
        return in_array($column, $this->promotionColumns(), true);
    }

    private function promotionColumns(): array
    {
        if (is_array($this->promotionColumns)) {
            return $this->promotionColumns;
        }

        $stmt = $this->db->pdo()->query('SHOW COLUMNS FROM promotions');
        $this->promotionColumns = array_map(
            static fn (array $row): string => (string) ($row['Field'] ?? ''),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        return $this->promotionColumns;
    }
}
