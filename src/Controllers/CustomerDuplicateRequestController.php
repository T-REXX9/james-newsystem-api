<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Repositories\CustomerDuplicateRequestRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CustomerDuplicateRequestController
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * GET /duplicate-requests - Get pending duplicate approval requests
     */
    public function listPending(Request $request, Response $response): Response
    {
        // Role check: master user only
        if (!$this->isMasterUserAccount()) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Unauthorized: Master user access required']));
        }

        $repo = new CustomerDuplicateRequestRepository($this->db);
        $limit = (int) ($request->getQueryParams()['limit'] ?? 50);
        $masterUserId = $this->getCurrentUserId();

        $duplicates = $repo->getPendingDuplicates($masterUserId, $limit);

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode([
                'success' => true,
                'data' => $duplicates,
                'count' => count($duplicates),
            ]));
    }

    /**
     * GET /duplicate-requests/count - Get pending count for dashboard badge
     */
    public function getPendingCount(Request $request, Response $response): Response
    {
        if (!$this->isMasterUserAccount()) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Unauthorized: Master user access required']));
        }

        $repo = new CustomerDuplicateRequestRepository($this->db);
        $count = $repo->getPendingCount();

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode([
                'success' => true,
                'pendingCount' => $count,
            ]));
    }

    /**
     * POST /duplicate-requests/{id}/approve - Approve a duplicate request
     */
    public function approve(Request $request, Response $response, array $args): Response
    {
        if (!$this->isMasterUserAccount()) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Unauthorized: Master user access required']));
        }

        $requestId = (int) $args['id'];
        $userId = $this->getCurrentUserId();
        $userName = $this->getCurrentUserName();

        $repo = new CustomerDuplicateRequestRepository($this->db);

        if (!$repo->approve($requestId, $userId, $userName)) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Failed to approve request or request not pending']));
        }

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['success' => true, 'message' => 'Duplicate request approved']));
    }

    /**
     * POST /duplicate-requests/{id}/reject - Reject a duplicate request
     */
    public function reject(Request $request, Response $response, array $args): Response
    {
        if (!$this->isMasterUserAccount()) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Unauthorized: Master user access required']));
        }

        $requestId = (int) $args['id'];
        $userId = $this->getCurrentUserId();
        $userName = $this->getCurrentUserName();

        $repo = new CustomerDuplicateRequestRepository($this->db);

        if (!$repo->reject($requestId, $userId, $userName)) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Failed to reject request or request not pending']));
        }

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['success' => true, 'message' => 'Duplicate request rejected']));
    }

    /**
     * POST /duplicate-requests/{id}/snooze - Snooze a duplicate request
     */
    public function snooze(Request $request, Response $response, array $args): Response
    {
        if (!$this->isMasterUserAccount()) {
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Unauthorized: Master user access required']));
        }

        $requestId = (int) $args['id'];
        $body = json_decode((string) $request->getBody(), true) ?? [];
        $hours = (int) ($body['hours'] ?? 24);

        // Validate hours (1-168 = 1 week max)
        if ($hours < 1 || $hours > 168) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Invalid snooze duration (1-168 hours allowed)']));
        }

        $repo = new CustomerDuplicateRequestRepository($this->db);

        if (!$repo->snooze($requestId, $hours)) {
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json')
                ->write(json_encode(['error' => 'Failed to snooze request or request not found']));
        }

        return $response->withHeader('Content-Type', 'application/json')
            ->write(json_encode(['success' => true, 'message' => "Duplicate request snoozed for {$hours} hours"]));
    }

    /**
     * Check if current user is a master account (staff with appropriate permissions)
     */
    private function isMasterUserAccount(): bool
    {
        // Implement based on your authentication/authorization system
        // This is a placeholder - replace with actual role check
        $userId = $this->getCurrentUserId();
        if ($userId <= 0) {
            return false;
        }

        // Query to check if user has master/admin permissions
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM tblaccount 
             WHERE lid = :id 
             AND (lstatus = 1 OR lrole IN ("master", "admin", "owner"))'
        );
        $stmt->execute(['id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Get current user ID from session/auth context
     */
    private function getCurrentUserId(): int
    {
        // Implement based on your authentication system
        return $_SESSION['userid'] ?? 0;
    }

    /**
     * Get current user name from session/auth context
     */
    private function getCurrentUserName(): string
    {
        // Implement based on your authentication system
        $stmt = $this->db->pdo()->prepare(
            "SELECT TRIM(CONCAT(COALESCE(lfname, ''), ' ', COALESCE(llname, ''))) AS name
             FROM tblaccount WHERE lid = :id LIMIT 1"
        );
        $stmt->execute(['id' => $this->getCurrentUserId()]);
        $name = $stmt->fetchColumn();
        return $name === false ? 'System' : trim((string) $name);
    }
}
