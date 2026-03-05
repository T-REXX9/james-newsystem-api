<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use PDO;

final class ContactsRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Get all contacts
     * Replaces Supabase contacts table queries
     */
    public function list(int $mainId, int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countSql = <<<SQL
SELECT COUNT(*) as total
FROM tblpatient
WHERE lmain_id = :main_id
SQL;
        $countStmt = $this->db->pdo()->prepare($countSql);
        $countStmt->execute(['main_id' => $mainId]);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        // Get paginated data
        $sql = <<<SQL
SELECT
    CAST(lsessionid AS CHAR) AS id,
    lcompany AS company,
    laddress AS address,
    ldelivery_address AS deliveryAddress,
    lprovince AS province,
    lcity AS city,
    larea AS area,
    ltin AS tin,
    lbusiness_line AS businessLine,
    lterms AS terms,
    ltransaction_type AS transactionType,
    lvat_type AS vatType,
    lvat_percent AS vatPercentage,
    ldealer_since AS dealershipSince,
    ldealer_quota AS dealershipQuota,
    lcredit AS creditLimit,
    lnotes AS comment,
    ldebt_type AS debtType,
    CAST(lstatus AS UNSIGNED) AS status,
    lprice_group AS dealershipTerms,
    lsales_person AS salesPerson,
    lemail AS email,
    lphone AS phone,
    lmobile AS mobile,
    CONCAT_WS(' ', lfname, llname) AS name,
    ltitle AS title,
    0 AS is_deleted
FROM tblpatient
WHERE lmain_id = :main_id
ORDER BY lcompany ASC
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize data types
        foreach ($items as &$item) {
            $item['creditLimit'] = (float) ($item['creditLimit'] ?? 0);
            $item['dealershipQuota'] = (float) ($item['dealershipQuota'] ?? 0);
            $item['vatPercentage'] = (float) ($item['vatPercentage'] ?? 0.12);
            $item['status'] = (int) ($item['status'] ?? 1);
            $item['is_deleted'] = false;
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Get single contact by ID
     */
    public function show(int $mainId, string $contactId): ?array
    {
        $sql = <<<SQL
SELECT
    CAST(lsessionid AS CHAR) AS id,
    lcompany AS company,
    laddress AS address,
    ldelivery_address AS deliveryAddress,
    lprovince AS province,
    lcity AS city,
    larea AS area,
    ltin AS tin,
    lbusiness_line AS businessLine,
    lterms AS terms,
    ltransaction_type AS transactionType,
    lvat_type AS vatType,
    lvat_percent AS vatPercentage,
    ldealer_since AS dealershipSince,
    ldealer_quota AS dealershipQuota,
    lcredit AS creditLimit,
    lnotes AS comment,
    ldebt_type AS debtType,
    CAST(lstatus AS UNSIGNED) AS status,
    lprice_group AS dealershipTerms,
    lsales_person AS salesPerson,
    lemail AS email,
    lphone AS phone,
    lmobile AS mobile,
    CONCAT_WS(' ', lfname, llname) AS name,
    ltitle AS title,
    0 AS is_deleted
FROM tblpatient
WHERE lmain_id = :main_id AND CAST(lsessionid AS CHAR) = :id
LIMIT 1
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'id' => $contactId,
        ]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return null;
        }

        // Normalize data types
        $item['creditLimit'] = (float) ($item['creditLimit'] ?? 0);
        $item['dealershipQuota'] = (float) ($item['dealershipQuota'] ?? 0);
        $item['vatPercentage'] = (float) ($item['vatPercentage'] ?? 0.12);
        $item['status'] = (int) ($item['status'] ?? 1);
        $item['is_deleted'] = false;

        return $item;
    }

    /**
     * Create a new contact
     */
    public function create(int $mainId, int $userId, array $data): array
    {
        $sessionId = trim((string) ($data['sessionId'] ?? $data['id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $this->generateSessionId($mainId);
        }

        $sql = <<<SQL
INSERT INTO tblpatient
(lmain_id, lencoded_by, ldatetime, lsessionid, lcompany, laddress, ldelivery_address,
 lprovince, lcity, larea, ltin, lbusiness_line, lterms, ltransaction_type, lvat_type,
 lvat_percent, ldealer_since, ldealer_quota, lcredit, lnotes, ldebt_type, lprice_group,
 lsales_person, lemail, lphone, lmobile, lstatus)
VALUES
(:main_id, :encoded_by, NOW(), :session_id, :company, :address, :delivery_address,
 :province, :city, :area, :tin, :business_line, :terms, :transaction_type, :vat_type,
 :vat_percent, :dealer_since, :dealer_quota, :credit, :notes, :debt_type, :price_group,
 :sales_person, :email, :phone, :mobile, :status)
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'encoded_by' => $userId,
            'session_id' => $sessionId,
            'company' => (string) ($data['company'] ?? ''),
            'address' => (string) ($data['address'] ?? ''),
            'delivery_address' => (string) ($data['deliveryAddress'] ?? $data['address'] ?? ''),
            'province' => (string) ($data['province'] ?? ''),
            'city' => (string) ($data['city'] ?? ''),
            'area' => (string) ($data['area'] ?? ''),
            'tin' => (string) ($data['tin'] ?? ''),
            'business_line' => (string) ($data['businessLine'] ?? ''),
            'terms' => (string) ($data['terms'] ?? ''),
            'transaction_type' => (string) ($data['transactionType'] ?? 'Order Slip'),
            'vat_type' => (string) ($data['vatType'] ?? 'Zero-Rated'),
            'vat_percent' => isset($data['vatPercentage']) ? (float) $data['vatPercentage'] : 0.12,
            'dealer_since' => $this->normalizeDateNullable((string) ($data['dealershipSince'] ?? '')),
            'dealer_quota' => isset($data['dealershipQuota']) ? (float) $data['dealershipQuota'] : 0,
            'credit' => isset($data['creditLimit']) ? (float) $data['creditLimit'] : 0,
            'notes' => (string) ($data['comment'] ?? ''),
            'debt_type' => (string) ($data['debtType'] ?? ''),
            'price_group' => (string) ($data['dealershipTerms'] ?? ''),
            'sales_person' => (string) ($data['salesPerson'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'mobile' => (string) ($data['mobile'] ?? ''),
            'status' => isset($data['status']) ? (int) $data['status'] : 1,
        ]);

        // Return created contact
        return $this->show($mainId, $sessionId) ?? ['id' => $sessionId];
    }

    /**
     * Update a contact
     */
    public function update(int $mainId, string $contactId, array $updates): void
    {
        $setClause = [];
        $params = [
            'main_id' => $mainId,
            'id' => $contactId,
        ];

        $fieldMap = [
            'company' => 'lcompany',
            'address' => 'laddress',
            'deliveryAddress' => 'ldelivery_address',
            'province' => 'lprovince',
            'city' => 'lcity',
            'area' => 'larea',
            'tin' => 'ltin',
            'businessLine' => 'lbusiness_line',
            'terms' => 'lterms',
            'transactionType' => 'ltransaction_type',
            'vatType' => 'lvat_type',
            'vatPercentage' => 'lvat_percent',
            'dealershipSince' => 'ldealer_since',
            'dealershipQuota' => 'ldealer_quota',
            'creditLimit' => 'lcredit',
            'comment' => 'lnotes',
            'debtType' => 'ldebt_type',
            'dealershipTerms' => 'lprice_group',
            'salesPerson' => 'lsales_person',
            'email' => 'lemail',
            'phone' => 'lphone',
            'mobile' => 'lmobile',
            'status' => 'lstatus',
        ];

        foreach ($updates as $key => $value) {
            if (isset($fieldMap[$key]) && $value !== null) {
                $column = $fieldMap[$key];
                $paramKey = ':' . $key;
                $setClause[] = "$column = $paramKey";
                $params[$key] = $value;
            }
        }

        if (empty($setClause)) {
            return;
        }

        $sql = 'UPDATE tblpatient SET ' . implode(', ', $setClause) . ' WHERE lmain_id = :main_id AND CAST(lsessionid AS CHAR) = :id';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Delete (soft delete) a contact
     */
    public function delete(int $mainId, string $contactId): bool
    {
        $sql = <<<SQL
UPDATE tblpatient
SET lstatus = 0
WHERE lmain_id = :main_id AND CAST(lsessionid AS CHAR) = :id
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        return $stmt->execute([
            'main_id' => $mainId,
            'id' => $contactId,
        ]);
    }

    /**
     * Bulk update contacts
     */
    public function bulkUpdate(int $mainId, array $ids, array $updates): void
    {
        if (empty($ids)) {
            return;
        }

        $setClause = [];
        $params = ['main_id' => $mainId];

        $fieldMap = [
            'company' => 'lcompany',
            'address' => 'laddress',
            'status' => 'lstatus',
            'salesPerson' => 'lsales_person',
            'terms' => 'lterms',
            'creditLimit' => 'lcredit',
        ];

        foreach ($updates as $key => $value) {
            if (isset($fieldMap[$key]) && $value !== null) {
                $column = $fieldMap[$key];
                $paramKey = ':' . $key;
                $setClause[] = "$column = $paramKey";
                $params[$key] = $value;
            }
        }

        if (empty($setClause)) {
            return;
        }

        // Create placeholders for IDs
        $placeholders = array_map(
            fn($i) => ':id_' . $i,
            range(0, count($ids) - 1)
        );
        foreach ($ids as $i => $id) {
            $params['id_' . $i] = $id;
        }

        $sql = 'UPDATE tblpatient SET ' . implode(', ', $setClause) . ' WHERE lmain_id = :main_id AND CAST(lsessionid AS CHAR) IN (' . implode(', ', $placeholders) . ')';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function generateSessionId(int $mainId): string
    {
        return sprintf(
            '%s%s%s',
            date('YmdHis'),
            str_pad((string) mt_rand(0, 999), 3, '0', STR_PAD_LEFT),
            str_pad((string) $mainId, 3, '0', STR_PAD_LEFT)
        );
    }

    private function normalizeDateNullable(string $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }
        try {
            return (new \DateTime($value))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
