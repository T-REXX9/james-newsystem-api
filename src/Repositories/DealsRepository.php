<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;

final class DealsRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * List all deals with pagination
     */
    public function list(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query('SELECT COUNT(*) as total FROM tbldeals WHERE is_deleted = 0');
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tbldeals WHERE is_deleted = 0 ORDER BY created_at DESC LIMIT ? OFFSET ?',
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
     * Get a single deal by ID
     */
    public function show(string $id): ?array
    {
        $result = $this->db->query(
            'SELECT * FROM tbldeals WHERE id = ? AND is_deleted = 0',
            [$id]
        );

        return $result ? $this->normalize($result[0]) : null;
    }

    /**
     * Create a new deal
     */
    public function create(array $data): array
    {
        $id = $data['id'] ?? bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO tbldeals (id, title, company, contact_name, value, currency, stage_id, owner_id, created_at, updated_at, is_deleted, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $data['title'] ?? '',
                $data['company'] ?? '',
                $data['contactName'] ?? $data['contact_name'] ?? '',
                (float) ($data['value'] ?? 0),
                $data['currency'] ?? 'USD',
                $data['stageId'] ?? $data['stage_id'] ?? '',
                $data['ownerId'] ?? $data['owner_id'] ?? null,
                $now,
                $now,
                0,
                $data['created_by'] ?? null,
            ]
        );

        return $this->show($id) ?? [];
    }

    /**
     * Update a deal
     */
    public function update(string $id, array $updates): void
    {
        $deal = $this->show($id);
        if (!$deal) {
            throw new HttpException(404, 'Deal not found');
        }

        $now = date('Y-m-d H:i:s');
        $setClauses = [];
        $values = [];

        foreach ($updates as $key => $value) {
            if (in_array($key, ['id', 'created_at', 'is_deleted', 'created_by'])) {
                continue;
            }

            // Convert camelCase to snake_case
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
            'UPDATE tbldeals SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id = ?',
            $values
        );
    }

    /**
     * Soft delete a deal
     */
    public function delete(string $id): bool
    {
        $deal = $this->show($id);
        if (!$deal) {
            throw new HttpException(404, 'Deal not found');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'UPDATE tbldeals SET is_deleted = 1, deleted_at = ? WHERE id = ?',
            [$now, $id]
        );

        return true;
    }

    /**
     * Restore a soft-deleted deal
     */
    public function restore(string $id): array
    {
        $result = $this->db->query(
            'SELECT * FROM tbldeals WHERE id = ? AND is_deleted = 1',
            [$id]
        );

        if (!$result) {
            throw new HttpException(404, 'Deleted deal not found');
        }

        $this->db->query(
            'UPDATE tbldeals SET is_deleted = 0, deleted_at = NULL WHERE id = ?',
            [$id]
        );

        return $this->show($id) ?? [];
    }

    /**
     * Bulk update deals
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
            if (in_array($key, ['id', 'created_at', 'is_deleted', 'created_by'])) {
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
            'UPDATE tbldeals SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id IN (' . $placeholders . ')',
            $values
        );
    }

    /**
     * Get deals by stage
     */
    public function getByStage(string $stageId, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query(
            'SELECT COUNT(*) as total FROM tbldeals WHERE stage_id = ? AND is_deleted = 0',
            [$stageId]
        );
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tbldeals WHERE stage_id = ? AND is_deleted = 0 ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$stageId, $perPage, $offset]
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
     * Move deal to different stage
     */
    public function moveToStage(string $id, string $stageId): array
    {
        $deal = $this->show($id);
        if (!$deal) {
            throw new HttpException(404, 'Deal not found');
        }

        $this->update($id, [
            'stageId' => $stageId,
            'daysInStage' => 0,
        ]);

        return $this->show($id) ?? [];
    }

    /**
     * Normalize database row to API response format
     */
    private function normalize(array $row): array
    {
        return [
            'id' => $row['id'] ?? '',
            'title' => $row['title'] ?? '',
            'company' => $row['company'] ?? '',
            'contactName' => $row['contact_name'] ?? '',
            'avatar' => $row['avatar'] ?? '',
            'value' => (float) ($row['value'] ?? 0),
            'currency' => $row['currency'] ?? 'USD',
            'stageId' => $row['stage_id'] ?? '',
            'ownerId' => $row['owner_id'],
            'ownerName' => $row['owner_name'] ?? null,
            'team' => $row['team'] ?? null,
            'customerType' => $row['customer_type'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['updated_at'] ?? null,
            'daysInStage' => (int) ($row['days_in_stage'] ?? 0),
            'isOverdue' => (bool) ($row['is_overdue'] ?? false),
            'isWarning' => (bool) ($row['is_warning'] ?? false),
            'nextStep' => $row['next_step'] ?? null,
            'entryEvidence' => $row['entry_evidence'] ?? null,
            'exitEvidence' => $row['exit_evidence'] ?? null,
            'riskFlag' => $row['risk_flag'] ?? null,
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
