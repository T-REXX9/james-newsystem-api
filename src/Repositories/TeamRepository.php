<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class TeamRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listTeams(
        int $mainId,
        string $search = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['t.lmain_id = :main_id'];
        $params = [
            'main_id' => $mainId,
        ];

        $trimmed = trim($search);
        if ($trimmed !== '') {
            $where[] = 't.lteamname LIKE :search';
            $params['search'] = '%' . $trimmed . '%';
        }

        $whereSql = implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(t.lid AS SIGNED) AS id,
    COALESCE(t.lteamname, '') AS name,
    CAST(COALESCE(t.lstatus, 1) AS SIGNED) AS status,
    (
        SELECT COUNT(*)
        FROM tblaccount a
        WHERE CAST(COALESCE(a.lteam, 0) AS SIGNED) = t.lid
          AND COALESCE(a.lstatus, 0) = 1
    ) AS member_count
FROM tblteamstaff t
WHERE {$whereSql}
ORDER BY t.lid DESC
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

        $countSql = "SELECT COUNT(*) AS total FROM tblteamstaff t WHERE {$whereSql}";
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
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

    public function getTeamById(int $mainId, int $teamId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(t.lid AS SIGNED) AS id,
    COALESCE(t.lteamname, '') AS name,
    CAST(COALESCE(t.lstatus, 1) AS SIGNED) AS status,
    (
        SELECT COUNT(*)
        FROM tblaccount a
        WHERE CAST(COALESCE(a.lteam, 0) AS SIGNED) = t.lid
          AND COALESCE(a.lstatus, 0) = 1
    ) AS member_count
FROM tblteamstaff t
WHERE t.lid = :team_id
  AND t.lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('team_id', $teamId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createTeam(int $mainId, string $name): array
    {
        $sql = <<<SQL
INSERT INTO tblteamstaff (lteamname, lmain_id, lstatus)
VALUES (:name, :main_id, 1)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'main_id' => $mainId,
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->getTeamById($mainId, $id) ?? ['id' => $id, 'name' => $name];
    }

    public function updateTeam(int $mainId, int $teamId, string $name): ?array
    {
        $existing = $this->getTeamById($mainId, $teamId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblteamstaff
SET lteamname = :name
WHERE lid = :team_id
  AND lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'team_id' => $teamId,
            'main_id' => $mainId,
        ]);

        return $this->getTeamById($mainId, $teamId);
    }

    public function deleteTeam(int $mainId, int $teamId): bool
    {
        $existing = $this->getTeamById($mainId, $teamId);
        if ($existing === null) {
            return false;
        }

        $sql = <<<SQL
DELETE FROM tblteamstaff
WHERE lid = :team_id
  AND lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'team_id' => $teamId,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
