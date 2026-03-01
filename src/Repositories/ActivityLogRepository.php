<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ActivityLogRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function list(
        int $mainId,
        string $search = '',
        string $userId = '',
        string $dateFrom = '',
        string $dateTo = '',
        int $page = 1,
        int $perPage = 100,
        bool $includeTotal = false
    ): array {
        $page = max(1, $page);
        $perPage = min(300, max(1, $perPage));
        $offset = ($page - 1) * $perPage;
        $limitWithProbe = $perPage + 1;

        $params = [
            'main_id' => $mainId,
        ];

        $where = ['log.lmain_id = :main_id'];

        $trimmedSearch = trim($search);
        if ($trimmedSearch !== '') {
            $params['search'] = '%' . $trimmedSearch . '%';
            $where[] = '(
                COALESCE(log.lpage, "") LIKE :search OR
                COALESCE(log.laction, "") LIKE :search OR
                COALESCE(log.lrefno, "") LIKE :search OR
                COALESCE(acc.lfname, "") LIKE :search OR
                COALESCE(acc.llname, "") LIKE :search
            )';
        }

        $trimmedUser = trim($userId);
        if ($trimmedUser !== '' && strtolower($trimmedUser) !== 'all') {
            $params['user_id'] = $trimmedUser;
            $where[] = 'log.luser_id = :user_id';
        }

        $dateFromNormalized = $this->normalizeDate($dateFrom);
        $dateToNormalized = $this->normalizeDate($dateTo);
        if ($dateFromNormalized !== '') {
            $params['date_from'] = $dateFromNormalized . ' 00:00:00';
            $where[] = 'log.ldatetime >= :date_from';
        }
        if ($dateToNormalized !== '') {
            $params['date_to'] = $dateToNormalized . ' 23:59:59';
            $where[] = 'log.ldatetime <= :date_to';
        }

        $whereSql = implode(' AND ', $where);
        $total = null;
        $totalPages = null;
        if ($includeTotal) {
            $countSql = <<<SQL
SELECT COUNT(*)
FROM tblaudit_trail log
LEFT JOIN tblaccount acc ON acc.lid = log.luser_id
WHERE {$whereSql}
SQL;

            $countStmt = $this->db->pdo()->prepare($countSql);
            foreach ($params as $key => $value) {
                if ($key === 'main_id') {
                    $countStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                    continue;
                }
                if ($key === 'user_id') {
                    $countStmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                    continue;
                }
                $countStmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $total = (int) ($countStmt->fetchColumn() ?: 0);
            $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        }

        $sql = <<<SQL
SELECT
    log.lid,
    COALESCE(log.lmain_id, '') AS lmain_id,
    COALESCE(log.luser_id, '') AS luser_id,
    COALESCE(log.lpage, '') AS lpage,
    COALESCE(log.laction, '') AS laction,
    COALESCE(log.lrefno, '') AS lrefno,
    COALESCE(log.ldatetime, '') AS ldatetime,
    COALESCE(acc.lfname, '') AS userfname,
    COALESCE(acc.llname, '') AS userlname
FROM tblaudit_trail log
LEFT JOIN tblaccount acc ON acc.lid = log.luser_id
WHERE {$whereSql}
ORDER BY log.lid DESC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === 'main_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            if ($key === 'user_id') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }
            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $limitWithProbe, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $hasMore,
                'filters' => [
                    'search' => $trimmedSearch,
                    'user_id' => $trimmedUser,
                    'date_from' => $dateFromNormalized,
                    'date_to' => $dateToNormalized,
                ],
            ],
        ];
    }

    public function users(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    CAST(acc.lid AS CHAR) AS user_id,
    COALESCE(acc.lfname, '') AS first_name,
    COALESCE(acc.llname, '') AS last_name
FROM tblaudit_trail log
INNER JOIN tblaccount acc ON acc.lid = log.luser_id
WHERE log.lmain_id = :main_id
GROUP BY acc.lid, acc.lfname, acc.llname
ORDER BY acc.lfname ASC, acc.llname ASC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizeDate(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $ts = strtotime($trimmed);
        if ($ts === false) {
            return '';
        }
        return date('Y-m-d', $ts);
    }
}
