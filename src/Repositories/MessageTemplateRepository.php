<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class MessageTemplateRepository
{
    private const LEGACY_TEMPLATE_TYPE = 'ai_message_template';

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

        // Legacy storage lives in tbltemp_list, scoped by a synthetic ltemp_type.
        $countSql = <<<SQL
SELECT COUNT(*) AS total
FROM tbltemp_list
WHERE COALESCE(lstatus, 1) = 1
  AND ltemp_type = :legacy_type
SQL;
        $params = [];
        $params['legacy_type'] = self::LEGACY_TEMPLATE_TYPE;

        if ($language !== '') {
            $countSql .= ' AND COALESCE(lrefno, \'\') LIKE :language_pattern';
            $params['language_pattern'] = $this->buildMetadataPrefix($language);
        }

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    lid,
    ltemp_name,
    lmessage,
    lstatus,
    lowner,
    ldate_created,
    lrefno
FROM tbltemp_list
WHERE COALESCE(lstatus, 1) = 1
  AND ltemp_type = :legacy_type
SQL;

        if ($language !== '') {
            $sql .= ' AND COALESCE(lrefno, \'\') LIKE :language_pattern';
        }

        $sql .= ' ORDER BY lid DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':legacy_type', self::LEGACY_TEMPLATE_TYPE, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        if ($language !== '') {
            $stmt->bindValue(':language_pattern', $this->buildMetadataPrefix($language), PDO::PARAM_STR);
        }
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item = $this->mapLegacyRow($item);
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
    lid,
    ltemp_name,
    lmessage,
    lstatus,
    lowner,
    ldate_created,
    lrefno
FROM tbltemp_list
WHERE lid = :id
  AND ltemp_type = :legacy_type
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'legacy_type' => self::LEGACY_TEMPLATE_TYPE,
        ]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return null;
        }

        return $this->mapLegacyRow($record);
    }

    /**
     * Create new message template
     */
    public function create(array $template, string $createdBy = ''): ?array
    {
        $sql = <<<SQL
INSERT INTO tbltemp_list (
    ltemp_name,
    lmessage,
    lstatus,
    ldate_created,
    lowner,
    lrefno,
    ltemp_type
) VALUES (
    :name,
    :content,
    :status,
    :created_at,
    :created_by,
    :refno,
    :legacy_type
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute([
            ':name' => $template['name'] ?? null,
            ':content' => $template['content'] ?? null,
            ':status' => isset($template['is_active']) ? ((bool) $template['is_active'] ? 1 : 0) : 1,
            ':created_at' => date('Y-m-d'),
            ':created_by' => $createdBy !== '' ? (int) $createdBy : null,
            ':refno' => $this->buildMetadataRefno($template),
            ':legacy_type' => self::LEGACY_TEMPLATE_TYPE,
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
        $current = $this->show($id);
        if ($current === null) {
            return null;
        }

        $sql = 'UPDATE tbltemp_list SET ';
        $fields = [];
        $params = ['id' => $id];

        if (isset($updates['name'])) {
            $fields[] = 'ltemp_name = :name';
            $params['name'] = $updates['name'];
        }

        if (isset($updates['content'])) {
            $fields[] = 'lmessage = :content';
            $params['content'] = $updates['content'];
        }

        if (isset($updates['is_active'])) {
            $fields[] = 'lstatus = :is_active';
            $params['is_active'] = (bool) $updates['is_active'] ? 1 : 0;
        }

        $nextRecord = [
            'name' => $updates['name'] ?? $current['name'],
            'language' => $updates['language'] ?? $current['language'],
            'template_type' => $updates['template_type'] ?? $current['template_type'],
            'variables' => $updates['variables'] ?? $current['variables'],
        ];
        $fields[] = 'lrefno = :refno';
        $params['refno'] = $this->buildMetadataRefno($nextRecord);

        if (empty($fields)) {
            return $current;
        }

        $sql .= implode(', ', $fields) . ' WHERE lid = :id AND ltemp_type = :legacy_type';
        $params['legacy_type'] = self::LEGACY_TEMPLATE_TYPE;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->show($id);
    }

    /**
     * Delete (soft delete by setting is_active to FALSE)
     */
    public function delete(string $id): bool
    {
        $sql = 'UPDATE tbltemp_list SET lstatus = 0 WHERE lid = :id AND ltemp_type = :legacy_type';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'legacy_type' => self::LEGACY_TEMPLATE_TYPE,
        ]);

        return $stmt->rowCount() > 0;
    }

    private function mapLegacyRow(array $row): array
    {
        $metadata = $this->parseMetadata((string) ($row['lrefno'] ?? ''));

        return [
            'id' => (string) ($row['lid'] ?? ''),
            'name' => (string) ($row['ltemp_name'] ?? ''),
            'language' => $metadata['language'] ?? 'english',
            'template_type' => $metadata['template_type'] ?? 'general',
            'content' => (string) ($row['lmessage'] ?? ''),
            'variables' => $metadata['variables'] ?? [],
            'is_active' => (int) ($row['lstatus'] ?? 1) === 1,
            'created_by' => isset($row['lowner']) ? (string) $row['lowner'] : null,
            'created_at' => $this->normalizeDate((string) ($row['ldate_created'] ?? '')),
            'updated_at' => $this->normalizeDate((string) ($row['ldate_created'] ?? '')),
        ];
    }

    private function buildMetadataPrefix(string $language): string
    {
        return 'ai-template|' . strtolower(trim($language)) . '|%';
    }

    private function buildMetadataRefno(array $template): string
    {
        $language = strtolower(trim((string) ($template['language'] ?? 'english')));
        $type = strtolower(trim((string) ($template['template_type'] ?? 'general')));
        $variables = $template['variables'] ?? [];
        $variablesJson = json_encode(array_values(is_array($variables) ? $variables : []));
        return sprintf('ai-template|%s|%s|%s', $language, $type, base64_encode((string) $variablesJson));
    }

    private function parseMetadata(string $refno): array
    {
        if (!str_starts_with($refno, 'ai-template|')) {
            return [];
        }

        $parts = explode('|', $refno, 4);
        $variables = [];
        if (isset($parts[3]) && $parts[3] !== '') {
            $decoded = base64_decode($parts[3], true);
            $parsed = $decoded !== false ? json_decode($decoded, true) : null;
            if (is_array($parsed)) {
                $variables = array_values($parsed);
            }
        }

        return [
            'language' => $parts[1] ?? 'english',
            'template_type' => $parts[2] ?? 'general',
            'variables' => $variables,
        ];
    }

    private function normalizeDate(string $date): ?string
    {
        $trimmed = trim($date);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed . ' 00:00:00';
    }
}
