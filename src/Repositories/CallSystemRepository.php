<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\PhoneNumberNormalizer;
use RuntimeException;

final class CallSystemRepository implements CallSystemRepositoryInterface
{
    private const SMS_TEMPLATE_TYPE = 'ai_message_template';

    public function __construct(private readonly Database $db)
    {
    }

    public function findDevice(string $deviceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lagent_id, ldevice_id, llast_seen, lstatus, lcreated_at
             FROM tblcall_devices
             WHERE ldevice_id = :device_id
             LIMIT 1'
        );
        $stmt->execute(['device_id' => $deviceId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function upsertDevice(int $agentId, string $deviceId, string $status): array
    {
        $existing = $this->findDevice($deviceId);
        if ($existing !== null && (int) ($existing['lagent_id'] ?? 0) !== $agentId) {
            throw new RuntimeException('Device is already assigned to another staff account');
        }

        if ($existing === null) {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO tblcall_devices (lagent_id, ldevice_id, llast_seen, lstatus)
                 VALUES (:agent_id, :device_id, CURRENT_TIMESTAMP, :status)'
            );
            $stmt->execute([
                'agent_id' => $agentId,
                'device_id' => $deviceId,
                'status' => $status,
            ]);
        } else {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblcall_devices
                 SET llast_seen = CURRENT_TIMESTAMP, lstatus = :status
                 WHERE lid = :id AND lagent_id = :agent_id'
            );
            $stmt->execute([
                'status' => $status,
                'id' => (int) $existing['lid'],
                'agent_id' => $agentId,
            ]);
        }

        $device = $this->findDevice($deviceId);
        if ($device === null) {
            throw new RuntimeException('Unable to load registered call device');
        }

        return $device;
    }

