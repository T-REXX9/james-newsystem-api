<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ApproverRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listApprovers(
        int $mainId,
        string $search = '',
        string $module = '',
        int $page = 1,
        int $perPage = 100
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['app.lmain_id = :main_id'];
        $params = [
            'main_id' => $mainId,
        ];

        // Filter by module if provided
        if ($module !== '' && $module !== 'all') {
            $where[] = 'app.ltrans_type = :module';
            $params['module'] = $module;
        }

        // Search by staff name
        $trimmed = trim($search);
        if ($trimmed !== '') {
            $where[] = "(a.lfname LIKE :search OR a.llname LIKE :search OR a.lemail LIKE :search)";
            $params['search'] = '%' . $trimmed . '%';
        }

        $whereSql = implode(' AND ', $where);

        $sql = <<<SQL
SELECT
    CAST(app.lid AS CHAR) AS id,
    CAST(app.lstaff_id AS CHAR) AS user_id,
    CAST(app.lstaff_id AS CHAR) AS staff_id,
    COALESCE(app.ltrans_type, 'Collection') AS module,
    CAST(COALESCE(app.lorder, 1) AS SIGNED) AS level,
    CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.llname, '')) AS staff_name,
    COALESCE(a.lemail, '') AS staff_email,
    app.lid AS created_at
FROM tblapprover app
LEFT JOIN tblaccount a ON a.lid = app.lstaff_id
WHERE {$whereSql}
ORDER BY app.lorder ASC, app.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $params['main_id'], PDO::PARAM_INT);
        if (isset($params['module'])) {
            $stmt->bindValue('module', $params['module'], PDO::PARAM_STR);
        }
        if (isset($params['search'])) {
            $stmt->bindValue('search', $params['search'], PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize rows
        foreach ($rows as &$row) {
            $row['staff_name'] = trim($row['staff_name']);
        }

        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tblapprover app
LEFT JOIN tblaccount a ON a.lid = app.lstaff_id
WHERE {$whereSql}
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        if (isset($params['module'])) {
            $countStmt->bindValue('module', $params['module'], PDO::PARAM_STR);
        }
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

    public function getApproverById(int $mainId, int $approverId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(app.lid AS CHAR) AS id,
    CAST(app.lstaff_id AS CHAR) AS user_id,
    CAST(app.lstaff_id AS CHAR) AS staff_id,
    COALESCE(app.ltrans_type, 'Collection') AS module,
    CAST(COALESCE(app.lorder, 1) AS SIGNED) AS level,
    CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.llname, '')) AS staff_name,
    COALESCE(a.lemail, '') AS staff_email
FROM tblapprover app
LEFT JOIN tblaccount a ON a.lid = app.lstaff_id
WHERE app.lid = :approver_id
  AND app.lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('approver_id', $approverId, PDO::PARAM_INT);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['staff_name'] = trim($row['staff_name']);
        return $row;
    }

    public function createApprover(int $mainId, int $staffId, string $module, int $level): array
    {
        $sql = <<<SQL
INSERT INTO tblapprover (lmain_id, lstaff_id, ltrans_type, lorder)
VALUES (:main_id, :staff_id, :module, :level)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'staff_id' => $staffId,
            'module' => $module,
            'level' => $level,
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();

        return $this->getApproverById($mainId, $id) ?? [
            'id' => (string) $id,
            'user_id' => (string) $staffId,
            'staff_id' => (string) $staffId,
            'module' => $module,
            'level' => $level,
        ];
    }

    public function updateApprover(int $mainId, int $approverId, array $data): ?array
    {
        $existing = $this->getApproverById($mainId, $approverId);
        if ($existing === null) {
            return null;
        }

        $updates = [];
        $params = [
            'approver_id' => $approverId,
            'main_id' => $mainId,
        ];

        if (isset($data['user_id']) || isset($data['staff_id'])) {
            $staffId = $data['user_id'] ?? $data['staff_id'];
            $updates[] = 'lstaff_id = :staff_id';
            $params['staff_id'] = (int) $staffId;
        }

        if (isset($data['module'])) {
            $updates[] = 'ltrans_type = :module';
            $params['module'] = $data['module'];
        }

        if (isset($data['level'])) {
            $updates[] = 'lorder = :level';
            $params['level'] = (int) $data['level'];
        }

        if (empty($updates)) {
            return $existing;
        }

        $updateSql = implode(', ', $updates);
        $sql = <<<SQL
UPDATE tblapprover
SET {$updateSql}
WHERE lid = :approver_id
  AND lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();

        return $this->getApproverById($mainId, $approverId);
    }

    public function deleteApprover(int $mainId, int $approverId): bool
    {
        $existing = $this->getApproverById($mainId, $approverId);
        if ($existing === null) {
            return false;
        }

        $sql = <<<SQL
DELETE FROM tblapprover
WHERE lid = :approver_id
  AND lmain_id = :main_id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'approver_id' => $approverId,
            'main_id' => $mainId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get available staff for approver dropdown
     */
    public function getAvailableStaff(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(a.lid AS CHAR) AS id,
    CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.llname, '')) AS full_name,
    COALESCE(a.lemail, '') AS email
FROM tblaccount a
WHERE a.lmother_id = :main_id
  AND a.lstatus = 1
  AND a.ltype != 1
ORDER BY a.lfname ASC, a.llname ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['full_name'] = trim($row['full_name']);
        }

        return $rows;
    }
}
