<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database;
use App\Support\AuditTrailWriter;
use PDO;
use RuntimeException;

final class CustomerDatabaseRepository
{
    private const DEFAULT_VAT_TYPE = 'Zero-Rated';
    private const CUSTOMER_PHONE_MAX_LENGTH = 15;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listCustomers(
        int $mainId,
        string $search = '',
        string $status = 'all',
        int $page = 1,
        int $perPage = 100,
        string $mode = 'full'
    ): array {
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $params = [
            'main_id' => $mainId,
            'limit' => $perPage,
            'offset' => $offset,
        ];
        $where = ['p.lmain_id = :main_id'];

        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus !== '' && $normalizedStatus !== 'all') {
            if ($normalizedStatus === 'active') {
                $where[] = 'COALESCE(p.lstatus, 1) = 1';
                $where[] = "COALESCE(p.lprofile_type, 'Old') <> 'Prospect'";
            } elseif ($normalizedStatus === 'inactive') {
                $where[] = 'COALESCE(p.lstatus, 1) = 0';
                $where[] = "COALESCE(p.lprofile_type, 'Old') <> 'Prospect'";
            } elseif ($normalizedStatus === 'prospect' || $normalizedStatus === 'prospective') {
                $where[] = "(COALESCE(p.lprofile_type, 'Old') = 'Prospect' OR COALESCE(p.lstatus, 1) = 3)";
            } elseif ($normalizedStatus === 'blacklisted') {
                $where[] = "COALESCE(p.ldebt_type, 'Good') = 'Bad'";
            }
        }

        $trimmedSearch = trim($search);
        $normalizedMode = strtolower(trim($mode));
        $isPickerMode = $normalizedMode === 'picker' || $normalizedMode === 'minimal';
        if ($trimmedSearch !== '') {
            $params['search_code'] = '%' . $trimmedSearch . '%';
            $params['search_company'] = '%' . $trimmedSearch . '%';
            $params['search_mobile'] = '%' . $trimmedSearch . '%';
            if ($isPickerMode) {
                $where[] = <<<SQL
(
    COALESCE(p.lpatient_code, '') LIKE :search_code
    OR COALESCE(p.lcompany, '') LIKE :search_company
    OR COALESCE(p.lmobile, '') LIKE :search_mobile
)
SQL;
            } else {
                $params['search_sales'] = '%' . $trimmedSearch . '%';
                $params['search_area'] = '%' . $trimmedSearch . '%';
                $params['search_tin'] = '%' . $trimmedSearch . '%';
                $params['search_contact'] = '%' . $trimmedSearch . '%';
                $where[] = <<<SQL
(
    COALESCE(p.lpatient_code, '') LIKE :search_code
    OR COALESCE(p.lcompany, '') LIKE :search_company
    OR TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) LIKE :search_sales
    OR COALESCE(p.larea, '') LIKE :search_area
    OR COALESCE(p.lmobile, '') LIKE :search_mobile
    OR COALESCE(p.ltin, '') LIKE :search_tin
    OR EXISTS (
        SELECT 1
        FROM tblcontact_person cp
        WHERE cp.lrefno = p.lsessionid
          AND CONCAT_WS(' ', COALESCE(cp.lfname, ''), COALESCE(cp.llname, ''), COALESCE(cp.lc_mobile, ''), COALESCE(cp.lemail, '')) LIKE :search_contact
    )
)
SQL;
            }
        }

        $whereSql = implode(' AND ', $where);
        $total = 0;
        if (!$isPickerMode) {
            $countSql = "SELECT COUNT(*) AS total FROM tblpatient p LEFT JOIN tblaccount acc ON acc.lid = p.lsales_person WHERE {$whereSql}";
            $countStmt = $this->db->pdo()->prepare($countSql);
            $this->bindParams($countStmt, $params, false);
            $countStmt->execute();
            $total = (int) ($countStmt->fetchColumn() ?: 0);
        }

        $sql = $isPickerMode ? <<<SQL
SELECT
    p.lid AS id,
    COALESCE(p.lsessionid, '') AS session_id,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lcompany, '') AS company
FROM tblpatient p
WHERE {$whereSql}
ORDER BY p.lcompany ASC, p.lid ASC
LIMIT :limit OFFSET :offset
SQL
        : <<<SQL
