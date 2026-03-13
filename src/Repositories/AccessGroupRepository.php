<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class AccessGroupRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function listGroups(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(id AS CHAR) AS id,
    CAST(main_id AS SIGNED) AS main_id,
    COALESCE(name, '') AS name,
    COALESCE(description, '') AS description,
    COALESCE(access_rights, '[]') AS access_rights,
    COALESCE(created_at, NOW()) AS created_at,
    (
        SELECT COUNT(*)
        FROM tblaccount a
        WHERE a.lmother_id = access_groups.main_id
          AND a.lstatus = 1
          AND a.ltype != 1
          AND a.group_id = access_groups.id
    ) AS assigned_staff_count
FROM access_groups
WHERE main_id = :main_id
ORDER BY name ASC, id ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $row): array => $this->normalizeGroupRow($row), $rows);
    }

    public function getGroupById(int $mainId, int $groupId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(id AS CHAR) AS id,
    CAST(main_id AS SIGNED) AS main_id,
    COALESCE(name, '') AS name,
    COALESCE(description, '') AS description,
    COALESCE(access_rights, '[]') AS access_rights,
    COALESCE(created_at, NOW()) AS created_at,
    (
        SELECT COUNT(*)
        FROM tblaccount a
        WHERE a.lmother_id = access_groups.main_id
          AND a.lstatus = 1
          AND a.ltype != 1
          AND a.group_id = access_groups.id
    ) AS assigned_staff_count
FROM access_groups
WHERE id = :group_id
  AND main_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeGroupRow($row) : null;
    }

    public function createGroup(int $mainId, array $data): array
    {
        $sql = <<<SQL
INSERT INTO access_groups (
    main_id,
    name,
    description,
    access_rights,
    created_at
) VALUES (
    :main_id,
    :name,
    :description,
    :access_rights,
    NOW()
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('name', trim((string) ($data['name'] ?? '')), PDO::PARAM_STR);
        $stmt->bindValue('description', trim((string) ($data['description'] ?? '')), PDO::PARAM_STR);
        $stmt->bindValue('access_rights', $this->encodeAccessRights($data['access_rights'] ?? []), PDO::PARAM_STR);
        $stmt->execute();

        return $this->getGroupById($mainId, (int) $this->db->pdo()->lastInsertId()) ?? [
            'id' => (string) $this->db->pdo()->lastInsertId(),
            'main_id' => $mainId,
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'access_rights' => $this->decodeAccessRights($data['access_rights'] ?? []),
        ];
    }

    public function updateGroup(int $mainId, int $groupId, array $data): ?array
    {
        $existing = $this->getGroupById($mainId, $groupId);
        if ($existing === null) {
            return null;
        }

        $updates = [];
        $params = [
            'main_id' => $mainId,
            'group_id' => $groupId,
        ];

        if (array_key_exists('name', $data)) {
            $updates[] = 'name = :name';
            $params['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $updates[] = 'description = :description';
            $params['description'] = trim((string) ($data['description'] ?? ''));
        }

        $accessRightsChanged = false;
        if (array_key_exists('access_rights', $data)) {
            $updates[] = 'access_rights = :access_rights';
            $params['access_rights'] = $this->encodeAccessRights($data['access_rights']);
            $accessRightsChanged = true;
        }

        if (empty($updates)) {
            return $existing;
        }

        $sql = sprintf(
            'UPDATE access_groups SET %s WHERE id = :group_id AND main_id = :main_id LIMIT 1',
            implode(', ', $updates)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        if ($accessRightsChanged) {
            $this->syncInheritingStaffRights($mainId, $groupId, $data['access_rights']);
        }

        return $this->getGroupById($mainId, $groupId);
    }

    /**
     * Sync laccess_rights on tblaccount for staff inheriting from this group
     * (access_override = 0 or NULL). Staff with access_override = 1 keep their
     * individually-set permissions.
     */
    public function syncInheritingStaffRights(int $mainId, int $groupId, mixed $accessRights): void
    {
        $encoded = $this->encodeAccessRights($accessRights);

        $sql = <<<SQL
UPDATE tblaccount
SET laccess_rights = :access_rights
WHERE group_id = :group_id
  AND lmother_id = :main_id
  AND lstatus = 1
  AND ltype != 1
  AND (access_override IS NULL OR access_override = 0)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('access_rights', $encoded, PDO::PARAM_STR);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function deleteGroup(int $mainId, int $groupId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM access_groups WHERE id = :group_id AND main_id = :main_id LIMIT 1'
        );
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function countAssignedStaff(int $mainId, int $groupId): int
    {
        $sql = <<<SQL
SELECT COUNT(*) AS total
FROM tblaccount
WHERE lmother_id = :main_id
  AND lstatus = 1
  AND ltype != 1
  AND group_id = :group_id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function normalizeGroupRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'main_id' => isset($row['main_id']) ? (int) $row['main_id'] : null,
            'name' => trim((string) ($row['name'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'access_rights' => $this->decodeAccessRights($row['access_rights'] ?? []),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'assigned_staff_count' => (int) ($row['assigned_staff_count'] ?? 0),
        ];
    }

    private function encodeAccessRights(mixed $value): string
    {
        return json_encode($this->decodeAccessRights($value), JSON_UNESCAPED_SLASHES);
    }

    private function decodeAccessRights(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_string($item)));
    }
}
