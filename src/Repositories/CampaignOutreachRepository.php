<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class CampaignOutreachRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Get campaign outreach records with optional filtering and pagination
     */
    public function list(
        string $campaignId,
        int $page = 1,
        int $perPage = 50,
        string $status = '',
        string $outcome = ''
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = <<<SQL
SELECT COUNT(*) as total
FROM ai_campaign_outreach
WHERE campaign_id = :campaign_id
SQL;
        $params = ['campaign_id' => $campaignId];

        if ($status !== '') {
            $countSql .= ' AND status = :status';
            $params['status'] = $status;
        }

        if ($outcome !== '') {
            $countSql .= ' AND outcome = :outcome';
            $params['outcome'] = $outcome;
        }

        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    id,
    campaign_id,
    client_id,
    outreach_type,
    status,
    language,
    message_content,
    scheduled_at,
    sent_at,
    response_received,
    response_content,
    outcome,
    conversation_id,
    error_message,
    retry_count,
    created_by,
    created_at,
    updated_at
FROM ai_campaign_outreach
WHERE campaign_id = :campaign_id
SQL;

        if ($status !== '') {
            $sql .= ' AND status = :status';
        }

        if ($outcome !== '') {
            $sql .= ' AND outcome = :outcome';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':campaign_id', $campaignId, PDO::PARAM_STR);
        if ($status !== '') {
            $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        }
        if ($outcome !== '') {
            $stmt->bindValue(':outcome', $outcome, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Hydrate with client info
        $items = $this->hydrateWithClients($items);

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
     * Get pending outreach scheduled for now or earlier (for queue processing)
     */
    public function getPending(int $limit = 50): array
    {
        $sql = <<<SQL
SELECT
    id,
    campaign_id,
    client_id,
    outreach_type,
    status,
    language,
    message_content,
    scheduled_at,
    sent_at,
    response_received,
    response_content,
    outcome,
    conversation_id,
    error_message,
    retry_count,
    created_by,
    created_at,
    updated_at
FROM ai_campaign_outreach
WHERE status = 'pending' AND scheduled_at <= NOW()
ORDER BY scheduled_at ASC
LIMIT :limit
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateWithClients($items);
    }

    /**
     * Get single outreach record
     */
    public function show(string $id): ?array
    {
        $sql = <<<SQL
SELECT
    id,
    campaign_id,
    client_id,
    outreach_type,
    status,
    language,
    message_content,
    scheduled_at,
    sent_at,
    response_received,
    response_content,
    outcome,
    conversation_id,
    error_message,
    retry_count,
    created_by,
    created_at,
    updated_at
FROM ai_campaign_outreach
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return null;
        }

        $records = $this->hydrateWithClients([$record]);
        return $records[0] ?? null;
    }

    /**
     * Create outreach records
     */
    public function createBatch(array $records): array
    {
        $sql = <<<SQL
INSERT INTO ai_campaign_outreach (
    campaign_id, client_id, outreach_type, status, language,
    message_content, scheduled_at, created_by, created_at, updated_at
) VALUES (
    :campaign_id, :client_id, :outreach_type, :status, :language,
    :message_content, :scheduled_at, :created_by, NOW(), NOW()
)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $created = [];

        foreach ($records as $record) {
            $stmt->execute([
                ':campaign_id' => $record['campaign_id'] ?? null,
                ':client_id' => $record['client_id'] ?? null,
                ':outreach_type' => $record['outreach_type'] ?? 'sms',
                ':status' => $record['status'] ?? 'pending',
                ':language' => $record['language'] ?? 'tagalog',
                ':message_content' => $record['message_content'] ?? null,
                ':scheduled_at' => $record['scheduled_at'] ?? date('Y-m-d H:i:s'),
                ':created_by' => $record['created_by'] ?? null,
            ]);

            $id = $this->db->pdo()->lastInsertId();
            if ($id) {
                $created[] = $this->show($id);
            }
        }

        return array_filter($created);
    }

    /**
     * Update outreach status and metadata
     */
    public function updateStatus(
        string $id,
        string $status,
        array $updates = []
    ): bool {
        $sql = 'UPDATE ai_campaign_outreach SET status = :status';
        $params = ['status' => $status, 'id' => $id];

        if (isset($updates['sent_at'])) {
            $sql .= ', sent_at = :sent_at';
            $params['sent_at'] = $updates['sent_at'];
        }

        if (isset($updates['error_message'])) {
            $sql .= ', error_message = :error_message';
            $params['error_message'] = $updates['error_message'];
        }

        if (isset($updates['retry_count'])) {
            $sql .= ', retry_count = :retry_count';
            $params['retry_count'] = $updates['retry_count'];
        }

        $sql .= ', updated_at = NOW() WHERE id = :id';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Record outreach response
     */
    public function recordResponse(
        string $id,
        string $responseContent,
        string $outcome,
        string $conversationId = ''
    ): bool {
        $sql = <<<SQL
UPDATE ai_campaign_outreach SET
    status = 'responded',
    response_received = TRUE,
    response_content = :response_content,
    outcome = :outcome,
    conversation_id = :conversation_id,
    updated_at = NOW()
WHERE id = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':response_content' => $responseContent,
            ':outcome' => $outcome,
            ':conversation_id' => $conversationId ?: null,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get campaign statistics
     */
    public function getStats(string $campaignId): array
    {
        $sql = <<<SQL
SELECT
    COUNT(*) as total_outreach,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_count,
    SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) as responded_count,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
    SUM(CASE WHEN outcome = 'interested' THEN 1 ELSE 0 END) as outcome_interested,
    SUM(CASE WHEN outcome = 'not_interested' THEN 1 ELSE 0 END) as outcome_not_interested,
    SUM(CASE WHEN outcome = 'no_response' THEN 1 ELSE 0 END) as outcome_no_response,
    SUM(CASE WHEN outcome = 'converted' THEN 1 ELSE 0 END) as outcome_converted,
    SUM(CASE WHEN outcome = 'escalated' THEN 1 ELSE 0 END) as outcome_escalated
FROM ai_campaign_outreach
WHERE campaign_id = :campaign_id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(['campaign_id' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $total = (int) ($row['total_outreach'] ?? 0);
        $responded = (int) ($row['responded_count'] ?? 0);
        $delivered = (int) ($row['delivered_count'] ?? 0);

        return [
            'total_outreach' => $total,
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'sent_count' => (int) ($row['sent_count'] ?? 0),
            'delivered_count' => $delivered,
            'responded_count' => $responded,
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'response_rate' => $delivered > 0 ? round(($responded / ($delivered + $responded)) * 100, 2) : 0,
            'conversion_rate' => $responded > 0 ? round((((int) ($row['outcome_converted'] ?? 0)) / $responded) * 100, 2) : 0,
            'sentiment_breakdown' => [
                'positive' => 0,
                'neutral' => 0,
                'negative' => 0,
            ],
            'outcome_breakdown' => [
                'interested' => (int) ($row['outcome_interested'] ?? 0),
                'not_interested' => (int) ($row['outcome_not_interested'] ?? 0),
                'no_response' => (int) ($row['outcome_no_response'] ?? 0),
                'converted' => (int) ($row['outcome_converted'] ?? 0),
                'escalated' => (int) ($row['outcome_escalated'] ?? 0),
            ],
        ];
    }

    /**
     * Hydrate outreach records with client information
     */
    private function hydrateWithClients(array $records): array
    {
        if (empty($records)) {
            return $records;
        }

        // Get all client IDs
        $clientIds = array_unique(array_column($records, 'client_id'));

        // Fetch client info
        $clientsMap = [];
        if (!empty($clientIds)) {
            $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
            $clientSql = <<<SQL
SELECT
    lsessionid AS id,
    lcompany AS company,
    lphone AS phone,
    lmobile AS mobile
FROM tblpatient
WHERE lsessionid IN ($placeholders)
SQL;

            $stmt = $this->db->pdo()->prepare($clientSql);
            $stmt->execute($clientIds);
            $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($clients as $client) {
                $clientsMap[$client['id']] = $client;
            }
        }

        // Hydrate records
        foreach ($records as &$record) {
            $record['client'] = $clientsMap[$record['client_id']] ?? null;
        }

        return $records;
    }
}