SELECT
    p.lid AS id,
    COALESCE(p.lsessionid, '') AS session_id,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lcompany, '') AS company,
    COALESCE(p.lemail, '') AS email,
    COALESCE(p.lphone, '') AS phone,
    COALESCE(p.lmobile, '') AS mobile,
    COALESCE(p.lprofile_type, 'Old') AS profile_type,
    COALESCE(p.lstatus, 1) AS status,
    COALESCE(p.ltransaction_type, '') AS transaction_type,
    COALESCE(p.lsales_person, '') AS sales_person_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person_name,
    COALESCE(p.lrefer_by, '') AS refer_by,
    COALESCE(p.laddress, '') AS address,
    COALESCE(p.ldelivery_address, '') AS delivery_address,
    COALESCE(p.larea, '') AS area,
    COALESCE(p.lcity, '') AS city,
    COALESCE(p.lprovince, '') AS province,
    COALESCE(p.ltin, '') AS tin,
    COALESCE(p.lprice_group, '') AS price_group,
    COALESCE(p.lbusiness_line, '') AS business_line,
    COALESCE(p.lterms, '') AS terms,
    COALESCE(p.lvat_type, '') AS vat_type,
    COALESCE(p.lvat_percent, 0) AS vat_percent,
    COALESCE(p.ldealer_since, NULL) AS dealer_since,
    COALESCE(p.ldealer_quota, 0) AS dealer_quota,
    COALESCE(p.lcredit, 0) AS credit_limit,
    COALESCE(p.ldebt_type, 'Good') AS debt_type,
    COALESCE(p.lnotes, '') AS notes,
    COALESCE(p.ldatereg, '') AS date_registered,
    (SELECT COUNT(*) FROM tblcontact_person cp WHERE cp.lrefno = p.lsessionid) AS contact_count,
    (SELECT COUNT(*) FROM tblpatient_terms pt WHERE pt.lpatient = p.lsessionid) AS term_count,
    COALESCE(
        (SELECT SUM(COALESCE(l.ldebit, 0)) - SUM(COALESCE(l.lcredit, 0))
         FROM tblledger l
         WHERE l.lcustomerid = p.lsessionid),
        0
    ) AS latest_balance
FROM tblpatient p
LEFT JOIN tblaccount acc
    ON acc.lid = p.lsales_person
