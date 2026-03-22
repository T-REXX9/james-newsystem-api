<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\LegacyPermissionMapper;
use PDO;

final class AccessGroupRepository
{
    private LegacyPermissionMapper $legacyPermissions;

    public function __construct(private readonly Database $db)
    {
        $this->legacyPermissions = new LegacyPermissionMapper($db->pdo());
    }

    public function listGroups(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT DISTINCT
                CAST(ut.lid AS CHAR) AS id,
                CAST(COALESCE(NULLIF(ut.lmain_id, 0), :main_id) AS SIGNED) AS main_id,
                COALESCE(ut.ltype_name, \'\') AS name,
                COALESCE(ut.ldesc, \'\') AS description
             FROM tblusertype ut
             LEFT JOIN tblaccount a
               ON a.ltype = ut.lid
              AND a.lmother_id = :main_id
              AND a.lstatus = 1
             LEFT JOIN tblweb_permission wp
               ON wp.lgroup = ut.lid
              AND wp.lmain_id = :main_id
             WHERE ut.lid != 7
               AND (
                 ut.lmain_id = :main_id
                 OR COALESCE(ut.lmain_id, 0) = 0
                 OR a.lid IS NOT NULL
                 OR wp.lpageno IS NOT NULL
               )
             ORDER BY name ASC, id ASC'
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $row): array => $this->normalizeGroupRow($mainId, $row), $rows);
    }

    public function getGroupById(int $mainId, int $groupId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(ut.lid AS CHAR) AS id,
                CAST(COALESCE(NULLIF(ut.lmain_id, 0), :main_id) AS SIGNED) AS main_id,
                COALESCE(ut.ltype_name, \'\') AS name,
                COALESCE(ut.ldesc, \'\') AS description
             FROM tblusertype ut
             WHERE ut.lid = :group_id
               AND (
                 ut.lmain_id = :main_id
                 OR COALESCE(ut.lmain_id, 0) = 0
                 OR EXISTS (
                   SELECT 1
                   FROM tblaccount a
                   WHERE a.ltype = ut.lid
                     AND a.lmother_id = :main_id
                     AND a.lstatus = 1
                 )
                 OR EXISTS (
                   SELECT 1
                   FROM tblweb_permission wp
                   WHERE wp.lgroup = ut.lid
                     AND wp.lmain_id = :main_id
                 )
               )
             LIMIT 1'
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeGroupRow($mainId, $row) : null;
    }

    public function createGroup(int $mainId, array $data): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblusertype (ltype_name, ldesc, lmain_id, ldefault)
             VALUES (:name, :description, :main_id, 0)'
        );
        $stmt->execute([
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'main_id' => $mainId,
        ]);

        $groupId = (int) $this->db->pdo()->lastInsertId();
        $this->legacyPermissions->syncGroupPermissions(
            $mainId,
            $groupId,
            $this->sanitizeAccessRights($data['access_rights'] ?? [])
        );

        return $this->getGroupById($mainId, $groupId) ?? [
            'id' => (string) $groupId,
            'main_id' => $mainId,
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'access_rights' => $this->sanitizeAccessRights($data['access_rights'] ?? []),
            'assigned_staff_count' => 0,
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
            $updates[] = 'ltype_name = :name';
            $params['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $updates[] = 'ldesc = :description';
            $params['description'] = trim((string) ($data['description'] ?? ''));
        }

        if ($updates !== []) {
            $stmt = $this->db->pdo()->prepare(
                sprintf(
                    'UPDATE tblusertype SET %s WHERE lid = :group_id AND lmain_id = :main_id LIMIT 1',
                    implode(', ', $updates)
                )
            );
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            $stmt->execute();
        }

        if (array_key_exists('access_rights', $data)) {
            $this->legacyPermissions->syncGroupPermissions(
                $mainId,
                $groupId,
                $this->sanitizeAccessRights($data['access_rights'])
            );
        }

        return $this->getGroupById($mainId, $groupId);
    }

    public function deleteGroup(int $mainId, int $groupId): bool
    {
        $deletePerms = $this->db->pdo()->prepare(
            'DELETE FROM tblweb_permission WHERE lmain_id = :main_id AND lgroup = :group_id'
        );
        $deletePerms->execute([
            'main_id' => $mainId,
            'group_id' => $groupId,
        ]);

        $deleteGroup = $this->db->pdo()->prepare(
            'DELETE FROM tblusertype WHERE lid = :group_id AND lmain_id = :main_id LIMIT 1'
        );
        $deleteGroup->execute([
            'group_id' => $groupId,
            'main_id' => $mainId,
        ]);

        return $deleteGroup->rowCount() > 0;
    }

    public function countAssignedStaff(int $mainId, int $groupId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*)
             FROM tblaccount
             WHERE lmother_id = :main_id
               AND lstatus = 1
               AND ltype = :group_id'
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function normalizeGroupRow(int $mainId, array $row): array
    {
        $groupId = (int) ($row['id'] ?? 0);

        return [
            'id' => (string) $groupId,
            'main_id' => isset($row['main_id']) ? (int) $row['main_id'] : $mainId,
            'name' => trim((string) ($row['name'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'access_rights' => $this->legacyPermissions->getAccessRightsForGroup($mainId, $groupId),
            'created_at' => '',
            'assigned_staff_count' => $this->countAssignedStaff($mainId, $groupId),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeAccessRights(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''));
    }
}
