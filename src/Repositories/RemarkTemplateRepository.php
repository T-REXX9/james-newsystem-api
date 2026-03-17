<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class RemarkTemplateRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listRemarkTemplates(
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
            $where[] = 'rt.lname LIKE :search';
            $params['search'] = '%' . $trimmed . '%';
        }

        $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(rt.lid AS SIGNED) AS id,
    COALESCE(rt.lname, '') AS name
FROM tblremark_template rt
{$whereSql}
ORDER BY rt.lname ASC, rt.lid ASC
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

        $countSql = "SELECT COUNT(*) AS total FROM tblremark_template rt {$whereSql}";
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

    public function getRemarkTemplateById(int $remarkTemplateId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(rt.lid AS SIGNED) AS id,
    COALESCE(rt.lname, '') AS name
FROM tblremark_template rt
WHERE rt.lid = :remark_template_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('remark_template_id', $remarkTemplateId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createRemarkTemplate(string $name): array
    {
        $sql = <<<SQL
INSERT INTO tblremark_template (lname)
VALUES (:name)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->getRemarkTemplateById($id) ?? ['id' => $id, 'name' => $name];
    }

    public function updateRemarkTemplate(int $remarkTemplateId, string $name): ?array
    {
        $existing = $this->getRemarkTemplateById($remarkTemplateId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblremark_template
SET lname = :name
WHERE lid = :remark_template_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'remark_template_id' => $remarkTemplateId,
        ]);

        return $this->getRemarkTemplateById($remarkTemplateId);
    }

    public function deleteRemarkTemplate(int $remarkTemplateId): bool
    {
        $existing = $this->getRemarkTemplateById($remarkTemplateId);
        if ($existing === null) {
            return false;
        }

        $sql = <<<SQL
DELETE FROM tblremark_template
WHERE lid = :remark_template_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'remark_template_id' => $remarkTemplateId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
