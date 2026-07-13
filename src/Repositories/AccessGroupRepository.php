<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\LegacyPermissionMapper;
use PDO;

final class AccessGroupRepository
{
    private LegacyPermissionMapper $legacyPermissions;
    private const SALES_AGENT_NAME = 'Sales Agent';
    private const WAREHOUSE_PERSONNEL_NAME = 'Warehouse Personnel';
    private const COMPANY_OWNER_NAME = 'Company Owner';

    public function __construct(private readonly Database $db)
    {
        $this->legacyPermissions = new LegacyPermissionMapper($db->pdo());
    }

    public function listGroups(int $mainId): array
    {
        $this->consolidateSalesPersonIntoSalesAgent($mainId);
        $this->ensureCoreAccessGroups($mainId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(ut.lid AS CHAR) AS id,
                CAST(COALESCE(NULLIF(ut.lmain_id, 0), :main_id_select) AS SIGNED) AS main_id,
                COALESCE(ut.ltype_name, \'\') AS name,
                COALESCE(ut.ldesc, \'\') AS description
             FROM tblusertype ut
             LEFT JOIN tblaccount a
               ON a.ltype = ut.lid
              AND a.lmother_id = :main_id_join_account
              AND a.lstatus = 1
             LEFT JOIN tblweb_permission wp
               ON wp.lgroup = ut.lid
              AND wp.lmain_id = :main_id_join_wp
             WHERE ut.lid != 7
               AND (
                 ut.lmain_id = :main_id_where
                 OR COALESCE(ut.lmain_id, 0) = 0
                 OR a.lid IS NOT NULL
                 OR wp.lpageno IS NOT NULL
               )
               AND LOWER(TRIM(COALESCE(ut.ltype_name, \'\'))) NOT IN (\'sales person\', \'salesperson\')
             GROUP BY ut.lid
             ORDER BY ut.ltype_name ASC, ut.lid ASC'
        );
        $stmt->bindValue('main_id_select', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_join_account', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_join_wp', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_where', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn (array $row): array => $this->normalizeGroupRow($mainId, $row), $rows);
    }

    public function getGroupById(int $mainId, int $groupId): ?array
    {
        $this->consolidateSalesPersonIntoSalesAgent($mainId);

        $stmt = $this->db->pdo()->prepare(
            'SELECT
                CAST(ut.lid AS CHAR) AS id,
                CAST(COALESCE(NULLIF(ut.lmain_id, 0), :main_id_select) AS SIGNED) AS main_id,
                COALESCE(ut.ltype_name, \'\') AS name,
                COALESCE(ut.ldesc, \'\') AS description
             FROM tblusertype ut
             WHERE ut.lid = :group_id
               AND (
                 ut.lmain_id = :main_id_where
                 OR COALESCE(ut.lmain_id, 0) = 0
                 OR EXISTS (
                   SELECT 1
                   FROM tblaccount a
                   WHERE a.ltype = ut.lid
                     AND a.lmother_id = :main_id_account
                     AND a.lstatus = 1
                 )
                 OR EXISTS (
                   SELECT 1
                   FROM tblweb_permission wp
                   WHERE wp.lgroup = ut.lid
                     AND wp.lmain_id = :main_id_wp
                 )
               )
             LIMIT 1'
        );
        $stmt->bindValue('main_id_select', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_where', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_account', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_wp', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('group_id', $groupId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeGroupRow($mainId, $row) : null;
    }

    public function createGroup(int $mainId, array $data): array
    {
        $name = $this->canonicalizeRoleName((string) ($data['name'] ?? ''));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblusertype (ltype_name, ldesc, lmain_id, ldefault)
             VALUES (:name, :description, :main_id, 0)'
        );
        $stmt->execute([
            'name' => $name,
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
            'name' => $name,
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
            $params['name'] = $this->canonicalizeRoleName((string) $data['name']);
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
            'name' => $this->canonicalizeRoleName((string) ($row['name'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
            'access_rights' => $this->isCompanyOwnerName((string) ($row['name'] ?? ''))
                ? ['*']
                : $this->legacyPermissions->getAccessRightsForGroup($mainId, $groupId),
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

    private function canonicalizeRoleName(string $name): string
    {
        if ($this->isSalesPersonName($name)) {
            return self::SALES_AGENT_NAME;
        }

        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        if (in_array($normalized, ['warehouse', 'warehouse staff', 'warehouse personnel'], true)) {
            return self::WAREHOUSE_PERSONNEL_NAME;
        }
        if (in_array($normalized, ['owner', 'company owner'], true)) {
            return self::COMPANY_OWNER_NAME;
        }

        return trim($name);
    }

    private function isSalesPersonName(string $name): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
        return $normalized === 'sales person' || $normalized === 'salesperson';
    }

    private function isSalesAgentName(string $name): bool
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name))) === 'sales agent';
    }

    private function isCompanyOwnerName(string $name): bool
    {
        return in_array(strtolower(trim(preg_replace('/\s+/', ' ', $name))), ['owner', 'company owner'], true);
    }

    private function findOrCreateAccessGroup(int $mainId, string $name, string $description, array $rights): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(lid AS SIGNED)
             FROM tblusertype
             WHERE LOWER(TRIM(COALESCE(ltype_name, \'\'))) = LOWER(TRIM(:name))
               AND (lmain_id = :main_id OR COALESCE(lmain_id, 0) = 0)
             ORDER BY CASE WHEN lmain_id = :main_id_order THEN 0 ELSE 1 END, lid ASC
             LIMIT 1'
        );
        $stmt->bindValue('name', $name, PDO::PARAM_STR);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('main_id_order', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $existing = (int) ($stmt->fetchColumn() ?: 0);

        if ($existing > 0) {
            $currentRights = $this->legacyPermissions->getAccessRightsForGroup($mainId, $existing);
            if (!$this->isCompanyOwnerName($name) && count(array_diff($currentRights, ['home'])) === 0) {
                $this->legacyPermissions->syncGroupPermissions($mainId, $existing, $rights);
            }
            return $existing;
        }

        $insertStmt = $this->db->pdo()->prepare(
            'INSERT INTO tblusertype (ltype_name, ldesc, lmain_id, ldefault)
             VALUES (:name, :description, :main_id, 0)'
        );
        $insertStmt->execute([
            'name' => $name,
            'description' => $description,
            'main_id' => $mainId,
        ]);

        $groupId = (int) $this->db->pdo()->lastInsertId();
        $this->legacyPermissions->syncGroupPermissions($mainId, $groupId, $rights);

        return $groupId;
    }

    private function ensureCoreAccessGroups(int $mainId): void
    {
        $this->findOrCreateAccessGroup($mainId, self::SALES_AGENT_NAME, 'Sales staff access for inquiries, orders, customers, calls, and sales reports.', [
            'home',
            'sales-pipeline-board',
            'sales-database-customer-database',
            'sales-transaction-sales-inquiry',
            'sales-transaction-sales-order',
            'sales-transaction-order-slip',
            'sales-transaction-invoice',
            'sales-transaction-daily-call-monitoring',
            'sales-transaction-product-promotions',
            'sales-reports-inquiry-report',
            'sales-reports-sales-report',
            'sales-reports-sales-development-report',
            'communication-productivity-tasks',
            'communication-productivity-calendar',
        ]);

        $this->findOrCreateAccessGroup($mainId, self::WAREHOUSE_PERSONNEL_NAME, 'Warehouse access for inventory, stock movement, purchasing, receiving, and warehouse reports.', [
            'home',
            'warehouse-inventory-product-database',
            'warehouse-inventory-stock-movement',
            'warehouse-inventory-transfer-stock',
            'warehouse-inventory-stock-adjustment',
            'warehouse-inventory-inventory-audit',
            'warehouse-purchasing-purchase-request',
            'warehouse-purchasing-purchase-order',
            'warehouse-purchasing-receiving-stock',
            'warehouse-purchasing-return-to-supplier',
            'warehouse-reports-inventory-report',
            'warehouse-reports-reorder-report',
            'warehouse-reports-item-suggested-for-stock-report',
            'warehouse-reports-fast-slow-inventory-report',
            'warehouse-reports-incident-items-report',
        ]);

        $this->findOrCreateAccessGroup($mainId, self::COMPANY_OWNER_NAME, 'Reserved owner-level account with full system access.', ['*']);
    }

    private function consolidateSalesPersonIntoSalesAgent(int $mainId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT CAST(lid AS SIGNED) AS id,
                    CAST(COALESCE(lmain_id, 0) AS SIGNED) AS main_id,
                    COALESCE(ltype_name, \'\') AS name
               FROM tblusertype
              WHERE lmain_id = :main_id OR COALESCE(lmain_id, 0) = 0'
        );
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $targetGroupId = 0;
        foreach ($rows as $row) {
            if (!$this->isSalesAgentName((string) ($row['name'] ?? ''))) {
                continue;
            }

            $candidateId = (int) ($row['id'] ?? 0);
            $candidateMainId = (int) ($row['main_id'] ?? 0);
            if ($candidateId <= 0) {
                continue;
            }

            if ($candidateMainId === $mainId) {
                $targetGroupId = $candidateId;
                break;
            }

            if ($targetGroupId === 0) {
                $targetGroupId = $candidateId;
            }
        }

        if ($targetGroupId === 0) {
            $insertStmt = $this->db->pdo()->prepare(
                'INSERT INTO tblusertype (ltype_name, ldesc, lmain_id, ldefault)
                 VALUES (:name, \'\', :main_id, 0)'
            );
            $insertStmt->execute([
                'name' => self::SALES_AGENT_NAME,
                'main_id' => $mainId,
            ]);
            $targetGroupId = (int) $this->db->pdo()->lastInsertId();
        }

        $mergedRights = $this->legacyPermissions->getAccessRightsForGroup($mainId, $targetGroupId);

        foreach ($rows as $row) {
            $sourceGroupId = (int) ($row['id'] ?? 0);
            if ($sourceGroupId <= 0 || $sourceGroupId === $targetGroupId) {
                continue;
            }

            if (!$this->isSalesPersonName((string) ($row['name'] ?? ''))) {
                continue;
            }

            $sourceRights = $this->legacyPermissions->getAccessRightsForGroup($mainId, $sourceGroupId);
            $mergedRights = array_values(array_unique(array_merge($mergedRights, $sourceRights)));

            $reassignAccounts = $this->db->pdo()->prepare(
                'UPDATE tblaccount
                    SET ltype = :target_group_id
                  WHERE lmother_id = :main_id
                    AND lstatus = 1
                    AND ltype = :source_group_id'
            );
            $reassignAccounts->execute([
                'target_group_id' => $targetGroupId,
                'main_id' => $mainId,
                'source_group_id' => $sourceGroupId,
            ]);

            $deleteSourcePermissions = $this->db->pdo()->prepare(
                'DELETE FROM tblweb_permission
                  WHERE lmain_id = :main_id
                    AND lgroup = :source_group_id'
            );
            $deleteSourcePermissions->execute([
                'main_id' => $mainId,
                'source_group_id' => $sourceGroupId,
            ]);
        }

        $this->legacyPermissions->syncGroupPermissions($mainId, $targetGroupId, $mergedRights);
    }
}
