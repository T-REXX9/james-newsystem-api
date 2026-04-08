<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class NotificationsRepository
{
    private const DEFAULT_MAX_AGE_DAYS = 10;
    private const LEGACY_METADATA_MAX_BYTES = 225;
    private const LEGACY_METADATA_VALUE_LIMITS = [
        'e' => 24,
        'i' => 64,
        'a' => 32,
        's' => 16,
        'u' => 64,
        't' => 16,
        'c' => 16,
        'ai' => 16,
        'ar' => 24,
        'at' => 24,
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function listByUser(string $userId, int $limit = 50, ?int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS): array
    {
        $cutoff = $this->resolveCutoffDate($maxAgeDays);
        $sql = 'SELECT *
                FROM tblnotifications
                WHERE luserid = :user_id
                  AND COALESCE(lstatus, 0) != -1';

        if ($cutoff !== null) {
            $sql .= ' AND COALESCE(ldatetime, NOW()) >= :cutoff';
        }

        $sql .= '
             ORDER BY
               CASE WHEN COALESCE(lstatus, 0) = 1 THEN 0 ELSE 1 END ASC,
               ldatetime DESC,
               lid DESC
             LIMIT :limit';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        if ($cutoff !== null) {
            $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row): array => $this->normalizeNotification($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getUnreadCount(string $userId, ?int $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS): int
    {
        $cutoff = $this->resolveCutoffDate($maxAgeDays);
        $sql = 'SELECT COUNT(*)
                FROM tblnotifications
                WHERE luserid = :user_id
                  AND COALESCE(lstatus, 0) = 1';

        if ($cutoff !== null) {
            $sql .= ' AND COALESCE(ldatetime, NOW()) >= :cutoff';
        }

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        if ($cutoff !== null) {
            $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function resolveCutoffDate(?int $maxAgeDays): ?string
    {
        if ($maxAgeDays === null) {
            $maxAgeDays = self::DEFAULT_MAX_AGE_DAYS;
        }

        if ($maxAgeDays <= 0) {
            return null;
        }

        $boundedDays = min(365, max(1, $maxAgeDays));
        return date('Y-m-d H:i:s', strtotime(sprintf('-%d days', $boundedDays)));
    }

    public function create(array $input): ?array
    {
        $rawMetadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $referenceKey = $this->resolveReferenceKey($rawMetadata);
        $metadata = $this->compactMetadata($input);
        $metadataJson = $this->encodeMetadataForStorage($metadata);
        $recipientId = trim((string) ($input['recipient_id'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $idempotencyKey = trim((string) ($rawMetadata['idempotency_key'] ?? $referenceKey));

        if ($recipientId === '' || $title === '' || $message === '') {
            return null;
        }

        if ($idempotencyKey !== '' && $referenceKey !== '') {
            $existing = $this->findExistingByRecipientAndMetadata(
                $recipientId,
                $referenceKey
            );
            if ($existing !== null) {
                return $existing;
            }
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblnotifications
                (ltitle, lmessage, ldatetime, lstatus, lmain_id, linv_session, lout_status, ltype, luserid, lrefno)
             VALUES
                (:title, :message, NOW(), 1, :main_id, :metadata, \'0\', :category, :recipient_id, :refno)'
        );

        $stmt->execute([
            ':title' => $title,
            ':message' => $message,
            ':main_id' => trim((string) ($input['main_id'] ?? '')),
            ':metadata' => $metadataJson,
            ':category' => $this->toDatabaseCategory((string) ($metadata['c'] ?? $input['category'] ?? 'notification')),
            ':recipient_id' => $recipientId,
            ':refno' => $referenceKey,
        ]);

        return $this->findById((string) $this->db->pdo()->lastInsertId());
    }

    public function markAsRead(string $id): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE tblnotifications SET lstatus = 0 WHERE lid = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function markAllAsRead(string $userId): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE tblnotifications SET lstatus = 0 WHERE luserid = :user_id AND COALESCE(lstatus, 0) = 1');
        $stmt->execute([':user_id' => $userId]);

        return true;
    }

    public function markByEntityKey(string $userId, ?string $entityType, ?string $entityId, ?string $refno): array
    {
        $entityType = trim((string) $entityType);
        $entityId = trim((string) $entityId);
        $refno = trim((string) $refno);
        $referenceKey = $refno !== '' ? $refno : ($entityType !== '' && $entityId !== '' ? $this->buildReferenceKey($entityType, $entityId) : '');

        if ($referenceKey === '') {
            return [
                'success' => false,
                'updatedCount' => 0,
                'updatedIds' => [],
            ];
        }

        $select = $this->db->pdo()->prepare(
            'SELECT lid FROM tblnotifications WHERE luserid = :user_id AND lrefno = :refno AND COALESCE(lstatus, 0) = 1'
        );
        $select->execute([
            ':user_id' => $userId,
            ':refno' => $referenceKey,
        ]);
        $ids = array_map(
            static fn (array $row): string => (string) ($row['lid'] ?? ''),
            $select->fetchAll(PDO::FETCH_ASSOC)
        );

        if ($ids === []) {
            return [
                'success' => true,
                'updatedCount' => 0,
                'updatedIds' => [],
                'readAt' => date(DATE_ATOM),
            ];
        }

        $update = $this->db->pdo()->prepare(
            'UPDATE tblnotifications SET lstatus = 0 WHERE luserid = :user_id AND lrefno = :refno AND COALESCE(lstatus, 0) = 1'
        );
        $update->execute([
            ':user_id' => $userId,
            ':refno' => $referenceKey,
        ]);

        return [
            'success' => true,
            'updatedCount' => count($ids),
            'updatedIds' => $ids,
            'readAt' => date(DATE_ATOM),
        ];
    }

    public function delete(string $id): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE tblnotifications SET lstatus = -1 WHERE lid = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function dispatchWorkflow(array $payload): array
    {
        $actorId = trim((string) ($payload['actorId'] ?? ''));
        $actorRole = trim((string) ($payload['actorRole'] ?? 'Unknown'));
        $targetRoles = is_array($payload['targetRoles'] ?? null) ? $payload['targetRoles'] : [];
        $targetUserIds = is_array($payload['targetUserIds'] ?? null) ? $payload['targetUserIds'] : [];
        $includeActor = (bool) ($payload['includeActor'] ?? false);
        $recipients = $this->resolveRecipients($targetRoles, $targetUserIds, $includeActor ? $actorId : null);

        $created = [];
        foreach ($recipients as $recipientId) {
            $notification = $this->create([
                'recipient_id' => $recipientId,
                'title' => $payload['title'] ?? '',
                'message' => $payload['message'] ?? '',
                'type' => $payload['type'] ?? 'info',
                'category' => $payload['metadata']['category'] ?? null,
                'action_url' => $payload['actionUrl'] ?? null,
                'main_id' => $payload['main_id'] ?? null,
                'metadata' => [
                    'actor_id' => $actorId,
                    'actor_role' => $actorRole,
                    'entity_type' => $payload['entityType'] ?? '',
                    'entity_id' => $payload['entityId'] ?? '',
                    'action' => $payload['action'] ?? '',
                    'status' => $payload['status'] ?? '',
                    'action_url' => $payload['actionUrl'] ?? null,
                    'refno' => $payload['metadata']['refno'] ?? ($payload['entityType'] ?? '') . ':' . ($payload['entityId'] ?? ''),
                    'idempotency_key' => $payload['metadata']['idempotency_key'] ?? implode(':', [
                        $recipientId,
                        $payload['entityType'] ?? '',
                        $payload['entityId'] ?? '',
                        $payload['action'] ?? '',
                        $payload['status'] ?? '',
                    ]),
                    'severity' => $payload['type'] ?? 'info',
                    'category' => $payload['metadata']['category'] ?? null,
                    'alert_type' => $payload['metadata']['alert_type'] ?? null,
                ] + (is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []),
            ]);

            if ($notification !== null) {
                $created[] = $notification;
            }
        }

        return $created;
    }

    public function scanInventoryAlerts(int $mainId = 0): array
    {
        $products = $this->inventoryAlertCandidates($mainId);
        $recipients = $this->resolveRecipients(['Warehouse Manager', 'Warehouse', 'Purchasing Manager', 'Purchasing Staff', 'Owner'], []);
        $created = [];

        foreach ($products as $product) {
            $conditions = [];
            $stock = (float) ($product['total_stock'] ?? 0);
            $reorderQuantity = (float) ($product['reorder_quantity'] ?? 0);
            $expiryDate = trim((string) ($product['expiry_date'] ?? ''));

            if ($stock <= 0) {
                $conditions[] = [
                    'condition' => 'out_of_stock',
                    'title' => 'Inventory Out of Stock',
                    'message' => sprintf(
                        'Item %s with Part No %s is already out of stock.',
                        trim((string) ($product['item_code'] ?? 'Unknown')),
                        trim((string) ($product['part_no'] ?? 'Unknown'))
                    ),
                    'type' => 'error',
                ];
            } elseif ($reorderQuantity > 0 && $stock <= $reorderQuantity) {
                $conditions[] = [
                    'condition' => 'critical_stock',
                    'title' => 'Inventory Critical Stock',
                    'message' => sprintf(
                        'Item %s with Part No %s is on critical stock (%s remaining).',
                        trim((string) ($product['item_code'] ?? 'Unknown')),
                        trim((string) ($product['part_no'] ?? 'Unknown')),
                        rtrim(rtrim(number_format($stock, 2, '.', ''), '0'), '.')
                    ),
                    'type' => 'warning',
                ];
            }

            if ($expiryDate !== '' && strtotime($expiryDate) !== false && strtotime($expiryDate) <= strtotime(date('Y-m-d'))) {
                $conditions[] = [
                    'condition' => 'expired',
                    'title' => 'Inventory Item Expired',
                    'message' => sprintf(
                        'Item %s with Part No %s expired on %s.',
                        trim((string) ($product['item_code'] ?? 'Unknown')),
                        trim((string) ($product['part_no'] ?? 'Unknown')),
                        date('Y-m-d', strtotime($expiryDate))
                    ),
                    'type' => 'warning',
                ];
            }

            foreach ($conditions as $condition) {
                foreach ($recipients as $recipientId) {
                    $notification = $this->create([
                        'recipient_id' => $recipientId,
                        'title' => $condition['title'],
                        'message' => $condition['message'],
                        'type' => $condition['type'],
                        'category' => 'alert',
                        'main_id' => $mainId > 0 ? (string) $mainId : '',
                        'metadata' => [
                            'entity_type' => 'inventory',
                            'entity_id' => (string) ($product['product_id'] ?? ''),
                            'action' => $condition['condition'],
                            'status' => 'active',
                            'action_url' => 'warehouse-inventory-product-database',
                            'refno' => $this->buildReferenceKey('inventory', (string) ($product['product_id'] ?? '')),
                            'idempotency_key' => implode(':', [
                                'inventory',
                                (string) ($product['product_id'] ?? ''),
                                $condition['condition'],
                                $recipientId,
                            ]),
                            'severity' => $condition['type'],
                            'category' => 'alert',
                            'alert_type' => $condition['condition'],
                        ],
                    ]);

                    if ($notification !== null) {
                        $created[] = $notification;
                    }
                }
            }
        }

        return $created;
    }

    private function findById(string $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM tblnotifications WHERE lid = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeNotification($row) : null;
    }

    private function findExistingByRecipientAndMetadata(string $recipientId, string $referenceKey): ?array
    {
        if ($referenceKey === '') {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tblnotifications WHERE luserid = :user_id AND lrefno = :refno AND lstatus = 1 LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $recipientId,
            ':refno' => $referenceKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeNotification($row) : null;
    }

    private function normalizeNotification(array $row): array
    {
        $metadata = $this->decodeMetadata($row['linv_session'] ?? null);
        $category = $this->normalizeCategory($row['ltype'] ?? null, $metadata['c'] ?? null);
        $type = $this->normalizeSeverity($metadata['t'] ?? null, $category);
        $referenceKey = trim((string) ($row['lrefno'] ?? ''));

        return [
            'id' => (string) ($row['lid'] ?? ''),
            'recipient_id' => (string) ($row['luserid'] ?? ''),
            'title' => (string) ($row['ltitle'] ?? ''),
            'message' => (string) ($row['lmessage'] ?? ''),
            'type' => $type,
            'category' => $category,
            'action_url' => $this->resolveActionUrl($metadata),
            'metadata' => [
                'actor_id' => (string) ($metadata['ai'] ?? 'system'),
                'actor_role' => (string) ($metadata['ar'] ?? 'system'),
                'entity_type' => (string) ($metadata['e'] ?? 'system'),
                'entity_id' => (string) ($metadata['i'] ?? $referenceKey),
                'severity' => $type,
                'category' => $category,
                'action' => (string) ($metadata['a'] ?? 'notify'),
                'status' => (string) ($metadata['s'] ?? (((int) ($row['lstatus'] ?? 0)) === 1 ? 'unread' : 'read')),
                'action_url' => $this->resolveActionUrl($metadata),
                'idempotency_key' => $referenceKey,
                'refno' => $referenceKey,
                'alert_type' => $metadata['at'] ?? null,
            ],
            'is_read' => (int) ($row['lstatus'] ?? 0) !== 1,
            'created_at' => $this->normalizeTimestamp($row['ldatetime'] ?? null),
            'read_at' => (int) ($row['lstatus'] ?? 0) !== 1 ? $this->normalizeTimestamp($row['ldatetime'] ?? null) : null,
        ];
    }

    private function compactMetadata(array $input): array
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $entityType = trim((string) ($metadata['entity_type'] ?? ''));
        $entityId = trim((string) ($metadata['entity_id'] ?? ''));

        $compact = [
            'e' => $entityType,
            'i' => $entityId,
            'a' => trim((string) ($metadata['action'] ?? 'notify')),
            's' => trim((string) ($metadata['status'] ?? 'created')),
            'u' => trim((string) ($input['action_url'] ?? $metadata['action_url'] ?? '')),
            't' => trim((string) ($input['type'] ?? $metadata['severity'] ?? 'info')),
            'c' => trim((string) ($input['category'] ?? $metadata['category'] ?? 'notification')),
            'ai' => trim((string) ($metadata['actor_id'] ?? 'system')),
            'ar' => trim((string) ($metadata['actor_role'] ?? 'system')),
            'at' => trim((string) ($metadata['alert_type'] ?? '')),
        ];

        return array_filter($compact, static fn (mixed $value): bool => $value !== '');
    }

    private function resolveReferenceKey(array $metadata): string
    {
        $refno = trim((string) ($metadata['refno'] ?? ''));
        if ($refno !== '') {
            return $refno;
        }

        $entityType = trim((string) ($metadata['entity_type'] ?? ''));
        $entityId = trim((string) ($metadata['entity_id'] ?? ''));
        if ($entityType === '' || $entityId === '') {
            return '';
        }

        return $this->buildReferenceKey($entityType, $entityId);
    }

    private function encodeMetadataForStorage(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }

        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            $trimmed = trim((string) $value);
            if ($trimmed === '') {
                continue;
            }

            $maxLength = self::LEGACY_METADATA_VALUE_LIMITS[$key] ?? 64;
            $normalized[$key] = substr($trimmed, 0, $maxLength);
        }

        if ($normalized === []) {
            return null;
        }

        $dropOrder = ['ar', 'ai', 'c', 'u', 'at', 's', 'a', 't', 'i', 'e'];
        $current = $normalized;

        while ($current !== []) {
            $json = json_encode($current, JSON_UNESCAPED_SLASHES);
            if (is_string($json) && strlen($json) <= self::LEGACY_METADATA_MAX_BYTES) {
                return $json;
            }

            $keyToDrop = array_shift($dropOrder);
            if ($keyToDrop === null) {
                break;
            }

            unset($current[$keyToDrop]);
        }

        return null;
    }

    private function resolveActionUrl(array $metadata): ?string
    {
        $actionUrl = trim((string) ($metadata['u'] ?? ''));
        if ($actionUrl !== '') {
            return $actionUrl;
        }

        if (trim((string) ($metadata['e'] ?? '')) === 'inventory') {
            return 'warehouse-inventory-product-database';
        }

        return null;
    }

    private function decodeMetadata(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function toDatabaseCategory(string $category): string
    {
        return strtolower(trim($category)) === 'alert' ? 'Alert' : 'Notification';
    }

    private function normalizeCategory(mixed $dbCategory, mixed $metadataCategory): string
    {
        $category = strtolower(trim((string) ($metadataCategory ?: $dbCategory ?: 'notification')));
        return $category === 'alert' ? 'alert' : 'notification';
    }

    private function normalizeSeverity(mixed $value, string $category): string
    {
        $severity = strtolower(trim((string) $value));
        if (in_array($severity, ['info', 'success', 'warning', 'error'], true)) {
            return $severity;
        }

        return $category === 'alert' ? 'warning' : 'info';
    }

    private function normalizeTimestamp(mixed $value): string
    {
        $timestamp = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
        return $timestamp === false ? date(DATE_ATOM) : date(DATE_ATOM, $timestamp);
    }

    private function resolveRecipients(array $targetRoles, array $targetUserIds, ?string $actorId = null): array
    {
        $recipients = [];

        foreach ($targetUserIds as $userId) {
            $normalized = trim((string) $userId);
            if ($normalized !== '') {
                $recipients[$normalized] = true;
            }
        }

        if ($targetRoles !== []) {
            $stmt = $this->db->pdo()->query(
                'SELECT
                    a.lid,
                    a.ltype,
                    ut.ltype_name AS role_name
                 FROM tblaccount a
                 LEFT JOIN tblusertype ut ON ut.lid = a.ltype
                 WHERE COALESCE(a.lstatus, 0) = 1'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $candidateRoles = $this->accountRoleCandidates($row);
                foreach ($targetRoles as $targetRole) {
                    $normalizedTargetRole = $this->normalizeRole((string) $targetRole);
                    foreach ($candidateRoles as $candidateRole) {
                        if ($this->roleMatches($candidateRole, $normalizedTargetRole)) {
                            $recipients[(string) ($row['lid'] ?? '')] = true;
                            break 2;
                        }
                    }
                }
            }
        }

        if ($actorId !== null && trim($actorId) !== '') {
            $recipients[trim($actorId)] = true;
        }

        return array_values(array_filter(array_keys($recipients), static fn (string $value): bool => $value !== ''));
    }

    private function normalizeRole(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($value))) ?: '';
    }

    private function roleMatches(string $candidate, string $target): bool
    {
        if ($candidate === '' || $target === '') {
            return false;
        }

        return $candidate === $target;
    }

    /**
     * @return array<int, string>
     */
    private function accountRoleCandidates(array $row): array
    {
        $type = trim((string) ($row['ltype'] ?? ''));
        $roleName = trim((string) ($row['role_name'] ?? ''));
        $candidates = [];

        if ($type === '1') {
            $candidates[] = 'owner';
            $candidates[] = 'main';
        } elseif ($type === '2') {
            $candidates[] = 'salesagent';
            $candidates[] = 'salesperson';
        } elseif ($type === '3') {
            $candidates[] = 'accountant';
        } elseif ($type === '4') {
            $candidates[] = 'warehouse';
            $candidates[] = 'warehousepersonnel';
        }

        $normalizedRoleName = $this->normalizeRole($roleName);
        if ($normalizedRoleName !== '') {
            $candidates[] = $normalizedRoleName;
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => $candidate !== '')));
    }

    private function buildReferenceKey(string $entityType, string $entityId): string
    {
        return trim($entityType) . ':' . trim($entityId);
    }

    private function inventoryAlertCandidates(int $mainId = 0): array
    {
        $sql = <<<SQL
SELECT
    CAST(itm.lsession AS CHAR) AS product_id,
    COALESCE(itm.litemcode, '') AS item_code,
    COALESCE(itm.lpartno, '') AS part_no,
    CAST(COALESCE(itm.lreorder_amt, 0) AS DECIMAL(15,2)) AS reorder_quantity,
    CASE
        WHEN COALESCE(itm.lenablexp, '') <> '' THEN itm.ldate_expire
        ELSE NULL
    END AS expiry_date,
    CAST(COALESCE((
        SELECT SUM(COALESCE(lg.lin, 0) - COALESCE(lg.lout, 0))
        FROM tblinventory_logs lg
        WHERE lg.linvent_id = itm.lsession
    ), 0) AS DECIMAL(15,2)) AS total_stock
FROM tblinventory_item itm
WHERE COALESCE(itm.lnot_inventory, 0) = 0
SQL;

        $params = [];
        if ($mainId > 0) {
            $sql .= ' AND itm.lmain_id = :main_id';
            $params[':main_id'] = $mainId;
        }

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
