<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CustomerGroupRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listGroups(
        int $mainId,
        string $search = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = [
            'g.lmain_id = :main_id',
            "g.lname != ''",
        ];
        $params = [
            'main_id' => $mainId,
        ];

        $trimmed = trim($search);
        if ($trimmed !== '') {
            $where[] = 'g.lname LIKE :search';
            $params['search'] = '%' . $trimmed . '%';
        }

        $whereSql = implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(g.lid AS SIGNED) AS id,
    COALESCE(g.lname, '') AS name,
    CAST((
        SELECT COUNT(p.lid)
        FROM tblgroup_patient p
        WHERE p.lgroup = g.lname
          AND p.lmain_id = g.lmain_id
    ) AS SIGNED) AS contact_count
FROM tblmain_group g
WHERE {$whereSql}
ORDER BY g.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $params['main_id'], PDO::PARAM_INT);
        if (isset($params['search'])) {
            $stmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) AS total FROM tblmain_group g WHERE {$whereSql}";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->bindValue('main_id', $params['main_id'], PDO::PARAM_INT);
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

    public function getGroupById(int $mainId, int $groupId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(g.lid AS SIGNED) AS id,
    COALESCE(g.lname, '') AS name,
    CAST((
        SELECT COUNT(p.lid)
        FROM tblgroup_patient p
        WHERE p.lgroup = g.lname
          AND p.lmain_id = g.lmain_id
    ) AS SIGNED) AS contact_count
FROM tblmain_group g
WHERE g.lid = :group_id
  AND g.lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createGroup(int $mainId, string $name): array
    {
        $sql = <<<SQL
INSERT INTO tblmain_group (lname, lmain_id, ldiscount, ltype, lamt, lrefno)
VALUES (:name, :main_id, '', '', '', :refno)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'main_id' => $mainId,
            'refno' => date('YmdHis') . random_int(1, 1000000),
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->getGroupById($mainId, $id) ?? ['id' => $id, 'name' => $name, 'contact_count' => 0];
    }

    public function updateGroup(int $mainId, int $groupId, string $name): ?array
    {
        $existing = $this->getGroupById($mainId, $groupId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblmain_group
SET lname = :name
WHERE lid = :group_id
  AND lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'group_id' => $groupId,
            'main_id' => $mainId,
        ]);

        return $this->getGroupById($mainId, $groupId);
    }

    public function deleteGroup(int $mainId, int $groupId): bool
    {
        $existing = $this->getGroupById($mainId, $groupId);
        if ($existing === null) {
            return false;
        }

        $deleteMembersSql = <<<SQL
DELETE FROM tblgroup_patient
WHERE lgroup = :group_name
  AND lmain_id = :main_id
SQL;
        $membersStmt = $this->db->pdo()->prepare($deleteMembersSql);
        $membersStmt->execute([
            'group_name' => $existing['name'],
            'main_id' => $mainId,
        ]);

        $deleteGroupSql = <<<SQL
DELETE FROM tblmain_group
WHERE lid = :group_id
  AND lmain_id = :main_id
LIMIT 1
SQL;
        $groupStmt = $this->db->pdo()->prepare($deleteGroupSql);
        $groupStmt->execute([
            'group_id' => $groupId,
            'main_id' => $mainId,
        ]);

        return $groupStmt->rowCount() > 0;
    }
}
