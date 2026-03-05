<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CampaignFeedbackRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Log campaign feedback
     */
    public function create(array $feedback): ?array
    {
        $sql = <<<SQL
INSERT INTO ai_campaign_feedback (
    campaign_id, outreach_id, client_id, feedback_type,
    content, sentiment, tags, ai_analysis, created_at
) VALUES (
    :campaign_id, :outreach_id, :client_id, :feedback_type,
    :content, :sentiment, :tags, :ai_analysis, NOW()
)
SQL;

        $tagsJson = !empty($feedback['tags']) ? json_encode($feedback['tags']) : null;
        $analysisJson = !empty($feedback['ai_analysis']) ? json_encode($feedback['ai_analysis']) : null;

        $stmt = $this->db->pdo()->prepare($sql);
        $result = $stmt->execute([
            ':campaign_id' => $feedback['campaign_id'] ?? null,
            ':outreach_id' => $feedback['outreach_id'] ?? null,
            ':client_id' => $feedback['client_id'] ?? null,
            ':feedback_type' => $feedback['feedback_type'] ?? null,
            ':content' => $feedback['content'] ?? null,
            ':sentiment' => $feedback['sentiment'] ?? null,
            ':tags' => $tagsJson,
            ':ai_analysis' => $analysisJson,
        ]);

        if (!$result) {
            return null;
        }

        $id = $this->db->pdo()->lastInsertId();
        return $this->show($id);
    }

    /**
     * Get feedback by ID
     */
    public function show(string $id): ?array
    {
        $sql = <<<SQL
SELECT
    id,
    campaign_id,
    outreach_id,
    client_id,
    feedback_type,
    content,
    sentiment,
    tags,
    ai_analysis,
    created_at
FROM ai_campaign_feedback
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return null;
        }

        $record['tags'] = $record['tags'] ? json_decode($record['tags'], true) : [];
        $record['ai_analysis'] = $record['ai_analysis'] ? json_decode($record['ai_analysis'], true) : null;

        return $record;
    }

    /**
     * Get feedback for a campaign
     */
    public function list(
        string $campaignId,
        int $page = 1,
        int $perPage = 50,
        string $feedbackType = '',
        string $sentiment = ''
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = <<<SQL
SELECT COUNT(*) as total
FROM ai_campaign_feedback
WHERE campaign_id = :campaign_id
SQL;
        $params = ['campaign_id' => $campaignId];

        if ($feedbackType !== '') {
            $countSql .= ' AND feedback_type = :feedback_type';
            $params['feedback_type'] = $feedbackType;
        }

        if ($sentiment !== '') {
            $countSql .= ' AND sentiment = :sentiment';
            $params['sentiment'] = $sentiment;
        }

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    id,
    campaign_id,
    outreach_id,
    client_id,
    feedback_type,
    content,
    sentiment,
    tags,
    ai_analysis,
    created_at
FROM ai_campaign_feedback
WHERE campaign_id = :campaign_id
SQL;

        if ($feedbackType !== '') {
            $sql .= ' AND feedback_type = :feedback_type';
        }

        if ($sentiment !== '') {
            $sql .= ' AND sentiment = :sentiment';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_STR);
        if ($feedbackType !== '') {
            $stmt->bindValue(':feedback_type', $feedbackType, PDO::PARAM_STR);
        }
        if ($sentiment !== '') {
            $stmt->bindValue(':sentiment', $sentiment, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON fields
        foreach ($items as &$item) {
            $item['tags'] = $item['tags'] ? json_decode($item['tags'], true) : [];
            $item['ai_analysis'] = $item['ai_analysis'] ? json_decode($item['ai_analysis'], true) : null;
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
     * Analyze campaign feedback for insights
     */
    public function analyzeFeedback(string $campaignId): array
    {
        $sql = <<<SQL
SELECT
    COUNT(*) as total_feedback,
    SUM(CASE WHEN sentiment = 'positive' THEN 1 ELSE 0 END) as sentiment_positive,
    SUM(CASE WHEN sentiment = 'neutral' THEN 1 ELSE 0 END) as sentiment_neutral,
    SUM(CASE WHEN sentiment = 'negative' THEN 1 ELSE 0 END) as sentiment_negative
FROM ai_campaign_feedback
WHERE campaign_id = :campaign_id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['campaign_id' => $campaignId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int) ($stats['total_feedback'] ?? 0);

        // Get feedback type distribution
        $typeSql = <<<SQL
SELECT feedback_type, COUNT(*) as count
FROM ai_campaign_feedback
WHERE campaign_id = :campaign_id
GROUP BY feedback_type
SQL;

        $typeStmt = $this->db->pdo()->prepare($typeSql);
        $typeStmt->execute(['campaign_id' => $campaignId]);
        $typeRows = $typeStmt->fetchAll(PDO::FETCH_ASSOC);

        $typeDistribution = [];
        foreach ($typeRows as $row) {
            $typeDistribution[$row['feedback_type']] = (int) $row['count'];
        }

        // Extract common tags
        $tagsSql = <<<SQL
SELECT tags FROM ai_campaign_feedback
WHERE campaign_id = :campaign_id AND tags IS NOT NULL
SQL;

        $tagsStmt = $this->db->pdo()->prepare($tagsSql);
        $tagsStmt->execute(['campaign_id' => $campaignId]);
        $tagRows = $tagsStmt->fetchAll(PDO::FETCH_ASSOC);

        $tagCounts = [];
        foreach ($tagRows as $row) {
            $tags = json_decode($row['tags'], true);
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }
        }

        // Sort tags by count and take top 10
        arsort($tagCounts);
        $commonTags = array_slice(
            array_map(fn($tag, $count) => ['tag' => $tag, 'count' => $count], array_keys($tagCounts), array_values($tagCounts)),
            0,
            10
        );

        return [
            'total_feedback' => $total,
            'sentiment_distribution' => [
                'positive' => (int) ($stats['sentiment_positive'] ?? 0),
                'neutral' => (int) ($stats['sentiment_neutral'] ?? 0),
                'negative' => (int) ($stats['sentiment_negative'] ?? 0),
            ],
            'feedback_type_distribution' => $typeDistribution,
            'common_tags' => $commonTags,
        ];
    }
}
