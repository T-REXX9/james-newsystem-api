<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class AuthRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findActiveUserByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
             FROM tblaccount
             WHERE lemail = :email
               AND COALESCE(lstatus, 0) = 1
             LIMIT 1'
        );
        $stmt->execute(['email' => trim($email)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findUserById(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *
             FROM tblaccount
             WHERE lid = :id
               AND COALESCE(lstatus, 0) = 1
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getWebPermissions(int $mainUserId, string $group): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lpageno, lstatus, ladd_action, ledit_action, ldelete_action
             FROM tblweb_permission
             WHERE lmain_id = :main_id
               AND lgroup = :lgroup'
        );
        $stmt->execute([
            'main_id' => $mainUserId,
            'lgroup' => $group,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPackagePermissions(int $mainUserId, string $packageId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lpageno, lstatus
             FROM tblmy_permission
             WHERE luserid = :main_id
               AND lpackage = :package_id'
        );
        $stmt->execute([
            'main_id' => $mainUserId,
            'package_id' => $packageId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function hashLegacyPassword(string $rawPassword): string
    {
        $recode = md5($rawPassword);
        return md5($rawPassword . $recode);
    }

    public function resolveMainUserId(array $user): int
    {
        $type = (string) ($user['ltype'] ?? '1');
        $userId = (int) ($user['lid'] ?? 0);
        if ($type === '1') {
            return $userId;
        }

        $mother = (int) ($user['lmother_id'] ?? 0);
        return $mother > 0 ? $mother : $userId;
    }
}
