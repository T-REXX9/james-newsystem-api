<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Support\Exceptions\HttpException;
use App\Support\SalesInquiryPrintPolicy;
use PDO;

final class SalesInquiryPrintController
{
    public function __construct(private readonly Database $db) {}

    public function confirmInquiry(array $params, array $query, array $body): array
    {
        [$mainId] = $this->context($body);
        $ref = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($ref === '') throw new HttpException(422, 'inquiryRefno is required');
        $stmt = $this->db->pdo()->prepare('UPDATE tblinquiry SET lconfirmed_at = COALESCE(lconfirmed_at, NOW()) WHERE lmain_id = :main_id AND lrefno = :ref AND COALESCE(IsCancel, 0) = 0');
        $stmt->execute(['main_id' => $mainId, 'ref' => $ref]);
        if ($stmt->rowCount() === 0) {
            $check = $this->db->pdo()->prepare('SELECT lconfirmed_at, COALESCE(IsCancel, 0) AS is_cancelled FROM tblinquiry WHERE lmain_id = :main_id AND lrefno = :ref');
            $check->execute(['main_id' => $mainId, 'ref' => $ref]);
            $existing = $check->fetch(PDO::FETCH_ASSOC);
            if ($existing === false) throw new HttpException(404, 'Sales inquiry not found');
            if ((int) $existing['is_cancelled'] > 0) throw new HttpException(409, 'A cancelled inquiry cannot be confirmed');
        }
        $read = $this->db->pdo()->prepare("SELECT DATE_FORMAT(lconfirmed_at, '%Y-%m-%d %H:%i:%s') FROM tblinquiry WHERE lmain_id = :main_id AND lrefno = :ref");
        $read->execute(['main_id' => $mainId, 'ref' => $ref]);
        return ['confirmed' => true, 'confirmed_at' => (string) $read->fetchColumn()];
    }

    public function printStatus(array $params, array $query, array $body): array
    {
        [$mainId, $userId, $isMaster] = $this->context($body);
        $ref = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($ref === '') throw new HttpException(422, 'inquiryRefno is required');
        $this->assertInquiryExists($mainId, $ref);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales_inquiry_prints WHERE main_id = :main_id AND inquiry_refno = :ref AND printed_by = :user_id');
        $stmt->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
        $count = (int) $stmt->fetchColumn();
        $request = $pdo->prepare("SELECT id FROM sales_inquiry_print_requests WHERE main_id = :main_id AND inquiry_refno = :ref AND requested_by = :user_id AND status = 'approved' ORDER BY id DESC LIMIT 1");
        $request->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
        $pending = $pdo->prepare("SELECT 1 FROM sales_inquiry_print_requests WHERE main_id = :main_id AND inquiry_refno = :ref AND requested_by = :user_id AND status = 'pending' LIMIT 1");
        $pending->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
        return ['can_print' => SalesInquiryPrintPolicy::canPrint($isMaster, $count, $request->fetchColumn() !== false), 'print_count' => $count, 'has_pending_request' => $pending->fetchColumn() !== false];
    }

