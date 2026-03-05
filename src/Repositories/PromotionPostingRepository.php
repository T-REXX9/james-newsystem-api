<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class PromotionPostingRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Get postings for a promotion
     */
    public function listByPromotion(
        string $promotionId,
        int $page = 1,
        int $perPage = 100,
        string $status = ''
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = 'SELECT COUNT(*) as total FROM promotion_postings WHERE promotion_id = :promotion_id';
        $params = ['promotion_id' => $promotionId];

        if ($status !== '') {
            $countSql .= ' AND status = :status';
            $params['status'] = $status;
        }

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    id,
    promotion_id,
    platform_name,
    posted_by,
    post_url,
    screenshot_url,
    status,
    reviewed_by,
    reviewed_at,
    rejection_reason,
    created_at,
    updated_at
FROM promotion_postings
WHERE promotion_id = :promotion_id
SQL;

        if ($status !== '') {
            $sql .= ' AND status = :status';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':promotion_id', $promotionId, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($status !== '') {
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        }
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
     * Get single posting
     */
    public function show(string $id): ?array
    {
        $sql = <<<SQL
SELECT
    id,
    promotion_id,
    platform_name,
    posted_by,
    post_url,
    screenshot_url,
    status,
    reviewed_by,
    reviewed_at,
    rejection_reason,
    created_at,
    updated_at
FROM promotion_postings
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Create posting
     */
    public function create(array $data): ?array
    {
        $sql = <<<SQL
INSERT INTO promotion_postings (
    promotion_id, platform_name, posted_by, post_url,
    screenshot_url, status, created_at, updated_at
) VALUES (
    :promotion_id, :platform_name, :posted_by, :post_url,
    :screenshot_url, :status, NOW(), NOW()
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute([
            ':promotion_id' => $data['promotion_id'] ?? null,
            ':platform_name' => $data['platform_name'] ?? null,
            ':posted_by' => $data['posted_by'] ?? null,
            ':post_url' => $data['post_url'] ?? null,
            ':screenshot_url' => $data['screenshot_url'] ?? null,
            ':status' => $data['status'] ?? 'Not Posted',
        ]);

        if (!$result) {
            return null;
        }

        $id = $this->db->pdo()->lastInsertId();
        return $this->show($id);
    }

    /**
     * Update posting status and proof
     */
    public function updatePosting(string $id, array $updates): ?array
    {
        $sql = 'UPDATE promotion_postings SET ';
        $fields = [];
        $params = ['id' => $id];

        if (isset($updates['post_url'])) {
            $fields[] = 'post_url = :post_url';
            $params['post_url'] = $updates['post_url'];
        }

        if (isset($updates['screenshot_url'])) {
            $fields[] = 'screenshot_url = :screenshot_url';
            $params['screenshot_url'] = $updates['screenshot_url'];
        }

        if (isset($updates['posted_by'])) {
            $fields[] = 'posted_by = :posted_by';
            $params['posted_by'] = $updates['posted_by'];
        }

        if (isset($updates['status'])) {
            $fields[] = 'status = :status';
            $params['status'] = $updates['status'];
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
     * Review posting (approve or reject)
     */
    public function reviewPosting(
        string $id,
        string $status,
        string $reviewedBy,
        string $rejectionReason = ''
    ): ?array {
        $sql = <<<SQL
UPDATE promotion_postings SET
    status = :status,
    reviewed_by = :reviewed_by,
    reviewed_at = NOW(),
    rejection_reason = :rejection_reason,
    updated_at = NOW()
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute([
            ':id' => $id,
            ':status' => $status,
            ':reviewed_by' => $reviewedBy,
            ':rejection_reason' => $rejectionReason ?: null,
        ]);

        if (!$result) {
            return null;
        }

        return $this->show($id);
    }

    /**
     * Get postings pending review
     */
    public function getPendingReview(int $limit = 50): array
    {
        $sql = <<<SQL
SELECT
    id,
    promotion_id,
    platform_name,
    posted_by,
    post_url,
    screenshot_url,
    status,
    created_at,
    updated_at
FROM promotion_postings
WHERE status = 'Pending Review'
ORDER BY created_at ASC
LIMIT :limit
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete posting
     */
    public function delete(string $id): bool
    {
        $sql = 'DELETE FROM promotion_postings WHERE id = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
