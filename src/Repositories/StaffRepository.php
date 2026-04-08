<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\LegacyPermissionMapper;
use App\Support\Exceptions\HttpException;
use PDO;

final class StaffRepository
{
    private LegacyPermissionMapper $legacyPermissions;

    public function __construct(private readonly Database $db)
    {
        $this->legacyPermissions = new LegacyPermissionMapper($db->pdo());
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listStaff(
        int $mainId,
        string $search = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['a.lmother_id = :main_id', 'a.lstatus = 1', 'a.ltype != 1'];
        $params = [
            'main_id' => $mainId,
        ];

        $trimmed = trim($search);
        if ($trimmed !== '') {
            $where[] = "(a.lfname LIKE :search OR a.llname LIKE :search OR a.lemail LIKE :search)";
            $params['search'] = '%' . $trimmed . '%';
        }

        $whereSql = implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(a.lid AS SIGNED) AS id,
    CAST(COALESCE(a.lmother_id, 0) AS SIGNED) AS main_id,
    CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.lmname, ''), COALESCE(a.llname, '')) AS full_name,
    COALESCE(a.lemail, '') AS email,
    COALESCE(
        (SELECT ut.ltype_name FROM tblusertype ut WHERE ut.lid = a.ltype LIMIT 1),
        'Sales Agent'
    ) AS role,
    COALESCE(a.lmobile, '') AS mobile,
    CAST(COALESCE(a.lteam, 0) AS CHAR) AS team_id,
    COALESCE(
        (SELECT t.lteamname FROM tblteamstaff t WHERE t.lid = a.lteam LIMIT 1),
        ''
    ) AS team_name,
    CAST(COALESCE(a.lstatus, 1) AS SIGNED) AS status,
    COALESCE(a.lbirthday, '') AS birthday,
    COALESCE(a.lavatar, '') AS avatar_url,
    CAST(COALESCE(a.ltype, 0) AS CHAR) AS group_id,
    CAST(COALESCE(a.lsales_quota, 0) AS DECIMAL(15,2)) AS monthly_quota,
    CAST(COALESCE(a.lcommission, 0) AS DECIMAL(10,2)) AS commission,
    COALESCE(a.ldatereg, NOW()) AS created_at,
    '[]' AS laccess_rights,
    0 AS access_override
FROM tblaccount a
WHERE {$whereSql}
ORDER BY a.lid DESC
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

        $rows = $this->dedupeStaffRows(array_map(fn (array $row): array => $this->normalizeStaffRow($row), $rows));

        $countSql = "SELECT COUNT(*) AS total FROM tblaccount a WHERE {$whereSql}";
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

    public function getStaffById(int $mainId, int $staffId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(a.lid AS SIGNED) AS id,
    CAST(COALESCE(a.lmother_id, 0) AS SIGNED) AS main_id,
    COALESCE(a.lfname, '') AS first_name,
    COALESCE(a.lmname, '') AS middle_name,
    COALESCE(a.llname, '') AS last_name,
    CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.lmname, ''), COALESCE(a.llname, '')) AS full_name,
    COALESCE(a.lemail, '') AS email,
    COALESCE(
        (SELECT ut.ltype_name FROM tblusertype ut WHERE ut.lid = a.ltype LIMIT 1),
        'Sales Agent'
    ) AS role,
    CAST(COALESCE(a.ltype, 0) AS SIGNED) AS role_id,
    COALESCE(a.lmobile, '') AS mobile,
    COALESCE(a.lcontact, '') AS contact,
    CAST(COALESCE(a.lteam, 0) AS CHAR) AS team_id,
    COALESCE(
        (SELECT t.lteamname FROM tblteamstaff t WHERE t.lid = a.lteam LIMIT 1),
        ''
    ) AS team_name,
    CAST(COALESCE(a.lstatus, 1) AS SIGNED) AS status,
    COALESCE(a.lgender, '') AS gender,
    COALESCE(DATE_FORMAT(a.lbirthday, '%Y-%m-%d'), '') AS birthday,
    COALESCE(a.lavatar, '') AS avatar_url,
    CAST(COALESCE(a.ltype, 0) AS CHAR) AS group_id,
    CAST(COALESCE(a.lbranch, 0) AS SIGNED) AS branch_id,
    CAST(COALESCE(a.lsales_quota, 0) AS DECIMAL(15,2)) AS sales_quota,
    CAST(COALESCE(a.lprospect_quota, 0) AS DECIMAL(15,2)) AS prospect_quota,
    CAST(COALESCE(a.lcommission, 0) AS DECIMAL(10,2)) AS commission,
    COALESCE(a.ldatereg, NOW()) AS created_at,
    '[]' AS laccess_rights,
    0 AS access_override