    public function recordPrint(array $params, array $query, array $body): array
    {
        [$mainId, $userId, $isMaster] = $this->context($body);
        $ref = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($ref === '') throw new HttpException(422, 'inquiryRefno is required');
        $this->assertInquiryExists($mainId, $ref);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT lid FROM tblinquiry WHERE lmain_id = :main_id AND lrefno = :ref FOR UPDATE');
            $lock->execute(['main_id' => $mainId, 'ref' => $ref]);
            if ($lock->fetchColumn() === false) throw new HttpException(404, 'Sales inquiry not found');
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM sales_inquiry_prints WHERE main_id = :main_id AND inquiry_refno = :ref AND printed_by = :user_id');
            $stmt->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            $count = (int) $stmt->fetchColumn();
            $approved = $pdo->prepare("SELECT id FROM sales_inquiry_print_requests WHERE main_id = :main_id AND inquiry_refno = :ref AND requested_by = :user_id AND status = 'approved' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $approved->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            $requestId = $approved->fetchColumn();
            if (!SalesInquiryPrintPolicy::canPrint($isMaster, $count, $requestId !== false)) {
                $pdo->rollBack();
                throw new HttpException(403, 'You have used your one print. Submit a reprint request to the Master User.');
            }
            if (!$isMaster && $requestId !== false) {
                $consume = $pdo->prepare("UPDATE sales_inquiry_print_requests SET status = 'used', reviewed_at = NOW() WHERE id = :id AND status = 'approved'");
                $consume->execute(['id' => $requestId]);
            }
            $insert = $pdo->prepare('INSERT INTO sales_inquiry_prints (main_id, inquiry_refno, printed_by) VALUES (:main_id, :ref, :user_id)');
            $insert->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            $pdo->commit();
            return ['printed' => true];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function requestReprint(array $params, array $query, array $body): array
    {
        [$mainId, $userId, $isMaster] = $this->context($body);
        if ($isMaster) throw new HttpException(422, 'Master Users can print without requesting approval.');
        $ref = trim((string) ($params['inquiryRefno'] ?? ''));
        if ($ref === '') throw new HttpException(422, 'inquiryRefno is required');
        $this->assertInquiryExists($mainId, $ref);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT lid FROM tblinquiry WHERE lmain_id = :main_id AND lrefno = :ref FOR UPDATE');
            $lock->execute(['main_id' => $mainId, 'ref' => $ref]);
            if ($lock->fetchColumn() === false) throw new HttpException(404, 'Sales inquiry not found');
            $pending = $pdo->prepare("SELECT id FROM sales_inquiry_print_requests WHERE main_id = :main_id AND inquiry_refno = :ref AND requested_by = :user_id AND status = 'pending' LIMIT 1");
            $pending->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            if ($pending->fetchColumn() !== false) {
                $pdo->commit();
                return ['requested' => true, 'already_pending' => true];
            }
            $printCount = $pdo->prepare('SELECT COUNT(*) FROM sales_inquiry_prints WHERE main_id = :main_id AND inquiry_refno = :ref AND printed_by = :user_id');
            $printCount->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            if ((int) $printCount->fetchColumn() < 1) {
                throw new HttpException(409, 'Your first print is still available.');
            }
            $approved = $pdo->prepare("SELECT id FROM sales_inquiry_print_requests WHERE main_id = :main_id AND inquiry_refno = :ref AND requested_by = :user_id AND status = 'approved' LIMIT 1");
            $approved->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            if ($approved->fetchColumn() !== false) {
                throw new HttpException(409, 'You already have an approved reprint available.');
            }
            $insert = $pdo->prepare('INSERT INTO sales_inquiry_print_requests (main_id, inquiry_refno, requested_by) VALUES (:main_id, :ref, :user_id)');
            $insert->execute(['main_id' => $mainId, 'ref' => $ref, 'user_id' => $userId]);
            $pdo->commit();
            return ['requested' => true, 'already_pending' => false];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function listRequests(array $params, array $query, array $body): array
    {
        [$mainId] = $this->context($body, true);
        $stmt = $this->db->pdo()->prepare("SELECT r.id, r.inquiry_refno, COALESCE(i.linqno, '') AS inquiry_no, r.requested_by, CONCAT_WS(' ', COALESCE(a.lfname, ''), COALESCE(a.lmname, ''), COALESCE(a.llname, '')) AS requested_by_name, DATE_FORMAT(r.requested_at, '%Y-%m-%d %H:%i:%s') AS requested_at FROM sales_inquiry_print_requests r LEFT JOIN tblinquiry i ON i.lmain_id = r.main_id AND i.lrefno = r.inquiry_refno LEFT JOIN tblaccount a ON a.lid = r.requested_by AND a.lmother_id = r.main_id WHERE r.main_id = :main_id AND r.status = 'pending' ORDER BY r.requested_at, r.id");
        $stmt->execute(['main_id' => $mainId]);
        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function reviewRequest(array $params, array $query, array $body): array
    {
        [$mainId, $userId] = $this->context($body, true);
        $id = (int) ($params['requestId'] ?? 0);
        $decision = (string) ($body['decision'] ?? '');
        if ($id <= 0 || !in_array($decision, ['approved', 'rejected'], true)) throw new HttpException(422, 'Valid requestId and decision are required');
        $stmt = $this->db->pdo()->prepare("UPDATE sales_inquiry_print_requests SET status = :decision, reviewed_by = :reviewer, reviewed_at = NOW() WHERE id = :id AND main_id = :main_id AND status = 'pending'");
        $stmt->execute(['decision' => $decision, 'reviewer' => $userId, 'id' => $id, 'main_id' => $mainId]);
        if ($stmt->rowCount() !== 1) throw new HttpException(409, 'Request is no longer pending.');
        return ['reviewed' => true, 'status' => $decision];
    }

    private function assertInquiryExists(int $mainId, string $ref): void
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM tblinquiry WHERE lmain_id = :main_id AND lrefno = :ref LIMIT 1');
        $stmt->execute(['main_id' => $mainId, 'ref' => $ref]);
        if ($stmt->fetchColumn() === false) throw new HttpException(404, 'Sales inquiry not found');
    }

    /** @return array{int, int, bool} */
    private function context(array $body, bool $masterOnly = false): array
    {
        $claims = is_array($body['__auth_claims'] ?? null) ? $body['__auth_claims'] : [];
        $mainId = (int) ($claims['main_userid'] ?? 0);
        $userId = (int) ($claims['sub'] ?? 0);
        $isMaster = (string) ($claims['user_type'] ?? '') === '1';
        if ($mainId <= 0 || $userId <= 0) throw new HttpException(401, 'Authenticated account required');
        if ($masterOnly && !$isMaster) throw new HttpException(403, 'Only the Master User can review print requests');
        return [$mainId, $userId, $isMaster];
    }
}