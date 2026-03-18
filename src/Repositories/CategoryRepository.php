<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CategoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listCategories(
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
FROM tblproduct_group c
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

        $countSql = "SELECT COUNT(*) AS total FROM tblproduct_group c {$whereSql}";
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

    public function getCategoryById(int $categoryId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(c.lid AS SIGNED) AS id,
    COALESCE(c.lname, '') AS name
FROM tblproduct_group c
WHERE c.lid = :category_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('category_id', $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createCategory(string $name): array
    {
        $sql = <<<SQL
INSERT INTO tblproduct_group (lname)
VALUES (:name)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->getCategoryById($id) ?? ['id' => $id, 'name' => $name];
    }

    public function updateCategory(int $categoryId, string $name): ?array
    {
        $existing = $this->getCategoryById($categoryId);
        if ($existing === null) {
            return null;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $syncSql = <<<SQL
UPDATE tblinventory_item
SET lproduct_group = :new_name
WHERE lproduct_group = :old_name
SQL;
            $syncStmt = $pdo->prepare($syncSql);
            $syncStmt->execute([
                'new_name' => $name,
                'old_name' => $existing['name'],
            ]);

            $updateSql = <<<SQL
UPDATE tblproduct_group
SET lname = :name
WHERE lid = :category_id
LIMIT 1
SQL;
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                'name' => $name,
                'category_id' => $categoryId,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->getCategoryById($categoryId);
    }

    public function deleteCategory(int $categoryId): bool
    {
        $existing = $this->getCategoryById($categoryId);
        if ($existing === null) {
            return false;
        }

        $sql = <<<SQL
DELETE FROM tblproduct_group
WHERE lid = :category_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'category_id' => $categoryId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
