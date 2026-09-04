<?php

declare(strict_types=1);
namespace App\Repositories;

use App\Database;
use App\Support\Exceptions\HttpException;
use PDO;
use Throwable;

final class CustomerRequestRepository
{
    private const FIELDS = ['company','email','phone','mobile','sales_person_id','refer_by','address','delivery_address','area','city','province','tin','price_group','business_line','terms','transaction_type','vat_type','vat_percent','dealer_since','dealer_quota','credit_limit','status','notes','debt_type','preferred_brand','profile_type','verification','contacts'];
    public function __construct(private readonly Database $db) {}

    private function customers(): CustomerDatabaseRepository { return new CustomerDatabaseRepository($this->db); }

    public function customer(int $mainId, string $contactId): array
    {
        $customer = $this->customers()->getCustomer($mainId, $contactId);
        if (!$customer) throw new HttpException(404, 'Customer not found');
        return $customer;
    }

    public function list(int $mainId, string $contactId, ?int $submitter = null): array
    {
        $this->customer($mainId, $contactId);
        $sql = 'SELECT r.*, TRIM(CONCAT(COALESCE(a.lfname,\'\'), \' \', COALESCE(a.llname,\'\'))) AS submitted_by_name FROM customer_requests r LEFT JOIN tblaccount a ON a.lid = r.submitted_by WHERE r.main_id = ? AND r.contact_id = ?';
        $params = [$mainId, $contactId];
        if ($submitter !== null) { $sql .= ' AND r.submitted_by = ?'; $params[] = $submitter; }
        $stmt = $this->db->pdo()->prepare($sql . ' ORDER BY r.submitted_at DESC, r.id DESC');
        $stmt->execute($params);
        return array_map([$this, 'decode'], $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function listAll(int $mainId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT r.*, TRIM(CONCAT(COALESCE(a.lfname,\'\'), \' \', COALESCE(a.llname,\'\'))) AS submitted_by_name
             FROM customer_requests r
             LEFT JOIN tblaccount a ON a.lid = r.submitted_by
             WHERE r.main_id = ?
             ORDER BY r.submitted_at DESC, r.id DESC'
        );
        $stmt->execute([$mainId]);
        return array_map([$this, 'decode'], $stmt->fetchAll());
    }

    private function decode(array $row): array
    {
        $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        // Baselines are for conflict detection, not an additional customer-data export.
        unset($row['baseline']);
        return $row;
    }

    public function create(int $mainId, string $contactId, int $userId, string $kind, array $payload): array
    {
        $customer = $this->customer($mainId, $contactId);
        $baseline = [];
        if ($kind === 'customer_update') {
            if (!$payload || array_diff(array_keys($payload), self::FIELDS)) throw new HttpException(422, 'Invalid or empty customer changes');
            foreach ($payload as $key => $value) {
                if ($key === 'contacts') {
                    $this->validateContacts($value, $customer['contacts']);
                } elseif (!is_scalar($value) && $value !== null) {
                    throw new HttpException(422, "Invalid {$key}");
                } elseif (strlen((string) $value) > 2000) {
                    throw new HttpException(422, "{$key} is too long");
                }
                if ($key === 'company' && trim((string) $value) === '') throw new HttpException(422, 'Company name is required');
                if (in_array($key, ['phone','mobile'], true) && mb_strlen((string) $value) > 15) throw new HttpException(422, 'Phone numbers may contain at most 15 characters');
                if ($key === 'sales_person_id' && $value !== '') {
                    $agent = (new AuthRepository($this->db))->findUserById((int) $value);
                    if (!$agent || (new AuthRepository($this->db))->resolveMainUserId($agent) !== $mainId) throw new HttpException(422, 'Sales agent must belong to this account');
                }
                if (in_array($key, ['dealer_quota','credit_limit','vat_percent'], true) && (!is_numeric($value) || (float) $value < 0)) throw new HttpException(422, "Invalid {$key}");
                if ($key === 'status' && !in_array($value, [0,1,2,3,4], true)) throw new HttpException(422, 'Invalid customer status');
                $baseline[$key] = $customer[$key] ?? null;
            }
        } elseif ($kind === 'discount') {
            $percent = $payload['discount_percentage'] ?? null;
            $reason = trim((string) ($payload['reason'] ?? ''));
            if (!is_numeric($percent) || (float) $percent < 1 || (float) $percent > 100 || strlen($reason) < 10 || strlen($reason) > 2000) throw new HttpException(422, 'Enter a discount from 1 to 100 and a reason of 10 to 2000 characters');
            // Approval records authorization only; it does not silently reprice existing documents.
            $payload = ['discount_percentage' => (float) $percent, 'reason' => $reason];
        } else {
            throw new HttpException(422, 'Invalid request kind');
        }
        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->pdo()->prepare('INSERT INTO customer_requests (id, main_id, contact_id, kind, payload, baseline, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $mainId, $contactId, $kind, json_encode($payload, JSON_THROW_ON_ERROR), json_encode($baseline, JSON_THROW_ON_ERROR), $userId]);
        return ['id' => $id, 'status' => 'pending'];
    }

    private function validateContacts(mixed $contacts, array $existing): void
    {
        if (!is_array($contacts) || !array_is_list($contacts) || count($contacts) > 50) throw new HttpException(422, 'Invalid contact people');
        $ids = array_map('strval', array_column($existing, 'id'));
        $seen = [];
        foreach ($contacts as $person) {
            if (!is_array($person) || array_diff(array_keys($person), ['id','first_name','middle_name','last_name','position','phone','mobile','email','address','birthday'])) throw new HttpException(422, 'Invalid contact person');
            foreach ($person as $value) if (!is_scalar($value) || strlen((string) $value) > 255) throw new HttpException(422, 'Invalid contact person field');
            $id = (string) ($person['id'] ?? '');
            if ($id !== '' && (!in_array($id, $ids, true) || in_array($id, $seen, true))) throw new HttpException(422, 'Contact person does not belong to this customer or is duplicated');
            if (trim((string) ($person['first_name'] ?? '')) === '') throw new HttpException(422, 'Contact person name is required');
            if ($id !== '') $seen[] = $id;
        }
    }

    public function review(int $mainId, string $contactId, string $id, int $reviewer, string $decision, string $note): array
    {
        if (!in_array($decision, ['approved','rejected'], true) || strlen($note) > 2000) throw new HttpException(422, 'Invalid review decision or note');
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM customer_requests WHERE main_id = ? AND contact_id = ? AND id = ? FOR UPDATE');
            $stmt->execute([$mainId, $contactId, $id]);
            $request = $stmt->fetch();
            if (!$request) throw new HttpException(404, 'Request not found');
            if ($request['status'] !== 'pending') throw new HttpException(409, 'This request has already been reviewed');
            if ($decision === 'approved' && $request['kind'] === 'customer_update') {
                $lock = $pdo->prepare('SELECT lid FROM tblpatient WHERE lmain_id = ? AND lsessionid = ? FOR UPDATE');
                $lock->execute([$mainId, $contactId]);
                $customer = $this->customer($mainId, $contactId);
                $baseline = json_decode($request['baseline'], true, 512, JSON_THROW_ON_ERROR);
                $payload = json_decode($request['payload'], true, 512, JSON_THROW_ON_ERROR);
                foreach ($baseline as $key => $value) {
                    if (($customer[$key] ?? null) != $value) throw new HttpException(409, 'Customer details changed after submission. Reject this request and request a fresh update.');
                }
                $this->customers()->updateCustomer($mainId, $contactId, array_diff_key($payload, ['contacts' => true]) + ['user_id' => $reviewer]);
                if (array_key_exists('contacts', $payload)) {
                    $this->validateContacts($payload['contacts'], $customer['contacts']);
                    $kept = [];
                    foreach ($payload['contacts'] as $person) {
                        if (!empty($person['id'])) {
                            $kept[] = (int) $person['id'];
                            $this->customers()->updateContact($mainId, (int) $person['id'], $person);
                        } else {
                            $this->customers()->addContact($mainId, $contactId, $person);
                        }
                    }
                    foreach ($customer['contacts'] as $person) if (!in_array((int) $person['id'], $kept, true)) $this->customers()->deleteContact($mainId, (int) $person['id']);
                }
            }
            $stmt = $pdo->prepare('UPDATE customer_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ? AND main_id = ?');
            $stmt->execute([$decision, $reviewer, $note, $id, $mainId]);
            $audit = $pdo->prepare('INSERT INTO tblaudit_trail (lmain_id,luser_id,lpage,laction,lrefno,ldatetime) VALUES (?,?,?,?,?,NOW())');
            $audit->execute([$mainId, $reviewer, 'Customer Requests', ucfirst($decision) . ' ' . $request['kind'], $id]);
            $pdo->commit();
            return ['id' => $id, 'status' => $decision];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