FROM tblaccount a
WHERE a.lid = :staff_id
  AND a.lmother_id = :main_id
  AND a.lstatus = 1
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('staff_id', $staffId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->normalizeStaffRow($row);
    }

    public function updateStaff(int $mainId, int $staffId, array $data): ?array
    {
        $existing = $this->getStaffById($mainId, $staffId);
        if ($existing === null) {
            return null;
        }

        $updates = [];
        $params = [
            'staff_id' => $staffId,
            'main_id' => $mainId,
        ];

        // Handle full_name -> split into first/last name
        if (isset($data['full_name'])) {
            $nameParts = explode(' ', trim($data['full_name']), 2);
            $updates[] = 'lfname = :first_name';
            $params['first_name'] = $nameParts[0] ?? '';
            if (isset($nameParts[1])) {
                $updates[] = 'llname = :last_name';
                $params['last_name'] = $nameParts[1];
            }
        }

        // Handle role -> need to find or create user type
        if (isset($data['role'])) {
            $roleId = $this->findOrCreateUserType($mainId, $data['role']);
            $updates[] = 'ltype = :role_id';
            $params['role_id'] = $roleId;
        }

        if (isset($data['mobile'])) {
            $updates[] = 'lmobile = :mobile';
            $params['mobile'] = $data['mobile'];
        }

        if (isset($data['team_id'])) {
            $updates[] = 'lteam = :team_id';
            $params['team_id'] = $data['team_id'] === '' ? null : (int) $data['team_id'];
        }

        if (isset($data['birthday'])) {
            $updates[] = 'lbirthday = :birthday';
            $params['birthday'] = $data['birthday'];
        }

        if (isset($data['gender'])) {
            $updates[] = 'lgender = :gender';
            $params['gender'] = $data['gender'];
        }

        if (isset($data['contact'])) {
            $updates[] = 'lcontact = :contact';
            $params['contact'] = $data['contact'];
        }

        if (isset($data['avatar_url'])) {
            $updates[] = 'lavatar = :avatar';
            $params['avatar'] = $data['avatar_url'];
        }

        if (isset($data['sales_quota'])) {
            $updates[] = 'lsales_quota = :sales_quota';
            $params['sales_quota'] = (float) $data['sales_quota'];
        }

        if (isset($data['prospect_quota'])) {
            $updates[] = 'lprospect_quota = :prospect_quota';
            $params['prospect_quota'] = (float) $data['prospect_quota'];
        }

        if (isset($data['commission'])) {
            $updates[] = 'lcommission = :commission';
            $params['commission'] = (float) $data['commission'];
        }

        if (isset($data['branch_id'])) {
            $updates[] = 'lbranch = :branch_id';
            $params['branch_id'] = (int) $data['branch_id'];
        }

        if (array_key_exists('group_id', $data)) {
            $updates[] = 'ltype = :group_id';
            $params['group_id'] = $data['group_id'] === '' || $data['group_id'] === null
                ? (int) ($existing['role_id'] ?? 0)
                : (int) $data['group_id'];
        }

        $effectiveGroupId = isset($params['group_id'])
            ? (int) $params['group_id']
            : (int) ($existing['role_id'] ?? 0);

        if (array_key_exists('access_rights', $data) && is_array($data['access_rights'])) {
            $this->legacyPermissions->syncGroupPermissions(
                $mainId,
                $effectiveGroupId,
                array_values($data['access_rights'])
            );
        }

        if (empty($updates)) {
            return $existing;
        }

        $updateSql = implode(', ', $updates);
        $sql = <<<SQL
UPDATE tblaccount
SET {$updateSql}
WHERE lid = :staff_id
  AND lmother_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : (is_null($value) ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        return $this->getStaffById($mainId, $staffId);
    }

    public function createStaff(int $mainId, array $data): array
    {
        $nameParts = preg_split('/\s+/', trim((string) ($data['full_name'] ?? '')), 2) ?: [];
        $firstName = trim((string) ($nameParts[0] ?? ''));
        $lastName = trim((string) ($nameParts[1] ?? ''));
        $email = $this->normalizeEmail((string) ($data['email'] ?? ''));
        if ($email === '') {
            throw new HttpException(422, 'email is required');
        }
        $this->assertEmailAvailable($email);
        $roleId = $this->findOrCreateUserType($mainId, (string) $data['role']);
        $password = (string) ($data['password'] ?? '');
        $recode = md5($password);
        $hashedPassword = md5($password . $recode);
        $sql = <<<SQL
INSERT INTO tblaccount (
    lfname,
    llname,
    lemail,
    lpassword,
    lmobile,
    lbirthday,
    ltype,
    lmother_id,
    lstatus,
    lactivation
) VALUES (
    :first_name,
    :last_name,
    :email,
    :password,
    :mobile,
    :birthday,
    :role_id,
    :main_id,
    1,
    1
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('first_name', $firstName, PDO::PARAM_STR);
        $stmt->bindValue('last_name', $lastName, PDO::PARAM_STR);
        $stmt->bindValue('email', $email, PDO::PARAM_STR);
        $stmt->bindValue('password', $hashedPassword, PDO::PARAM_STR);
        $stmt->bindValue('mobile', (string) ($data['mobile'] ?? ''), PDO::PARAM_STR);
        $birthday = trim((string) ($data['birthday'] ?? ''));
        if ($birthday === '') {
            $stmt->bindValue('birthday', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue('birthday', $birthday, PDO::PARAM_STR);
        }
        $stmt->bindValue('role_id', (string) $roleId, PDO::PARAM_STR);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $created = $this->getStaffById($mainId, (int) $this->db->pdo()->lastInsertId());
        if ($created === null) {
            throw new \RuntimeException('Failed to load created staff record');
        }

        return $created;
    }

    public function deleteStaff(int $mainId, int $staffId): bool
    {
        $existing = $this->getStaffById($mainId, $staffId);
        if ($existing === null) {
            return false;
        }

        // Soft delete - set status to 0
        $sql = <<<SQL
UPDATE tblaccount
SET lstatus = 0
WHERE lid = :staff_id
  AND lmother_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'staff_id' => $staffId,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Find user type by name or create if not exists
     */
    private function findOrCreateUserType(int $mainId, string $roleName): int
    {
        // First try to find existing
        $sql = "SELECT lid FROM tblusertype WHERE ltype_name = :name AND (lmain_id = :main_id OR ldefault = 0) LIMIT 1";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('name', $roleName, PDO::PARAM_STR);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $existing = $stmt->fetchColumn();

        if ($existing) {
            return (int) $existing;
        }

        // Create new user type
        $insertSql = "INSERT INTO tblusertype (ltype_name, lmain_id, ldefault) VALUES (:name, :main_id, 0)";
        $insertStmt = $this->db->pdo()->prepare($insertSql);
        $insertStmt->execute([
            'name' => $roleName,
            'main_id' => $mainId,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Get all user types/roles for dropdown
     */
    public function getUserTypes(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(lid AS SIGNED) AS id,
    COALESCE(ltype_name, '') AS name
FROM tblusertype
WHERE lmain_id = :main_id OR ldefault = 0
ORDER BY ltype_name ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function parseAccessRights($value): array
    {
        if (is_array($value)) {
            return array_filter($value, 'is_string');
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $parsed = json_decode($value, true);
            return is_array($parsed) ? array_filter($parsed, 'is_string') : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private function normalizeStaffRow(array $row): array
    {
        $row['id'] = (string) ($row['id'] ?? '');
        $row['full_name'] = trim((string) ($row['full_name'] ?? ''));
        if (array_key_exists('team_id', $row)) {
            $row['team_id'] = ($row['team_id'] ?? '') === '0' ? '' : (string) $row['team_id'];
        }
        $groupId = (int) ($row['group_id'] ?? $row['role_id'] ?? 0);
        $row['group_id'] = $groupId > 0 ? (string) $groupId : null;
        $row['access_override'] = false;
        $mainId = (int) ($row['main_id'] ?? 0);
        if ($mainId <= 0) {
            $mainId = (int) ($row['mother_id'] ?? 0);
        }
        if ($mainId <= 0) {
            $mainId = (int) ($row['resolved_main_id'] ?? 0);
        }
        $row['access_rights'] = $mainId > 0 && $groupId > 0
            ? $this->legacyPermissions->getAccessRightsForGroup($mainId, $groupId)
            : ['home'];

        return $row;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function dedupeStaffRows(array $rows): array
    {
        $seen = [];
        $deduped = [];

        foreach ($rows as $row) {
            $emailKey = $this->normalizeEmail((string) ($row['email'] ?? ''));
            $nameKey = strtolower(trim((string) ($row['full_name'] ?? '')));
            $key = $emailKey !== '' ? 'email:' . $emailKey : 'name:' . $nameKey;

            if ($key !== 'name:' && isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduped[] = $row;
        }

        return $deduped;
    }

    private function assertEmailAvailable(string $email, ?int $excludeStaffId = null): void
    {
        $sql = <<<SQL
SELECT CAST(lid AS SIGNED) AS id
FROM tblaccount
WHERE LOWER(TRIM(COALESCE(lemail, ''))) = :email
  AND COALESCE(lstatus, 0) = 1
  AND (:exclude_staff_id IS NULL OR lid <> :exclude_staff_id_match)
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('email', $email, PDO::PARAM_STR);
        if ($excludeStaffId === null) {
            $stmt->bindValue('exclude_staff_id', null, PDO::PARAM_NULL);
            $stmt->bindValue('exclude_staff_id_match', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue('exclude_staff_id', $excludeStaffId, PDO::PARAM_INT);
            $stmt->bindValue('exclude_staff_id_match', $excludeStaffId, PDO::PARAM_INT);
        }
        $stmt->execute();

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new HttpException(422, 'An active account with this email already exists');
        }
    }
}
