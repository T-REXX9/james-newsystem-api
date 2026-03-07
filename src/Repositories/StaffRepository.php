<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class StaffRepository
{
    public function __construct(private readonly Database $db)
    {
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
    COALESCE(a.laccess_rights, '[]') AS access_rights,
    CAST(COALESCE(a.lsales_quota, 0) AS DECIMAL(15,2)) AS monthly_quota,
    CAST(COALESCE(a.lcommission, 0) AS DECIMAL(10,2)) AS commission,
    COALESCE(a.ldatereg, NOW()) AS created_at
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

        // Normalize rows
        foreach ($rows as &$row) {
            $row = $this->normalizeStaffRow($row);
        }

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
    COALESCE(a.laccess_rights, '[]') AS access_rights,
    CAST(COALESCE(a.lbranch, 0) AS SIGNED) AS branch_id,
    CAST(COALESCE(a.lsales_quota, 0) AS DECIMAL(15,2)) AS sales_quota,
    CAST(COALESCE(a.lprospect_quota, 0) AS DECIMAL(15,2)) AS prospect_quota,
    CAST(COALESCE(a.lcommission, 0) AS DECIMAL(10,2)) AS commission,
    COALESCE(a.ldatereg, NOW()) AS created_at
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

        if (array_key_exists('access_rights', $data)) {
            $updates[] = 'laccess_rights = :access_rights';
            $params['access_rights'] = json_encode(
                is_array($data['access_rights']) ? array_values($data['access_rights']) : [],
                JSON_UNESCAPED_SLASHES
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
        $roleId = $this->findOrCreateUserType($mainId, (string) $data['role']);
        $password = (string) ($data['password'] ?? '');
        $recode = md5($password);
        $hashedPassword = md5($password . $recode);
        $accessRights = json_encode(
            is_array($data['access_rights'] ?? null) ? array_values($data['access_rights']) : [],
            JSON_UNESCAPED_SLASHES
        );

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
    lactivation,
    laccess_rights
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
    1,
    :access_rights
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('first_name', $firstName, PDO::PARAM_STR);
        $stmt->bindValue('last_name', $lastName, PDO::PARAM_STR);
        $stmt->bindValue('email', trim((string) ($data['email'] ?? '')), PDO::PARAM_STR);
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
        $stmt->bindValue('access_rights', $accessRights, PDO::PARAM_STR);
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

    private function normalizeStaffRow(array $row): array
    {
        $row['id'] = (string) ($row['id'] ?? '');
        $row['full_name'] = trim((string) ($row['full_name'] ?? ''));
        if (array_key_exists('team_id', $row)) {
            $row['team_id'] = ($row['team_id'] ?? '') === '0' ? '' : (string) $row['team_id'];
        }
        $row['access_rights'] = $this->decodeAccessRights($row['access_rights'] ?? []);

        return $row;
    }

    /**
     * @return array<int, string>
     */
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