WHERE {$whereSql}
ORDER BY p.lcompany ASC, p.lid ASC
LIMIT :limit OFFSET :offset
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $this->bindParams($stmt, $params, true);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$isPickerMode && $items !== []) {
            foreach ($items as &$item) {
                $sessionId = trim((string) ($item['session_id'] ?? ''));
                $item['contacts'] = $sessionId !== '' ? $this->listContacts($sessionId) : [];
            }
            unset($item);
        }

        if ($isPickerMode) {
            $total = $offset + count($items);
        }

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $isPickerMode ? $page : (int) ceil($total / max(1, $perPage)),
                'filters' => [
                    'search' => $trimmedSearch,
                    'status' => $normalizedStatus === '' ? 'all' : $normalizedStatus,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCustomer(int $mainId, string $sessionId): ?array
    {
        $sql = <<<SQL
SELECT
    p.lid AS id,
    COALESCE(p.lsessionid, '') AS session_id,
    COALESCE(p.lpatient_code, '') AS customer_code,
    COALESCE(p.lcompany, '') AS company,
    COALESCE(p.lemail, '') AS email,
    COALESCE(p.lphone, '') AS phone,
    COALESCE(p.lmobile, '') AS mobile,
    COALESCE(p.lprofile_type, 'Old') AS profile_type,
    COALESCE(p.lstatus, 1) AS status,
    COALESCE(p.ltransaction_type, '') AS transaction_type,
    COALESCE(p.lsales_person, '') AS sales_person_id,
    TRIM(CONCAT(COALESCE(acc.lfname, ''), ' ', COALESCE(acc.llname, ''))) AS sales_person_name,
    COALESCE(p.lrefer_by, '') AS refer_by,
    COALESCE(p.laddress, '') AS address,
    COALESCE(p.ldelivery_address, '') AS delivery_address,
    COALESCE(p.larea, '') AS area,
    COALESCE(p.lcity, '') AS city,
    COALESCE(p.lprovince, '') AS province,
    COALESCE(p.ltin, '') AS tin,
    COALESCE(p.lprice_group, '') AS price_group,
    COALESCE(p.lbusiness_line, '') AS business_line,
    COALESCE(p.lterms, '') AS terms,
    COALESCE(p.lvat_type, '') AS vat_type,
    COALESCE(p.lvat_percent, 0) AS vat_percent,
    COALESCE(p.ldealer_since, NULL) AS dealer_since,
    COALESCE(p.ldealer_quota, 0) AS dealer_quota,
    COALESCE(p.lcredit, 0) AS credit_limit,
    COALESCE(p.ldebt_type, 'Good') AS debt_type,
    COALESCE(p.lnotes, '') AS notes,
    COALESCE(p.ldatereg, '') AS date_registered,
    COALESCE(
        (SELECT SUM(COALESCE(l.ldebit, 0)) - SUM(COALESCE(l.lcredit, 0))
         FROM tblledger l
         WHERE l.lcustomerid = p.lsessionid),
        0
    ) AS latest_balance
FROM tblpatient p
LEFT JOIN tblaccount acc
    ON acc.lid = p.lsales_person
WHERE p.lmain_id = :main_id
  AND p.lsessionid = :session_id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->bindValue('session_id', $sessionId, PDO::PARAM_STR);
        $stmt->execute();
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($customer === false) {
            return null;
        }

        $customer['contacts'] = $this->listContacts($sessionId);
        $customer['terms_history'] = $this->listTerms($sessionId);

        return $customer;
    }

    public function createCustomer(int $mainId, int $userId, array $payload): array
    {
        $company = trim((string) ($payload['company'] ?? ''));
        if ($company === '') {
            throw new RuntimeException('company is required');
        }

        $this->assertCustomerPhoneLengths($payload);

        $sessionId = trim((string) ($payload['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = $this->generateSessionId($mainId);
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO tblpatient
                (lmain_id, lencoded_by, lremarks, ldatereg, ldatetime, lpatient_today, lsessionid, lcompany, lemail, lphone, lmobile, lsales_person, lrefer_by, laddress, ldelivery_address, larea, ltin, lprice_group, lbusiness_line, lterms, ltransaction_type, lvat_type, lvat_percent, ldealer_since, ldealer_quota, lcredit, lstatus, lnotes, lprovince, lcity, ldebt_type, lprofile_type, lsince)
                VALUES
                (:main_id, :encoded_by, "New Patient", :datereg, NOW(), CURDATE(), :session_id, :company, :email, :phone, :mobile, :sales_person, :refer_by, :address, :delivery_address, :area, :tin, :price_group, :business_line, :terms, :transaction_type, :vat_type, :vat_percent, :dealer_since, :dealer_quota, :credit, :status, :notes, :province, :city, :debt_type, :profile_type, :since_date)'
            );
            $insert->execute([
                'main_id' => $mainId,
                'encoded_by' => $userId,
                'datereg' => date('Y-m-d H:i:s'),
                'session_id' => $sessionId,
                'company' => $company,
                'email' => (string) ($payload['email'] ?? ''),
                'phone' => (string) ($payload['phone'] ?? ''),
                'mobile' => (string) ($payload['mobile'] ?? ''),
                'sales_person' => (string) ($payload['sales_person_id'] ?? ''),
                'refer_by' => (string) ($payload['refer_by'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'delivery_address' => (string) (($payload['delivery_address'] ?? '') !== '' ? $payload['delivery_address'] : ($payload['address'] ?? '')),
                'area' => (string) ($payload['area'] ?? ''),
                'tin' => (string) ($payload['tin'] ?? ''),
                'price_group' => (string) ($payload['price_group'] ?? ''),
                'business_line' => (string) ($payload['business_line'] ?? ''),
                'terms' => (string) ($payload['terms'] ?? ''),
                'transaction_type' => (string) (($payload['transaction_type'] ?? '') !== '' ? $payload['transaction_type'] : 'Order Slip'),
                'vat_type' => (string) (($payload['vat_type'] ?? '') !== '' ? $payload['vat_type'] : self::DEFAULT_VAT_TYPE),
                'vat_percent' => isset($payload['vat_percent']) ? ((float) $payload['vat_percent']) : 0.12,
                'dealer_since' => $this->normalizeDateNullable((string) ($payload['dealer_since'] ?? ''), 'dealer_since'),
                'dealer_quota' => isset($payload['dealer_quota']) ? (float) $payload['dealer_quota'] : 0,
                'credit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : 0,
                'status' => isset($payload['status']) ? (int) $payload['status'] : 1,
                'notes' => (string) ($payload['notes'] ?? ''),
                'province' => (string) ($payload['province'] ?? ''),
                'city' => (string) ($payload['city'] ?? ''),
                'debt_type' => (string) (($payload['debt_type'] ?? '') !== '' ? $payload['debt_type'] : 'Good'),
                'profile_type' => (string) (($payload['profile_type'] ?? '') !== '' ? $payload['profile_type'] : 'Old'),
                'since_date' => $this->normalizeDateNullable((string) ($payload['since'] ?? ''), 'since_date') ?? date('Y-m-d'),
            ]);

            $initialTerms = trim((string) ($payload['terms'] ?? ''));
            if ($initialTerms !== '') {
                $this->insertTerm($pdo, $sessionId, [
                    'name' => $initialTerms,
                    'class_code' => (string) ($payload['price_group'] ?? ''),
                    'since' => date('Y-m-d'),
                    'quota' => 0,
                ]);
            }

            $contacts = is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
            foreach ($contacts as $contact) {
                if (!is_array($contact)) {
                    continue;
                }
                $name = trim((string) ($contact['name'] ?? $contact['first_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $this->insertContact($pdo, $mainId, $sessionId, $contact);
            }

            (new AuditTrailWriter($pdo))->write($mainId, $userId, 'Customer Database', 'Create', $sessionId);
            $pdo->commit();
            return $this->getCustomer($mainId, $sessionId) ?? [];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->rethrowAsFriendlyValidation($e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateCustomer(int $mainId, string $sessionId, array $payload): ?array
    {
        $existing = $this->getCustomer($mainId, $sessionId);
        if ($existing === null) {
            return null;
        }

        $this->assertCustomerPhoneLengths([
            'phone' => (string) ($payload['phone'] ?? $existing['phone'] ?? ''),
            'mobile' => (string) ($payload['mobile'] ?? $existing['mobile'] ?? ''),
        ]);

        $sql = <<<SQL
UPDATE tblpatient
SET
    lcompany = :company,
    lemail = :email,
    lphone = :phone,
    lmobile = :mobile,
    lsales_person = :sales_person,
    lrefer_by = :refer_by,
    laddress = :address,
    ldelivery_address = :delivery_address,
    larea = :area,
    ltin = :tin,
    lprice_group = :price_group,
    lbusiness_line = :business_line,
    lterms = :terms,
    ltransaction_type = :transaction_type,
    lvat_type = :vat_type,
    lvat_percent = :vat_percent,
    ldealer_since = :dealer_since,
    ldealer_quota = :dealer_quota,
    lcredit = :credit,
    lstatus = :status,
    lnotes = :notes,
    lprovince = :province,
    lcity = :city,
    ldebt_type = :debt_type,
    lprofile_type = :profile_type
WHERE lmain_id = :main_id
  AND lsessionid = :session_id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        try {
            $stmt->execute([
                'company' => (string) ($payload['company'] ?? $existing['company'] ?? ''),
                'email' => (string) ($payload['email'] ?? $existing['email'] ?? ''),
                'phone' => (string) ($payload['phone'] ?? $existing['phone'] ?? ''),
                'mobile' => (string) ($payload['mobile'] ?? $existing['mobile'] ?? ''),
                'sales_person' => (string) ($payload['sales_person_id'] ?? $existing['sales_person_id'] ?? ''),
                'refer_by' => (string) ($payload['refer_by'] ?? $existing['refer_by'] ?? ''),
                'address' => (string) ($payload['address'] ?? $existing['address'] ?? ''),
                'delivery_address' => (string) ($payload['delivery_address'] ?? $existing['delivery_address'] ?? ''),
                'area' => (string) ($payload['area'] ?? $existing['area'] ?? ''),
                'tin' => (string) ($payload['tin'] ?? $existing['tin'] ?? ''),
                'price_group' => (string) ($payload['price_group'] ?? $existing['price_group'] ?? ''),
                'business_line' => (string) ($payload['business_line'] ?? $existing['business_line'] ?? ''),
                'terms' => (string) ($payload['terms'] ?? $existing['terms'] ?? ''),
                'transaction_type' => (string) ($payload['transaction_type'] ?? $existing['transaction_type'] ?? ''),
                'vat_type' => (string) ($payload['vat_type'] ?? $existing['vat_type'] ?? ''),
                'vat_percent' => isset($payload['vat_percent']) ? ((float) $payload['vat_percent']) : (float) ($existing['vat_percent'] ?? 0),
                'dealer_since' => $this->normalizeDateNullable((string) ($payload['dealer_since'] ?? $existing['dealer_since'] ?? ''), 'dealer_since'),
                'dealer_quota' => isset($payload['dealer_quota']) ? (float) $payload['dealer_quota'] : (float) ($existing['dealer_quota'] ?? 0),
                'credit' => isset($payload['credit_limit']) ? (float) $payload['credit_limit'] : (float) ($existing['credit_limit'] ?? 0),
                'status' => isset($payload['status']) ? (int) $payload['status'] : (int) ($existing['status'] ?? 1),
                'notes' => (string) ($payload['notes'] ?? $existing['notes'] ?? ''),
                'province' => (string) ($payload['province'] ?? $existing['province'] ?? ''),
                'city' => (string) ($payload['city'] ?? $existing['city'] ?? ''),
                'debt_type' => (string) ($payload['debt_type'] ?? $existing['debt_type'] ?? 'Good'),
                'profile_type' => (string) ($payload['profile_type'] ?? $existing['profile_type'] ?? 'Old'),
                'main_id' => $mainId,
                'session_id' => $sessionId,
            ]);
        } catch (\Throwable $e) {
            $this->rethrowAsFriendlyValidation($e);
        }

        $auditUserId = isset($payload['user_id']) ? (int) $payload['user_id'] : 0;
        (new AuditTrailWriter($this->db->pdo()))->write($mainId, $auditUserId, 'Customer Database', 'Update', $sessionId);

        return $this->getCustomer($mainId, $sessionId);
    }

    public function bulkUpdateCustomers(int $mainId, array $sessionIds, array $payload): array
    {
        $normalizedSessionIds = array_values(array_unique(array_filter(array_map(
            static fn ($sessionId): string => trim((string) $sessionId),
            $sessionIds
        ), static fn (string $sessionId): bool => $sessionId !== '')));

        if ($normalizedSessionIds === []) {
            throw new RuntimeException('session_ids must include at least one valid session ID');
        }

        $fieldMap = [
            'company' => ['column' => 'lcompany', 'value' => static fn ($value): string => (string) $value],
            'email' => ['column' => 'lemail', 'value' => static fn ($value): string => (string) $value],
            'phone' => ['column' => 'lphone', 'value' => static fn ($value): string => (string) $value],
            'mobile' => ['column' => 'lmobile', 'value' => static fn ($value): string => (string) $value],
            'sales_person_id' => ['column' => 'lsales_person', 'value' => static fn ($value): string => (string) $value],
            'refer_by' => ['column' => 'lrefer_by', 'value' => static fn ($value): string => (string) $value],
            'address' => ['column' => 'laddress', 'value' => static fn ($value): string => (string) $value],
            'delivery_address' => ['column' => 'ldelivery_address', 'value' => static fn ($value): string => (string) $value],
            'area' => ['column' => 'larea', 'value' => static fn ($value): string => (string) $value],
            'city' => ['column' => 'lcity', 'value' => static fn ($value): string => (string) $value],
            'province' => ['column' => 'lprovince', 'value' => static fn ($value): string => (string) $value],
            'tin' => ['column' => 'ltin', 'value' => static fn ($value): string => (string) $value],
            'price_group' => ['column' => 'lprice_group', 'value' => static fn ($value): string => (string) $value],
            'business_line' => ['column' => 'lbusiness_line', 'value' => static fn ($value): string => (string) $value],
            'terms' => ['column' => 'lterms', 'value' => static fn ($value): string => (string) $value],
            'transaction_type' => ['column' => 'ltransaction_type', 'value' => static fn ($value): string => (string) $value],
            'vat_type' => ['column' => 'lvat_type', 'value' => static fn ($value): string => (string) $value],
            'vat_percent' => ['column' => 'lvat_percent', 'value' => static fn ($value): float => (float) $value],
            'dealer_since' => ['column' => 'ldealer_since', 'value' => fn ($value): ?string => $this->normalizeDateNullable((string) $value, 'dealer_since')],
            'dealer_quota' => ['column' => 'ldealer_quota', 'value' => static fn ($value): float => (float) $value],
            'credit_limit' => ['column' => 'lcredit', 'value' => static fn ($value): float => (float) $value],
            'status' => ['column' => 'lstatus', 'value' => static fn ($value): int => (int) $value],
            'notes' => ['column' => 'lnotes', 'value' => static fn ($value): string => (string) $value],
            'debt_type' => ['column' => 'ldebt_type', 'value' => static fn ($value): string => (string) $value],
            'profile_type' => ['column' => 'lprofile_type', 'value' => static fn ($value): string => (string) $value],
        ];

        $assignments = [];
        $params = ['main_id' => $mainId];

        foreach ($fieldMap as $apiField => $config) {
            if (!array_key_exists($apiField, $payload)) {
                continue;
            }

            $paramKey = 'set_' . $apiField;
            $assignments[] = sprintf('%s = :%s', $config['column'], $paramKey);
            $params[$paramKey] = $config['value']($payload[$apiField]);
        }

        if ($assignments === []) {
            throw new RuntimeException('No supported fields provided for bulk update');
        }

        $this->assertCustomerPhoneLengths($payload);

        $sessionPlaceholders = [];
        foreach ($normalizedSessionIds as $index => $sessionId) {
            $paramKey = 'session_id_' . $index;
            $sessionPlaceholders[] = ':' . $paramKey;
            $params[$paramKey] = $sessionId;
        }

        $sql = sprintf(
            'UPDATE tblpatient SET %s WHERE lmain_id = :main_id AND lsessionid IN (%s)',
            implode(', ', $assignments),
            implode(', ', $sessionPlaceholders)
        );

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            if ($value === null) {
                $stmt->bindValue($key, null, PDO::PARAM_NULL);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
        try {
            $stmt->execute();
        } catch (\Throwable $e) {
            $this->rethrowAsFriendlyValidation($e);
        }

        return [
            'updated' => true,
            'updated_count' => $stmt->rowCount(),
            'session_ids' => $normalizedSessionIds,
        ];
    }

    public function deleteCustomer(int $mainId, string $sessionId): bool
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT lid FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id LIMIT 1');
            $check->execute([
                'main_id' => $mainId,
                'session_id' => $sessionId,
            ]);
            if ($check->fetch(PDO::FETCH_ASSOC) === false) {
                $pdo->rollBack();
                return false;
            }

            $delPatient = $pdo->prepare('DELETE FROM tblpatient WHERE lmain_id = :main_id AND lsessionid = :session_id');
            $delPatient->execute([
                'main_id' => $mainId,
                'session_id' => $sessionId,
            ]);

            $delContacts = $pdo->prepare('DELETE FROM tblcontact_person WHERE lrefno = :session_id');
            $delContacts->execute(['session_id' => $sessionId]);

            $delTerms = $pdo->prepare('DELETE FROM tblpatient_terms WHERE lpatient = :session_id');
            $delTerms->execute(['session_id' => $sessionId]);

            $delImages = $pdo->prepare('DELETE FROM tblpatient_image WHERE lrefno = :session_id');
            $delImages->execute(['session_id' => $sessionId]);

            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function addContact(int $mainId, string $sessionId, array $payload): array
    {
        $exists = $this->getCustomer($mainId, $sessionId);
        if ($exists === null) {
            throw new RuntimeException('Customer not found');
        }

        $pdo = $this->db->pdo();
        $id = $this->insertContact($pdo, $mainId, $sessionId, $payload);

        $stmt = $pdo->prepare('SELECT * FROM tblcontact_person WHERE lid = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateContact(int $mainId, int $contactId, array $payload): ?array
    {
        $existing = $this->getContactById($mainId, $contactId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblcontact_person
SET
    lfname = :first_name,
    lmname = :middle_name,
    llname = :last_name,
    lposition = :position,
    lc_phone = :phone,
    lc_mobile = :mobile,
    lemail = :email,
    laddress = :address,
    lbday = :birthday
WHERE lid = :id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'first_name' => (string) ($payload['first_name'] ?? $existing['lfname'] ?? ''),
            'middle_name' => (string) ($payload['middle_name'] ?? $existing['lmname'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? $existing['llname'] ?? ''),
            'position' => (string) ($payload['position'] ?? $existing['lposition'] ?? ''),
            'phone' => (string) ($payload['phone'] ?? $existing['lc_phone'] ?? ''),
            'mobile' => (string) ($payload['mobile'] ?? $existing['lc_mobile'] ?? ''),
            'email' => (string) ($payload['email'] ?? $existing['lemail'] ?? ''),
            'address' => (string) ($payload['address'] ?? $existing['laddress'] ?? ''),
            'birthday' => $this->normalizeDateNullable((string) ($payload['birthday'] ?? $existing['lbday'] ?? ''), 'birthday'),
            'id' => $contactId,
        ]);

        return $this->getContactById($mainId, $contactId);
    }

    public function deleteContact(int $mainId, int $contactId): bool
    {
        $existing = $this->getContactById($mainId, $contactId);
        if ($existing === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM tblcontact_person WHERE lid = :id');
        $stmt->execute(['id' => $contactId]);
        return true;
    }

    public function addTerm(int $mainId, string $sessionId, array $payload): array
    {
        $exists = $this->getCustomer($mainId, $sessionId);
        if ($exists === null) {
            throw new RuntimeException('Customer not found');
        }

        $pdo = $this->db->pdo();
        $id = $this->insertTerm($pdo, $sessionId, $payload);
        $stmt = $pdo->prepare('SELECT * FROM tblpatient_terms WHERE lid = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : [];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>}
     */
    public function getTermsHistory(int $mainId, string $sessionId): array
    {
        $exists = $this->getCustomer($mainId, $sessionId);
        if ($exists === null) {
            throw new RuntimeException('Customer not found');
        }

        return [
            'items' => $this->listTerms($sessionId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateTerm(int $mainId, int $termId, array $payload): ?array
    {
        $existing = $this->getTermById($mainId, $termId);
        if ($existing === null) {
            return null;
        }

        $sql = <<<SQL
UPDATE tblpatient_terms
SET
    lname = :name,
    lclass_code = :class_code,
    lsince = :since_date,
    lquota = :quota
WHERE lid = :id
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'name' => (string) ($payload['name'] ?? $existing['lname'] ?? ''),
            'class_code' => (string) ($payload['class_code'] ?? $existing['lclass_code'] ?? ''),
            'since_date' => $this->normalizeDateNullable((string) ($payload['since'] ?? $existing['lsince'] ?? ''), 'since_date'),
            'quota' => isset($payload['quota']) ? (float) $payload['quota'] : (float) ($existing['lquota'] ?? 0),
            'id' => $termId,
        ]);

        return $this->getTermById($mainId, $termId);
    }

    public function deleteTerm(int $mainId, int $termId): bool
    {
        $existing = $this->getTermById($mainId, $termId);
        if ($existing === null) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('DELETE FROM tblpatient_terms WHERE lid = :id');
        $stmt->execute(['id' => $termId]);
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listContacts(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid AS id, lsessionid AS session_id, lfname, lmname, llname, lposition, lc_phone, lc_mobile, lemail, laddress, lbday
             FROM tblcontact_person
             WHERE lrefno = :session_id
             ORDER BY lid ASC'
        );
        $stmt->execute(['session_id' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listTerms(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT lid AS id, lrefno AS term_refno, lmonth, lname, lsince, lclass_code, lquota, lstatus
             FROM tblpatient_terms
             WHERE lpatient = :session_id
             ORDER BY lsince DESC, lid DESC'
        );
        $stmt->execute(['session_id' => $sessionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getContactById(int $mainId, int $contactId): ?array
    {
        $sql = <<<SQL
SELECT cp.*
FROM tblcontact_person cp
INNER JOIN tblpatient p ON p.lsessionid = cp.lrefno
WHERE p.lmain_id = :main_id
  AND cp.lid = :id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'id' => $contactId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getTermById(int $mainId, int $termId): ?array
    {
        $sql = <<<SQL
SELECT t.*
FROM tblpatient_terms t
INNER JOIN tblpatient p ON p.lsessionid = t.lpatient
WHERE p.lmain_id = :main_id
  AND t.lid = :id
LIMIT 1
SQL;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            'main_id' => $mainId,
            'id' => $termId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function insertContact(PDO $pdo, int $mainId, string $sessionId, array $payload): int
    {
        $firstName = trim((string) ($payload['first_name'] ?? $payload['name'] ?? ''));
        if ($firstName === '') {
            throw new RuntimeException('contact first_name is required');
        }

        $contactSession = date('ymdhis') . random_int(1000, 99999999);
        $stmt = $pdo->prepare(
            'INSERT INTO tblcontact_person
             (lmainid, lrefno, lfname, lmname, llname, lgender, lbday, laddress, lc_mobile, lc_phone, lemail, lposition, lsessionid)
             VALUES
             (:main_id, :refno, :first_name, :middle_name, :last_name, :gender, :birthday, :address, :mobile, :phone, :email, :position, :session_id)'
        );
        $stmt->execute([
            'main_id' => (string) $mainId,
            'refno' => $sessionId,
            'first_name' => $firstName,
            'middle_name' => (string) ($payload['middle_name'] ?? ''),
            'last_name' => (string) ($payload['last_name'] ?? ''),
            'gender' => (string) ($payload['gender'] ?? ''),
            'birthday' => $this->normalizeDateNullable((string) ($payload['birthday'] ?? ''), 'birthday'),
            'address' => (string) ($payload['address'] ?? ''),
            'mobile' => (string) ($payload['mobile'] ?? ''),
            'phone' => (string) ($payload['phone'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'position' => (string) ($payload['position'] ?? ''),
            'session_id' => $contactSession,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function insertTerm(PDO $pdo, string $sessionId, array $payload): int
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('term name is required');
        }

        $termRefno = date('ymdhis') . random_int(1000, 99999999);
        $stmt = $pdo->prepare(
            'INSERT INTO tblpatient_terms
             (lpatient, lrefno, lmonth, lname, lstatus, lsince, lclass_code, lquota)
             VALUES
             (:patient, :refno, :months, :name, :status, :since_date, :class_code, :quota)'
        );
        $stmt->execute([
            'patient' => $sessionId,
            'refno' => $termRefno,
            'months' => (string) ($payload['months'] ?? ''),
            'name' => $name,
            'status' => isset($payload['status']) ? (int) $payload['status'] : 0,
            'since_date' => $this->normalizeDateNullable((string) ($payload['since'] ?? date('Y-m-d')), 'since_date'),
            'class_code' => (string) ($payload['class_code'] ?? ''),
            'quota' => isset($payload['quota']) ? (float) $payload['quota'] : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function generateSessionId(int $mainId): string
    {
        return (string) random_int(10, 15) . date('YmdHis') . (string) random_int(1, 10000) . (string) $mainId;
    }

    private function assertCustomerPhoneLengths(array $payload): void
    {
        $phone = trim((string) ($payload['phone'] ?? ''));
        $mobile = trim((string) ($payload['mobile'] ?? ''));

        if ($phone !== '' && mb_strlen($phone) > self::CUSTOMER_PHONE_MAX_LENGTH) {
            throw new RuntimeException('Please enter a valid telephone number. The customer telephone field allows up to 15 characters only.');
        }

        if ($mobile !== '' && mb_strlen($mobile) > self::CUSTOMER_PHONE_MAX_LENGTH) {
            throw new RuntimeException('Please enter a valid phone number. The customer mobile field allows up to 15 characters only.');
        }
    }

    private function rethrowAsFriendlyValidation(\Throwable $e): never
    {
        $message = trim($e->getMessage());

        if (stripos($message, "Data too long for column 'lphone'") !== false) {
            throw new RuntimeException('Please enter a valid telephone number. The customer telephone field allows up to 15 characters only.', 0, $e);
        }

        if (stripos($message, "Data too long for column 'lmobile'") !== false) {
            throw new RuntimeException('Please enter a valid phone number. The customer mobile field allows up to 15 characters only.', 0, $e);
        }

        throw $e;
    }

    private function normalizeDateNullable(string $value, string $fieldName = 'date'): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $timestamp = strtotime($trimmed);
        if ($timestamp === false) {
            throw new RuntimeException("Invalid date format for field '{$fieldName}'. Please use YYYY-MM-DD.");
        }
        return date('Y-m-d', $timestamp);
    }

    private function bindParams(\PDOStatement $stmt, array $params, bool $bindLimitOffset): void
    {
        foreach ($params as $key => $value) {
            if (($key === 'limit' || $key === 'offset') && !$bindLimitOffset) {
                continue;
            }

            if (($key === 'limit' || $key === 'offset') && $bindLimitOffset) {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
                continue;
            }

            if (is_int($value)) {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
                continue;
            }

            $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
        }
    }

    /**
     * Lightweight query for the Sales Map: returns customer counts per province.
     *
     * Resolution order for province:
     *   1. tblpatient.lprovince  (if filled)
     *   2. Match the last part of tblpatient.laddress against refprovince.provDesc
     *
     * @return array<int, array{province: string, customer_count: int}>
     */
    /**
     * Mapping from DB province names (UPPER) to GeoJSON PROVINCE values.
     * The GeoJSON source is macoymejia/geojsonph Province/Provinces.json.
     */
    private const GEOJSON_PROVINCE_MAP = [
        'ABRA' => 'Abra',
        'AGUSAN DEL NORTE' => 'Agusan del Norte',
        'AGUSAN DEL SUR' => 'Agusan del Sur',
        'AKLAN' => 'Aklan',
        'ALBAY' => 'Albay',
        'ANTIQUE' => 'Antique',
        'APAYAO' => 'Apayao',
        'AURORA' => 'Aurora',
        'BASILAN' => 'Basilan',
        'BATAAN' => 'Bataan',
        'BATANES' => 'Batanes',
        'BATANGAS' => 'Batangas',
        'BENGUET' => 'Benguet',
        'BILIRAN' => 'Biliran',
        'BOHOL' => 'Bohol',
        'BUKIDNON' => 'Bukidnon',
        'BULACAN' => 'Bulacan',
        'CAGAYAN' => 'Cagayan',
        'CAMARINES NORTE' => 'Camarines Norte',
        'CAMARINES SUR' => 'Camarines Sur',
        'CAMIGUIN' => 'Camiguin',
        'CAPIZ' => 'Capiz',
        'CATANDUANES' => 'Catanduanes',
        'CAVITE' => 'Cavite',
        'CEBU' => 'Cebu',
        'COMPOSTELA VALLEY' => 'Compostela Valley',
        'DAVAO DE ORO' => 'Compostela Valley',
        'DAVAO DEL NORTE' => 'Davao del Norte',
        'DAVAO DEL SUR' => 'Davao del Sur',
        'DAVAO OCCIDENTAL' => 'Davao del Sur',
        'DAVAO ORIENTAL' => 'Davao Oriental',
        'DINAGAT ISLANDS' => 'Dinagat Islands',
        'EASTERN SAMAR' => 'Eastern Samar',
        'GUIMARAS' => 'Guimaras',
        'IFUGAO' => 'Ifugao',
        'ILOCOS NORTE' => 'Ilocos Norte',
        'ILOCOS SUR' => 'Ilocos Sur',
        'ILOILO' => 'Iloilo',
        'ISABELA' => 'Isabela',
        'CITY OF ISABELA' => 'Isabela',
        'KALINGA' => 'Kalinga',
        'LA UNION' => 'La Union',
        'LAGUNA' => 'Laguna',
        'LANAO DEL NORTE' => 'Lanao del Norte',
        'LANAO DEL SUR' => 'Lanao del Sur',
        'LEYTE' => 'Leyte',
        'MAGUINDANAO' => 'Maguindanao',
        'MARINDUQUE' => 'Marinduque',
        'MASBATE' => 'Masbate',
        'MOUNTAIN PROVINCE' => 'Mountain Province',
        'MISAMIS OCCIDENTAL' => 'Misamis Occidental',
        'MISAMIS ORIENTAL' => 'Misamis Oriental',
        'NEGROS OCCIDENTAL' => 'Negros Occidental',
        'NEGROS ORIENTAL' => 'Negros Oriental',
        'COTABATO (NORTH COTABATO)' => 'North Cotabato',
        'NORTH COTABATO' => 'North Cotabato',
        'NORTHERN SAMAR' => 'Northern Samar',
        'NUEVA ECIJA' => 'Nueva Ecija',
        'NUEVA VIZCAYA' => 'Nueva Vizcaya',
        'OCCIDENTAL MINDORO' => 'Occidental Mindoro',
        'ORIENTAL MINDORO' => 'Oriental Mindoro',
        'PALAWAN' => 'Palawan',
        'PAMPANGA' => 'Pampanga',
        'PANGASINAN' => 'Pangasinan',
        'QUEZON' => 'Quezon',
        'QUIRINO' => 'Quirino',
        'RIZAL' => 'Rizal',
        'ROMBLON' => 'Romblon',
        'SAMAR (WESTERN SAMAR)' => 'Samar',
        'SAMAR' => 'Samar',
        'SARANGANI' => 'Sarangani',
        'SIQUIJOR' => 'Siquijor',
        'SORSOGON' => 'Sorsogon',
        'SOUTH COTABATO' => 'South Cotabato',
        'SOUTHERN LEYTE' => 'Southern Leyte',
        'SULTAN KUDARAT' => 'Sultan Kudarat',
        'SULU' => 'Sulu',
        'SURIGAO DEL NORTE' => 'Surigao del Norte',
        'SURIGAO DEL SUR' => 'Surigao del Sur',
        'TARLAC' => 'Tarlac',
        'TAWI-TAWI' => 'Tawi-Tawi',
        'ZAMBALES' => 'Zambales',
        'ZAMBOANGA DEL NORTE' => 'Zamboanga del Norte',
        'ZAMBOANGA DEL SUR' => 'Zamboanga del Sur',
        'ZAMBOANGA SIBUGAY' => 'Zamboanga Sibugay',
        // NCR districts all map to Metropolitan Manila
        'NCR, CITY OF MANILA, FIRST DISTRICT' => 'Metropolitan Manila',
        'CITY OF MANILA' => 'Metropolitan Manila',
        'NCR, SECOND DISTRICT' => 'Metropolitan Manila',
        'NCR, THIRD DISTRICT' => 'Metropolitan Manila',
        'NCR, FOURTH DISTRICT' => 'Metropolitan Manila',
        'COTABATO CITY' => 'Maguindanao',
        'METRO MANILA' => 'Metropolitan Manila',
        'MANILA' => 'Metropolitan Manila',
    ];

    /**
     * Map a raw DB province name to its GeoJSON equivalent.
     */
    private function resolveGeoJsonProvince(string $raw): ?string
    {
        $upper = strtoupper(trim($raw));
        return self::GEOJSON_PROVINCE_MAP[$upper] ?? null;
    }

    public function getCustomerCountsByProvince(int $mainId): array
    {
        $sql = <<<SQL
SELECT
    UPPER(TRIM(resolved_province)) AS province,
    COUNT(*) AS customer_count
FROM (
    SELECT
        CASE
            WHEN COALESCE(TRIM(p.lprovince), '') <> ''
                THEN TRIM(p.lprovince)
            ELSE (
                SELECT rp.provDesc
                FROM refprovince rp
                WHERE UPPER(p.laddress) LIKE CONCAT('%', UPPER(rp.provDesc), '%')
                ORDER BY CHAR_LENGTH(rp.provDesc) DESC
                LIMIT 1
            )
        END AS resolved_province
    FROM tblpatient p
    WHERE p.lmain_id = :main_id
      AND COALESCE(p.lstatus, 1) = 1
      AND COALESCE(p.lprofile_type, 'Old') <> 'Prospect'
) sub
WHERE resolved_province IS NOT NULL
  AND resolved_province <> ''
GROUP BY UPPER(TRIM(resolved_province))
ORDER BY customer_count DESC
SQL;

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue('main_id', $mainId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Re-key by GeoJSON province name and merge counts for aliases
        $merged = [];
        foreach ($rows as $row) {
            $geoName = $this->resolveGeoJsonProvince((string) $row['province']);
            if ($geoName === null) {
                continue; // skip unrecognised values
            }
            if (!isset($merged[$geoName])) {
                $merged[$geoName] = 0;
            }
            $merged[$geoName] += (int) $row['customer_count'];
        }

        $result = [];
        foreach ($merged as $province => $count) {
            $result[] = ['province' => $province, 'customer_count' => $count];
        }

        usort($result, static fn ($a, $b) => $b['customer_count'] <=> $a['customer_count']);

        return $result;
    }
}
