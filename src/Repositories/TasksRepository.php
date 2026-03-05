<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;

final class TasksRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * List all tasks with pagination
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query('SELECT COUNT(*) as total FROM tbltasks WHERE is_deleted = 0');
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tbltasks WHERE is_deleted = 0 ORDER BY due_date ASC, created_at DESC LIMIT ? OFFSET ?',
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
     * Get a single task by ID
     */
    public function show(string $id): ?array
    {
        $result = $this->db->query(
            'SELECT * FROM tbltasks WHERE id = ? AND is_deleted = 0',
            [$id]
        );

        return $result ? $this->normalize($result[0]) : null;
    }

    /**
     * Create a new task
     */
    public function create(array $data): array
    {
        $id = $data['id'] ?? bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO tbltasks (id, title, description, assigned_to, assignee_id, created_by, created_by_id, due_date, priority, status, created_at, updated_at, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $data['title'] ?? '',
                $data['description'] ?? '',
                $data['assignedTo'] ?? $data['assigned_to'] ?? '',
                $data['assigneeId'] ?? $data['assignee_id'] ?? null,
                $data['createdBy'] ?? $data['created_by'] ?? '',
                $data['createdById'] ?? $data['created_by_id'] ?? null,
                $data['dueDate'] ?? $data['due_date'] ?? null,
                $data['priority'] ?? 'Medium',
                $data['status'] ?? 'Todo',
                $now,
                $now,
                0,
            ]
        );

        return $this->show($id) ?? [];
    }

    /**
     * Update a task
     */
    public function update(string $id, array $updates): void
    {
        $task = $this->show($id);
        if (!$task) {
            throw new HttpException(404, 'Task not found');
        }

        $now = date('Y-m-d H:i:s');
        $setClauses = [];
        $values = [];

        foreach ($updates as $key => $value) {
            if (in_array($key, ['id', 'created_at', 'is_deleted', 'created_by', 'created_by_id'])) {
                continue;
            }

            $dbKey = $this->toSnakeCase($key);
            $setClauses[] = "$dbKey = ?";
            $values[] = $value;
        }

        if (empty($setClauses)) {
            return;
        }

        $values[] = $now;
        $values[] = $id;

        $this->db->query(
            'UPDATE tbltasks SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id = ?',
            $values
        );
    }

    /**
     * Soft delete a task
     */
    public function delete(string $id): bool
    {
        $task = $this->show($id);
        if (!$task) {
            throw new HttpException(404, 'Task not found');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'UPDATE tbltasks SET is_deleted = 1, deleted_at = ? WHERE id = ?',
            [$now, $id]
        );

        return true;
    }

    /**
     * Restore a deleted task
     */
    public function restore(string $id): array
    {
        $result = $this->db->query(
            'SELECT * FROM tbltasks WHERE id = ? AND is_deleted = 1',
            [$id]
        );

        if (!$result) {
            throw new HttpException(404, 'Deleted task not found');
        }

        $this->db->query(
            'UPDATE tbltasks SET is_deleted = 0, deleted_at = NULL WHERE id = ?',
            [$id]
        );

        return $this->show($id) ?? [];
    }

    /**
     * Get tasks by status
     */
    public function getByStatus(string $status, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query(
            'SELECT COUNT(*) as total FROM tbltasks WHERE status = ? AND is_deleted = 0',
            [$status]
        );
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tbltasks WHERE status = ? AND is_deleted = 0 ORDER BY due_date ASC LIMIT ? OFFSET ?',
            [$status, $perPage, $offset]
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
     * Bulk update tasks
     */
    public function bulkUpdate(array $ids, array $updates): void
    {
        if (empty($ids)) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $setClauses = [];
        $values = [];

        foreach ($updates as $key => $value) {
            if (in_array($key, ['id', 'created_at', 'is_deleted', 'created_by', 'created_by_id'])) {
                continue;
            }

            $dbKey = $this->toSnakeCase($key);
            $setClauses[] = "$dbKey = ?";
            $values[] = $value;
        }

        if (empty($setClauses)) {
            return;
        }

        $values[] = $now;

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $values = array_merge($values, $ids);

        $this->db->query(
            'UPDATE tbltasks SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id IN (' . $placeholders . ')',
            $values
        );
    }

    /**
     * Normalize database row to API response format
     */
    private function normalize(array $row): array
    {
        return [
            'id' => $row['id'] ?? '',
            'title' => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'assignedTo' => $row['assigned_to'] ?? '',
            'assigneeId' => $row['assignee_id'],
            'assigneeAvatar' => $row['assignee_avatar'] ?? null,
            'createdBy' => $row['created_by'] ?? '',
            'createdById' => $row['created_by_id'],
            'dueDate' => $row['due_date'] ?? null,
            'priority' => $row['priority'] ?? 'Medium',
            'status' => $row['status'] ?? 'Todo',
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Convert camelCase to snake_case
     */
    private function toSnakeCase(string $str): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $str));
    }
}
