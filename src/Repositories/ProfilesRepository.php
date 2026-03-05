<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;

final class ProfilesRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * List all user profiles with pagination
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query('SELECT COUNT(*) as total FROM tbluser WHERE lstatus = 1');
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tbluser WHERE lstatus = 1 ORDER BY lfname ASC LIMIT ? OFFSET ?',
            [$perPage, $offset]
        );

        return [
            'data' => array_map(fn($row) => $this->normalize($row), $result),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Get a single user profile by ID
     */
    public function show(int $id): ?array
    {
        $result = $this->db->query(
            'SELECT * FROM tbluser WHERE lid = ? AND lstatus = 1',
            [$id]
        );

        return $result ? $this->normalize($result[0]) : null;
    }

    /**
     * Get sales agents (specific role)
     */
    public function getSalesAgents(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        // Match sales agents based on group field - you may need to adjust this logic
        $countResult = $this->db->query(
            "SELECT COUNT(*) as total FROM tbluser WHERE lstatus = 1 AND (lgroup LIKE '%Sales%' OR lgroup LIKE '%Agent%')"
        );
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            "SELECT * FROM tbluser WHERE lstatus = 1 AND (lgroup LIKE '%Sales%' OR lgroup LIKE '%Agent%') ORDER BY lfname ASC LIMIT ? OFFSET ?",
            [$perPage, $offset]
        );

        return [
            'data' => array_map(fn($row) => $this->normalize($row), $result),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Update a user profile
     */
    public function update(int $id, array $updates): void
    {
        $user = $this->show($id);
        if (!$user) {
            throw new HttpException(404, 'User profile not found');
        }

        $setClauses = [];
        $values = [];

        $fieldMap = [
            'fullName' => 'lfname',
            'full_name' => 'lfname',
            'email' => 'lusername',
            'role' => 'lgroup',
            'group' => 'lgroup',
        ];

        foreach ($updates as $key => $value) {
            if (in_array($key, ['id', 'lid'])) {
                continue;
            }

            $dbKey = $fieldMap[$key] ?? null;
            if ($dbKey) {
                $setClauses[] = "$dbKey = ?";
                $values[] = $value;
            }
        }

        if (empty($setClauses)) {
            return;
        }

        $values[] = $id;

        $this->db->query(
            'UPDATE tbluser SET ' . implode(', ', $setClauses) . ' WHERE lid = ?',
            $values
        );
    }

    /**
     * Deactivate a staff account
     */
    public function deactivate(int $id): bool
    {
        $user = $this->show($id);
        if (!$user) {
            throw new HttpException(404, 'User profile not found');
        }

        $this->db->query(
            'UPDATE tbluser SET lstatus = 0 WHERE lid = ?',
            [$id]
        );

        return true;
    }

    /**
     * Activate a staff account
     */
    public function activate(int $id): bool
    {
        $result = $this->db->query(
            'SELECT * FROM tbluser WHERE lid = ?',
            [$id]
        );

        if (!$result) {
            throw new HttpException(404, 'User profile not found');
        }

        $this->db->query(
            'UPDATE tbluser SET lstatus = 1 WHERE lid = ?',
            [$id]
        );

        return true;
    }

    /**
     * Update user role/group
     */
    public function updateRole(int $id, string $role): void
    {
        $user = $this->show($id);
        if (!$user) {
            throw new HttpException(404, 'User profile not found');
        }

        $this->db->query(
            'UPDATE tbluser SET lgroup = ? WHERE lid = ?',
            [$role, $id]
        );
    }

    /**
     * Normalize database row to API response format
     */
    private function normalize(array $row): array
    {
        $fullName = trim(($row['lfname'] ?? '') . ' ' . ($row['llname'] ?? ''));

        return [
            'id' => (string) ($row['lid'] ?? ''),
            'email' => $row['lusername'] ?? '',
            'fullName' => $fullName,
            'full_name' => $fullName,
            'role' => $row['lgroup'] ?? '',
            'status' => (int) ($row['lstatus'] ?? 0),
            'avatar' => null, // Not in original tbluser
            'avatarUrl' => null,
        ];
    }
}
