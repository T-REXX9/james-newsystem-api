<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class MessageTemplateRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Get active message templates
     */
    public function list(int $page = 1, int $perPage = 100, string $language = ''): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = 'SELECT COUNT(*) as total FROM ai_message_templates WHERE is_active = TRUE';
        $params = [];

        if ($language !== '') {
            $countSql .= ' AND language = :language';
            $params['language'] = $language;
        }

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    id,
    name,
    language,
    template_type,
    content,
    variables,
    is_active,
    created_by,
    created_at,
    updated_at
FROM ai_message_templates
WHERE is_active = TRUE
SQL;

        if ($language !== '') {
            $sql .= ' AND language = :language';
        }

        $sql .= ' ORDER BY template_type, language ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($language !== '') {
            $stmt->bindValue(':language', $language, PDO::PARAM_STR);
        }
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode variables
        foreach ($items as &$item) {
            $item['variables'] = $item['variables'] ? json_decode($item['variables'], true) : [];
        }

        return [
            'data' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    /**
     * Get template by ID
     */
    public function show(string $id): ?array
    {
        $sql = <<<SQL
SELECT
    id,
    name,
    language,
    template_type,
    content,
    variables,
    is_active,
    created_by,
    created_at,
    updated_at
FROM ai_message_templates
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return null;
        }

        $record['variables'] = $record['variables'] ? json_decode($record['variables'], true) : [];

        return $record;
    }

    /**
     * Create new message template
     */
    public function create(array $template, string $createdBy = ''): ?array
    {
        $sql = <<<SQL
INSERT INTO ai_message_templates (
    name, language, template_type, content, variables,
    is_active, created_by, created_at, updated_at
) VALUES (
    :name, :language, :template_type, :content, :variables,
    :is_active, :created_by, NOW(), NOW()
)
SQL;

        $variablesJson = !empty($template['variables']) ? json_encode($template['variables']) : null;

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute([
            ':name' => $template['name'] ?? null,
            ':language' => $template['language'] ?? 'tagalog',
            ':template_type' => $template['template_type'] ?? null,
            ':content' => $template['content'] ?? null,
            ':variables' => $variablesJson,
            ':is_active' => isset($template['is_active']) ? (bool) $template['is_active'] ? 1 : 0 : 1,
            ':created_by' => $createdBy ?: null,
        ]);

        if (!$result) {
            return null;
        }

        $id = $this->db->pdo()->lastInsertId();
        return $this->show($id);
    }

    /**
     * Update message template
     */
    public function update(string $id, array $updates): ?array
    {
        $sql = 'UPDATE ai_message_templates SET ';
        $fields = [];
        $params = ['id' => $id];

        if (isset($updates['name'])) {
            $fields[] = 'name = :name';
            $params['name'] = $updates['name'];
        }

        if (isset($updates['language'])) {
            $fields[] = 'language = :language';
            $params['language'] = $updates['language'];
        }

        if (isset($updates['template_type'])) {
            $fields[] = 'template_type = :template_type';
            $params['template_type'] = $updates['template_type'];
        }

        if (isset($updates['content'])) {
            $fields[] = 'content = :content';
            $params['content'] = $updates['content'];
        }

        if (isset($updates['variables'])) {
            $fields[] = 'variables = :variables';
            $params['variables'] = !empty($updates['variables']) ? json_encode($updates['variables']) : null;
        }

        if (isset($updates['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (bool) $updates['is_active'] ? 1 : 0;
        }

        if (empty($fields)) {
            return $this->show($id);
        }

        $sql .= implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->show($id);
    }

    /**
     * Delete (soft delete by setting is_active to FALSE)
     */
    public function delete(string $id): bool
    {
        $sql = 'UPDATE ai_message_templates SET is_active = FALSE, updated_at = NOW() WHERE id = :id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
