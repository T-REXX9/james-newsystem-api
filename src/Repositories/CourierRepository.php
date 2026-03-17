<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CourierRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listCouriers(
        string $search = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [];
        $params = [];

        $trimmed = trim($search);
        if ($trimmed !== '') {
            $where[] = 'c.lname LIKE :search';
            $params['search'] = '%' . $trimmed . '%';
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(c.lid AS SIGNED) AS id,
    COALESCE(c.lname, '') AS name
FROM tblsend_by c
{$whereSql}
ORDER BY c.lname ASC, c.lid ASC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        if (isset($params['search'])) {
            $stmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) AS total FROM tblsend_by c {$whereSql}";
        $countStmt = $this->db->pdo()->prepare($countSql);
        if (isset($params['search'])) {
            $countStmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / max(1, $perPage)),
            ],
        ];
    }

    public function getCourierById(int $courierId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(c.lid AS SIGNED) AS id,
    COALESCE(c.lname, '') AS name
FROM tblsend_by c
WHERE c.lid = :courier_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('courier_id', $courierId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createCourier(string $name): array
    {
        $sql = <<<SQL
INSERT INTO tblsend_by (lname)
VALUES (:name)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->getCourierById($id) ?? ['id' => $id, 'name' => $name];
    }

    public function updateCourier(int $courierId, string $name): ?array
    {
        $existing = $this->getCourierById($courierId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblsend_by
SET lname = :name
WHERE lid = :courier_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'courier_id' => $courierId,
        ]);

        return $this->getCourierById($courierId);
    }

    public function deleteCourier(int $courierId): bool
    {
        $existing = $this->getCourierById($courierId);
        if ($existing === null) {
            return false;
        }

        $sql = <<<SQL
DELETE FROM tblsend_by
WHERE lid = :courier_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'courier_id' => $courierId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
