<?php

namespace App\Controllers;

use App\Database;
use PDO;
use RuntimeException;

class SmsGatewayController
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function queue(array $request): array
    {
        $payload = json_decode(file_get_contents('php://input'), true) ?: ($request['body'] ?? []);
        $messages = $payload['messages'] ?? [];
        $simId = isset($payload['sim_id']) && $payload['sim_id'] !== '' ? (int) $payload['sim_id'] : null;
        
        if (empty($messages)) {
            http_response_code(400);
            return ['error' => 'messages array is required'];
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        
        try {
            // We use lmodem field to temporarily hold sim_id during queueing since we can't change schema
            // It will be overwritten with 'Success' or error message after sending
            $stmt = $pdo->prepare(
                'INSERT INTO tblsms (lto, lmessage, lstatus, lpriority, ldatetime, lmodem) 
                 VALUES (:phone, :message, "pending", 1, NOW(), :sim_id)'
            );
            
            $queued = 0;
            foreach ($messages as $msg) {
                $phone = trim((string) ($msg['phone'] ?? ''));
                $text = trim((string) ($msg['message'] ?? ''));
                
                if ($phone !== '' && $text !== '') {
                    $stmt->execute([
                        'phone' => $phone,
                        'message' => $text,
                        'sim_id' => $simId !== null ? "SIM_ID:$simId" : null
                    ]);
                    $queued++;
                }
            }
            
            $pdo->commit();
            return ['success' => true, 'queued' => $queued];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            return ['error' => 'Failed to queue messages'];
        }
    }

    public function fetchJobs(array $request): array
    {
        $payload = json_decode(file_get_contents('php://input'), true) ?: ($request['body'] ?? []);
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        $limit = min(50, max(1, (int) ($payload['limit'] ?? 5)));

        if ($deviceId === '') {
            http_response_code(400);
            return ['error' => 'device_id is required'];
        }

        $pdo = $this->db->pdo();
        
        // Find unsent SMS messages
        // lstatus 'pending' or 'queued' are targets
        $stmt = $pdo->prepare(
            'SELECT lid AS id, lto AS phone, lmessage AS message, lresend_ctr AS retries, lmodem 
             FROM tblsms 
             WHERE lstatus IN ("pending", "queued") 
               AND (lgateway IS NULL OR lgateway = :device_id)
               AND (lresend_ctr IS NULL OR lresend_ctr < 3)
             ORDER BY lpriority DESC, lid ASC 
             LIMIT :limit'
        );
        
        $stmt->bindValue('device_id', $deviceId, PDO::PARAM_STR);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $rawJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $jobs = [];
        foreach ($rawJobs as $job) {
            $simId = null;
            if (isset($job['lmodem']) && strpos($job['lmodem'], 'SIM_ID:') === 0) {
                $simId = (int) substr($job['lmodem'], 7);
            }
            $jobs[] = [
                'id' => $job['id'],
                'phone' => $job['phone'],
                'message' => $job['message'],
                'retries' => $job['retries'],
                'sim_id' => $simId
            ];
        }
        
        // Mark as processing
        if (!empty($jobs)) {
            $ids = array_column($jobs, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            $updateStmt = $pdo->prepare(
                "UPDATE tblsms 
                 SET lstatus = 'processing', lgateway = ?, ldatetime_pickup = NOW() 
                 WHERE lid IN ($placeholders)"
            );
            
            $params = array_merge([$deviceId], $ids);
            $updateStmt->execute($params);
        }
        
        return ['jobs' => $jobs];
    }

    public function registerDevice(array $request): array
    {
        $payload = json_decode(file_get_contents('php://input'), true) ?: ($request['body'] ?? []);
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        $simCards = $payload['sim_cards'] ?? [];
        
        if ($deviceId === '') {
            http_response_code(400);
            return ['error' => 'device_id is required'];
        }
        
        // Since we can't change schema, we can store device info in a file cache or memory cache
        // For a production system without schema changes, we can use the filesystem temporarily
        $cacheDir = __DIR__ . '/../../../cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        
        $cacheFile = $cacheDir . '/gateway_devices.json';
        $devices = [];
        if (file_exists($cacheFile)) {
            $devices = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        
        $devices[$deviceId] = [
            'last_seen' => date('Y-m-d H:i:s'),
            'sim_cards' => $simCards
        ];
        
        file_put_contents($cacheFile, json_encode($devices, JSON_PRETTY_PRINT));
        
        return ['success' => true];
    }

    public function getHistory(array $request): array
    {
        $pdo = $this->db->pdo();
        // Fetch the 100 most recent SMS activity logs
        $stmt = $pdo->prepare(
            'SELECT lid AS id, lto AS phone, lmessage AS message, lstatus AS status, ldatetime AS created_at, lmodem AS details, lgateway AS device_id, lresend_ctr AS retries 
             FROM tblsms 
             ORDER BY lid DESC 
             LIMIT 100'
        );
        $stmt->execute();
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Parse sim_id out of lmodem if it's still pending
        foreach ($history as &$row) {
            $row['sim_id'] = null;
            if ($row['status'] === 'pending' && isset($row['details']) && strpos($row['details'], 'SIM_ID:') === 0) {
                $row['sim_id'] = (int) substr($row['details'], 7);
                $row['details'] = 'Waiting for gateway...';
            }
        }

        return ['history' => $history];
    }

    public function getDevices(array $request): array
    {
        $cacheFile = __DIR__ . '/../../../cache/gateway_devices.json';
        $devices = [];
        if (file_exists($cacheFile)) {
            $devices = json_decode(file_get_contents($cacheFile), true) ?: [];
        }
        
        // Clean up old devices (not seen in 24 hours)
        $activeDevices = [];
        $threshold = strtotime('-24 hours');
        foreach ($devices as $id => $info) {
            if (strtotime($info['last_seen']) > $threshold) {
                $activeDevices[$id] = $info;
            }
        }
        
        return ['devices' => $activeDevices];
    }

    public function reportStatus(array $request): array
    {
        $payload = json_decode(file_get_contents('php://input'), true) ?: ($request['body'] ?? []);
        $deviceId = trim((string) ($payload['device_id'] ?? ''));
        $jobId = (int) ($payload['job_id'] ?? 0);
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $details = trim((string) ($payload['details'] ?? ''));

        if ($deviceId === '' || $jobId <= 0 || $status === '') {
            http_response_code(400);
            return ['error' => 'device_id, job_id, and status are required'];
        }

        $pdo = $this->db->pdo();
        
        // Map app status to db status
        $dbStatus = $status === 'sent' ? 'sent' : 'failed';
        
        if ($dbStatus === 'failed') {
            // Increment retry counter
            $stmt = $pdo->prepare(
                "UPDATE tblsms 
                 SET lstatus = CASE WHEN COALESCE(lresend_ctr, 0) >= 2 THEN 'failed' ELSE 'pending' END,
                     lresend_ctr = COALESCE(lresend_ctr, 0) + 1,
                     lmodem = :details
                 WHERE lid = :job_id AND lgateway = :device_id"
            );
        } else {
            $stmt = $pdo->prepare(
                "UPDATE tblsms 
                 SET lstatus = 'sent', lmodem = :details 
                 WHERE lid = :job_id AND lgateway = :device_id"
            );
        }
        
        $stmt->execute([
            'job_id' => $jobId,
            'device_id' => $deviceId,
            'details' => substr($details, 0, 255) // truncate to fit standard column if needed
        ]);
        
        return ['success' => true, 'updated' => $stmt->rowCount() > 0];
    }
}
