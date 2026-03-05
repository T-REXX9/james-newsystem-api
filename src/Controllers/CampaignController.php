<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\CampaignOutreachRepository;
use App\Repositories\CampaignFeedbackRepository;
use App\Repositories\MessageTemplateRepository;
use App\Support\Exceptions\HttpException;

final class CampaignController
{
    public function __construct(
        private readonly CampaignOutreachRepository $outreachRepo,
        private readonly CampaignFeedbackRepository $feedbackRepo,
        private readonly MessageTemplateRepository $templateRepo
    ) {
    }

    // ========================================================================
    // Outreach Endpoints
    // ========================================================================

    public function listOutreach(array $params = [], array $query = [], array $body = []): array
    {
        $campaignId = trim((string) ($params['campaignId'] ?? ''));
        if ($campaignId === '') {
            throw new HttpException(422, 'campaignId is required');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 50)));
        $status = trim((string) ($query['status'] ?? ''));
        $outcome = trim((string) ($query['outcome'] ?? ''));

        return $this->outreachRepo->list($campaignId, $page, $perPage, $status, $outcome);
    }

    public function getOutreach(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $outreach = $this->outreachRepo->show($id);
        if ($outreach === null) {
            throw new HttpException(404, 'Outreach record not found');
        }

        return $outreach;
    }

    public function createOutreach(array $params = [], array $query = [], array $body = []): array
    {
        $campaignId = trim((string) ($params['campaignId'] ?? ''));
        if ($campaignId === '') {
            throw new HttpException(422, 'campaignId is required');
        }

        $records = $body['records'] ?? [];
        if (!is_array($records) || empty($records)) {
            throw new HttpException(422, 'records array is required');
        }

        // Validate each record
        foreach ($records as $record) {
            if (empty($record['client_id'])) {
                throw new HttpException(422, 'client_id is required for each record');
            }
        }

        // Add campaign_id to each record
        foreach ($records as &$record) {
            $record['campaign_id'] = $campaignId;
            if (empty($record['outreach_type'])) {
                $record['outreach_type'] = 'sms';
            }
            if (empty($record['language'])) {
                $record['language'] = 'tagalog';
            }
        }

        $created = $this->outreachRepo->createBatch($records);
        return [
            'created' => count($created),
            'records' => $created,
        ];
    }

    public function updateOutreachStatus(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $status = trim((string) ($body['status'] ?? ''));
        if ($status === '') {
            throw new HttpException(422, 'status is required');
        }

        $updates = [];
        if (isset($body['sent_at'])) {
            $updates['sent_at'] = $body['sent_at'];
        }
        if (isset($body['error_message'])) {
            $updates['error_message'] = $body['error_message'];
        }
        if (isset($body['retry_count'])) {
            $updates['retry_count'] = (int) $body['retry_count'];
        }

        $success = $this->outreachRepo->updateStatus($id, $status, $updates);
        if (!$success) {
            throw new HttpException(404, 'Outreach record not found');
        }

        return $this->outreachRepo->show($id) ?? ['ok' => true];
    }

    public function recordOutreachResponse(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $responseContent = trim((string) ($body['response_content'] ?? ''));
        $outcome = trim((string) ($body['outcome'] ?? ''));

        if ($responseContent === '') {
            throw new HttpException(422, 'response_content is required');
        }

        if ($outcome === '') {
            throw new HttpException(422, 'outcome is required');
        }

        $conversationId = trim((string) ($body['conversation_id'] ?? ''));

        $success = $this->outreachRepo->recordResponse($id, $responseContent, $outcome, $conversationId);
        if (!$success) {
            throw new HttpException(404, 'Outreach record not found');
        }

        return $this->outreachRepo->show($id) ?? ['ok' => true];
    }

    public function getPendingOutreach(array $params = [], array $query = [], array $body = []): array
    {
        $limit = max(1, min(500, (int) ($query['limit'] ?? 50)));

        return [
            'data' => $this->outreachRepo->getPending($limit),
        ];
    }

    // ========================================================================
    // Feedback Endpoints
    // ========================================================================

    public function listFeedback(array $params = [], array $query = [], array $body = []): array
    {
        $campaignId = trim((string) ($params['campaignId'] ?? ''));
        if ($campaignId === '') {
            throw new HttpException(422, 'campaignId is required');
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 50)));
        $feedbackType = trim((string) ($query['feedback_type'] ?? ''));
        $sentiment = trim((string) ($query['sentiment'] ?? ''));

        return $this->feedbackRepo->list($campaignId, $page, $perPage, $feedbackType, $sentiment);
    }

    public function createFeedback(array $params = [], array $query = [], array $body = []): array
    {
        $campaignId = trim((string) ($params['campaignId'] ?? ''));
        if ($campaignId === '') {
            throw new HttpException(422, 'campaignId is required');
        }

        $feedbackType = trim((string) ($body['feedback_type'] ?? ''));
        $content = trim((string) ($body['content'] ?? ''));

        if ($feedbackType === '') {
            throw new HttpException(422, 'feedback_type is required');
        }

        if ($content === '') {
            throw new HttpException(422, 'content is required');
        }

        $feedback = [
            'campaign_id' => $campaignId,
            'outreach_id' => $body['outreach_id'] ?? null,
            'client_id' => $body['client_id'] ?? null,
            'feedback_type' => $feedbackType,
            'content' => $content,
            'sentiment' => $body['sentiment'] ?? null,
            'tags' => $body['tags'] ?? [],
            'ai_analysis' => $body['ai_analysis'] ?? null,
        ];

        $created = $this->feedbackRepo->create($feedback);
        if ($created === null) {
            throw new HttpException(500, 'Failed to create feedback');
        }

        return $created;
    }

    public function analyzeFeedback(array $params = [], array $query = [], array $body = []): array
    {
        $campaignId = trim((string) ($params['campaignId'] ?? ''));
        if ($campaignId === '') {
            throw new HttpException(422, 'campaignId is required');
        }

        return $this->feedbackRepo->analyzeFeedback($campaignId);
    }

    // ========================================================================
    // Campaign Stats Endpoint
    // ========================================================================

    public function getStats(array $params = [], array $query = [], array $body = []): array
    {
        $campaignId = trim((string) ($params['campaignId'] ?? ''));
        if ($campaignId === '') {
            throw new HttpException(422, 'campaignId is required');
        }

        $stats = $this->outreachRepo->getStats($campaignId);

        // Merge feedback sentiment into stats
        $feedbackAnalysis = $this->feedbackRepo->analyzeFeedback($campaignId);
        $stats['sentiment_breakdown'] = $feedbackAnalysis['sentiment_distribution'];

        return $stats;
    }

    // ========================================================================
    // Message Template Endpoints
    // ========================================================================

    public function listTemplates(array $params = [], array $query = [], array $body = []): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 100)));
        $language = trim((string) ($query['language'] ?? ''));

        return $this->templateRepo->list($page, $perPage, $language);
    }

    public function getTemplate(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $template = $this->templateRepo->show($id);
        if ($template === null) {
            throw new HttpException(404, 'Template not found');
        }

        return $template;
    }

    public function createTemplate(array $params = [], array $query = [], array $body = []): array
    {
        $name = trim((string) ($body['name'] ?? ''));
        $language = trim((string) ($body['language'] ?? 'tagalog'));
        $templateType = trim((string) ($body['template_type'] ?? ''));
        $content = trim((string) ($body['content'] ?? ''));

        if ($name === '' || $templateType === '' || $content === '') {
            throw new HttpException(422, 'name, template_type, and content are required');
        }

        $template = [
            'name' => $name,
            'language' => $language,
            'template_type' => $templateType,
            'content' => $content,
            'variables' => $body['variables'] ?? [],
            'is_active' => $body['is_active'] ?? true,
        ];

        $created = $this->templateRepo->create($template, (string) ($body['created_by'] ?? ''));
        if ($created === null) {
            throw new HttpException(500, 'Failed to create template');
        }

        return $created;
    }

    public function updateTemplate(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $updated = $this->templateRepo->update($id, $body);
        if ($updated === null) {
            throw new HttpException(404, 'Template not found');
        }

        return $updated;
    }

    public function deleteTemplate(array $params = [], array $query = [], array $body = []): array
    {
        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            throw new HttpException(422, 'id is required');
        }

        $success = $this->templateRepo->delete($id);
        if (!$success) {
            throw new HttpException(404, 'Template not found');
        }

        return ['ok' => true];
    }

    // ========================================================================
    // Queue Processing Endpoint
    // ========================================================================

    public function processOutreachQueue(array $params = [], array $query = [], array $body = []): array
    {
        $limit = max(1, min(500, (int) ($body['limit'] ?? 20)));

        $pending = $this->outreachRepo->getPending($limit);
        $successful = 0;
        $failed = 0;

        // Process pending outreach (placeholder for SMS/provider integration)
        foreach ($pending as $outreach) {
            // TODO: Integrate with actual SMS provider (Twilio, Semaphore, etc.)
            // For now, just mark as sent
            $this->outreachRepo->updateStatus($outreach['id'], 'sent', [
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
            $successful++;
        }

        return [
            'processed' => count($pending),
            'successful' => $successful,
            'failed' => $failed,
        ];
    }
}