    public function updateHeartbeat(int $agentId, string $deviceId, string $status): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblcall_devices
             SET llast_seen = CURRENT_TIMESTAMP, lstatus = :status
             WHERE ldevice_id = :device_id AND lagent_id = :agent_id'
        );
        $stmt->execute([
            'status' => $status,
            'device_id' => $deviceId,
            'agent_id' => $agentId,
        ]);

        $device = $this->findDevice($deviceId);
        if ($device === null || (int) ($device['lagent_id'] ?? 0) !== $agentId) {
            throw new RuntimeException('Call device is not registered to this staff account');
        }

        return $device;
    }

    public function findCustomerIdByPhone(int $mainId, string $phoneNumber): ?int
    {
        if ($mainId <= 0) {
            return null;
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT p.lid, p.lmobile, p.lphone, cp.lc_mobile, cp.lc_phone
             FROM tblpatient p
             LEFT JOIN tblcontact_person cp ON cp.lrefno = p.lsessionid
             WHERE p.lmain_id = :main_id'
        );
        $stmt->execute(['main_id' => $mainId]);
        $target = PhoneNumberNormalizer::normalize($phoneNumber);
        if ($target === '') {
            return null;
        }

        while ($row = $stmt->fetch()) {
            foreach (['lmobile', 'lphone', 'lc_mobile', 'lc_phone'] as $field) {
                if (PhoneNumberNormalizer::equivalent($target, (string) ($row[$field] ?? ''))) {
                    return (int) ($row['lid'] ?? 0) ?: null;
                }
            }
        }

        return null;
    }

    public function createCallLog(int $agentId, string $deviceId, array $call): array
    {
        $phoneNumber = (string) ($call['phone_number'] ?? '');
        $direction = (string) ($call['direction'] ?? '');
        $durationSeconds = (int) ($call['duration_seconds'] ?? 0);
        $callTimestamp = (string) ($call['call_timestamp'] ?? '');
        $source = (string) ($call['source'] ?? 'hardware');
        $customerId = isset($call['customer_id']) ? (int) $call['customer_id'] : null;

        $duplicate = $this->findDuplicateCallLog(
            $agentId,
            $deviceId,
            $phoneNumber,
            $direction,
            $durationSeconds,
            $callTimestamp
        );
        if ($duplicate !== null) {
            if ($customerId !== null && (int) ($duplicate['lcustomer_id'] ?? 0) <= 0) {
                $stmt = $this->db->pdo()->prepare(
                    'UPDATE tblcall_logs_v2
                     SET lcustomer_id = :customer_id
                     WHERE lid = :id AND lcustomer_id IS NULL'
                );
                $stmt->execute([
                    'customer_id' => $customerId,
                    'id' => (int) $duplicate['lid'],
                ]);
                $duplicate = $this->getCallLog((int) $duplicate['lid']) ?? $duplicate;
            }
            return [
                'created' => false,
                'duplicate' => true,
                'call' => $duplicate,
            ];
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblcall_logs_v2
                (lagent_id, ldevice_id, lcustomer_id, lphone_number, ldirection,
                 lduration_seconds, lcall_timestamp, lsource)
             VALUES
                (:agent_id, :device_id, :customer_id, :phone_number, :direction,
                 :duration_seconds, :call_timestamp, :source)'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'device_id' => $deviceId,
            'customer_id' => $customerId,
            'phone_number' => $phoneNumber,
            'direction' => $direction,
            'duration_seconds' => $durationSeconds,
            'call_timestamp' => $callTimestamp,
            'source' => $source,
        ]);

        $id = (int) $this->db->pdo()->lastInsertId();
        $callRow = $this->getCallLog($id);
        if ($callRow === null) {
            throw new RuntimeException('Unable to load created call log');
        }

        return [
            'created' => true,
            'duplicate' => false,
            'call' => $callRow,
        ];
    }

    public function createDialRequest(int $agentId, ?int $customerId, string $phoneNumber): array
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO tblcall_dial_requests
                (lagent_id, lphone_number, lcustomer_id, lstatus)
             VALUES
                (:agent_id, :phone_number, :customer_id, \'pending\')'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'phone_number' => $phoneNumber,
            'customer_id' => $customerId,
        ]);

        $request = $this->getDialRequest((int) $this->db->pdo()->lastInsertId(), $agentId);
        if ($request === null) {
            throw new RuntimeException('Unable to load created dial request');
        }

        return $request;
    }

    public function listPendingDialRequests(int $agentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lagent_id, lphone_number, lcustomer_id, lstatus, lcreated_at, lprocessed_at
             FROM tblcall_dial_requests
             WHERE lagent_id = :agent_id AND lstatus = \'pending\'
             ORDER BY lid ASC
             LIMIT 50'
        );
        $stmt->execute(['agent_id' => $agentId]);
        return $stmt->fetchAll();
    }

    public function updateDialRequestStatus(int $agentId, int $requestId, string $status): array
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE tblcall_dial_requests
             SET lstatus = :status, lprocessed_at = CURRENT_TIMESTAMP
             WHERE lid = :id AND lagent_id = :agent_id AND lstatus = \'pending\''
        );
        $stmt->execute([
            'status' => $status,
            'id' => $requestId,
            'agent_id' => $agentId,
        ]);

        $request = $this->getDialRequest($requestId, $agentId);
        if ($request === null) {
            throw new RuntimeException('Dial request was not found for this staff account');
        }

        return $request;
    }

    public function listDevices(int $viewerId, int $mainId, bool $canViewTeam): array
    {
        $scopeSql = $canViewTeam
            ? '(a.lid = :main_id OR a.lmother_id = :main_id_2)'
            : 'd.lagent_id = :viewer_id';
        $params = $canViewTeam
            ? ['main_id' => $mainId, 'main_id_2' => $mainId]
            : ['viewer_id' => $viewerId];

        $stmt = $this->db->pdo()->prepare(
            'SELECT d.lid, d.lagent_id, d.ldevice_id, d.llast_seen, d.lstatus, d.lcreated_at,
                    COALESCE(a.lfname, \'\') AS agent_first_name,
                    COALESCE(a.llname, \'\') AS agent_last_name,
                    CASE
                        WHEN d.llast_seen < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 6 MINUTE)
                        THEN \'device_offline\'
                        ELSE d.lstatus
                    END AS effective_status
             FROM tblcall_devices d
             INNER JOIN tblaccount a ON a.lid = d.lagent_id
             WHERE ' . $scopeSql . '
             ORDER BY a.lfname ASC, a.llname ASC, d.lid ASC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function listCallLogs(int $viewerId, int $mainId, bool $canViewTeam, array $filters = []): array
    {
        $conditions = [];
        $params = [];
        if ($canViewTeam) {
            $conditions[] = '(a.lid = :main_id OR a.lmother_id = :main_id_2)';
            $params['main_id'] = $mainId;
            $params['main_id_2'] = $mainId;
        } else {
            $conditions[] = 'c.lagent_id = :viewer_id';
            $params['viewer_id'] = $viewerId;
        }

        if (($filters['agent_id'] ?? 0) > 0 && $canViewTeam) {
            $conditions[] = 'c.lagent_id = :filter_agent_id';
            $params['filter_agent_id'] = (int) $filters['agent_id'];
        }
        if (($filters['customer_id'] ?? 0) > 0) {
            $conditions[] = 'c.lcustomer_id = :filter_customer_id';
            $params['filter_customer_id'] = (int) $filters['customer_id'];
        }
        if (($filters['direction'] ?? '') !== '') {
            $conditions[] = 'c.ldirection = :direction';
            $params['direction'] = (string) $filters['direction'];
        }
        if (($filters['from_date'] ?? '') !== '') {
            $conditions[] = 'c.lcall_timestamp >= :from_date';
            $params['from_date'] = (string) $filters['from_date'] . ' 00:00:00';
        }
        if (($filters['to_date'] ?? '') !== '') {
            $conditions[] = 'c.lcall_timestamp <= :to_date';
            $params['to_date'] = (string) $filters['to_date'] . ' 23:59:59';
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT c.lid, c.lagent_id, c.ldevice_id, c.lcustomer_id, c.lphone_number,
                    c.ldirection, c.lduration_seconds, c.lcall_timestamp, c.lsource, c.lcreated_at,
                    COALESCE(a.lfname, \'\') AS agent_first_name,
                    COALESCE(a.llname, \'\') AS agent_last_name,
                    COALESCE(p.lcompany, \'\') AS customer_company,
                    COALESCE(p.lpatient_code, \'\') AS customer_code
             FROM tblcall_logs_v2 c
             INNER JOIN tblaccount a ON a.lid = c.lagent_id
             LEFT JOIN tblpatient p ON p.lid = c.lcustomer_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY c.lcall_timestamp DESC, c.lid DESC
             LIMIT 200'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function getAutoReplySettings(?int $agentId = null): ?array
    {
        $condition = $agentId === null ? 'lagent_id IS NULL' : 'lagent_id = :agent_id';
        $params = $agentId === null ? [] : ['agent_id' => $agentId];
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lagent_id, lis_active, ltemplate_id, lcooldown_minutes, lupdated_at
             FROM tblcall_auto_reply_settings
             WHERE ' . $condition . '
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function saveAutoReplySettings(?int $agentId, bool $active, int $templateId, int $cooldownMinutes): array
    {
        if ($templateId <= 0 || $this->getActiveSmsTemplate($templateId) === null) {
            throw new RuntimeException('Active SMS template was not found');
        }

        $cooldownMinutes = max(1, min(10080, $cooldownMinutes));
        $existing = $this->getAutoReplySettings($agentId);
        if ($existing === null) {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO tblcall_auto_reply_settings
                    (lagent_id, lis_active, ltemplate_id, lcooldown_minutes)
                 VALUES (:agent_id, :active, :template_id, :cooldown_minutes)'
            );
            $stmt->bindValue(':agent_id', $agentId, $agentId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $stmt->bindValue(':active', $active ? 1 : 0, \PDO::PARAM_INT);
            $stmt->bindValue(':template_id', $templateId, \PDO::PARAM_INT);
            $stmt->bindValue(':cooldown_minutes', $cooldownMinutes, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE tblcall_auto_reply_settings
                 SET lis_active = :active, ltemplate_id = :template_id, lcooldown_minutes = :cooldown_minutes
                 WHERE lid = :id'
            );
            $stmt->execute([
                'active' => $active ? 1 : 0,
                'template_id' => $templateId,
                'cooldown_minutes' => $cooldownMinutes,
                'id' => (int) $existing['lid'],
            ]);
        }

        $settings = $this->getAutoReplySettings($agentId);
        if ($settings === null) {
            throw new RuntimeException('Unable to load saved auto-reply settings');
        }

        return $settings;
    }

    public function processMissedCallAutoReply(int $agentId, string $phoneNumber, int $templateId, int $cooldownMinutes): array
    {
        $template = $this->getActiveSmsTemplate($templateId);
        if ($template === null) {
            return ['queued' => false, 'reason' => 'template_missing'];
        }

        $normalizedPhone = PhoneNumberNormalizer::normalize($phoneNumber);
        if ($normalizedPhone === '') {
            return ['queued' => false, 'reason' => 'invalid_phone_number'];
        }

        $cutoff = date('Y-m-d H:i:s', time() - (max(1, $cooldownMinutes) * 60));
        $cooldownStmt = $this->db->pdo()->prepare(
            'SELECT lid
             FROM tblcall_auto_replies
             WHERE lagent_id = :agent_id
               AND lphone_number = :phone_number
               AND lsent_at >= :cutoff
             ORDER BY lid DESC
             LIMIT 1'
        );
        $cooldownStmt->execute([
            'agent_id' => $agentId,
            'phone_number' => $normalizedPhone,
            'cutoff' => $cutoff,
        ]);
        if ($cooldownStmt->fetchColumn() !== false) {
            return ['queued' => false, 'reason' => 'cooldown'];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $smsStmt = $pdo->prepare(
                'INSERT INTO tblsms (lto, lmessage, lstatus, lpriority, ldatetime, lmodem)
                 VALUES (:phone_number, :message, \'pending\', 1, CURRENT_TIMESTAMP, \'CALL_AUTO_REPLY\')'
            );
            $smsStmt->execute([
                'phone_number' => $normalizedPhone,
                'message' => (string) ($template['lmessage'] ?? ''),
            ]);
            $smsId = (int) $pdo->lastInsertId();

            $auditStmt = $pdo->prepare(
                'INSERT INTO tblcall_auto_replies
                    (lagent_id, lphone_number, lmessage_sent, lsent_at)
                 VALUES (:agent_id, :phone_number, :message, CURRENT_TIMESTAMP)'
            );
            $auditStmt->execute([
                'agent_id' => $agentId,
                'phone_number' => $normalizedPhone,
                'message' => (string) ($template['lmessage'] ?? ''),
            ]);
            $auditId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return [
                'queued' => true,
                'reason' => 'queued',
                'sms_id' => $smsId,
                'audit_id' => $auditId,
                'phone_number' => $normalizedPhone,
            ];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function listAutoReplyAudit(int $viewerId, int $mainId, bool $canViewTeam): array
    {
        $scopeSql = $canViewTeam
            ? '(a.lid = :main_id OR a.lmother_id = :main_id_2)'
            : 'r.lagent_id = :viewer_id';
        $params = $canViewTeam
            ? ['main_id' => $mainId, 'main_id_2' => $mainId]
            : ['viewer_id' => $viewerId];

        $stmt = $this->db->pdo()->prepare(
            'SELECT r.lid, r.lagent_id, r.lphone_number, r.lmessage_sent, r.lsent_at,
                    COALESCE(a.lfname, \'\') AS agent_first_name,
                    COALESCE(a.llname, \'\') AS agent_last_name
             FROM tblcall_auto_replies r
             INNER JOIN tblaccount a ON a.lid = r.lagent_id
             WHERE ' . $scopeSql . '
             ORDER BY r.lsent_at DESC, r.lid DESC
             LIMIT 200'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private function getActiveSmsTemplate(int $templateId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, ltemp_name, lmessage, lstatus
             FROM tbltemp_list
             WHERE lid = :id
               AND ltemp_type = :template_type
               AND COALESCE(lstatus, 1) = 1
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $templateId,
            'template_type' => self::SMS_TEMPLATE_TYPE,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findDuplicateCallLog(
        int $agentId,
        string $deviceId,
        string $phoneNumber,
        string $direction,
        int $durationSeconds,
        string $callTimestamp
    ): ?array {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lagent_id, ldevice_id, lcustomer_id, lphone_number, ldirection,
                    lduration_seconds, lcall_timestamp, lsource, lcreated_at
             FROM tblcall_logs_v2
             WHERE lagent_id = :agent_id
               AND ldevice_id = :device_id
               AND ldirection = :direction
               AND lduration_seconds = :duration_seconds
               AND lcall_timestamp = :call_timestamp
             ORDER BY lid DESC
             LIMIT 20'
        );
        $stmt->execute([
            'agent_id' => $agentId,
            'device_id' => $deviceId,
            'direction' => $direction,
            'duration_seconds' => $durationSeconds,
            'call_timestamp' => $callTimestamp,
        ]);

        while ($row = $stmt->fetch()) {
            if (PhoneNumberNormalizer::equivalent($phoneNumber, (string) ($row['lphone_number'] ?? ''))) {
                return $row;
            }
        }

        return null;
    }

    private function getCallLog(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lagent_id, ldevice_id, lcustomer_id, lphone_number, ldirection,
                    lduration_seconds, lcall_timestamp, lsource, lcreated_at
             FROM tblcall_logs_v2
             WHERE lid = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function getDialRequest(int $id, int $agentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid, lagent_id, lphone_number, lcustomer_id, lstatus, lcreated_at, lprocessed_at
             FROM tblcall_dial_requests
             WHERE lid = :id AND lagent_id = :agent_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'agent_id' => $agentId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
