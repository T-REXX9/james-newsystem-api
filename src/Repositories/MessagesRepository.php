<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;

final class MessagesRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * List all messages in a team with pagination
     */
    public function list(string $teamId, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query(
            'SELECT COUNT(*) as total FROM tblteam_messages WHERE team_id = ? AND is_deleted = 0',
            [$teamId]
        );
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tblteam_messages WHERE team_id = ? AND is_deleted = 0 ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$teamId, $perPage, $offset]
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
     * Get a single message by ID
     */
    public function show(string $id): ?array
    {
        $result = $this->db->query(
            'SELECT * FROM tblteam_messages WHERE id = ? AND is_deleted = 0',
            [$id]
        );

        return $result ? $this->normalize($result[0]) : null;
    }

    /**
     * Create a new message
     */
    public function create(array $data): array
    {
        $id = $data['id'] ?? bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO tblteam_messages (id, team_id, sender_id, sender_name, sender_avatar, message, message_type, attachment_url, created_at, updated_at, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $data['teamId'] ?? $data['team_id'] ?? '',
                $data['senderId'] ?? $data['sender_id'] ?? '',
                $data['senderName'] ?? $data['sender_name'] ?? '',
                $data['senderAvatar'] ?? $data['sender_avatar'] ?? null,
                $data['message'] ?? '',
                $data['messageType'] ?? $data['message_type'] ?? 'text',
                $data['attachmentUrl'] ?? $data['attachment_url'] ?? null,
                $now,
                $now,
                0,
            ]
        );

        return $this->show($id) ?? [];
    }

    /**
     * Update a message
     */
    public function update(string $id, array $updates): void
    {
        $message = $this->show($id);
        if (!$message) {
            throw new HttpException(404, 'Message not found');
        }

        $now = date('Y-m-d H:i:s');
        $setClauses = [];
        $values = [];

        foreach ($updates as $key => $value) {
            if (in_array($key, ['id', 'team_id', 'sender_id', 'created_at', 'is_deleted'])) {
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
            'UPDATE tblteam_messages SET ' . implode(', ', $setClauses) . ', updated_at = ? WHERE id = ?',
            $values
        );
    }

    /**
     * Soft delete a message
     */
    public function delete(string $id): bool
    {
        $message = $this->show($id);
        if (!$message) {
            throw new HttpException(404, 'Message not found');
        }

        $now = date('Y-m-d H:i:s');
        $this->db->query(
            'UPDATE tblteam_messages SET is_deleted = 1, deleted_at = ? WHERE id = ?',
            [$now, $id]
        );

        return true;
    }

    /**
     * Get messages by sender
     */
    public function getBySender(string $teamId, string $senderId, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        $countResult = $this->db->query(
            'SELECT COUNT(*) as total FROM tblteam_messages WHERE team_id = ? AND sender_id = ? AND is_deleted = 0',
            [$teamId, $senderId]
        );
        $total = (int) ($countResult[0]['total'] ?? 0);

        $result = $this->db->query(
            'SELECT * FROM tblteam_messages WHERE team_id = ? AND sender_id = ? AND is_deleted = 0 ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$teamId, $senderId, $perPage, $offset]
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
     * Normalize database row to API response format
     */
    private function normalize(array $row): array
    {
        return [
            'id' => $row['id'] ?? '',
            'teamId' => $row['team_id'] ?? '',
            'senderId' => $row['sender_id'] ?? '',
            'senderName' => $row['sender_name'] ?? '',
            'senderAvatar' => $row['sender_avatar'] ?? null,
            'message' => $row['message'] ?? '',
            'messageType' => $row['message_type'] ?? 'text',
            'attachmentUrl' => $row['attachment_url'] ?? null,
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
